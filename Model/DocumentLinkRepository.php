<?php
declare(strict_types=1);

namespace Backstage\Bridge\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * enllaç document fiscal d'Odoo ↔ document mirall de Magento.
 */
class DocumentLinkRepository
{
    public const TYPE_INVOICE = 'invoice';
    public const TYPE_CREDIT_NOTE = 'credit_note';
    public const MODE_CREATED = 'created';
    public const MODE_MATCHED = 'matched';
    public const MATCH_EXACT = 'exact';
    public const MATCH_TOLERANCE = 'within_tolerance';
    public const MATCH_AUDITED = 'mismatch_audited';

    private const TABLE = 'backstage_bridge_odoo_document_link';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Json $json
    ) {
    }

    public function get(string $odooType, string $odooId): ?array
    {
        $connection = $this->resource->getConnection('sales');
        $row = $connection->fetchRow(
            $connection->select()->from($this->resource->getTableName(self::TABLE))
                ->where('odoo_document_type = ?', $odooType)->where('odoo_document_id = ?', $odooId)
        );
        return $row ?: null;
    }

    /**
     * import d'Odoo ja enllaçat a cada document Magento (per a cobertura N:M).
     *
     * @return array<int, float> magento_entity_id => import cobert
     */
    public function getCoverage(int $orderId, string $magentoType): array
    {
        $connection = $this->resource->getConnection('sales');
        $rows = $connection->fetchPairs(
            $connection->select()->from($this->resource->getTableName(self::TABLE), ['magento_entity_id', 'SUM(amount)'])
                ->where('order_id = ?', $orderId)
                ->where('magento_entity_type = ?', $magentoType)
                ->group('magento_entity_id')
        );
        return array_map('floatval', $rows);
    }

    public function save(array $data): void
    {
        if (isset($data['amounts_json']) && is_array($data['amounts_json'])) {
            $data['amounts_json'] = $this->json->serialize($data['amounts_json']);
        }
        $this->resource->getConnection('sales')->insert($this->resource->getTableName(self::TABLE), $data);
    }

    public function decodeAmounts(array $row): array
    {
        return $row['amounts_json'] ? (array)$this->json->unserialize($row['amounts_json']) : [];
    }
}
