<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTradeCreditPlugin\Integration\Health;

use Psr\Log\NullLogger;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Tests\Tmonier\SyliusTradeCreditPlugin\Functional\TradeCreditTestTrait;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthStatus;
use Tmonier\SyliusTradeCreditPlugin\Health\StaleReservationsCheck;
use Tmonier\SyliusTradeCreditPlugin\Reservation\ReservationMaintenance;
use Tmonier\SyliusTradeCreditPlugin\Reservation\ReservationStore;

/**
 * The borders of "stale", against the real schema: a reservation of exactly one hour is not stale, one second older it is.
 */
final class StaleReservationsQueryTest extends KernelTestCase
{
    use TradeCreditTestTrait;

    private const NOW = '2026-11-27 12:00:00';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->beginTransaction();
        $this->connection()->executeStatement("UPDATE tmonier_trade_credit_order_terms SET state = 'released' WHERE state = 'reserved'");
    }

    protected function tearDown(): void
    {
        $this->rollBack();
        parent::tearDown();
    }

    public function testNothingIsStaleWithoutAReservation(): void
    {
        self::assertSame(HealthStatus::Ok, $this->check()->run()->status);
    }

    public function testAReservationOfExactlyOneHourIsNotStale(): void
    {
        $this->reservationOfAnUnplacedOrder('2026-11-27 11:00:00');

        self::assertSame(HealthStatus::Ok, $this->check()->run()->status);
    }

    public function testAReservationOfOneHourAndOneSecondIsStale(): void
    {
        $this->reservationOfAnUnplacedOrder('2026-11-27 10:59:59');

        self::assertSame(HealthStatus::Warning, $this->check()->run()->status);
    }

    public function testAReservationOfFiftyNineMinutesFiftyNineSecondsIsNotStale(): void
    {
        $this->reservationOfAnUnplacedOrder('2026-11-27 11:00:01');

        self::assertSame(HealthStatus::Ok, $this->check()->run()->status);
    }

    public function testAnOldReservationOfAPlacedOrderIsHealthy(): void
    {
        $order = $this->reservedOrder('2026-11-27 08:00:00');

        self::assertInstanceOf(OrderInterface::class, $order);
        self::assertSame(HealthStatus::Ok, $this->check()->run()->status);
    }

    public function testAnOldReservationOfACancelledOrderIsStale(): void
    {
        $order = $this->reservedOrder('2026-11-27 08:00:00');
        $this->connection()->executeStatement("UPDATE sylius_order SET state = 'cancelled' WHERE id = ?", [$order->getId()]);

        self::assertSame(HealthStatus::Warning, $this->check()->run()->status);
    }

    public function testTheProbeNeverReleasesTheReservation(): void
    {
        $order = $this->reservedOrder('2026-11-27 08:00:00');
        $this->connection()->executeStatement("UPDATE sylius_order SET state = 'cancelled' WHERE id = ?", [$order->getId()]);

        $this->check()->run();

        self::assertSame(['reserved'], $this->termsStates($order));
    }

    public function testTheNumberOfRowsReadIsBounded(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->reservationOfAnUnplacedOrder('2026-11-27 08:00:00');
        }

        self::assertCount(2, $this->maintenance()->staleReservations(false, 2));
        self::assertCount(3, $this->maintenance()->staleReservations(false));
    }

    private function reservationOfAnUnplacedOrder(string $createdAt): void
    {
        $order = $this->reservedOrder($createdAt);
        $this->connection()->executeStatement("UPDATE sylius_order SET checkout_state = 'payment_selected' WHERE id = ?", [$order->getId()]);
    }

    private function reservedOrder(string $createdAt): OrderInterface
    {
        $customer = $this->customer();
        $this->account($customer);
        $order = $this->order('completed', $this->onAccountMethod(), $customer);
        $this->connection()->executeStatement('UPDATE tmonier_trade_credit_order_terms SET created_at = ? WHERE order_id = ?', [$createdAt, $order->getId()]);

        return $order;
    }

    private function check(): StaleReservationsCheck
    {
        $this->entityManager()->clear();

        return new StaleReservationsCheck($this->maintenance());
    }

    private function maintenance(): ReservationMaintenance
    {
        $store = $this->service(ReservationStore::class);
        self::assertInstanceOf(ReservationStore::class, $store);

        return new ReservationMaintenance($store, $this->reserver(), $this->entityManager(), $this->policyProvider(), new MockClock(self::NOW), new NullLogger());
    }
}
