<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Terms;

/** How the end of month applies to net terms ("45 jours fin de mois" and "fin de mois 45 jours"). */
enum EndOfMonthMode: string
{
    /** Due date = start + N days. */
    case None = 'none';

    /** Start + N days, then the end of that month ("45 days end of month"). */
    case DaysThenEndOfMonth = 'days_then_end_of_month';

    /** End of the month of the start, then + N days ("end of month + 45 days"). */
    case EndOfMonthThenDays = 'end_of_month_then_days';

    public function isEndOfMonth(): bool
    {
        return self::None !== $this;
    }
}
