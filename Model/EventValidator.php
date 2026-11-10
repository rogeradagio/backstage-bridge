<?php
declare(strict_types=1);

namespace Backstage\Bridge\Model;

use Backstage\Bridge\Api\Data\FulfillmentEventInterface;
use Backstage\Bridge\Api\Data\NotificationPolicyInterface;
use Backstage\Bridge\Model\Exception\BridgeException;
use Backstage\Bridge\Model\PayloadSchema\PayloadSchemaInterface;

/**
 * Valida el sobre i el payload sense tocar cap dada de negoci.
 */
class EventValidator
{
    private const EVENT_ID_PATTERN = '/^[A-Za-z0-9._:-]{1,64}$/';
    private const FUTURE_TOLERANCE_SECONDS = 300;

    /**
     * @param PayloadSchemaInterface[] $payloadSchemas event_type => esquema
     */
    public function __construct(
        private readonly Config $config,
        private readonly array $payloadSchemas = []
    ) {
    }

    /**
     * @return array Payload descodificat i validat
     * @throws BridgeException
     */
    public function validate(FulfillmentEventInterface $event): array
    {
        $errors = [];

        if (!preg_match(self::EVENT_ID_PATTERN, $event->getEventId())) {
            $errors[] = 'event_id must match ' . self::EVENT_ID_PATTERN;
        }
        if ($event->getStoreCode() === '' || strlen($event->getStoreCode()) > 32) {
            $errors[] = 'store_code is required (max 32)';
        }
        if ($event->getOrderReference() === '' || strlen($event->getOrderReference()) > 50) {
            $errors[] = 'order_reference is required (max 50)';
        }
        $this->validateOccurredAt($event->getOccurredAt(), $errors);
        $this->validateNotification($event->getNotification(), $errors);

        if ($errors) {
            throw BridgeException::badRequest('invalid_envelope', implode('; ', $errors), $errors);
        }

        $type = $event->getEventType();
        if (!isset($this->payloadSchemas[$type])) {
            throw BridgeException::badRequest('unsupported_event_type', sprintf('Event type "%s" is not supported', $type));
        }

        $payload = $this->decodePayload($event->getPayload());
        $payloadErrors = $this->payloadSchemas[$type]->validate($payload);
        if ($payloadErrors) {
            throw BridgeException::badRequest('invalid_payload', implode('; ', $payloadErrors), $payloadErrors);
        }

        return $payload;
    }

    public function isReplay(array $payload): bool
    {
        return ($payload['replay'] ?? false) === true;
    }

    private function validateOccurredAt(string $occurredAt, array &$errors): void
    {
        $iso = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/';
        try {
            if (!preg_match($iso, $occurredAt)) {
                throw new \InvalidArgumentException();
            }
            $date = new \DateTimeImmutable($occurredAt);
        } catch (\Exception) {
            $errors[] = 'occurred_at must be ISO-8601 with timezone (e.g. 2026-11-07T10:20:00Z)';
            return;
        }
        $now = time();
        if ($date->getTimestamp() > $now + self::FUTURE_TOLERANCE_SECONDS) {
            $errors[] = 'occurred_at is in the future';
        }
    }

    private function validateNotification(?NotificationPolicyInterface $notification, array &$errors): void
    {
        if ($notification === null) {
            $errors[] = 'notification.mode is required (send|suppress)';
            return;
        }
        if (!in_array($notification->getMode(), [NotificationPolicyInterface::MODE_SEND, NotificationPolicyInterface::MODE_SUPPRESS], true)) {
            $errors[] = 'notification.mode must be send or suppress';
        }
        if ($notification->getTemplate() !== null && !in_array($notification->getTemplate(), NotificationService::CODES, true)) {
            $errors[] = 'notification.template must be one of: ' . implode(', ', NotificationService::CODES);
        }
        if ($notification->getComment() !== null && strlen($notification->getComment()) > 1000) {
            $errors[] = 'notification.comment max 1000';
        }
    }

    private function decodePayload(string $raw): array
    {
        if (strlen($raw) > $this->config->getMaxPayloadBytes()) {
            throw BridgeException::badRequest('invalid_payload', 'payload exceeds the maximum size');
        }
        try {
            $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw BridgeException::badRequest('invalid_payload', 'payload must be a JSON object string: ' . $e->getMessage());
        }
        if (!is_array($payload) || array_is_list($payload) && $payload !== []) {
            throw BridgeException::badRequest('invalid_payload', 'payload must be a JSON object');
        }
        return $payload;
    }

    /**
     * Antiguitat: un esdeveniment molt antic només s'accepta com a replay amb suppress.
     */
    public function assertAge(FulfillmentEventInterface $event, array $payload): void
    {
        $date = new \DateTimeImmutable($event->getOccurredAt());
        $maxAge = $this->config->getMaxEventAgeDays() * 86400;
        if ($date->getTimestamp() < time() - $maxAge) {
            $suppress = $event->getNotification()?->getMode() === NotificationPolicyInterface::MODE_SUPPRESS;
            if (!$suppress || !$this->isReplay($payload)) {
                throw BridgeException::badRequest('invalid_envelope', 'occurred_at is too old; resend with notification.mode=suppress and payload.replay=true');
            }
        }
    }
}
