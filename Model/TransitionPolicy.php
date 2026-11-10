<?php
declare(strict_types=1);

namespace Backstage\Bridge\Model;

use Backstage\Bridge\Model\Exception\BridgeException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;

/**
 * El bridge només tria el status dins el state que Magento ja ha calculat. Mai crida setState().
 */
class TransitionPolicy
{
    /** States on no s'accepta cap canvi logístic. */
    private const FINAL_STATES = [Order::STATE_CANCELED, Order::STATE_CLOSED];

    /** Status logístics que no es poden tornar enrere amb un esdeveniment previ. */
    private const PROGRESS = [
        'preparing_at_warehouse' => 10,
        'in_transit_to_store' => 20,
        'ready_for_pickup' => 30,
        'partially_fulfilled' => 40,
        'dispatched' => 50,
        'in_transit' => 60,
        'collected' => 90,
        'delivered' => 90,
    ];

    /**
     * @throws BridgeException 422
     */
    public function assertStatusAllowed(OrderInterface $order, string $requestedStatus): void
    {
        $state = (string)$order->getState();

        if (in_array($state, self::FINAL_STATES, true)) {
            throw BridgeException::unprocessable(
                'transition_not_allowed',
                sprintf('Order is %s; logistic events are not accepted', $state)
            );
        }

        $allowedStates = StatusMatrix::statesFor($requestedStatus);
        if (!in_array($state, $allowedStates, true)) {
            throw BridgeException::unprocessable(
                'status_not_allowed_for_state',
                sprintf('Status "%s" is not allowed for state "%s" (allowed: %s)', $requestedStatus, $state, implode(', ', $allowedStates)),
                ['state' => $state, 'requested_status' => $requestedStatus, 'allowed_states' => $allowedStates]
            );
        }

        $current = (string)$order->getStatus();
        if (isset(self::PROGRESS[$current], self::PROGRESS[$requestedStatus])
            && self::PROGRESS[$requestedStatus] < self::PROGRESS[$current]) {
            throw BridgeException::unprocessable(
                'transition_not_allowed',
                sprintf('Cannot go back from "%s" to "%s"', $current, $requestedStatus),
                ['current_status' => $current, 'requested_status' => $requestedStatus]
            );
        }
    }
}
