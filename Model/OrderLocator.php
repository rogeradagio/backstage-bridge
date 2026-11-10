<?php
declare(strict_types=1);

namespace Backstage\Bridge\Model;

use Backstage\Bridge\Model\Exception\BridgeException;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Api\StoreRepositoryInterface;

/**
 * Localitza la comanda per store_code + increment_id (hi ha increment_id repetits entre botigues).
 */
class OrderLocator
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly StoreRepositoryInterface $storeRepository
    ) {
    }

    /**
     * @throws BridgeException 404
     */
    public function locate(string $storeCode, string $incrementId): OrderInterface
    {
        try {
            $store = $this->storeRepository->get($storeCode);
        } catch (NoSuchEntityException) {
            throw BridgeException::notFound('order_not_found', sprintf('Unknown store_code "%s"', $storeCode));
        }

        $criteria = $this->searchCriteriaBuilder
            ->addFilter(OrderInterface::INCREMENT_ID, $incrementId)
            ->addFilter(OrderInterface::STORE_ID, (int)$store->getId())
            ->setPageSize(2)
            ->create();
        $orders = $this->orderRepository->getList($criteria)->getItems();

        if (count($orders) !== 1) {
            throw BridgeException::notFound(
                'order_not_found',
                sprintf('Order "%s" not found in store "%s"', $incrementId, $storeCode)
            );
        }

        return reset($orders);
    }
}
