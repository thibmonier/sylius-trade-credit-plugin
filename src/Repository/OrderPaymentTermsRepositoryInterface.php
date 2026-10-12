<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Repository;

use Doctrine\ORM\QueryBuilder;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccountInterface;

/** @extends RepositoryInterface<OrderPaymentTermsInterface> */
interface OrderPaymentTermsRepositoryInterface extends RepositoryInterface
{
    public function findReservedForOrder(OrderInterface $order): ?OrderPaymentTermsInterface;

    /** The reserved terms, else the last settled ones (invoice terms of a paid order). */
    public function findLatestForOrder(OrderInterface $order): ?OrderPaymentTermsInterface;

    /** @return list<OrderPaymentTermsInterface> reserved terms of the account, oldest due date first */
    public function findOpenByAccount(TradeCreditAccountInterface $account): array;

    public function hasOverdue(TradeCreditAccountInterface $account, \DateTimeInterface $today): bool;

    /**
     * Sum of the reserved amounts, per account id.
     *
     * @return array<int, int>
     */
    public function sumReservedByAccount(): array;

    /** Admin open items grid. */
    public function createOpenItemsQueryBuilder(): QueryBuilder;
}
