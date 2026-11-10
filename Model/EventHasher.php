<?php
declare(strict_types=1);

namespace Backstage\Bridge\Model;

use Backstage\Bridge\Api\Data\FulfillmentEventInterface;

/**
 * hash estable del sobre: claus ordenades, payload descodificat, sense notification.comment.
 * odoo pot reenviar el mateix esdeveniment amb un JSON formatat diferent i el hash no canvia.
 */
class EventHasher
{
    public function hash(FulfillmentEventInterface $event, array $payload): string
    {
        $notification = $event->getNotification();
        $canonical = [
            'event_id' => $event->getEventId(),
            'event_type' => $event->getEventType(),
            'store_code' => $event->getStoreCode(),
            'order_reference' => $event->getOrderReference(),
            'occurred_at' => $event->getOccurredAt(),
            'notification' => [
                'mode' => $notification ? $notification->getMode() : null,
                'template' => $notification ? $notification->getTemplate() : null,
            ],
            'payload' => $payload,
        ];

        return hash('sha256', json_encode($this->sortRecursive($canonical), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursive($item);
        }
        return $value;
    }
}
