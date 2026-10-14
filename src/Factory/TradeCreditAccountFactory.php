<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Factory;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tmonier\SyliusOrderHoldPlugin\AccountOwner\AccountOwnerResolverInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccountInterface;

/**
 * @implements FactoryInterface<TradeCreditAccountInterface>
 */
final readonly class TradeCreditAccountFactory implements FactoryInterface
{
    /**
     * @param FactoryInterface<TradeCreditAccountInterface> $decorated
     * @param RepositoryInterface<CustomerInterface> $customerRepository
     * @param RepositoryInterface<ChannelInterface> $channelRepository
     */
    public function __construct(
        private FactoryInterface $decorated,
        private AccountOwnerResolverInterface $accountOwnerResolver,
        private RepositoryInterface $customerRepository,
        private RepositoryInterface $channelRepository,
    ) {
    }

    public function createNew(): TradeCreditAccountInterface
    {
        $account = $this->decorated->createNew();
        $channel = $this->channelRepository->findOneBy(['enabled' => true]);
        if ($channel instanceof ChannelInterface) {
            $account->setCurrencyCode($channel->getBaseCurrency()?->getCode());
        }

        return $account;
    }

    /** Account of the owner of a customer (admin customer page). */
    public function createForCustomer(mixed $customerId): TradeCreditAccountInterface
    {
        $customer = $this->customerRepository->find($customerId);
        $owner = $customer instanceof CustomerInterface ? $this->accountOwnerResolver->resolveForCustomer($customer) : null;
        if (!$owner instanceof \Tmonier\SyliusOrderHoldPlugin\AccountOwner\AccountOwner) {
            throw new NotFoundHttpException('Customer not found.');
        }

        $account = $this->createNew();
        $account->setOwner($owner->getType(), $owner->getIdentifier());

        return $account;
    }
}
