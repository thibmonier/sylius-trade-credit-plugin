<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Terms;

/**
 * Warns when terms exceed the usual French maximums (article L441-10 of the Code de commerce:
 * 60 days from the invoice date, or 45 days end of month; sector caps of L441-11 may be lower).
 * Limits are configurable per channel. A warning, not legal advice: nothing is blocked.
 */
final class LegalTermsChecker
{
    /**
     * @param array<string, array{net_days: int, end_of_month_days: int}> $channelLimits
     */
    public function __construct(
        private readonly int $maxNetDays = 60,
        private readonly int $maxEndOfMonthDays = 45,
        private readonly array $channelLimits = [],
    ) {
    }

    /** Translation key of the warning, or null when the terms are within the limits. */
    public function warning(PaymentTerms $terms, ?string $channelCode = null): ?string
    {
        $limits = null === $channelCode ? null : ($this->channelLimits[$channelCode] ?? null);
        if ($terms->endOfMonthMode->isEndOfMonth()) {
            return $terms->netDays > ($limits['end_of_month_days'] ?? $this->maxEndOfMonthDays) ? 'tmonier_trade_credit.ui.legal_warning.end_of_month' : null;
        }

        return $terms->netDays > ($limits['net_days'] ?? $this->maxNetDays) ? 'tmonier_trade_credit.ui.legal_warning.net' : null;
    }

    /** @return array{net_days: int, end_of_month_days: int} */
    public function limits(?string $channelCode = null): array
    {
        return (null === $channelCode ? null : ($this->channelLimits[$channelCode] ?? null)) ?? ['net_days' => $this->maxNetDays, 'end_of_month_days' => $this->maxEndOfMonthDays];
    }
}
