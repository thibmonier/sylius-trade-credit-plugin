<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Account;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusOrderHoldPlugin\AccountOwner\AccountOwnerResolverInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccountInterface;
use Tmonier\SyliusTradeCreditPlugin\Repository\TradeCreditAccountRepositoryInterface;

/** Account of the owner of an order or customer (order-hold owner resolver: the customer by default). */
final class TradeCreditAccountProvider
{
    public function __construct(
        private readonly AccountOwnerResolverInterface $accountOwnerResolver,
        private readonly TradeCreditAccountRepositoryInterface $accountRepository,
    ) {
    }

    public function forOrder(OrderInterface $order): ?TradeCreditAccountInterface
    {
        $owner = $this->accountOwnerResolver->resolveForOrder($order);

        return null === $owner ? null : $this->accountRepository->findOneByOwner($owner->getType(), $owner->getIdentifier());
    }

    public function forCustomer(CustomerInterface $customer): ?TradeCreditAccountInterface
    {
        $owner = $this->accountOwnerResolver->resolveForCustomer($customer);

        return null === $owner ? null : $this->accountRepository->findOneByOwner($owner->getType(), $owner->getIdentifier());
    }
}
