<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Reservation;

use Doctrine\DBAL\Connection;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Tmonier\SyliusTradeCreditPlugin\Terms\PaymentTerms;

final readonly class PaymentTermsResolver
{
    public function __construct(
        private ReservationStore $store,
    ) {
    }

    /**
     * Net days, end-of-month mode and due date of a new reservation: the first reservation of a
     * placed order gives the start date; the previous reservation with the same method gives
     * the whole terms (after a failed or cancelled payment).
     *
     * @return array{0: int, 1: string, 2: \DateTimeImmutable}
     */
    public function resolve(Connection $connection, OrderInterface $order, PaymentMethodInterface $paymentMethod, bool $placed, PaymentTerms $accountTerms, \DateTimeImmutable $now): array
    {
        if (!$placed) {
            return [$accountTerms->netDays, $accountTerms->endOfMonthMode->value, $accountTerms->dueDate($now)];
        }

        /** @var list<array{payment_method_id: int|string|null, net_days: int|string, end_of_month_mode: string, due_date: string, created_at: string, closing_reason: string|null}> $previous */
        $previous = $connection->fetchAllAssociative(
            sprintf('SELECT payment_method_id, net_days, end_of_month_mode, due_date, created_at, closing_reason FROM %s WHERE order_id = ? ORDER BY created_at ASC', $this->store->termsTable()),
            [$order->getId()],
        );
        $previous = array_values(array_filter($previous, static fn (array $row): bool => TradeCreditReserver::REASON_STALE !== $row['closing_reason']));
        if ([] === $previous) {
            $start = $order->getCheckoutCompletedAt() instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($order->getCheckoutCompletedAt()) : $now;

            return [$accountTerms->netDays, $accountTerms->endOfMonthMode->value, $accountTerms->dueDate($start)];
        }

        foreach (array_reverse($previous) as $row) {
            if (null !== $row['payment_method_id'] && (int) $row['payment_method_id'] === $paymentMethod->getId()) {
                return [(int) $row['net_days'], $row['end_of_month_mode'], new \DateTimeImmutable($row['due_date'])];
            }
        }

        $start = new \DateTimeImmutable($previous[0]['created_at']);

        return [$accountTerms->netDays, $accountTerms->endOfMonthMode->value, $accountTerms->dueDate($start)];
    }
}
