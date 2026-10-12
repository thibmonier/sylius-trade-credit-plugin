<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Credit;

/** Credit of an account, amounts in minor units: Sylius figures plus the external position. */
final class CreditPosition
{
    public function __construct(
        public readonly int $creditLimit,
        public readonly int $committedAmount,
        public readonly int $externalCommittedAmount = 0,
        public readonly bool $external = false,
        public readonly bool $externalLimit = false,
    ) {
    }

    /** Can be negative (account allowed to exceed its limit). */
    public function getAvailableAmount(): int
    {
        return $this->creditLimit - $this->committedAmount - $this->externalCommittedAmount;
    }
}
