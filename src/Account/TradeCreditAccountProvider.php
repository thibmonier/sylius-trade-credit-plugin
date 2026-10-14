<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Account;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusOrderHoldPlugin\AccountOwner\AccountOwnerResolverInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccountInterface;
use Tmonier\SyliusTradeCreditPlugin\Repository\TradeCreditAccountRepositoryInterface;

/** Account of the owner of an order or customer (order-hold owner resolver: the customer by default). */
final readonly class TradeCreditAccountProvider
{
    public function __construct(
        private AccountOwnerResolverInterface $accountOwnerResolver,
        private TradeCreditAccountRepositoryInterface $accountRepository,
    ) {
    }

    public function forOrder(OrderInterface $order): ?TradeCreditAccountInterface
    {
        $owner = $this->accountOwnerResolver->resolveForOrder($order);

        return $owner instanceof \Tmonier\SyliusOrderHoldPlugin\AccountOwner\AccountOwner ? $this->accountRepository->findOneByOwner($owner->getType(), $owner->getIdentifier()) : null;
    }

    public function forCustomer(CustomerInterface $customer): ?TradeCreditAccountInterface
    {
        $owner = $this->accountOwnerResolver->resolveForCustomer($customer);

        return $owner instanceof \Tmonier\SyliusOrderHoldPlugin\AccountOwner\AccountOwner ? $this->accountRepository->findOneByOwner($owner->getType(), $owner->getIdentifier()) : null;
    }
}
