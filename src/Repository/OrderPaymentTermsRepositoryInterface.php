<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Repository;

use Doctrine\ORM\QueryBuilder;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
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

    public function countReservedForPaymentMethod(PaymentMethodInterface $paymentMethod): int;

    /** A reserved item past its due date, or an order left unpaid after leaving payment on account (see below). */
    public function hasOverdue(TradeCreditAccountInterface $account, \DateTimeInterface $today): bool;

    /**
     * Overdue items of orders that left payment on account (method switched, payment failed or
     * cancelled) and are still unpaid: their latest terms, released, past their due date.
     *
     * @return list<OrderPaymentTermsInterface>
     */
    public function findLeftUnpaidOverdueByAccount(TradeCreditAccountInterface $account, \DateTimeInterface $today): array;

    /** @return list<string> ids of the terms of `findLeftUnpaidOverdueByAccount()`, all accounts */
    public function findLeftUnpaidOverdueIds(\DateTimeInterface $today): array;

    public function isLeftUnpaidOverdue(OrderPaymentTermsInterface $terms, \DateTimeInterface $today): bool;

    /** Admin open items grid. */
    public function createOpenItemsQueryBuilder(): QueryBuilder;
}
