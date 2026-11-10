<?php
declare(strict_types=1);

namespace Backstage\Bridge\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Trams logístics: còpia lleugera dels albarans d'Odoo (la font de veritat és Odoo).
 */
class LegRepository
{
    public const TYPE_INTERNAL_TRANSFER = 'internal_transfer';
    public const TYPE_CUSTOMER_DELIVERY = 'customer_delivery';
    public const TYPE_STORE_PICKUP = 'store_pickup';
    public const TYPE_RETURN = 'return';

    private const LEG = 'backstage_bridge_leg';
    private const ITEM = 'backstage_bridge_leg_item';

    /**
     * Quantitat ja retornada per línia de comanda (trams de devolució).
     *
     * @return array<int, float> order_item_id => qty
     */
    public function getReturnedQtys(int $orderId): array
    {
        $connection = $this->resource->getConnection('sales');
        $rows = $connection->fetchPairs(
            $connection->select()
                ->from(['i' => $this->resource->getTableName(self::ITEM)], ['order_item_id', 'SUM(i.qty)'])
                ->join(['l' => $this->resource->getTableName(self::LEG)], 'l.leg_id = i.leg_id', [])
                ->where('l.order_id = ?', $orderId)
                ->where('l.type = ?', self::TYPE_RETURN)
                ->group('i.order_item_id')
        );
        return array_map('floatval', $rows);
    }

    /**
     * Tram de devolució rebut (sense shipment ni moviment d'estoc a Magento).
     */
    public function saveReturn(int $orderId, string $legRef, ?string $destination, array $items, string $eventId): int
    {
        $connection = $this->resource->getConnection('sales');
        $legTable = $this->resource->getTableName(self::LEG);
        $connection->insert($legTable, [
            'order_id' => $orderId,
            'leg_ref' => $legRef,
            'type' => self::TYPE_RETURN,
            'destination_source_code' => $destination,
            'status' => 'received',
            'created_event_id' => $eventId,
        ]);
        $legId = (int)$connection->lastInsertId($legTable);
        foreach ($items as $item) {
            $connection->insert($this->resource->getTableName(self::ITEM), [
                'leg_id' => $legId,
                'order_item_id' => (int)$item['order_item_id'],
                'sku' => (string)$item['sku'],
                'qty' => (float)$item['qty'],
            ]);
        }
        return $legId;
    }

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function getByRef(int $orderId, string $legRef): ?array
    {
        $connection = $this->resource->getConnection('sales');
        $row = $connection->fetchRow(
            $connection->select()->from($this->resource->getTableName(self::LEG))
                ->where('order_id = ?', $orderId)->where('leg_ref = ?', $legRef)
        );
        return $row ?: null;
    }

    /**
     * @return int[] shipment ids d'un tram
     */
    public function getShipmentIds(int $legId): array
    {
        $connection = $this->resource->getConnection('sales');
        return array_map('intval', $connection->fetchCol(
            $connection->select()->distinct()->from($this->resource->getTableName(self::ITEM), ['magento_shipment_id'])
                ->where('leg_id = ?', $legId)->where('magento_shipment_id IS NOT NULL')
        ));
    }

    /**
     * @return string[] tipus de trams de client amb shipment (store_pickup / customer_delivery)
     */
    public function getShippedCustomerLegTypes(int $orderId): array
    {
        $connection = $this->resource->getConnection('sales');
        return $connection->fetchCol(
            $connection->select()->distinct()->from($this->resource->getTableName(self::LEG), ['type'])
                ->where('order_id = ?', $orderId)
                ->where('type IN (?)', [self::TYPE_STORE_PICKUP, self::TYPE_CUSTOMER_DELIVERY])
                ->where('status = ?', 'shipped')
        );
    }

    /**
     * Desa el tram i les línies amb el shipment creat.
     *
     * @param array $items [['order_item_id'=>int,'sku'=>string,'qty'=>float], ...]
     */
    public function saveShipped(
        int $orderId,
        string $legRef,
        string $type,
        ?string $originSource,
        ?string $destinationSource,
        ?string $carrier,
        ?string $tracking,
        array $items,
        int $shipmentId,
        string $eventId
    ): int {
        $connection = $this->resource->getConnection('sales');
        $legTable = $this->resource->getTableName(self::LEG);
        $existing = $this->getByRef($orderId, $legRef);
        if ($existing) {
            $legId = (int)$existing['leg_id'];
            $connection->update($legTable, [
                'status' => 'shipped',
                'carrier' => $carrier,
                'tracking_number' => $tracking,
                'updated_event_id' => $eventId,
            ], ['leg_id = ?' => $legId]);
        } else {
            $connection->insert($legTable, [
                'order_id' => $orderId,
                'leg_ref' => $legRef,
                'type' => $type,
                'origin_source_code' => $originSource,
                'destination_source_code' => $destinationSource,
                'carrier' => $carrier,
                'tracking_number' => $tracking,
                'status' => 'shipped',
                'created_event_id' => $eventId,
            ]);
            $legId = (int)$connection->lastInsertId($legTable);
        }
        foreach ($items as $item) {
            $connection->insertOnDuplicate($this->resource->getTableName(self::ITEM), [
                'leg_id' => $legId,
                'order_item_id' => (int)$item['order_item_id'],
                'sku' => (string)$item['sku'],
                'qty' => (float)$item['qty'],
                'magento_shipment_id' => $shipmentId,
            ], ['qty', 'magento_shipment_id']);
        }
        return $legId;
    }

    /**
     * Tram informatiu sense shipment (p. ex. traspàs a botiga).
     */
    public function saveStatus(int $orderId, string $legRef, string $type, ?string $origin, ?string $destination, string $status, string $eventId): void
    {
        $connection = $this->resource->getConnection('sales');
        $legTable = $this->resource->getTableName(self::LEG);
        $existing = $this->getByRef($orderId, $legRef);
        if ($existing) {
            $connection->update($legTable, ['status' => $status, 'updated_event_id' => $eventId], ['leg_id = ?' => (int)$existing['leg_id']]);
            return;
        }
        $connection->insert($legTable, [
            'order_id' => $orderId,
            'leg_ref' => $legRef,
            'type' => $type,
            'origin_source_code' => $origin,
            'destination_source_code' => $destination,
            'status' => $status,
            'created_event_id' => $eventId,
        ]);
    }
}
