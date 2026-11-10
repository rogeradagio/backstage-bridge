<?php
declare(strict_types=1);

namespace Backstage\Bridge\Model\Data;

use Backstage\Bridge\Api\Data\FulfillmentEventResultInterface;
use Magento\Framework\DataObject;

class FulfillmentEventResult extends DataObject implements FulfillmentEventResultInterface
{
    public function getEventId()
    {
        return (string)$this->getData('event_id');
    }

    public function setEventId($eventId)
    {
        return $this->setData('event_id', $eventId);
    }

    public function getStatus()
    {
        return (string)$this->getData('status');
    }

    public function setStatus($status)
    {
        return $this->setData('status', $status);
    }

    public function getOrderId()
    {
        $value = $this->getData('order_id');
        return $value === null ? null : (int)$value;
    }

    public function setOrderId($orderId)
    {
        return $this->setData('order_id', $orderId === null ? null : (int)$orderId);
    }

    public function getOrderState()
    {
        return $this->getData('order_state');
    }

    public function setOrderState($orderState)
    {
        return $this->setData('order_state', $orderState);
    }

    public function getOrderStatus()
    {
        return $this->getData('order_status');
    }

    public function setOrderStatus($orderStatus)
    {
        return $this->setData('order_status', $orderStatus);
    }

    public function getMagentoInvoiceIds()
    {
        return array_map('intval', (array)$this->getData('magento_invoice_ids'));
    }

    public function setMagentoInvoiceIds(array $ids)
    {
        return $this->setData('magento_invoice_ids', array_values(array_map('intval', $ids)));
    }

    public function getMagentoShipmentIds()
    {
        return array_map('intval', (array)$this->getData('magento_shipment_ids'));
    }

    public function setMagentoShipmentIds(array $ids)
    {
        return $this->setData('magento_shipment_ids', array_values(array_map('intval', $ids)));
    }

    public function getMagentoCreditmemoIds()
    {
        return array_map('intval', (array)$this->getData('magento_creditmemo_ids'));
    }

    public function setMagentoCreditmemoIds(array $ids)
    {
        return $this->setData('magento_creditmemo_ids', array_values(array_map('intval', $ids)));
    }

    public function getPendingCompletion()
    {
        return array_map('strval', (array)$this->getData('pending_completion'));
    }

    public function setPendingCompletion(array $pending)
    {
        return $this->setData('pending_completion', array_values(array_map('strval', $pending)));
    }

    public function getDryRun()
    {
        return (bool)$this->getData('dry_run');
    }

    public function setDryRun($dryRun)
    {
        return $this->setData('dry_run', (bool)$dryRun);
    }

    /**
     * Representació estable per desar a result_json i retornar en replays.
     */
    public function toResultArray(): array
    {
        return [
            'event_id' => $this->getEventId(),
            'status' => $this->getStatus(),
            'order_id' => $this->getOrderId(),
            'order_state' => $this->getOrderState(),
            'order_status' => $this->getOrderStatus(),
            'magento_invoice_ids' => $this->getMagentoInvoiceIds(),
            'magento_shipment_ids' => $this->getMagentoShipmentIds(),
            'magento_creditmemo_ids' => $this->getMagentoCreditmemoIds(),
            'pending_completion' => $this->getPendingCompletion(),
            'dry_run' => $this->getDryRun(),
        ];
    }
}
