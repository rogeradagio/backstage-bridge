<?php
declare(strict_types=1);

namespace Backstage\Bridge\Api\Data;

/**
 * Sobre d'un esdeveniment de fulfilment. El payload és un string JSON.
 *
 * @api
 */
interface FulfillmentEventInterface
{
    public const EVENT_ID = 'event_id';
    public const EVENT_TYPE = 'event_type';
    public const STORE_CODE = 'store_code';
    public const ORDER_REFERENCE = 'order_reference';
    public const OCCURRED_AT = 'occurred_at';
    public const NOTIFICATION = 'notification';
    public const PAYLOAD = 'payload';

    /**
     * @return string
     */
    public function getEventId();

    /**
     * @param string $eventId
     * @return $this
     */
    public function setEventId($eventId);

    /**
     * @return string
     */
    public function getEventType();

    /**
     * @param string $eventType
     * @return $this
     */
    public function setEventType($eventType);

    /**
     * @return string
     */
    public function getStoreCode();

    /**
     * @param string $storeCode
     * @return $this
     */
    public function setStoreCode($storeCode);

    /**
     * @return string
     */
    public function getOrderReference();

    /**
     * @param string $orderReference
     * @return $this
     */
    public function setOrderReference($orderReference);

    /**
     * @return string
     */
    public function getOccurredAt();

    /**
     * @param string $occurredAt
     * @return $this
     */
    public function setOccurredAt($occurredAt);

    /**
     * @return \Backstage\Bridge\Api\Data\NotificationPolicyInterface|null
     */
    public function getNotification();

    /**
     * @param \Backstage\Bridge\Api\Data\NotificationPolicyInterface $notification
     * @return $this
     */
    public function setNotification($notification);

    /**
     * @return string
     */
    public function getPayload();

    /**
     * @param string $payload
     * @return $this
     */
    public function setPayload($payload);
}
