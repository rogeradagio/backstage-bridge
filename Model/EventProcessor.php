<?php
declare(strict_types=1);

namespace Backstage\Bridge\Model;

use Backstage\Bridge\Api\Data\FulfillmentEventInterface;
use Backstage\Bridge\Api\Data\FulfillmentEventResultInterface;
use Backstage\Bridge\Api\FulfillmentEventManagementInterface;
use Backstage\Bridge\Model\Data\FulfillmentEventResult;
use Backstage\Bridge\Model\Data\FulfillmentEventResultFactory;
use Backstage\Bridge\Model\Exception\BridgeException;
use Backstage\Bridge\Model\Handler\HandlerInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * orquestra un esdeveniment: validació → comanda → idempotència → regles → aplicació → resultat.
 */
class EventProcessor implements FulfillmentEventManagementInterface
{
    private const ORDER_LOCK_TIMEOUT = 10;

    /**
     * @param HandlerInterface[] $handlers event_type => handler
     */
    public function __construct(
        private readonly Config $config,
        private readonly EventValidator $validator,
        private readonly EventHasher $hasher,
        private readonly OrderLocator $orderLocator,
        private readonly IdempotencyService $idempotency,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly FulfillmentEventResultFactory $resultFactory,
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger,
        private readonly NotificationService $notifications,
        private readonly array $handlers = []
    ) {
    }

    public function submit(FulfillmentEventInterface $event)
    {
        try {
            return $this->process($event);
        } catch (BridgeException $e) {
            $this->log('warning', $event, $e->getErrorCode() . ': ' . $e->getDetail());
            throw $this->toWebapiException($e);
        } catch (\Throwable $e) {
            $this->log('error', $event, $e->getMessage(), ['exception' => $e]);
            throw $this->toWebapiException(BridgeException::unavailable('temporary_failure', 'Temporary failure; safe to retry'));
        }
    }

    private function process(FulfillmentEventInterface $event): FulfillmentEventResultInterface
    {
        $mode = $this->config->getMode();
        if ($mode === Config::MODE_DISABLED) {
            throw BridgeException::unavailable('bridge_disabled', 'Backstage Bridge is disabled');
        }
        $dryRun = $mode === Config::MODE_DRY_RUN;

        $payload = $this->validator->validate($event);
        $this->validator->assertAge($event, $payload);
        $handler = $this->handlers[$event->getEventType()] ?? null;
        if (!$handler instanceof HandlerInterface) {
            throw BridgeException::badRequest('unsupported_event_type', sprintf('Event type "%s" has no handler', $event->getEventType()));
        }

        $hash = $this->hasher->hash($event, $payload);
        $order = $this->orderLocator->locate($event->getStoreCode(), $event->getOrderReference());
        $orderId = (int)$order->getEntityId();

        $previous = $this->idempotency->claim($event, $hash, $orderId, $dryRun);
        if ($previous !== null) {
            $this->log('info', $event, 'replayed');
            return $this->buildResult($previous)->setStatus(FulfillmentEventResultInterface::STATUS_REPLAYED);
        }

        try {
            $result = $this->withOrderLock($orderId, function () use ($handler, $orderId, $payload, $event, $dryRun) {
                // Rellegir dins el lock: un altre esdeveniment pot haver canviat la comanda.
                $order = $this->orderRepository->get($orderId);
                $handler->check($order, $payload, $event);

                $result = $this->newResult($event, $orderId, $dryRun);
                if (!$dryRun) {
                    $handler->apply($order, $payload, $event, $result);
                    $order = $this->orderRepository->get($orderId);
                }
                $result->setOrderState((string)$order->getState())
                    ->setOrderStatus((string)$order->getStatus())
                    ->setStatus($dryRun ? FulfillmentEventResultInterface::STATUS_VALIDATED : FulfillmentEventResultInterface::STATUS_PROCESSED);
                return $result;
            });
        } catch (\Throwable $e) {
            $this->idempotency->fail($event->getEventId(), $e);
            throw $e;
        }

        $this->idempotency->complete($event->getEventId(), $result->toResultArray());
        $this->log('info', $event, $result->getStatus(), ['order_status' => $result->getOrderStatus()]);

        // Correu només després de completar l'acció; mai en dry-run ni en replay. Mai fa fallar l'esdeveniment.
        if (!$dryRun) {
            $notification = $this->notifications->afterEvent($event, $result, $payload);
            if ($notification !== null) {
                $this->log('info', $event, 'notification ' . $notification);
            }
        }
        return $result;
    }

    private function withOrderLock(int $orderId, callable $callback): FulfillmentEventResult
    {
        $connection = $this->resource->getConnection('sales');
        $lockName = 'backstage_bridge_order_' . $orderId;
        if ((int)$connection->fetchOne('SELECT GET_LOCK(?, ?)', [$lockName, self::ORDER_LOCK_TIMEOUT]) !== 1) {
            throw BridgeException::unavailable('temporary_failure', 'Order is locked by another event; retry later');
        }
        try {
            return $callback();
        } finally {
            $connection->fetchOne('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }

    private function newResult(FulfillmentEventInterface $event, int $orderId, bool $dryRun): FulfillmentEventResult
    {
        /** @var FulfillmentEventResult $result */
        $result = $this->resultFactory->create();
        return $result->setEventId($event->getEventId())
            ->setOrderId($orderId)
            ->setMagentoInvoiceIds([])
            ->setMagentoShipmentIds([])
            ->setMagentoCreditmemoIds([])
            ->setPendingCompletion([])
            ->setDryRun($dryRun);
    }

    private function buildResult(array $data): FulfillmentEventResult
    {
        /** @var FulfillmentEventResult $result */
        $result = $this->resultFactory->create();
        return $result->setEventId((string)($data['event_id'] ?? ''))
            ->setOrderId($data['order_id'] ?? null)
            ->setOrderState($data['order_state'] ?? null)
            ->setOrderStatus($data['order_status'] ?? null)
            ->setMagentoInvoiceIds((array)($data['magento_invoice_ids'] ?? []))
            ->setMagentoShipmentIds((array)($data['magento_shipment_ids'] ?? []))
            ->setMagentoCreditmemoIds((array)($data['magento_creditmemo_ids'] ?? []))
            ->setPendingCompletion((array)($data['pending_completion'] ?? []))
            ->setDryRun((bool)($data['dry_run'] ?? false));
    }

    private function toWebapiException(BridgeException $e): WebapiException
    {
        return new WebapiException(
            __('[%code] %detail', ['code' => $e->getErrorCode(), 'detail' => $e->getDetail()]),
            0,
            $e->getHttpCode()
        );
    }

    /** Logs amb referències, sense dades personals. */
    private function log(string $level, FulfillmentEventInterface $event, string $message, array $context = []): void
    {
        $this->logger->log($level, sprintf(
            '[%s] %s %s/%s: %s',
            $event->getEventId(),
            $event->getEventType(),
            $event->getStoreCode(),
            $event->getOrderReference(),
            $message
        ), $context);
    }
}
