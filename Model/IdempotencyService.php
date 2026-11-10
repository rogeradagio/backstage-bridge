<?php
declare(strict_types=1);

namespace Backstage\Bridge\Model;

use Backstage\Bridge\Api\Data\FulfillmentEventInterface;
use Backstage\Bridge\Model\Exception\BridgeException;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Registre d'esdeveniments: garanteix que cada event_id s'aplica com a màxim una vegada.
 *
 * Estats: processing → completed | failed (reintentable) | rejected (error de negoci, definitiu).
 */
class IdempotencyService
{
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REJECTED = 'rejected';

    private const TABLE = 'backstage_bridge_event';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Json $json,
        private readonly Config $config
    ) {
    }

    /**
     * Reclama l'esdeveniment per processar-lo.
     *
     * @return array|null Resultat anterior si ja estava completat (replay); null si s'ha de processar ara.
     * @throws BridgeException 409 si hi ha conflicte o està en curs; el mateix error si es va rebutjar.
     */
    public function claim(FulfillmentEventInterface $event, string $hash, ?int $orderId, bool $dryRun): ?array
    {
        $connection = $this->resource->getConnection('sales');
        $table = $this->resource->getTableName(self::TABLE);
        $now = gmdate('Y-m-d H:i:s');
        $lockUntil = gmdate('Y-m-d H:i:s', time() + $this->config->getProcessingLockSeconds());

        $connection->beginTransaction();
        try {
            $row = $connection->fetchRow(
                $connection->select()->from($table)->where('event_id = ?', $event->getEventId())->forUpdate(true)
            );

            if (!$row) {
                $connection->insert($table, [
                    'event_id' => $event->getEventId(),
                    'event_type' => $event->getEventType(),
                    'store_code' => $event->getStoreCode(),
                    'order_reference' => $event->getOrderReference(),
                    'order_id' => $orderId,
                    'payload_hash' => $hash,
                    'status' => self::STATUS_PROCESSING,
                    'attempt_count' => 1,
                    'locked_until' => $lockUntil,
                    'dry_run' => (int)$dryRun,
                    'occurred_at' => gmdate('Y-m-d H:i:s', (new \DateTimeImmutable($event->getOccurredAt()))->getTimestamp()),
                ]);
                $connection->commit();
                return null;
            }

            if ($row['payload_hash'] !== $hash) {
                throw BridgeException::conflict('event_id_conflict', 'The same event_id was already received with different content');
            }

            // Un esdeveniment validat en dry-run es pot tornar a enviar en live.
            if ((int)$row['dry_run'] === 1 && !$dryRun && $row['status'] === self::STATUS_COMPLETED) {
                $row['status'] = self::STATUS_FAILED;
            }

            switch ($row['status']) {
                case self::STATUS_COMPLETED:
                    $connection->commit();
                    return $row['result_json'] ? $this->json->unserialize($row['result_json']) : [];

                case self::STATUS_REJECTED:
                    $error = $row['error_json'] ? $this->json->unserialize($row['error_json']) : [];
                    $connection->commit();
                    throw new BridgeException(
                        (string)($error['code'] ?? 'rejected'),
                        (string)($error['detail'] ?? 'Event was rejected previously'),
                        (int)($error['http'] ?? 422),
                        (array)($error['details'] ?? [])
                    );

                case self::STATUS_PROCESSING:
                    if ($row['locked_until'] !== null && $row['locked_until'] > $now) {
                        throw BridgeException::conflict('event_in_progress', 'The event is being processed; retry later');
                    }
                    // Bloqueig caducat: es pot reintentar.
                    // no break
                default:
                    $connection->update($table, [
                        'status' => self::STATUS_PROCESSING,
                        'attempt_count' => (int)$row['attempt_count'] + 1,
                        'locked_until' => $lockUntil,
                        'dry_run' => (int)$dryRun,
                        'order_id' => $orderId,
                    ], ['entity_id = ?' => (int)$row['entity_id']]);
                    $connection->commit();
                    return null;
            }
        } catch (\Throwable $e) {
            if ($connection->getTransactionLevel() > 0) {
                $connection->rollBack();
            }
            throw $e;
        }
    }

    public function complete(string $eventId, array $result): void
    {
        $this->update($eventId, [
            'status' => self::STATUS_COMPLETED,
            'result_json' => $this->json->serialize($result),
            'error_json' => null,
            'locked_until' => null,
            'processed_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function fail(string $eventId, BridgeException|\Throwable $error): void
    {
        $isRejection = $error instanceof BridgeException && $error->isRejection();
        $payload = $error instanceof BridgeException
            ? $error->toArray()
            : ['code' => 'temporary_failure', 'detail' => $error->getMessage(), 'http' => 503];

        $this->update($eventId, [
            'status' => $isRejection ? self::STATUS_REJECTED : self::STATUS_FAILED,
            'error_json' => $this->json->serialize($payload),
            'locked_until' => null,
            'processed_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function update(string $eventId, array $data): void
    {
        $connection = $this->resource->getConnection('sales');
        $connection->update($this->resource->getTableName(self::TABLE), $data, ['event_id = ?' => $eventId]);
    }
}
