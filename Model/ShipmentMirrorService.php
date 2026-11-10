<?php
declare(strict_types=1);

namespace Backstage\Bridge\Model;

use Backstage\Bridge\Model\Exception\BridgeException;
use Magento\InventoryApi\Api\GetSourcesAssignedToStockOrderedByPriorityInterface;
use Magento\InventorySalesApi\Model\StockByWebsiteIdResolverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\Data\ShipmentCreationArgumentsExtensionInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentCreationArgumentsInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentItemCreationInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentTrackCreationInterfaceFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Shipment mirall amb ShipOrder natiu: MSI dedueix la font indicada i compensa la reserva.
 * Mai inventa quantitat ni expedeix des de fonts no físiques.
 */
class ShipmentMirrorService
{
    /** Fonts que mai poden originar un shipment. */
    private const NON_PHYSICAL_SOURCES = ['proveedor', 'default'];

    public function __construct(
        private readonly ShipOrderInterface $shipOrder,
        private readonly ShipmentItemCreationInterfaceFactory $itemFactory,
        private readonly ShipmentTrackCreationInterfaceFactory $trackFactory,
        private readonly ShipmentCreationArgumentsInterfaceFactory $argumentsFactory,
        private readonly ShipmentCreationArgumentsExtensionInterfaceFactory $argumentsExtensionFactory,
        private readonly StockByWebsiteIdResolverInterface $stockByWebsite,
        private readonly GetSourcesAssignedToStockOrderedByPriorityInterface $sourcesByStock,
        private readonly StoreManagerInterface $storeManager,
        private readonly OrderRepositoryInterface $orderRepository
    ) {
    }

    /**
     * Valida sense modificar res.
     *
     * @param array $items [['order_item_id'=>int,'sku'=>string,'qty'=>float], ...]
     * @throws BridgeException 422
     */
    public function validate(OrderInterface $order, string $sourceCode, array $items, bool $isPickup): void
    {
        $errors = [];

        if (in_array(strtolower($sourceCode), self::NON_PHYSICAL_SOURCES, true)) {
            $errors[] = sprintf('Source "%s" cannot originate a shipment', $sourceCode);
        } elseif (!$this->isSourceInOrderStock($order, $sourceCode)) {
            throw BridgeException::unprocessable('source_not_in_stock', sprintf('Source "%s" is not assigned to the stock of this order', $sourceCode));
        }

        if ($isPickup) {
            $pickup = (string)($order->getExtensionAttributes()?->getPickupLocationCode() ?? '');
            if ($pickup !== '' && $pickup !== $sourceCode) {
                $errors[] = sprintf('Pickup order must be collected at "%s", not "%s"', $pickup, $sourceCode);
            }
        }

        $orderItems = $this->indexItems($order);
        $seen = [];
        foreach ($items as $item) {
            $id = (int)$item['order_item_id'];
            $line = $orderItems[$id] ?? null;
            if ($line === null) {
                $errors[] = sprintf('order_item_id %d does not belong to the order', $id);
                continue;
            }
            if (isset($seen[$id])) {
                $errors[] = sprintf('order_item_id %d is repeated', $id);
            }
            $seen[$id] = true;
            if ((string)$line->getSku() !== (string)$item['sku']) {
                $errors[] = sprintf('order_item_id %d has sku "%s", not "%s"', $id, $line->getSku(), $item['sku']);
            }
            if ($line->getIsVirtual()) {
                $errors[] = sprintf('order_item_id %d is virtual and cannot be shipped', $id);
            }
            $pending = $this->qtyToShip($line);
            if ((float)$item['qty'] > $pending + 0.0001) {
                $errors[] = sprintf('order_item_id %d: qty %s exceeds pending qty %s', $id, $item['qty'], $pending);
            }
        }

        if ($errors) {
            throw BridgeException::unprocessable('qty_exceeds_or_invalid_items', implode('; ', $errors), $errors);
        }
    }

    /**
     * @return int shipment id
     */
    public function ship(OrderInterface $order, string $sourceCode, array $items, ?string $carrier, ?string $tracking, bool $isPickup = false): int
    {
        if ($isPickup) {
            $this->allowNativePickupShipment((int)$order->getEntityId());
        }

        $creationItems = [];
        foreach ($items as $item) {
            $creationItem = $this->itemFactory->create();
            $creationItem->setOrderItemId((int)$item['order_item_id']);
            $creationItem->setQty((float)$item['qty']);
            $creationItems[] = $creationItem;
        }

        $tracks = [];
        if ($tracking) {
            $track = $this->trackFactory->create();
            $track->setTrackNumber($tracking);
            $track->setTitle($carrier ?: 'Carrier');
            $track->setCarrierCode('custom');
            $tracks[] = $track;
        }

        $arguments = $this->argumentsFactory->create();
        $extension = $arguments->getExtensionAttributes() ?: $this->argumentsExtensionFactory->create();
        $extension->setSourceCode($sourceCode);
        $arguments->setExtensionAttributes($extension);

        return (int)$this->shipOrder->execute(
            (int)$order->getEntityId(),
            $creationItems,
            false,   // notify: mai; els correus els envia NotificationService
            false,   // appendComment
            null,
            $tracks,
            [],
            $arguments
        );
    }

    /**
     * Magento_InventoryInStorePickupSales (AdaptShipmentPlugin) rebutja el shipment d'una comanda de recollida
     * si send_notification és null; el seu flux natiu el posa abans de crear-lo. Aquí el posem a 0:
     * el shipment el crea la recollida real i el correu el decideix el bridge, no el notificador natiu.
     * El plugin llegeix la comanda del registre d'OrderRepository, que és la mateixa instància.
     */
    private function allowNativePickupShipment(int $orderId): void
    {
        $extension = $this->orderRepository->get($orderId)->getExtensionAttributes();
        if ($extension !== null && $extension->getSendNotification() === null) {
            $extension->setSendNotification(0);
        }
    }

    public function qtyToShip(OrderItemInterface $line): float
    {
        return max(0.0, (float)$line->getQtyOrdered() - (float)$line->getQtyShipped()
            - (float)$line->getQtyRefunded() - (float)$line->getQtyCanceled());
    }

    private function isSourceInOrderStock(OrderInterface $order, string $sourceCode): bool
    {
        $websiteId = (int)$this->storeManager->getStore((int)$order->getStoreId())->getWebsiteId();
        $stockId = (int)$this->stockByWebsite->execute($websiteId)->getStockId();
        foreach ($this->sourcesByStock->execute($stockId) as $source) {
            if ($source->getSourceCode() === $sourceCode) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return OrderItemInterface[] item_id => item
     */
    private function indexItems(OrderInterface $order): array
    {
        $index = [];
        foreach ($order->getItems() as $item) {
            $index[(int)$item->getItemId()] = $item;
        }
        return $index;
    }
}
