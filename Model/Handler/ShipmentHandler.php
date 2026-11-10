<?php
declare(strict_types=1);

namespace Backstage\Bridge\Model\Handler;

use Backstage\Bridge\Api\Data\FulfillmentEventInterface;
use Backstage\Bridge\Model\CompletionService;
use Backstage\Bridge\Model\Data\FulfillmentEventResult;
use Backstage\Bridge\Model\Exception\BridgeException;
use Backstage\Bridge\Model\LegRepository;
use Backstage\Bridge\Model\ShipmentMirrorService;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;

/**
 * collected (recollida a botiga) i shipment_dispatched (enviament): un esdeveniment = un shipment.
 */
class ShipmentHandler implements HandlerInterface
{
    public function __construct(
        private readonly ShipmentMirrorService $shipmentService,
        private readonly LegRepository $legs,
        private readonly CompletionService $completion,
        private readonly string $legType
    ) {
    }

    public function check(OrderInterface $order, array $payload, FulfillmentEventInterface $event): void
    {
        if (in_array($order->getState(), [Order::STATE_CANCELED, Order::STATE_CLOSED, Order::STATE_HOLDED], true)) {
            throw BridgeException::unprocessable('transition_not_allowed', sprintf('Order is %s', $order->getState()));
        }
        if ($this->alreadyShipped($order, $payload)) {
            return; // reintent després d'una fallada: el shipment ja existeix
        }
        $this->shipmentService->validate($order, (string)$payload['source_code'], $payload['items'], $this->isPickup());
    }

    public function apply(OrderInterface $order, array $payload, FulfillmentEventInterface $event, FulfillmentEventResult $result): void
    {
        $orderId = (int)$order->getEntityId();
        $leg = $this->legs->getByRef($orderId, (string)$payload['leg_ref']);

        if ($leg && $leg['status'] === 'shipped') {
            $shipmentIds = $this->legs->getShipmentIds((int)$leg['leg_id']);
        } else {
            $shipmentId = $this->shipmentService->ship(
                $order,
                (string)$payload['source_code'],
                $payload['items'],
                $payload['carrier'] ?? null,
                $payload['tracking_number'] ?? null,
                $this->isPickup()
            );
            $this->legs->saveShipped(
                $orderId,
                (string)$payload['leg_ref'],
                $this->legType,
                (string)$payload['source_code'],
                null,
                $payload['carrier'] ?? null,
                $payload['tracking_number'] ?? null,
                $payload['items'],
                $shipmentId,
                $event->getEventId()
            );
            $shipmentIds = [$shipmentId];
        }

        $result->setMagentoShipmentIds($shipmentIds);
        $result->setPendingCompletion($this->completion->evaluateAndApply($orderId, $event));
    }

    private function isPickup(): bool
    {
        return $this->legType === LegRepository::TYPE_STORE_PICKUP;
    }

    private function alreadyShipped(OrderInterface $order, array $payload): bool
    {
        $leg = $this->legs->getByRef((int)$order->getEntityId(), (string)$payload['leg_ref']);
        return $leg !== null && $leg['status'] === 'shipped';
    }
}
