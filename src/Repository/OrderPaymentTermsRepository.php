<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Repository;

use Doctrine\ORM\QueryBuilder;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccountInterface;

/** @extends EntityRepository<OrderPaymentTermsInterface> */
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

    public function hasOverdue(TradeCreditAccountInterface $account, \DateTimeInterface $today): bool
    {
        return null !== $this->createQueryBuilder('t')
            ->select('t.id')
            ->andWhere('t.account = :account')
            ->andWhere('t.state = :state')
            ->andWhere('t.dueDate < :today')
            ->setParameter('account', $account)
            ->setParameter('state', OrderPaymentTermsInterface::STATE_RESERVED)
            ->setParameter('today', \DateTimeImmutable::createFromInterface($today)->setTime(0, 0), 'date_immutable')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }

    public function sumReservedByAccount(): array
    {
        /** @var list<array{accountId: int|string, total: int|string|null}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.account) AS accountId', 'SUM(t.amount) AS total')
            ->andWhere('t.state = :state')
            ->setParameter('state', OrderPaymentTermsInterface::STATE_RESERVED)
            ->groupBy('t.account')
            ->getQuery()
            ->getArrayResult()
        ;

        $sums = [];
        foreach ($rows as $row) {
            $sums[(int) $row['accountId']] = (int) $row['total'];
        }

        return $sums;
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
