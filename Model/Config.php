<?php
declare(strict_types=1);

namespace Backstage\Bridge\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

class Config
{
    public const MODE_DISABLED = 'disabled';
    public const MODE_DRY_RUN = 'dry_run';
    public const MODE_LIVE = 'live';

    private const XML_PATH = 'backstage_bridge/general/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function getMode(): string
    {
        $mode = (string)$this->scopeConfig->getValue(self::XML_PATH . 'mode');
        return in_array($mode, [self::MODE_DRY_RUN, self::MODE_LIVE], true) ? $mode : self::MODE_DISABLED;
    }

    public function getMaxPayloadBytes(): int
    {
        return max(1024, (int)$this->scopeConfig->getValue(self::XML_PATH . 'max_payload_bytes'));
    }

    public function getMaxEventAgeDays(): int
    {
        return max(1, (int)$this->scopeConfig->getValue(self::XML_PATH . 'max_event_age_days'));
    }

    public function getProcessingLockSeconds(): int
    {
        return max(30, (int)$this->scopeConfig->getValue(self::XML_PATH . 'processing_lock_seconds'));
    }

    /** Abonament online = el bridge demana a la passarel·la que retorni diners. Desactivat per defecte. */
    public function isOnlineRefundAllowed(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH . 'allow_online_refund');
    }
}
