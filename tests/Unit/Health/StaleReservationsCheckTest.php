<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTradeCreditPlugin\Unit\Health;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Translation\TranslatableMessage;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCategory;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCheckRunner;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthStatus;
use Tmonier\SyliusTradeCreditPlugin\Health\StaleReservationsCheck;
use Tmonier\SyliusTradeCreditPlugin\Reservation\StaleReservationFinderInterface;

final class StaleReservationsCheckTest extends TestCase
{
    /**
     * One row per row of the table of cases of US-304 that the probe decides; the borders of the hour (60 min exactly is
     * not stale, 60 min and a second is) are proven against the database by Integration\Health\StaleReservationsQueryTest.
     *
     * @return iterable<string, array{int, HealthStatus}>
     */
    public static function counts(): iterable
    {
        yield 'no stale reservation' => [0, HealthStatus::Ok];
        yield 'one stale reservation' => [1, HealthStatus::Warning];
        yield 'several' => [12, HealthStatus::Warning];
    }

    #[DataProvider('counts')]
    public function testTheStatusFollowsTheNumberOfStaleReservations(int $count, HealthStatus $expected): void
    {
        self::assertSame($expected, $this->check($count)->run()->status);
    }

    public function testTheReservationsAreOnlyReadNeverReleased(): void
    {
        $reservations = $this->createMock(StaleReservationFinderInterface::class);
        $reservations->expects(self::once())->method('staleReservations')->with(false, 501)->willReturn([]);

        (new StaleReservationsCheck($reservations))->run();
    }

    public function testTheSummaryGivesTheCountAndNoOrderNumber(): void
    {
        $summary = $this->check(3)->run()->summary;

        self::assertInstanceOf(TranslatableMessage::class, $summary);
        self::assertSame('tmonier_trade_credit.health.stale_reservations.found', $summary->getMessage());
        self::assertSame(['%count%' => '3'], $summary->getParameters());
    }

    public function testAHealthySummaryCarriesNoParameter(): void
    {
        $summary = $this->check(0)->run()->summary;

        self::assertInstanceOf(TranslatableMessage::class, $summary);
        self::assertSame('tmonier_trade_credit.health.stale_reservations.ok', $summary->getMessage());
    }

    public function testFiveHundredReservationsAreCountedExactly(): void
    {
        $summary = $this->check(500)->run()->summary;

        self::assertInstanceOf(TranslatableMessage::class, $summary);
        self::assertSame('500', $summary->getParameters()['%count%']);
    }

    public function testBeyondFiveHundredTheSummarySaysMoreThanFiveHundred(): void
    {
        $summary = $this->check(501)->run()->summary;

        self::assertInstanceOf(TranslatableMessage::class, $summary);
        self::assertSame('500+', $summary->getParameters()['%count%']);
    }

    public function testStaleReservationsLinkToTheAccountsGrid(): void
    {
        $action = $this->check(1)->run()->action;

        self::assertNotNull($action);
        self::assertSame('tmonier_trade_credit_admin_account_index', $action->route);
    }

    public function testTheProbeBelongsToThePluginsCategoryWithAFixedIdentifier(): void
    {
        $check = $this->check(0);

        self::assertSame('trade_credit.stale_reservations', $check->identifier());
        self::assertSame(HealthCategory::Plugins, $check->category());
    }

    public function testAFailingQueryMakesTheProbeUnknownWithoutTheCause(): void
    {
        $reservations = $this->createStub(StaleReservationFinderInterface::class);
        $reservations->method('staleReservations')->willThrowException(new \RuntimeException('SQLSTATE[HY000] mysql://shop:secret@db/sylius'));

        $reports = (new HealthCheckRunner([new StaleReservationsCheck($reservations)], new NullLogger()))->run();

        self::assertSame(HealthStatus::Unknown, $reports[0]->result->status);
        self::assertInstanceOf(TranslatableMessage::class, $reports[0]->result->summary);
        self::assertSame('tmonier_platform_health.result.check_failed', $reports[0]->result->summary->getMessage());
    }

    private function check(int $count): StaleReservationsCheck
    {
        $reservations = $this->createStub(StaleReservationFinderInterface::class);
        $reservations->method('staleReservations')->willReturn(array_map(static fn (int $i): string => sprintf('#%06d', $i), $count > 0 ? range(1, $count) : []));

        return new StaleReservationsCheck($reservations);
    }
}
