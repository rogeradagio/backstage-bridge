<?php
declare(strict_types=1);

namespace Backstage\Bridge\Model;

use Backstage\Bridge\Api\Data\FulfillmentEventInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\InventorySalesApi\Model\StockByWebsiteIdResolverInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;

/**
 * tancament de comanda (§13): decideix el status final segons el que realment hi ha a Magento.
 *
 * - collected només quan: tot expedit, tot facturat, totes les factures enllaçades a Odoo i reserva MSI neta zero.
 * - dispatched quan tot està expedit (no depèn de la factura).
 * - partially_fulfilled quan hi ha shipments però queda quantitat per expedir.
 * mai força el state: si el status no és vàlid per al state que ha calculat Magento, no el posa.
 */
class CompletionService
{
    public const PENDING_SHIPMENT = 'shipment';
    public const PENDING_INVOICE = 'invoice';
    public const PENDING_RESERVATION = 'reservation';

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly DocumentLinkRepository $links,
        private readonly LegRepository $legs,
        private readonly ResourceConnection $resource,
        private readonly StockByWebsiteIdResolverInterface $stockByWebsite,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @return string[] pending_completion
     */
    /**
     * Què falta per poder tancar la comanda, sense modificar res.
     *
     * @return string[]
     */
    public function pendingFor(int $orderId): array
    {
        /** @var Order $order */
        $order = $this->orderRepository->get($orderId);
        return $this->computePending($order, $this->remainingToShip($order));
    }

    private function computePending(Order $order, float $remaining): array
    {
        $pending = [];
        if ($remaining > 0.0001) {
            $pending[] = self::PENDING_SHIPMENT;
        }
        if (!$this->isFullyInvoicedAndLinked($order)) {
            $pending[] = self::PENDING_INVOICE;
        }
        if ($remaining <= 0.0001 && !$this->reservationIsBalanced($order)) {
            $pending[] = self::PENDING_RESERVATION;
        }
        return $pending;
    }

    public function evaluateAndApply(int $orderId, FulfillmentEventInterface $event, ?string $comment = null): array
    {
        /** @var Order $order */
        $order = $this->orderRepository->get($orderId);
        $remaining = $this->remainingToShip($order);
        $hasShipments = $this->countDocuments('sales_shipment', $orderId) > 0;
        $pending = $this->computePending($order, $remaining);

        $target = null;
        if ($remaining > 0.0001) {
            $target = $hasShipments ? 'partially_fulfilled' : null;
        } else {
            $shippedTypes = $this->legs->getShippedCustomerLegTypes($orderId);
            if (in_array(LegRepository::TYPE_STORE_PICKUP, $shippedTypes, true)) {
                $target = $pending === [] ? 'collected' : null;
            } elseif (in_array(LegRepository::TYPE_CUSTOMER_DELIVERY, $shippedTypes, true)) {
                $target = 'dispatched';
            }
        }

        $changed = false;
        if ($target !== null && $target !== (string)$order->getStatus()
            && in_array((string)$order->getState(), StatusMatrix::statesFor($target), true)
            && !in_array((string)$order->getStatus(), ['collected', 'delivered'], true)) {
            $order->setStatus($target);
            $changed = true;
        }

        if ($changed || $comment !== null) {
            $text = $comment ?? sprintf('[Odoo] %s (%s)', $event->getEventType(), $event->getEventId());
            if ($pending) {
                $text .= ' · pending: ' . implode(', ', $pending);
            }
            $order->addCommentToStatusHistory($text, (string)$order->getStatus())
                ->setIsCustomerNotified(false)
                ->setIsVisibleOnFront(false);
            $this->orderRepository->save($order);
        }

        return $pending;
    }

    private function remainingToShip(Order $order): float
    {
        $remaining = 0.0;
        foreach ($order->getAllItems() as $item) {
            if ($item->getParentItemId() || $item->getIsVirtual()) {
                continue;
            }
            $remaining += max(0.0, (float)$item->getQtyOrdered() - (float)$item->getQtyShipped()
                - (float)$item->getQtyRefunded() - (float)$item->getQtyCanceled());
        }
        return $remaining;
    }

    private function isFullyInvoicedAndLinked(Order $order): bool
    {
        if ($order->canInvoice()) {
            return false;
        }
        $coverage = $this->links->getCoverage((int)$order->getEntityId(), 'invoice');
        $connection = $this->resource->getConnection('sales');
        $invoices = $connection->fetchPairs(
            $connection->select()->from($this->resource->getTableName('sales_invoice'), ['entity_id', 'grand_total'])
                ->where('order_id = ?', (int)$order->getEntityId())
        );
        foreach ($invoices as $invoiceId => $grandTotal) {
            $covered = $coverage[(int)$invoiceId] ?? 0.0;
            if ((float)$grandTotal - $covered > InvoiceMirrorService::TOTAL_TOLERANCE) {
                return false;
            }
        }
        foreach ($order->getAllItems() as $item) {
            if ((float)$item->getQtyShipped() > (float)$item->getQtyInvoiced() + 0.0001) {
                return false;
            }
        }
        return true;
    }

    private function countDocuments(string $table, int $orderId): int
    {
        $connection = $this->resource->getConnection('sales');
        return (int)$connection->fetchOne(
            $connection->select()->from($this->resource->getTableName($table), ['COUNT(*)'])->where('order_id = ?', $orderId)
        );
    }

    /**
     * Reserva MSI neta de la comanda per SKU (filtrant primer per stock i SKU per no escanejar la taula sencera).
     */
    private function reservationIsBalanced(Order $order): bool
    {
        $skus = [];
        foreach ($order->getAllItems() as $item) {
            $skus[] = (string)$item->getSku();
        }
        if (!$skus) {
            return true;
        }
        $websiteId = (int)$this->storeManager->getStore((int)$order->getStoreId())->getWebsiteId();
        $stockId = (int)$this->stockByWebsite->execute($websiteId)->getStockId();

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('inventory_reservation'), ['sku', 'net' => 'SUM(quantity)'])
            ->where('stock_id = ?', $stockId)
            ->where('sku IN (?)', array_unique($skus))
            ->where("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.object_increment_id')) = ?", (string)$order->getIncrementId())
            ->group('sku')
            ->having('ABS(SUM(quantity)) > 0.0001');

        return $connection->fetchAll($select) === [];
    }
}
