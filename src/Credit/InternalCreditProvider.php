<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Credit;

use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccountInterface;

/** Default: accounts are managed in Sylius only. */
final class InternalCreditProvider implements ExternalCreditProviderInterface
{
    public function getPosition(TradeCreditAccountInterface $account): ?ExternalCreditPosition
    {
        return null;
    }
}
