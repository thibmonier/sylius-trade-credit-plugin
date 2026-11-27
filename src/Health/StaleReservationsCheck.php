<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Health;

use Symfony\Component\Translation\TranslatableMessage;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCategory;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCheckInterface;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthResult;
use Tmonier\SyliusTradeCreditPlugin\Reservation\StaleReservationFinderInterface;

/**
 * Credit reserved for an order that is not placed or was cancelled, for more than an hour: the committed amount of an
 * account is too high until `tmonier:trade-credit:recalculate --apply` releases it. Read-only: never applies.
 * Loaded only when the health plugin is installed: this class implements its interface.
 */
final class StaleReservationsCheck implements HealthCheckInterface
{
    public function __construct(
        private readonly StaleReservationFinderInterface $reservations,
    ) {
    }

    public function identifier(): string
    {
        return 'trade_credit.stale_reservations';
    }

    public function category(): HealthCategory
    {
        return HealthCategory::Plugins;
    }

    public function run(): HealthResult
    {
        $this->reservations->staleReservations(false, 1);

        return HealthResult::unknown(new TranslatableMessage('tmonier_trade_credit.health.stale_reservations.ok'));
    }
}
