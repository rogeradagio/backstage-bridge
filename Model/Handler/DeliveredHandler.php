<?php
declare(strict_types=1);

namespace Backstage\Bridge\Model\Handler;

use Backstage\Bridge\Api\Data\FulfillmentEventInterface;
use Backstage\Bridge\Model\CompletionService;
use Backstage\Bridge\Model\Data\FulfillmentEventResult;
use Backstage\Bridge\Model\Exception\BridgeException;
use Backstage\Bridge\Model\LegRepository;
use Backstage\Bridge\Model\StatusMatrix;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

/**
 * delivered: el transportista ha lliurat. Només si la comanda està tancada (§13): tot expedit, facturat,
 * enllaçat a Odoo i amb reserva neta zero. Si falta alguna cosa, 422 amb el detall; mai es força.
 */
class DeliveredHandler implements HandlerInterface
{
    public function __construct(
        private readonly CompletionService $completion,
        private readonly LegRepository $legs,
        private readonly OrderRepositoryInterface $orderRepository
    ) {
    }

    public function check(OrderInterface $order, array $payload, FulfillmentEventInterface $event): void
    {
        if ((string)$order->getStatus() === 'delivered') {
            return;
        }
        $leg = $this->legs->getByRef((int)$order->getEntityId(), (string)$payload['leg_ref']);
        if (!$leg || $leg['type'] !== LegRepository::TYPE_CUSTOMER_DELIVERY || $leg['status'] !== 'shipped') {
            throw BridgeException::unprocessable('transition_not_allowed', sprintf('Leg "%s" is not a shipped customer delivery', $payload['leg_ref']));
        }
        $pending = $this->completion->pendingFor((int)$order->getEntityId());
        if ($pending !== []) {
            throw BridgeException::unprocessable(
                'completion_preconditions_failed',
                'Order cannot be closed as delivered; pending: ' . implode(', ', $pending),
                ['pending' => $pending]
            );
        }
        if (!in_array((string)$order->getState(), StatusMatrix::statesFor('delivered'), true)) {
            throw BridgeException::unprocessable('status_not_allowed_for_state', sprintf('Status "delivered" is not allowed for state "%s"', $order->getState()));
        }
    }

    public function apply(OrderInterface $order, array $payload, FulfillmentEventInterface $event, FulfillmentEventResult $result): void
    {
        /** @var Order $order */
        if ((string)$order->getStatus() !== 'delivered') {
            $order->setStatus('delivered');
            $order->addCommentToStatusHistory(sprintf('[Odoo] delivered (%s) · leg_ref=%s', $event->getEventId(), $payload['leg_ref']), 'delivered')
                ->setIsCustomerNotified(false)->setIsVisibleOnFront(false);
            $this->orderRepository->save($order);
        }
        $this->legs->saveStatus((int)$order->getEntityId(), (string)$payload['leg_ref'], LegRepository::TYPE_CUSTOMER_DELIVERY, null, null, 'delivered', $event->getEventId());
    }
}
