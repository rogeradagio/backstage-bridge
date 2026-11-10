<?php
declare(strict_types=1);

namespace Backstage\Bridge\Model;

use Backstage\Bridge\Api\Data\FulfillmentEventInterface;
use Backstage\Bridge\Api\Data\FulfillmentEventResultInterface;
use Backstage\Bridge\Api\Data\NotificationPolicyInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\InventoryApi\Api\SourceRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Correus de negoci al client. Regles (§9.3):
 * - Només s'envia si l'esdeveniment ha acabat bé i porta notification.mode = send.
 * - Un sol correu per event_id + plantilla (clau única), encara que Odoo reintenti.
 * - Plantilla per store view via configuració, mai per ID hardcoded.
 * - Un correu fallit queda registrat però no fa fallar l'esdeveniment.
 */
class NotificationService
{
    /** Codis lògics de plantilla permesos. */
    public const CODES = [
        'preparing_at_warehouse',
        'in_transit_to_store',
        'ready_for_pickup',
        'partially_fulfilled',
        'dispatched',
        'collected',
        'delivered',
        'cancelled',
    ];

    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SUPPRESSED = 'suppressed';

    private const TABLE = 'backstage_bridge_notification';
    private const XML_PATH = 'backstage_bridge/notifications/';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly TransportBuilder $transportBuilder,
        private readonly StateInterface $inlineTranslation,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SourceRepositoryInterface $sourceRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Plantilla per defecte de cada esdeveniment, segons el resultat. null = l'esdeveniment no notifica mai.
     */
    public function defaultCode(string $eventType, FulfillmentEventResultInterface $result): ?string
    {
        return match ($eventType) {
            'preparing_at_warehouse', 'in_transit_to_store', 'ready_for_pickup', 'collected', 'delivered', 'cancelled' => $eventType,
            'shipment_dispatched' => $result->getOrderStatus() === 'partially_fulfilled' ? 'partially_fulfilled' : 'dispatched',
            default => null,
        };
    }

    /**
     * @return string|null estat de la notificació (sent|failed|suppressed) o null si no n'hi havia
     */
    public function afterEvent(FulfillmentEventInterface $event, FulfillmentEventResultInterface $result, array $payload): ?string
    {
        $code = $event->getNotification()?->getTemplate() ?: $this->defaultCode($event->getEventType(), $result);
        if ($code === null || $result->getOrderId() === null) {
            return null;
        }

        try {
            /** @var Order $order */
            $order = $this->orderRepository->get((int)$result->getOrderId());
            $storeId = (int)$order->getStoreId();
            $row = $this->claim($event->getEventId(), $code, (int)$order->getEntityId(), $storeId);
            if ($row['status'] === self::STATUS_SENT) {
                return self::STATUS_SENT; // ja enviat per aquest esdeveniment
            }

            $mode = $event->getNotification()?->getMode();
            if ($mode !== NotificationPolicyInterface::MODE_SEND) {
                $this->update((int)$row['notification_id'], self::STATUS_SUPPRESSED, 'notification.mode=suppress');
                return self::STATUS_SUPPRESSED;
            }
            if (!$this->scopeConfig->isSetFlag(self::XML_PATH . 'enabled', ScopeInterface::SCOPE_STORE, $storeId)) {
                $this->update((int)$row['notification_id'], self::STATUS_SUPPRESSED, 'disabled by configuration');
                return self::STATUS_SUPPRESSED;
            }

            $this->send($order, $code, $this->buildVars($order, $payload));
            $this->update((int)$row['notification_id'], self::STATUS_SENT, null);

            $order->addCommentToStatusHistory(
                sprintf('[Odoo] Correu "%s" enviat al client (%s)', $code, $event->getEventId()),
                (string)$order->getStatus()
            )->setIsCustomerNotified(true)->setIsVisibleOnFront(false);
            $this->orderRepository->save($order);

            return self::STATUS_SENT;
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[%s] notification %s failed: %s', $event->getEventId(), $code, $e->getMessage()));
            if (isset($row['notification_id'])) {
                $this->update((int)$row['notification_id'], self::STATUS_FAILED, $e->getMessage());
            }
            return self::STATUS_FAILED;
        }
    }

    private function send(Order $order, string $code, array $vars): void
    {
        $storeId = (int)$order->getStoreId();
        $templateId = (string)$this->scopeConfig->getValue(self::XML_PATH . $code . '_template', ScopeInterface::SCOPE_STORE, $storeId);
        $identity = (string)$this->scopeConfig->getValue(self::XML_PATH . 'identity', ScopeInterface::SCOPE_STORE, $storeId) ?: 'sales';
        $copyTo = array_filter(array_map('trim', explode(',', (string)$this->scopeConfig->getValue(self::XML_PATH . 'copy_to', ScopeInterface::SCOPE_STORE, $storeId))));

        $this->inlineTranslation->suspend();
        try {
            $this->transportBuilder
                ->setTemplateIdentifier($templateId)
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                ->setTemplateVars($vars)
                ->setFromByScope($identity, $storeId)
                ->addTo((string)$order->getCustomerEmail(), (string)$order->getCustomerName());
            foreach ($copyTo as $bcc) {
                $this->transportBuilder->addBcc($bcc);
            }
            $this->transportBuilder->getTransport()->sendMessage();
        } finally {
            $this->inlineTranslation->resume();
        }
    }

    private function buildVars(Order $order, array $payload): array
    {
        $pickupCode = $payload['pickup_location_code'] ?? $payload['destination_source_code']
            ?? ($order->getShippingMethod() === 'instore_pickup' ? ($payload['source_code'] ?? null) : null)
            ?? $order->getExtensionAttributes()?->getPickupLocationCode();
        $pickupName = '';
        if ($pickupCode) {
            try {
                $source = $this->sourceRepository->get((string)$pickupCode);
                $pickupName = (string)($source->getExtensionAttributes()?->getFrontendName() ?: $source->getName());
            } catch (\Exception) {
                $pickupName = (string)$pickupCode;
            }
        }
        $isPickup = $order->getShippingMethod() === 'instore_pickup';

        return [
            'order' => $order,
            'order_id' => $order->getId(),
            'store' => $order->getStore(),
            'order_data' => [
                'customer_name' => $order->getCustomerName(),
                'increment_id' => $order->getIncrementId(),
                'is_not_virtual' => $order->getIsNotVirtual(),
            ],
            'tracking_number' => (string)($payload['tracking_number'] ?? ''),
            'carrier' => (string)($payload['carrier'] ?? ''),
            'pickup_location_name' => $pickupName,
            'store_pickup_shipping' => $isPickup,
            'standard_shipping' => !$isPickup,
        ];
    }

    private function claim(string $eventId, string $code, int $orderId, int $storeId): array
    {
        $connection = $this->resource->getConnection('sales');
        $table = $this->resource->getTableName(self::TABLE);
        $connection->insertOnDuplicate($table, [
            'event_id' => $eventId,
            'template_code' => $code,
            'order_id' => $orderId,
            'store_id' => $storeId,
            'status' => 'pending',
        ], ['store_id']);
        $connection->update($table, ['attempt_count' => new \Magento\Framework\DB\Sql\Expression('attempt_count + 1')], [
            'event_id = ?' => $eventId,
            'template_code = ?' => $code,
        ]);
        return $connection->fetchRow(
            $connection->select()->from($table)->where('event_id = ?', $eventId)->where('template_code = ?', $code)
        );
    }

    private function update(int $notificationId, string $status, ?string $error): void
    {
        $this->resource->getConnection('sales')->update($this->resource->getTableName(self::TABLE), [
            'status' => $status,
            'error' => $error,
            'sent_at' => $status === self::STATUS_SENT ? gmdate('Y-m-d H:i:s') : null,
        ], ['notification_id = ?' => $notificationId]);
    }
}
