<?php
declare(strict_types=1);

namespace Backstage\Bridge\Model\Handler;

use Backstage\Bridge\Api\Data\FulfillmentEventInterface;
use Backstage\Bridge\Model\Data\FulfillmentEventResult;
use Backstage\Bridge\Model\Exception\BridgeException;
use Magento\Sales\Api\Data\OrderInterface;

interface HandlerInterface
{
    /**
     * comprova les regles de negoci sense modificar res (s'executa també en dry-run).
     *
     * @throws BridgeException
     */
    public function check(OrderInterface $order, array $payload, FulfillmentEventInterface $event): void;

    /**
     * aplica l'esdeveniment i omple el resultat. Crea com a màxim un tipus de document.
     *
     * @throws BridgeException
     */
    public function apply(OrderInterface $order, array $payload, FulfillmentEventInterface $event, FulfillmentEventResult $result): void;
}
