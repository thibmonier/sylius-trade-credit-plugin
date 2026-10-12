<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Repository;

use Doctrine\ORM\QueryBuilder;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditLedgerEntryInterface;

/** @extends RepositoryInterface<TradeCreditLedgerEntryInterface> */
interface TradeCreditLedgerEntryRepositoryInterface extends RepositoryInterface
{
    public function createByAccountQueryBuilder(mixed $accountId): QueryBuilder;
}
