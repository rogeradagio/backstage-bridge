<?php
declare(strict_types=1);

namespace Backstage\Bridge\Model\Handler;

use Backstage\Bridge\Api\Data\FulfillmentEventInterface;
use Backstage\Bridge\Api\Data\NotificationPolicyInterface;
use Backstage\Bridge\Model\CompletionService;
use Backstage\Bridge\Model\Data\FulfillmentEventResult;
use Backstage\Bridge\Model\DocumentLinkRepository;
use Backstage\Bridge\Model\Exception\BridgeException;
use Backstage\Bridge\Model\InvoiceMirrorService;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;

/**
 * invoice_issued: factura mirall (cas B) o enllaç amb la factura de captura (cas A).
 */
class InvoiceIssuedHandler implements HandlerInterface
{
    public function __construct(
        private readonly InvoiceMirrorService $invoiceService,
        private readonly CompletionService $completion
    ) {
    }

    public function check(OrderInterface $order, array $payload, FulfillmentEventInterface $event): void
    {
        /** @var Order $order */
        if ($order->getState() === Order::STATE_CANCELED) {
            throw BridgeException::unprocessable('transition_not_allowed', 'Order is canceled');
        }
        if ($event->getNotification()?->getMode() === NotificationPolicyInterface::MODE_SEND) {
            throw BridgeException::badRequest('invalid_envelope', 'invoice_issued never notifies the customer; use notification.mode=suppress');
        }
        if (($payload['accept_mismatch'] ?? false) === true
            && $event->getNotification()?->getMode() !== NotificationPolicyInterface::MODE_SUPPRESS) {
            throw BridgeException::badRequest('invalid_payload', 'accept_mismatch requires notification.mode=suppress');
        }
        $this->invoiceService->plan($order, $payload);
    }

    public function apply(OrderInterface $order, array $payload, FulfillmentEventInterface $event, FulfillmentEventResult $result): void
    {
        /** @var Order $order */
        $plan = $this->invoiceService->plan($order, $payload);
        $invoiceId = $this->invoiceService->apply($order, $payload, $plan, $event->getEventId());

        $result->setMagentoInvoiceIds([$invoiceId]);
        $result->setPendingCompletion($this->completion->evaluateAndApply((int)$order->getEntityId(), $event, sprintf(
            '[Odoo] invoice_issued %s (%s) → Magento invoice #%d, %s/%s',
            $payload['odoo_invoice_number'],
            $event->getEventId(),
            $invoiceId,
            $plan['mode'] === 'existing' ? 'already linked' : $plan['mode'],
            $plan['match_status']
        )));
    }
}
