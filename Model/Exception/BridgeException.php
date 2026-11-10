<?php
declare(strict_types=1);

namespace Backstage\Bridge\Model\Exception;

use Magento\Framework\Webapi\Exception as WebapiException;

/**
 * error de negoci o de validació amb codi estable per a Odoo.
 * es converteix a WebapiException (HTTP + parameters.code) a EventProcessor.
 */
class BridgeException extends \RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        private readonly string $detail,
        private readonly int $httpCode = WebapiException::HTTP_BAD_REQUEST,
        private readonly array $details = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($errorCode . ': ' . $detail, 0, $previous);
    }

    public static function badRequest(string $code, string $detail, array $details = []): self
    {
        return new self($code, $detail, WebapiException::HTTP_BAD_REQUEST, $details);
    }

    public static function notFound(string $code, string $detail): self
    {
        return new self($code, $detail, WebapiException::HTTP_NOT_FOUND);
    }

    public static function conflict(string $code, string $detail): self
    {
        return new self($code, $detail, 409);
    }

    public static function unprocessable(string $code, string $detail, array $details = []): self
    {
        return new self($code, $detail, 422, $details);
    }

    public static function unavailable(string $code, string $detail): self
    {
        return new self($code, $detail, 503);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getDetail(): string
    {
        return $this->detail;
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }

    public function getDetails(): array
    {
        return $this->details;
    }

    /** 4xx de negoci: no s'ha de reintentar amb el mateix event_id. */
    public function isRejection(): bool
    {
        return $this->httpCode >= 400 && $this->httpCode < 500;
    }

    public function toArray(): array
    {
        return ['code' => $this->errorCode, 'detail' => $this->detail, 'http' => $this->httpCode, 'details' => $this->details];
    }
}
