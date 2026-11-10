<?php
declare(strict_types=1);

namespace Backstage\Bridge\Setup\Patch\Data;

use Backstage\Bridge\Model\StatusMatrix;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * crea els status logístics i els associa als states segons la matriu fixa (§7.1 de l'especificació).
 * idempotent: reutilitza status existents i només afegeix les associacions que falten. Mai canvia is_default.
 */
class InstallFulfillmentStatuses implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $statusTable = $this->moduleDataSetup->getTable('sales_order_status');
        $stateTable = $this->moduleDataSetup->getTable('sales_order_status_state');

        $connection->startSetup();
        foreach (StatusMatrix::STATUSES as $status => $definition) {
            $exists = (bool)$connection->fetchOne(
                $connection->select()->from($statusTable, ['status'])->where('status = ?', $status)
            );
            if (!$exists) {
                $connection->insert($statusTable, ['status' => $status, 'label' => $definition['label']]);
            }
            foreach ($definition['states'] as $state) {
                $assigned = (bool)$connection->fetchOne(
                    $connection->select()->from($stateTable, ['status'])
                        ->where('status = ?', $status)
                        ->where('state = ?', $state)
                );
                if (!$assigned) {
                    $connection->insert($stateTable, [
                        'status' => $status,
                        'state' => $state,
                        'is_default' => 0,
                        'visible_on_front' => 1,
                    ]);
                }
            }
        }
        $connection->endSetup();

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
