<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTradeCreditPlugin\Unit\Terms;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tmonier\SyliusTradeCreditPlugin\Terms\EndOfMonthMode;
use Tmonier\SyliusTradeCreditPlugin\Terms\LegalTermsChecker;
use Tmonier\SyliusTradeCreditPlugin\Terms\PaymentTerms;

final class PaymentTermsTest extends TestCase
{
    /** @return iterable<string, array{string, int, EndOfMonthMode, string}> */
    public static function dueDates(): iterable
    {
        yield 'net 30' => ['2026-10-12 15:30', 30, EndOfMonthMode::None, '2026-11-11'];
        yield '31/01 + 30 days' => ['2026-01-31', 30, EndOfMonthMode::None, '2026-03-02'];
        yield '31/01 + 30 days, leap year' => ['2028-01-31', 30, EndOfMonthMode::None, '2028-03-01'];
        yield 'net 0' => ['2026-10-12', 0, EndOfMonthMode::None, '2026-10-12'];
        yield '45 days end of month' => ['2026-01-20', 45, EndOfMonthMode::DaysThenEndOfMonth, '2026-03-31'];
        yield '45 days end of month, from 31/12' => ['2026-12-31', 45, EndOfMonthMode::DaysThenEndOfMonth, '2027-02-28'];
        yield '45 days end of month, leap February' => ['2028-01-10', 45, EndOfMonthMode::DaysThenEndOfMonth, '2028-02-29'];
        yield 'end of month + 45 days' => ['2026-01-20', 45, EndOfMonthMode::EndOfMonthThenDays, '2026-03-17'];
        yield 'end of month + 45 days, leap year' => ['2028-01-20', 45, EndOfMonthMode::EndOfMonthThenDays, '2028-03-16'];
        yield 'end of month + 30 days from 15/02' => ['2026-02-15', 30, EndOfMonthMode::EndOfMonthThenDays, '2026-03-30'];
    }

    #[DataProvider('dueDates')]
    public function testDueDates(string $start, int $netDays, EndOfMonthMode $mode, string $expected): void
    {
        self::assertSame($expected, (new PaymentTerms($netDays, $mode))->dueDate(new \DateTimeImmutable($start))->format('Y-m-d'));
    }

    public function testNegativeDaysAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PaymentTerms(-1);
    }

    public function testLegalWarnings(): void
    {
        $checker = new LegalTermsChecker(60, 45, ['B2B_BUILDING' => ['net_days' => 30, 'end_of_month_days' => 30]]);

        self::assertNull($checker->warning(new PaymentTerms(60)));
        self::assertSame('tmonier_trade_credit.ui.legal_warning.net', $checker->warning(new PaymentTerms(61)));
        self::assertNull($checker->warning(new PaymentTerms(45, EndOfMonthMode::DaysThenEndOfMonth)));
        self::assertSame('tmonier_trade_credit.ui.legal_warning.end_of_month', $checker->warning(new PaymentTerms(46, EndOfMonthMode::EndOfMonthThenDays)));
        self::assertSame('tmonier_trade_credit.ui.legal_warning.net', $checker->warning(new PaymentTerms(45), 'B2B_BUILDING'));
        self::assertSame(['net_days' => 30, 'end_of_month_days' => 30], $checker->limits('B2B_BUILDING'));
        self::assertSame(['net_days' => 60, 'end_of_month_days' => 45], $checker->limits('OTHER'));
    }
}
