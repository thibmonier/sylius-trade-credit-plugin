<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Repository;

use Doctrine\ORM\QueryBuilder;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;

class TradeCreditLedgerEntryRepository extends EntityRepository implements TradeCreditLedgerEntryRepositoryInterface
{
    public function createByAccountQueryBuilder(mixed $accountId): QueryBuilder
    {
        return $this->createQueryBuilder('o')
            ->addSelect('ord')
            ->leftJoin('o.order', 'ord')
            ->andWhere('o.account = :account')
            ->setParameter('account', $accountId)
        ;
    }
}
