<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Reservation;

/**
 * The reservations that should have been closed: open for more than an hour on an order that is not placed (a cart left
 * by a failed save) or was cancelled.
 */
interface StaleReservationFinderInterface
{
    /**
     * Order numbers (or ids) of the stale reservations, released with `$apply`. `$limit` bounds the number of rows read
     * (no limit when null), for the callers that only need to know whether there are many.
     *
     * @return list<string>
     */
    public function staleReservations(bool $apply, ?int $limit = null): array;
}
