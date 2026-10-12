<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Repository;

use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccountInterface;

class TradeCreditAccountRepository extends EntityRepository implements TradeCreditAccountRepositoryInterface
{
    public function findOneByOwner(string $ownerType, string $ownerIdentifier): ?TradeCreditAccountInterface
    {
        $account = $this->findOneBy(['ownerType' => $ownerType, 'ownerIdentifier' => $ownerIdentifier]);

        return $account instanceof TradeCreditAccountInterface ? $account : null;
    }
}
