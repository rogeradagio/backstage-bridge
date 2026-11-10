<?php
declare(strict_types=1);

namespace Backstage\Bridge\Model\Handler;

use Backstage\Bridge\Api\Data\FulfillmentEventInterface;
use Backstage\Bridge\Model\Data\FulfillmentEventResult;
use Backstage\Bridge\Model\Exception\BridgeException;
use Backstage\Bridge\Model\LegRepository;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

/**
 * return_received: el material ha tornat (albarà de devolució validat a Odoo).
 * només registra el tram per a l'auditoria i l'historial. no mou estoc ni crea abonament:
 * l'estoc el porta l'export d'Odoo i els diners, credit_note_issued.
 */
class ReturnReceivedHandler implements HandlerInterface
{
    public function __construct(
        private readonly LegRepository $legs,
        private readonly OrderRepositoryInterface $orderRepository
    ) {
    }

    public function check(OrderInterface $order, array $payload, FulfillmentEventInterface $event): void
    {
        $existing = $this->legs->getByRef((int)$order->getEntityId(), (string)$payload['leg_ref']);
        if ($existing) {
            if ($existing['type'] !== LegRepository::TYPE_RETURN) {
                throw BridgeException::conflict('odoo_document_conflict', sprintf('leg_ref "%s" already exists and is not a return', $payload['leg_ref']));
            }
            return; // ja registrada
        }

        $returned = $this->legs->getReturnedQtys((int)$order->getEntityId());
        $lines = [];
        foreach ($order->getItems() as $line) {
            $lines[(int)$line->getItemId()] = $line;
        }
        $errors = [];
        foreach ($payload['items'] as $item) {
            $id = (int)$item['order_item_id'];
            $line = $lines[$id] ?? null;
            if ($line === null) {
                $errors[] = sprintf('order_item_id %d does not belong to the order', $id);
                continue;
            }
            if ((string)$line->getSku() !== (string)$item['sku']) {
                $errors[] = sprintf('order_item_id %d has sku "%s", not "%s"', $id, $line->getSku(), $item['sku']);
            }
            $returnable = (float)$line->getQtyShipped() - ($returned[$id] ?? 0.0);
            if ((float)$item['qty'] > $returnable + 0.0001) {
                $errors[] = sprintf('order_item_id %d: qty %s exceeds shipped and not yet returned qty %s', $id, $item['qty'], $returnable);
            }
        }
        if ($errors) {
            throw BridgeException::unprocessable('qty_exceeds_or_invalid_items', implode('; ', $errors), $errors);
        }
    }

    public function apply(OrderInterface $order, array $payload, FulfillmentEventInterface $event, FulfillmentEventResult $result): void
    {
        $orderId = (int)$order->getEntityId();
        if ($this->legs->getByRef($orderId, (string)$payload['leg_ref'])) {
            return;
        }
        $this->legs->saveReturn($orderId, (string)$payload['leg_ref'], $payload['destination_source_code'] ?? null, $payload['items'], $event->getEventId());

        /** @var Order $fresh */
        $fresh = $this->orderRepository->get($orderId);
        $summary = implode(', ', array_map(static fn($i) => $i['sku'] . ' x' . $i['qty'], $payload['items']));
        $fresh->addCommentToStatusHistory(sprintf(
            '[Odoo] return_received %s (%s) · %s%s',
            $payload['leg_ref'],
            $event->getEventId(),
            $summary,
            isset($payload['destination_source_code']) ? ' → ' . $payload['destination_source_code'] : ''
        ), (string)$fresh->getStatus())->setIsCustomerNotified(false)->setIsVisibleOnFront(false);
        $this->orderRepository->save($fresh);
    }
}
