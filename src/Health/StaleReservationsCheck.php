<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Health;

use Symfony\Component\Translation\TranslatableMessage;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthActionLink;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCategory;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCheckInterface;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthResult;
use Tmonier\SyliusTradeCreditPlugin\Reservation\StaleReservationFinderInterface;

/**
 * Credit reserved for an order that is not placed or was cancelled, for more than an hour: the committed amount of an
 * account is too high until `tmonier:trade-credit:recalculate --apply` releases it. Read-only: never applies. At most 501
 * rows are read, beyond 500 the summary says "500+"; the summary gives a count, never an order number.
 * Loaded only when the health plugin is installed: this class implements its interface.
 */
final class StaleReservationsCheck implements HealthCheckInterface
{
    private const COUNT_LIMIT = 500;

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
        $stale = \count($this->reservations->staleReservations(false, self::COUNT_LIMIT + 1));
        if (0 === $stale) {
            return HealthResult::ok(new TranslatableMessage('tmonier_trade_credit.health.stale_reservations.ok'));
        }

        return HealthResult::warning(
            new TranslatableMessage('tmonier_trade_credit.health.stale_reservations.found', [
                '%count%' => $stale > self::COUNT_LIMIT ? self::COUNT_LIMIT . '+' : (string) $stale,
            ]),
            action: new HealthActionLink('tmonier_trade_credit_admin_account_index'),
        );
    }
}
