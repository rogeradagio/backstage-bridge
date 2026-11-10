<?php
declare(strict_types=1);

namespace Backstage\Bridge\Api\Data;

/**
 * Resultat d'un esdeveniment processat, reprès (replayed) o validat (dry-run).
 *
 * @api
 */
interface FulfillmentEventResultInterface
{
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_REPLAYED = 'replayed';
    public const STATUS_VALIDATED = 'validated';

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
    public function getStatus();

    /**
     * @param string $status
     * @return $this
     */
    public function setStatus($status);

    /**
     * @return int|null
     */
    public function getOrderId();

    /**
     * @param int|null $orderId
     * @return $this
     */
    public function setOrderId($orderId);

    /**
     * @return string|null
     */
    public function getOrderState();

    /**
     * @param string|null $orderState
     * @return $this
     */
    public function setOrderState($orderState);

    /**
     * @return string|null
     */
    public function getOrderStatus();

    /**
     * @param string|null $orderStatus
     * @return $this
     */
    public function setOrderStatus($orderStatus);

    /**
     * @return int[]
     */
    public function getMagentoInvoiceIds();

    /**
     * @param int[] $ids
     * @return $this
     */
    public function setMagentoInvoiceIds(array $ids);

    /**
     * @return int[]
     */
    public function getMagentoShipmentIds();

    /**
     * @param int[] $ids
     * @return $this
     */
    public function setMagentoShipmentIds(array $ids);

    /**
     * @return int[]
     */
    public function getMagentoCreditmemoIds();

    /**
     * @param int[] $ids
     * @return $this
     */
    public function setMagentoCreditmemoIds(array $ids);

    /**
     * @return string[]
     */
    public function getPendingCompletion();

    /**
     * @param string[] $pending
     * @return $this
     */
    public function setPendingCompletion(array $pending);

    /**
     * @return bool
     */
    public function getDryRun();

    /**
     * @param bool $dryRun
     * @return $this
     */
    public function setDryRun($dryRun);
}
