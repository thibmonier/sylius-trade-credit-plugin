<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Repository;

use Doctrine\ORM\QueryBuilder;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Tmonier\SyliusTradeCreditPlugin\Ageing\AgeingBuckets;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccountInterface;

/** @extends RepositoryInterface<OrderPaymentTermsInterface> */
interface OrderPaymentTermsRepositoryInterface extends RepositoryInterface
{
    public function findReservedForOrder(OrderInterface $order): ?OrderPaymentTermsInterface;

    /** The reserved terms, else the last settled ones (invoice terms of a paid order). */
    public function findLatestForOrder(OrderInterface $order): ?OrderPaymentTermsInterface;

    /**
     * Items a payment reminder may be due for: open items due within `$daysBeforeDue` days or
     * past due, and left unpaid overdue items.
     *
     * @return list<OrderPaymentTermsInterface>
     */
    public function findRemindable(\DateTimeInterface $today, int $daysBeforeDue): array;

    /** The latest terms of the order, whatever their state. */
    public function findLastForOrder(OrderInterface $order): ?OrderPaymentTermsInterface;

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

    /**
     * DQL condition "`$termsAlias` is a left unpaid overdue item" (an `IN` subquery, parameters
     * added to `$queryBuilder`), for the admin open items grid filters.
     */
    public function leftUnpaidOverdueCondition(QueryBuilder $queryBuilder, string $termsAlias, \DateTimeInterface $today): string;

    public function isLeftUnpaidOverdue(OrderPaymentTermsInterface $terms, \DateTimeInterface $today): bool;

    /**
     * Ageing of the open items and left unpaid overdue items of every account, per currency.
     *
     * @return array<string, AgeingBuckets>
     */
    public function sumAgeingByCurrency(\DateTimeInterface $today): array;

    /** Admin open items grid. */
    public function createOpenItemsQueryBuilder(): QueryBuilder;
}
