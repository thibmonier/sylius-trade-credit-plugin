<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Credit;

/** Credit of an account, amounts in minor units: Sylius figures plus the external position. */
final readonly class CreditPosition
{
    public function __construct(
        public int $creditLimit,
        public int $committedAmount,
        public int $externalCommittedAmount = 0,
        public bool $external = false,
        public bool $externalLimit = false,
    ) {
    }

    /** Can be negative (account allowed to exceed its limit). */
    public function getAvailableAmount(): int
    {
        return $this->creditLimit - $this->committedAmount - $this->externalCommittedAmount;
    }
}
