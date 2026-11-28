<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTradeCreditPlugin\Integration\Health;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tests\Tmonier\SyliusTradeCreditPlugin\Functional\TradeCreditTestTrait;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCheckReport;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCheckRunner;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthStatus;

/**
 * The probe as the health page runs it: through the runner of the health plugin, against the test application.
 */
final class HealthProbeTest extends KernelTestCase
{
    use TradeCreditTestTrait;

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

    public function testTheProbeIsRegisteredAndOkWhenNothingIsReserved(): void
    {
        self::assertSame(['trade_credit.stale_reservations' => HealthStatus::Ok], $this->statuses());
    }

    public function testAReservationLeftBehindForHoursMakesTheProbeWarn(): void
    {
        $customer = $this->customer();
        $this->account($customer);
        $order = $this->order('completed', $this->onAccountMethod(), $customer);
        $this->connection()->executeStatement("UPDATE sylius_order SET checkout_state = 'payment_selected' WHERE id = ?", [$order->getId()]);
        $this->connection()->executeStatement('UPDATE tmonier_trade_credit_order_terms SET created_at = ? WHERE order_id = ?', [(new \DateTimeImmutable('-3 hours', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'), $order->getId()]);

        self::assertSame(['trade_credit.stale_reservations' => HealthStatus::Warning], $this->statuses());
    }

    /**
     * @return array<string, HealthStatus>
     */
    private function statuses(): array
    {
        $this->entityManager()->clear();
        $runner = $this->service('tmonier_sylius_platform_health.runner');
        self::assertInstanceOf(HealthCheckRunner::class, $runner);

        $statuses = [];
        foreach ($runner->run() as $report) {
            self::assertInstanceOf(HealthCheckReport::class, $report);
            if (str_starts_with($report->identifier, 'trade_credit.')) {
                $statuses[$report->identifier] = $report->result->status;
            }
        }

        return $statuses;
    }
}
