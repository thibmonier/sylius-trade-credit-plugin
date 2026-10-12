<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Repository;

use Doctrine\ORM\QueryBuilder;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
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
            ->setParameter('today', self::day($today), 'date_immutable')
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

    public function findLeftUnpaidOverdueIds(\DateTimeInterface $today): array
    {
        /** @var list<string> $ids */
        $ids = array_map(static fn (mixed $id): string => is_scalar($id) ? (string) $id : '', $this->createLeftUnpaidQueryBuilder($today)
            ->select('t.id')
            ->getQuery()
            ->getSingleColumnResult());

        return $ids;
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
    private function createLeftUnpaidQueryBuilder(\DateTimeInterface $today): QueryBuilder
    {
        return $this->createQueryBuilder('t')
            ->innerJoin('t.order', 'o')
            ->andWhere('t.state = :released')
            ->andWhere('t.closingReason IN (:lostReasons)')
            ->andWhere('t.dueDate < :today')
            ->andWhere('o.state = :orderNew')
            ->andWhere('o.paymentState NOT IN (:paidStates)')
            ->andWhere(sprintf('NOT EXISTS (SELECT later.id FROM %s later WHERE later.order = t.order AND later.id > t.id)', $this->getEntityName()))
            ->setParameter('released', OrderPaymentTermsInterface::STATE_RELEASED)
            ->setParameter('lostReasons', TradeCreditReserver::LOST_RESERVATION_REASONS)
            ->setParameter('orderNew', OrderInterface::STATE_NEW)
            ->setParameter('paidStates', [OrderPaymentStates::STATE_PAID, OrderPaymentStates::STATE_PARTIALLY_REFUNDED, OrderPaymentStates::STATE_REFUNDED])
            ->setParameter('today', self::day($today), 'date_immutable')
        ;
    }

    private static function day(\DateTimeInterface $today): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($today)->setTime(0, 0);
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
