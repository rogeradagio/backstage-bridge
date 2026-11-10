<?php
declare(strict_types=1);

namespace Backstage\Bridge\Model;

use Backstage\Bridge\Model\Exception\BridgeException;
use Magento\Sales\Api\Data\InvoiceItemCreationInterfaceFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\InvoiceOrderInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Service\InvoiceService;

/**
 * Factura mirall de la factura fiscal d'Odoo.
 *
 * Cas A: la comanda ja té factura Magento (captura en línia) → s'enllaça (matched), no se'n crea cap.
 * Cas B: no en té → es crea amb InvoiceOrder, capture=false, notify=false (created).
 */
class InvoiceMirrorService
{
    public const LINE_TOLERANCE = 0.01;
    public const TOTAL_TOLERANCE = 0.02;

    public function __construct(
        private readonly InvoiceOrderInterface $invoiceOrder,
        private readonly InvoiceItemCreationInterfaceFactory $itemFactory,
        private readonly InvoiceService $invoiceService,
        private readonly DocumentLinkRepository $links,
        private readonly OrderFactory $orderFactory,
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Decideix el cas i valida sense modificar res.
     *
     * @return array{mode:string, magento_invoice_id:?int, match_status:string}
     * @throws BridgeException
     */
    public function plan(Order $order, array $payload): array
    {
        $odooId = (string)$payload['odoo_invoice_id'];
        $existing = $this->links->get(DocumentLinkRepository::TYPE_INVOICE, $odooId);
        if ($existing) {
            $same = abs((float)$existing['amount'] - (float)$payload['grand_total']) < 0.00001
                && (int)$existing['order_id'] === (int)$order->getEntityId();
            if (!$same) {
                throw BridgeException::conflict('odoo_document_conflict', sprintf('Odoo invoice %s is already linked with different data', $odooId));
            }
            return ['mode' => 'existing', 'magento_invoice_id' => (int)$existing['magento_entity_id'], 'match_status' => (string)$existing['match_status']];
        }

        if (strtoupper((string)$payload['currency']) !== strtoupper((string)$order->getOrderCurrencyCode())) {
            throw BridgeException::unprocessable('amount_mismatch', sprintf('Currency %s does not match order currency %s', $payload['currency'], $order->getOrderCurrencyCode()));
        }

        $this->assertItemsBelongToOrder($order, $payload['items']);
        $grandTotal = (float)$payload['grand_total'];
        $acceptMismatch = ($payload['accept_mismatch'] ?? false) === true;

        // Cas A: factures Magento amb import encara no cobert per Odoo
        // Factures desades a la BD (mai la col·lecció en memòria: pot contenir previsualitzacions no desades)
        $coverage = $this->links->getCoverage((int)$order->getEntityId(), 'invoice');
        foreach ($this->savedInvoices((int)$order->getEntityId()) as $invoice) {
            $invoiceId = (int)$invoice['entity_id'];
            $uncovered = (float)$invoice['grand_total'] - ($coverage[$invoiceId] ?? 0.0);
            if ($uncovered <= self::TOTAL_TOLERANCE) {
                continue;
            }
            $diff = $uncovered - $grandTotal;
            if ($diff >= -self::TOTAL_TOLERANCE) {
                return [
                    'mode' => DocumentLinkRepository::MODE_MATCHED,
                    'magento_invoice_id' => $invoiceId,
                    'match_status' => abs($diff) < 0.00001 ? DocumentLinkRepository::MATCH_EXACT : DocumentLinkRepository::MATCH_TOLERANCE,
                ];
            }
            if ($acceptMismatch) {
                return ['mode' => DocumentLinkRepository::MODE_MATCHED, 'magento_invoice_id' => $invoiceId, 'match_status' => DocumentLinkRepository::MATCH_AUDITED];
            }
            throw BridgeException::unprocessable('amount_mismatch', sprintf(
                'Odoo invoice total %.2f exceeds the uncovered amount %.2f of Magento invoice %s',
                $grandTotal,
                $uncovered,
                $invoice['increment_id']
            ), ['magento_invoice' => $invoice['increment_id'], 'uncovered' => round($uncovered, 2), 'odoo_total' => $grandTotal]);
        }

        // Cas B: crear factura mirall
        if (!$order->canInvoice()) {
            throw BridgeException::unprocessable('nothing_to_invoice', 'The order has nothing left to invoice and no uncovered Magento invoice');
        }
        $qtys = $this->qtyMap($payload['items']);
        $this->assertQtysInvoiceable($order, $qtys);

        // Previsualització sobre una còpia independent: prepareInvoice afegeix la factura a la comanda en memòria
        $isolated = $this->orderFactory->create()->load((int)$order->getEntityId());
        $preview = $this->invoiceService->prepareInvoice($isolated, $qtys);
        $errors = [];
        foreach ($payload['items'] as $item) {
            if (!isset($item['row_total_incl_tax'])) {
                continue;
            }
            foreach ($preview->getAllItems() as $previewItem) {
                if ((int)$previewItem->getOrderItemId() === (int)$item['order_item_id']) {
                    $diff = abs((float)$previewItem->getRowTotalInclTax() - (float)$item['row_total_incl_tax']);
                    if ($diff > self::LINE_TOLERANCE) {
                        $errors[] = sprintf('order_item_id %d: Magento %.2f vs Odoo %.2f', $item['order_item_id'], $previewItem->getRowTotalInclTax(), $item['row_total_incl_tax']);
                    }
                }
            }
        }
        $totalDiff = abs((float)$preview->getGrandTotal() - $grandTotal);
        if ($totalDiff > self::TOTAL_TOLERANCE) {
            $errors[] = sprintf('grand_total: Magento %.2f vs Odoo %.2f', $preview->getGrandTotal(), $grandTotal);
        }
        if ($errors && !$acceptMismatch) {
            throw BridgeException::unprocessable('amount_mismatch', implode('; ', $errors), $errors);
        }

        return [
            'mode' => DocumentLinkRepository::MODE_CREATED,
            'magento_invoice_id' => null,
            'match_status' => $errors ? DocumentLinkRepository::MATCH_AUDITED
                : ($totalDiff < 0.00001 ? DocumentLinkRepository::MATCH_EXACT : DocumentLinkRepository::MATCH_TOLERANCE),
        ];
    }

    /**
     * Aplica el pla: crea la factura (cas B) i desa l'enllaç.
     *
     * @return int magento invoice id
     */
    public function apply(Order $order, array $payload, array $plan, string $eventId): int
    {
        if ($plan['mode'] === 'existing') {
            return (int)$plan['magento_invoice_id'];
        }

        $invoiceId = $plan['magento_invoice_id'];
        if ($plan['mode'] === DocumentLinkRepository::MODE_CREATED) {
            $items = [];
            foreach ($this->qtyMap($payload['items']) as $itemId => $qty) {
                $item = $this->itemFactory->create();
                $item->setOrderItemId($itemId); // els setters d'ItemCreation no retornen $this
                $item->setQty($qty);
                $items[] = $item;
            }
            $invoiceId = (int)$this->invoiceOrder->execute((int)$order->getEntityId(), false, $items, false, false, null, null);
        }

        $this->links->save([
            'order_id' => (int)$order->getEntityId(),
            'odoo_document_type' => DocumentLinkRepository::TYPE_INVOICE,
            'odoo_document_id' => (string)$payload['odoo_invoice_id'],
            'odoo_document_number' => (string)$payload['odoo_invoice_number'],
            'magento_entity_type' => 'invoice',
            'magento_entity_id' => $invoiceId,
            'link_mode' => $plan['mode'],
            'match_status' => $plan['match_status'],
            'amount' => (float)$payload['grand_total'],
            'payment_state' => $payload['payment_state'] ?? null,
            'amounts_json' => [
                'grand_total' => (float)$payload['grand_total'],
                'shipping_amount' => (float)($payload['shipping_amount'] ?? 0),
                'items' => $payload['items'],
                'issued_at' => $payload['issued_at'] ?? null,
            ],
            'event_id' => $eventId,
        ]);

        return (int)$invoiceId;
    }

    private function savedInvoices(int $orderId): array
    {
        $connection = $this->resource->getConnection('sales');
        return $connection->fetchAll(
            $connection->select()->from($this->resource->getTableName('sales_invoice'), ['entity_id', 'increment_id', 'grand_total'])
                ->where('order_id = ?', $orderId)->order('entity_id')
        );
    }

    /**
     * @return array<int, float>
     */
    private function qtyMap(array $items): array
    {
        $qtys = [];
        foreach ($items as $item) {
            $qtys[(int)$item['order_item_id']] = ($qtys[(int)$item['order_item_id']] ?? 0) + (float)$item['qty'];
        }
        return $qtys;
    }

    private function assertItemsBelongToOrder(OrderInterface $order, array $items): void
    {
        $skus = [];
        foreach ($order->getItems() as $line) {
            $skus[(int)$line->getItemId()] = (string)$line->getSku();
        }
        $errors = [];
        foreach ($items as $item) {
            $id = (int)$item['order_item_id'];
            if (!isset($skus[$id])) {
                $errors[] = sprintf('order_item_id %d does not belong to the order', $id);
            } elseif ($skus[$id] !== (string)$item['sku']) {
                $errors[] = sprintf('order_item_id %d has sku "%s", not "%s"', $id, $skus[$id], $item['sku']);
            }
        }
        if ($errors) {
            throw BridgeException::unprocessable('qty_exceeds_or_invalid_items', implode('; ', $errors), $errors);
        }
    }

    private function assertQtysInvoiceable(Order $order, array $qtys): void
    {
        $errors = [];
        foreach ($order->getAllItems() as $line) {
            $id = (int)$line->getItemId();
            if (isset($qtys[$id]) && $qtys[$id] > (float)$line->getQtyToInvoice() + 0.0001) {
                $errors[] = sprintf('order_item_id %d: qty %s exceeds invoiceable qty %s', $id, $qtys[$id], $line->getQtyToInvoice());
            }
        }
        if ($errors) {
            throw BridgeException::unprocessable('qty_exceeds_or_invalid_items', implode('; ', $errors), $errors);
        }
    }
}
