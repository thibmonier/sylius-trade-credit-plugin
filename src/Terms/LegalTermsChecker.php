<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Terms;

/**
 * Warns when terms exceed the usual French maximums (article L441-10 of the Code de commerce:
 * 60 days from the invoice date, or 45 days end of month), or the lower limits configured per
 * channel (sector caps of L441-11). A warning, not legal advice: nothing is blocked.
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

    /**
     * The strictest limit exceeded among the general one and those of the given channels (all
     * configured channels when none is given, e.g. for an account usable in every channel).
     *
     * @param list<string>|null $channelCodes
     *
     * @return array{message: string, parameters: array<string, int|string>}|null
     */
    public function warning(PaymentTerms $terms, ?array $channelCodes = null): ?array
    {
        $candidates = ['' => ['net_days' => $this->maxNetDays, 'end_of_month_days' => $this->maxEndOfMonthDays]];
        foreach (null === $channelCodes ? array_keys($this->channelLimits) : $channelCodes as $code) {
            if (isset($this->channelLimits[$code])) {
                $candidates[$code] = $this->channelLimits[$code];
            }
        }

        $key = $terms->endOfMonthMode->isEndOfMonth() ? 'end_of_month_days' : 'net_days';
        $strictest = null;
        foreach ($candidates as $code => $limits) {
            if ($terms->netDays > $limits[$key] && (null === $strictest || $limits[$key] < $strictest[1][$key])) {
                $strictest = [(string) $code, $limits];
            }
        }
        if (null === $strictest) {
            return null;
        }

        [$code, $limits] = $strictest;

        return [
            'message' => sprintf('tmonier_trade_credit.ui.legal_warning.%s%s', $terms->endOfMonthMode->isEndOfMonth() ? 'end_of_month' : 'net', '' === $code ? '' : '_channel'),
            'parameters' => ['%net_days%' => $limits['net_days'], '%end_of_month_days%' => $limits['end_of_month_days'], '%channel%' => $code],
        ];
    }
}
