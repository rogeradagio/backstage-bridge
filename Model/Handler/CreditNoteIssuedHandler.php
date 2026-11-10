<?php
declare(strict_types=1);

namespace Backstage\Bridge\Model\Handler;

use Backstage\Bridge\Api\Data\FulfillmentEventInterface;
use Backstage\Bridge\Api\Data\NotificationPolicyInterface;
use Backstage\Bridge\Model\CreditMemoMirrorService;
use Backstage\Bridge\Model\Data\FulfillmentEventResult;
use Backstage\Bridge\Model\Exception\BridgeException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

/**
 * credit_note_issued: abonament mirall de la rectificativa d'Odoo contra la factura Magento enllaçada.
 */
class CreditNoteIssuedHandler implements HandlerInterface
{
    public function __construct(
        private readonly CreditMemoMirrorService $creditMemoService,
        private readonly OrderRepositoryInterface $orderRepository
    ) {
    }

    public function check(OrderInterface $order, array $payload, FulfillmentEventInterface $event): void
    {
        if (($payload['accept_mismatch'] ?? false) === true
            && $event->getNotification()?->getMode() !== NotificationPolicyInterface::MODE_SUPPRESS) {
            throw BridgeException::badRequest('invalid_payload', 'accept_mismatch requires notification.mode=suppress');
        }
        /** @var Order $order */
        $this->creditMemoService->plan($order, $payload);
    }

    public function apply(OrderInterface $order, array $payload, FulfillmentEventInterface $event, FulfillmentEventResult $result): void
    {
        /** @var Order $order */
        $plan = $this->creditMemoService->plan($order, $payload);
        $creditmemoId = $this->creditMemoService->apply($order, $payload, $plan, $event->getEventId());
        $result->setMagentoCreditmemoIds([$creditmemoId]);

        if ($plan['mode'] !== 'existing') {
            /** @var Order $fresh */
            $fresh = $this->orderRepository->get((int)$order->getEntityId());
            $fresh->addCommentToStatusHistory(sprintf(
                '[Odoo] credit_note_issued %s (%s) → Magento creditmemo #%d, %s, %s',
                $payload['odoo_credit_note_number'],
                $event->getEventId(),
                $creditmemoId,
                $payload['refund_mode'] ?? 'offline',
                $plan['match_status']
            ), (string)$fresh->getStatus())->setIsCustomerNotified(false)->setIsVisibleOnFront(false);
            $this->orderRepository->save($fresh);
        }
    }
}
