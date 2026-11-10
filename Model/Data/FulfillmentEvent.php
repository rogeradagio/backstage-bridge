<?php
declare(strict_types=1);

namespace Backstage\Bridge\Model\Data;

use Backstage\Bridge\Api\Data\FulfillmentEventInterface;
use Magento\Framework\DataObject;

class FulfillmentEvent extends DataObject implements FulfillmentEventInterface
{
    public function getEventId()
    {
        return (string)$this->getData(self::EVENT_ID);
    }

    public function setEventId($eventId)
    {
        return $this->setData(self::EVENT_ID, $eventId);
    }

    public function getEventType()
    {
        return (string)$this->getData(self::EVENT_TYPE);
    }

    public function setEventType($eventType)
    {
        return $this->setData(self::EVENT_TYPE, $eventType);
    }

    public function getStoreCode()
    {
        return (string)$this->getData(self::STORE_CODE);
    }

    public function setStoreCode($storeCode)
    {
        return $this->setData(self::STORE_CODE, $storeCode);
    }

    public function getOrderReference()
    {
        return (string)$this->getData(self::ORDER_REFERENCE);
    }

    public function setOrderReference($orderReference)
    {
        return $this->setData(self::ORDER_REFERENCE, $orderReference);
    }

    public function getOccurredAt()
    {
        return (string)$this->getData(self::OCCURRED_AT);
    }

    public function setOccurredAt($occurredAt)
    {
        return $this->setData(self::OCCURRED_AT, $occurredAt);
    }

    public function getNotification()
    {
        return $this->getData(self::NOTIFICATION);
    }

    public function setNotification($notification)
    {
        return $this->setData(self::NOTIFICATION, $notification);
    }

    public function getPayload()
    {
        return (string)$this->getData(self::PAYLOAD);
    }

    public function setPayload($payload)
    {
        return $this->setData(self::PAYLOAD, $payload);
    }
}
