<?php
declare(strict_types=1);

namespace Backstage\Bridge\Model\Handler;

use Backstage\Bridge\Api\Data\FulfillmentEventInterface;
use Backstage\Bridge\Model\Data\FulfillmentEventResult;
use Backstage\Bridge\Model\TransitionPolicy;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * esdeveniments que només canvien el status (preparing_at_warehouse, in_transit_to_store, ready_for_pickup).
 * no crea documents ni toca MSI. els correus arribaran a NotificationService (tirada següent).
 */
class StatusChangeHandler implements HandlerInterface
{
    public function __construct(
        private readonly TransitionPolicy $transitionPolicy,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly string $targetStatus
    ) {
    }

    public function check(OrderInterface $order, array $payload, FulfillmentEventInterface $event): void
    {
        if ((string)$order->getStatus() === $this->targetStatus) {
            return; // Ja hi és: idempotent a nivell de negoci.
        }
        $this->transitionPolicy->assertStatusAllowed($order, $this->targetStatus);
    }

    public function apply(OrderInterface $order, array $payload, FulfillmentEventInterface $event, FulfillmentEventResult $result): void
    {
        if ((string)$order->getStatus() !== $this->targetStatus) {
            $order->setStatus($this->targetStatus);
            /** @var \Magento\Sales\Model\Order $order */
            $order->addCommentToStatusHistory($this->buildComment($event, $payload), $this->targetStatus)
                ->setIsCustomerNotified(false)
                ->setIsVisibleOnFront(false);
            $this->orderRepository->save($order);
        }
    }

    private function buildComment(FulfillmentEventInterface $event, array $payload): string
    {
        $parts = [sprintf('[Odoo] %s (%s)', $event->getEventType(), $event->getEventId())];
        foreach (['leg_ref', 'pickup_location_code', 'origin_source_code', 'destination_source_code'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key])) {
                $parts[] = $key . '=' . $payload[$key];
            }
        }
        if ($event->getNotification()?->getComment()) {
            $parts[] = $event->getNotification()->getComment();
        }
        return implode(' · ', $parts);
    }
}
