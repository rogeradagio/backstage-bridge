<?php
declare(strict_types=1);

namespace Backstage\Bridge\Model\Handler;

use Backstage\Bridge\Api\Data\FulfillmentEventInterface;
use Backstage\Bridge\Model\Data\FulfillmentEventResult;
use Backstage\Bridge\Model\Exception\BridgeException;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

/**
 * cancelled: només abans de cap shipment i sense factura viva. MSI compensa la reserva de manera nativa.
 * amb factura cal primer credit_note_issued; amb shipments és una devolució, no una cancel·lació.
 */
class CancelledHandler implements HandlerInterface
{
    public function __construct(
        private readonly OrderManagementInterface $orderManagement,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ResourceConnection $resource
    ) {
    }

    public function check(OrderInterface $order, array $payload, FulfillmentEventInterface $event): void
    {
        /** @var Order $order */
        if ($order->getState() === Order::STATE_CANCELED) {
            return; // ja cancel·lada: idempotent
        }
        $hasShipments = $this->count('sales_shipment', (int)$order->getEntityId()) > 0;
        if ($order->getState() === Order::STATE_CLOSED && !$hasShipments) {
            return; // abonada sencera abans d'expedir: Magento ja l'ha tancada (equivalent a cancel·lada)
        }
        if ($hasShipments) {
            throw BridgeException::unprocessable('has_shipments', 'The order has shipments; use a return (return_received / credit_note_issued) instead of cancelling');
        }
        if ((float)$order->getTotalInvoiced() - (float)$order->getTotalRefunded() > 0.0001) {
            throw BridgeException::unprocessable('invoice_requires_credit_note', 'The order has a live invoice; send credit_note_issued before cancelling');
        }
        if (!$order->canCancel()) {
            throw BridgeException::unprocessable('transition_not_allowed', sprintf('Magento does not allow cancelling this order (state %s)', $order->getState()));
        }
    }

    public function apply(OrderInterface $order, array $payload, FulfillmentEventInterface $event, FulfillmentEventResult $result): void
    {
        $orderId = (int)$order->getEntityId();
        if (!in_array($order->getState(), [Order::STATE_CANCELED, Order::STATE_CLOSED], true)) {
            $this->orderManagement->cancel($orderId);
        }
        /** @var Order $fresh */
        $fresh = $this->orderRepository->get($orderId);
        $fresh->addCommentToStatusHistory(
            sprintf('[Odoo] cancelled (%s)%s', $event->getEventId(), isset($payload['reason']) ? ' · ' . $payload['reason'] : ''),
            (string)$fresh->getStatus()
        )->setIsCustomerNotified(false)->setIsVisibleOnFront(false);
        $this->orderRepository->save($fresh);
    }

    private function count(string $table, int $orderId): int
    {
        $connection = $this->resource->getConnection('sales');
        return (int)$connection->fetchOne(
            $connection->select()->from($this->resource->getTableName($table), ['COUNT(*)'])->where('order_id = ?', $orderId)
        );
    }
}
