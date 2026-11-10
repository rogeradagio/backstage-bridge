<?php
declare(strict_types=1);

namespace Backstage\Bridge\Model;

use Backstage\Bridge\Model\Exception\BridgeException;
use Magento\Sales\Api\Data\CreditmemoCreationArgumentsExtensionInterfaceFactory;
use Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterfaceFactory;
use Magento\Sales\Api\Data\CreditmemoItemCreationInterfaceFactory;
use Magento\Sales\Api\RefundInvoiceInterface;
use Magento\Sales\Api\RefundOrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Order\InvoiceFactory;

/**
 * abonament mirall de la factura rectificativa d'Odoo (§14).
 *
 * - sempre contra la factura Magento enllaçada a la factura d'Odoo d'origen.
 * - offline per defecte: no mou diners. l'online (crida a la passarel·la) només si es configura explícitament.
 * - mai retorna estoc a la font (return_to_stock_items buit): la reposició la porta Odoo amb l'export d'estoc.
 */
class CreditMemoMirrorService
{
    public function __construct(
        private readonly RefundInvoiceInterface $refundInvoice,
        private readonly RefundOrderInterface $refundOrder,
        private readonly CreditmemoItemCreationInterfaceFactory $itemFactory,
        private readonly CreditmemoCreationArgumentsInterfaceFactory $argumentsFactory,
        private readonly CreditmemoCreationArgumentsExtensionInterfaceFactory $argumentsExtensionFactory,
        private readonly CreditmemoFactory $creditmemoFactory,
        private readonly InvoiceFactory $invoiceFactory,
        private readonly DocumentLinkRepository $links,
        private readonly Config $config
    ) {
    }

    /**
     * @return array{mode:string, magento_invoice_id:int, magento_creditmemo_id:?int, match_status:string}
     * @throws BridgeException
     */
    public function plan(Order $order, array $payload): array
    {
        $odooId = (string)$payload['odoo_credit_note_id'];
        $existing = $this->links->get(DocumentLinkRepository::TYPE_CREDIT_NOTE, $odooId);
        if ($existing) {
            $same = abs((float)$existing['amount'] - (float)$payload['grand_total']) < 0.00001
                && (int)$existing['order_id'] === (int)$order->getEntityId();
            if (!$same) {
                throw BridgeException::conflict('odoo_document_conflict', sprintf('Odoo credit note %s is already linked with different data', $odooId));
            }
            return ['mode' => 'existing', 'magento_invoice_id' => 0, 'magento_creditmemo_id' => (int)$existing['magento_entity_id'], 'match_status' => (string)$existing['match_status']];
        }

        if (($payload['refund_mode'] ?? 'offline') === 'online' && !$this->config->isOnlineRefundAllowed()) {
            throw BridgeException::unprocessable('online_refund_not_allowed', 'Online refunds through Magento are disabled; refund the payment where it is done today and send refund_mode=offline');
        }
        if (strtoupper((string)$payload['currency']) !== strtoupper((string)$order->getOrderCurrencyCode())) {
            throw BridgeException::unprocessable('amount_mismatch', sprintf('Currency %s does not match order currency %s', $payload['currency'], $order->getOrderCurrencyCode()));
        }

        $invoiceLink = $this->links->get(DocumentLinkRepository::TYPE_INVOICE, (string)$payload['odoo_invoice_id']);
        if (!$invoiceLink || (int)$invoiceLink['order_id'] !== (int)$order->getEntityId() || !$invoiceLink['magento_entity_id']) {
            throw BridgeException::unprocessable('invoice_not_linked', sprintf('Odoo invoice %s is not linked to a Magento invoice of this order; send invoice_issued first', $payload['odoo_invoice_id']));
        }
        $invoiceId = (int)$invoiceLink['magento_entity_id'];

        // Previsualització sobre una factura carregada a part (no toca la comanda en memòria)
        $invoice = $this->invoiceFactory->create()->load($invoiceId);
        if (!$invoice->getId() || !$invoice->canRefund()) {
            throw BridgeException::unprocessable('nothing_to_refund', sprintf('Magento invoice %s cannot be refunded', $invoice->getIncrementId() ?: $invoiceId));
        }
        $this->assertItems($invoice, $payload['items']);
        $preview = $this->creditmemoFactory->createByInvoice($invoice, $this->previewData($payload));

        $diff = abs((float)$preview->getGrandTotal() - (float)$payload['grand_total']);
        if ($diff > InvoiceMirrorService::TOTAL_TOLERANCE && ($payload['accept_mismatch'] ?? false) !== true) {
            throw BridgeException::unprocessable('amount_mismatch', sprintf(
                'grand_total: Magento %.2f vs Odoo %.2f',
                $preview->getGrandTotal(),
                $payload['grand_total']
            ), ['magento' => round((float)$preview->getGrandTotal(), 2), 'odoo' => (float)$payload['grand_total']]);
        }

        return [
            'mode' => DocumentLinkRepository::MODE_CREATED,
            'magento_invoice_id' => $invoiceId,
            'magento_creditmemo_id' => null,
            'match_status' => $diff > InvoiceMirrorService::TOTAL_TOLERANCE ? DocumentLinkRepository::MATCH_AUDITED
                : ($diff < 0.00001 ? DocumentLinkRepository::MATCH_EXACT : DocumentLinkRepository::MATCH_TOLERANCE),
        ];
    }

    /**
     * @return int creditmemo id
     */
    public function apply(Order $order, array $payload, array $plan, string $eventId): int
    {
        if ($plan['mode'] === 'existing') {
            return (int)$plan['magento_creditmemo_id'];
        }

        $items = [];
        foreach ($this->qtyMap($payload['items']) as $itemId => $qty) {
            $item = $this->itemFactory->create();
            $item->setOrderItemId($itemId);
            $item->setQty($qty);
            $items[] = $item;
        }

        $arguments = $this->argumentsFactory->create();
        $arguments->setShippingAmount((float)($payload['shipping_amount'] ?? 0));
        $arguments->setAdjustmentPositive((float)($payload['adjustment_positive'] ?? 0));
        $arguments->setAdjustmentNegative((float)($payload['adjustment_negative'] ?? 0));
        $extension = $arguments->getExtensionAttributes() ?: $this->argumentsExtensionFactory->create();
        $extension->setReturnToStockItems([]);
        $arguments->setExtensionAttributes($extension);

        $online = ($payload['refund_mode'] ?? 'offline') === 'online';
        if ($online || $order->getPayment()->getMethodInstance()->canRefund()) {
            // Contra la factura (passarel·les que admeten abonament: Adyen, PayPal...). Offline si no s'ha demanat online.
            $creditmemoId = (int)$this->refundInvoice->execute(
                (int)$plan['magento_invoice_id'], $items, $online, false, false, null, $arguments
            );
        } else {
            // Mètodes offline (contra reemborsament, transferència): RefundInvoice els rebutja; abonament offline de la comanda.
            $creditmemoId = (int)$this->refundOrder->execute(
                (int)$order->getEntityId(), $items, false, false, null, $arguments
            );
        }

        $this->links->save([
            'order_id' => (int)$order->getEntityId(),
            'odoo_document_type' => DocumentLinkRepository::TYPE_CREDIT_NOTE,
            'odoo_document_id' => (string)$payload['odoo_credit_note_id'],
            'odoo_document_number' => (string)$payload['odoo_credit_note_number'],
            'magento_entity_type' => 'creditmemo',
            'magento_entity_id' => $creditmemoId,
            'link_mode' => DocumentLinkRepository::MODE_CREATED,
            'match_status' => $plan['match_status'],
            'amount' => (float)$payload['grand_total'],
            'payment_state' => $payload['refund_mode'] ?? 'offline',
            'amounts_json' => [
                'grand_total' => (float)$payload['grand_total'],
                'odoo_invoice_id' => $payload['odoo_invoice_id'],
                'shipping_amount' => (float)($payload['shipping_amount'] ?? 0),
                'items' => $payload['items'],
                'issued_at' => $payload['issued_at'] ?? null,
            ],
            'event_id' => $eventId,
        ]);

        return $creditmemoId;
    }

    private function previewData(array $payload): array
    {
        return [
            'qtys' => $this->qtyMap($payload['items']),
            // Explícit: si no s'indica, Magento retornaria tots els ports pendents
            'shipping_amount' => (float)($payload['shipping_amount'] ?? 0),
            'adjustment_positive' => (float)($payload['adjustment_positive'] ?? 0),
            'adjustment_negative' => (float)($payload['adjustment_negative'] ?? 0),
        ];
    }

    private function assertItems(Order\Invoice $invoice, array $items): void
    {
        $refundable = [];
        foreach ($invoice->getAllItems() as $invoiceItem) {
            $orderItem = $invoiceItem->getOrderItem();
            $refundable[(int)$invoiceItem->getOrderItemId()] = [
                'sku' => (string)$invoiceItem->getSku(),
                'qty' => (float)$invoiceItem->getQty() - (float)$orderItem->getQtyRefunded() + 0.0,
            ];
        }
        $errors = [];
        foreach ($this->qtyMap($items) as $itemId => $qty) {
            if (!isset($refundable[$itemId])) {
                $errors[] = sprintf('order_item_id %d is not in the linked Magento invoice', $itemId);
            } elseif ($qty > $refundable[$itemId]['qty'] + 0.0001) {
                $errors[] = sprintf('order_item_id %d: qty %s exceeds refundable qty %s', $itemId, $qty, $refundable[$itemId]['qty']);
            }
        }
        if ($errors) {
            throw BridgeException::unprocessable('qty_exceeds_or_invalid_items', implode('; ', $errors), $errors);
        }
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
}
