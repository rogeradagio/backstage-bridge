<?php
declare(strict_types=1);

namespace Backstage\Bridge\Api;

/**
 * Entrada única dels esdeveniments de fulfilment enviats per Odoo.
 *
 * @api
 */
interface FulfillmentEventManagementInterface
{
    /**
     * Valida i aplica (o simula, en dry_run) un esdeveniment idempotent.
     *
     * @param \Backstage\Bridge\Api\Data\FulfillmentEventInterface $event
     * @return \Backstage\Bridge\Api\Data\FulfillmentEventResultInterface
     * @throws \Magento\Framework\Webapi\Exception
     */
    public function submit(\Backstage\Bridge\Api\Data\FulfillmentEventInterface $event);
}
