<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Repository;

use Doctrine\ORM\QueryBuilder;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Tmonier\SyliusTradeCreditPlugin\Ageing\AgeingBuckets;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccountInterface;
use Tmonier\SyliusTradeCreditPlugin\Reservation\TradeCreditReserver;

class OrderPaymentTermsRepository extends EntityRepository implements OrderPaymentTermsRepositoryInterface
{
    public function findReservedForOrder(OrderInterface $order): ?OrderPaymentTermsInterface
    {
        $terms = $this->createQueryBuilder('t')
            ->andWhere('t.order = :order')
            ->andWhere('t.state = :state')
            ->setParameter('order', $order)
            ->setParameter('state', OrderPaymentTermsInterface::STATE_RESERVED)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        return $terms instanceof OrderPaymentTermsInterface ? $terms : null;
    }

    public function findLatestForOrder(OrderInterface $order): ?OrderPaymentTermsInterface
    {
        $terms = $this->createQueryBuilder('t')
            ->andWhere('t.order = :order')
            ->andWhere('t.state IN (:states)')
            ->setParameter('order', $order)
            ->setParameter('states', [OrderPaymentTermsInterface::STATE_RESERVED, OrderPaymentTermsInterface::STATE_SETTLED])
            ->addOrderBy('t.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        return $terms instanceof OrderPaymentTermsInterface ? $terms : null;
    }

    public function findLastForOrder(OrderInterface $order): ?OrderPaymentTermsInterface
    {
        $terms = $this->createQueryBuilder('t')
            ->andWhere('t.order = :order')
            ->setParameter('order', $order)
            // UUID v7 ids: in creation order.
            ->addOrderBy('t.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        return $terms instanceof OrderPaymentTermsInterface ? $terms : null;
    }

    public function findRemindable(\DateTimeInterface $today, int $daysBeforeDue): array
    {
        /** @var list<OrderPaymentTermsInterface> $reserved */
        $reserved = $this->createQueryBuilder('t')
            ->addSelect('o')
            ->innerJoin('t.order', 'o')
            ->andWhere('t.state = :state')
            ->andWhere('t.dueDate <= :until')
            ->setParameter('state', OrderPaymentTermsInterface::STATE_RESERVED)
            ->setParameter('until', $this->day($today)->modify(sprintf('+%d days', max(0, $daysBeforeDue))), 'date_immutable')
            ->addOrderBy('t.dueDate', 'ASC')
            ->getQuery()
            ->getResult()
        ;
        /** @var list<OrderPaymentTermsInterface> $leftUnpaid */
        $leftUnpaid = $this->createLeftUnpaidQueryBuilder($today)->addSelect('o')->addOrderBy('t.dueDate', 'ASC')->getQuery()->getResult();

        return [...$reserved, ...$leftUnpaid];
    }

    public function findOpenByAccount(TradeCreditAccountInterface $account): array
    {
        /** @var list<OrderPaymentTermsInterface> $terms */
        $terms = $this->createQueryBuilder('t')
            ->addSelect('o')
            ->innerJoin('t.order', 'o')
            ->andWhere('t.account = :account')
            ->andWhere('t.state = :state')
            ->setParameter('account', $account)
            ->setParameter('state', OrderPaymentTermsInterface::STATE_RESERVED)
            ->addOrderBy('t.dueDate', 'ASC')
            ->addOrderBy('t.createdAt', 'ASC')
            ->getQuery()
            ->getResult()
        ;

        return $terms;
    }

    public function countReservedForPaymentMethod(PaymentMethodInterface $paymentMethod): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.paymentMethod = :method')
            ->andWhere('t.state = :state')
            ->setParameter('method', $paymentMethod)
            ->setParameter('state', OrderPaymentTermsInterface::STATE_RESERVED)
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    public function hasOverdue(TradeCreditAccountInterface $account, \DateTimeInterface $today): bool
    {
        $reserved = $this->createQueryBuilder('t')
            ->select('t.id')
            ->andWhere('t.account = :account')
            ->andWhere('t.state = :state')
            ->andWhere('t.dueDate < :today')
            ->setParameter('account', $account)
            ->setParameter('state', OrderPaymentTermsInterface::STATE_RESERVED)
            ->setParameter('today', $this->day($today), 'date_immutable')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        return null !== $reserved || [] !== $this->createLeftUnpaidQueryBuilder($today)
            ->andWhere('t.account = :account')
            ->setParameter('account', $account)
            ->setMaxResults(1)
            ->getQuery()
            ->getResult()
        ;
    }

    public function findLeftUnpaidOverdueByAccount(TradeCreditAccountInterface $account, \DateTimeInterface $today): array
    {
        /** @var list<OrderPaymentTermsInterface> $terms */
        $terms = $this->createLeftUnpaidQueryBuilder($today)
            ->andWhere('t.account = :account')
            ->setParameter('account', $account)
            ->addOrderBy('t.dueDate', 'ASC')
            ->getQuery()
            ->getResult()
        ;

        return $terms;
    }

    public function leftUnpaidOverdueCondition(QueryBuilder $queryBuilder, string $termsAlias, \DateTimeInterface $today): string
    {
        // Unique aliases per call (the state and overdue filters may both add the condition).
        $prefix = sprintf('tclu%d_', count($queryBuilder->getParameters()));
        $subQuery = $this->createLeftUnpaidQueryBuilder($today, $prefix, $this->getEntityManager()->createQueryBuilder(), $queryBuilder)->select($prefix . 't.id');

        return sprintf('%s.id IN (%s)', $termsAlias, $subQuery->getDQL());
    }

    public function isLeftUnpaidOverdue(OrderPaymentTermsInterface $terms, \DateTimeInterface $today): bool
    {
        return [] !== $this->createLeftUnpaidQueryBuilder($today)
            ->andWhere('t.id = :id')
            ->setParameter('id', $terms->getId())
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * Terms released because the order left payment on account (switched to another method,
     * payment failed or cancelled), still the latest terms of the order (no reservation since),
     * order not cancelled and unpaid, due date passed: switching methods never clears an overdue
     * item.
     */
    /**
     * Aliases and parameter names are prefixed, so that the query can be a subquery of another
     * one (`$parametersTo`: the outer query, which holds the parameters).
     */
    private function createLeftUnpaidQueryBuilder(\DateTimeInterface $today, string $prefix = '', ?QueryBuilder $queryBuilder = null, ?QueryBuilder $parametersTo = null): QueryBuilder
    {
        [$t, $o, $later] = [$prefix . 't', $prefix . 'o', $prefix . 'later'];
        $queryBuilder = $queryBuilder?->from($this->getEntityName(), $t) ?? $this->createQueryBuilder($t);
        ($parametersTo ?? $queryBuilder)
            ->setParameter($prefix . 'released', OrderPaymentTermsInterface::STATE_RELEASED)
            ->setParameter($prefix . 'lostReasons', TradeCreditReserver::LOST_RESERVATION_REASONS)
            ->setParameter($prefix . 'orderNew', OrderInterface::STATE_NEW)
            ->setParameter($prefix . 'paidStates', [OrderPaymentStates::STATE_PAID, OrderPaymentStates::STATE_PARTIALLY_REFUNDED, OrderPaymentStates::STATE_REFUNDED])
            ->setParameter($prefix . 'today', $this->day($today), 'date_immutable')
        ;

        return $queryBuilder
            ->innerJoin(sprintf('%s.order', $t), $o)
            ->andWhere(sprintf('%s.state = :%sreleased', $t, $prefix))
            ->andWhere(sprintf('%s.closingReason IN (:%slostReasons)', $t, $prefix))
            ->andWhere(sprintf('%s.dueDate < :%stoday', $t, $prefix))
            ->andWhere(sprintf('%s.state = :%sorderNew', $o, $prefix))
            ->andWhere(sprintf('%s.paymentState NOT IN (:%spaidStates)', $o, $prefix))
            ->andWhere(sprintf('NOT EXISTS (SELECT %3$s.id FROM %4$s %3$s WHERE %3$s.order = %1$s.order AND %3$s.id > %1$s.id)', $t, $o, $later, $this->getEntityName()))
        ;
    }

    private function day(\DateTimeInterface $today): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($today)->setTime(0, 0);
    }

    public function sumAgeingByCurrency(\DateTimeInterface $today): array
    {
        $day = $this->day($today);
        $queryBuilder = $this->createQueryBuilder('t');
        $queryBuilder
            ->select('t.currencyCode AS currencyCode')
            ->addSelect('SUM(CASE WHEN t.dueDate >= :ageingToday THEN t.amount ELSE 0 END) AS current')
            ->addSelect('SUM(CASE WHEN t.dueDate < :ageingToday AND t.dueDate >= :ageing30 THEN t.amount ELSE 0 END) AS days_1_30')
            ->addSelect('SUM(CASE WHEN t.dueDate < :ageing30 AND t.dueDate >= :ageing60 THEN t.amount ELSE 0 END) AS days_31_60')
            ->addSelect('SUM(CASE WHEN t.dueDate < :ageing60 AND t.dueDate >= :ageing90 THEN t.amount ELSE 0 END) AS days_61_90')
            ->addSelect('SUM(CASE WHEN t.dueDate < :ageing90 THEN t.amount ELSE 0 END) AS days_over_90')
            ->andWhere(sprintf('t.state = :ageingReserved OR %s', $this->leftUnpaidOverdueCondition($queryBuilder, 't', $today)))
            ->setParameter('ageingReserved', OrderPaymentTermsInterface::STATE_RESERVED)
            ->setParameter('ageingToday', $day, 'date_immutable')
            ->setParameter('ageing30', $day->modify('-30 days'), 'date_immutable')
            ->setParameter('ageing60', $day->modify('-60 days'), 'date_immutable')
            ->setParameter('ageing90', $day->modify('-90 days'), 'date_immutable')
            ->groupBy('t.currencyCode')
            ->orderBy('t.currencyCode')
        ;

        /** @var list<array<string, mixed>> $rows */
        $rows = $queryBuilder->getQuery()->getArrayResult();
        $ageing = [];
        foreach ($rows as $row) {
            $amounts = [];
            foreach (AgeingBuckets::BUCKETS as $bucket) {
                $amounts[$bucket] = is_numeric($row[$bucket] ?? null) ? (int) $row[$bucket] : 0;
            }
            $ageing[is_string($row['currencyCode'] ?? null) ? $row['currencyCode'] : ''] = AgeingBuckets::fromArray($amounts);
        }

        return $ageing;
    }

    public function createOpenItemsQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('o')
            ->addSelect('ord', 'customer', 'account')
            ->innerJoin('o.order', 'ord')
            ->leftJoin('ord.customer', 'customer')
            ->innerJoin('o.account', 'account')
        ;
    }
}
