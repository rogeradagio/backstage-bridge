<?php
declare(strict_types=1);

namespace Backstage\Bridge\Model;

use Magento\Sales\Model\Order;

/**
 * Matriu fixa status ↔ state. És la font per al Data Patch i per a TransitionPolicy.
 */
final class StatusMatrix
{
    public const STATUSES = [
        'preparing_at_warehouse' => [
            'label' => 'Preparando en el almacén',
            'states' => [Order::STATE_NEW, Order::STATE_PROCESSING],
        ],
        'in_transit_to_store' => [
            'label' => 'En camino a la tienda',
            'states' => [Order::STATE_NEW, Order::STATE_PROCESSING],
        ],
        'ready_for_pickup' => [
            'label' => 'Listo para recoger',
            'states' => [Order::STATE_NEW, Order::STATE_PROCESSING],
        ],
        'partially_fulfilled' => [
            'label' => 'Enviado parcialmente',
            'states' => [Order::STATE_PROCESSING],
        ],
        'dispatched' => [
            'label' => 'Enviado',
            'states' => [Order::STATE_PROCESSING, Order::STATE_COMPLETE],
        ],
        'in_transit' => [
            'label' => 'En tránsito',
            'states' => [Order::STATE_PROCESSING, Order::STATE_COMPLETE],
        ],
        'collected' => [
            'label' => 'Recogido',
            'states' => [Order::STATE_COMPLETE],
        ],
        'delivered' => [
            'label' => 'Entregado',
            'states' => [Order::STATE_COMPLETE],
        ],
    ];

    /**
     * @return string[]
     */
    public static function statesFor(string $status): array
    {
        return self::STATUSES[$status]['states'] ?? [];
    }
}
