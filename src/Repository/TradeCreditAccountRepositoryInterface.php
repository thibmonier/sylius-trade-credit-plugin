<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Repository;

use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccountInterface;

/** @extends RepositoryInterface<TradeCreditAccountInterface> */
interface TradeCreditAccountRepositoryInterface extends RepositoryInterface
{
    public function findOneByOwner(string $ownerType, string $ownerIdentifier): ?TradeCreditAccountInterface;
}
