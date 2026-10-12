<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Terms;

/** Net days + end-of-month mode, as applied to an order. */
final class PaymentTerms
{
    public function __construct(
        public readonly int $netDays,
        public readonly EndOfMonthMode $endOfMonthMode = EndOfMonthMode::None,
    ) {
        if ($netDays < 0) {
            throw new \InvalidArgumentException('Net days cannot be negative.');
        }
    }

    public function dueDate(\DateTimeInterface $start): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromInterface($start)->setTime(0, 0);

        return match ($this->endOfMonthMode) {
            EndOfMonthMode::None => $date->modify(sprintf('+%d days', $this->netDays)),
            EndOfMonthMode::DaysThenEndOfMonth => $date->modify(sprintf('+%d days', $this->netDays))->modify('last day of this month'),
            EndOfMonthMode::EndOfMonthThenDays => $date->modify('last day of this month')->modify(sprintf('+%d days', $this->netDays)),
        };
    }
}
