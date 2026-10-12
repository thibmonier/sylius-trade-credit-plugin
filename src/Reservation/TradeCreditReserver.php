<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Reservation;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface as CorePaymentInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Symfony\Component\Uid\Uuid;
use Tmonier\SyliusTradeCreditPlugin\Account\OrderOwnerChecker;
use Tmonier\SyliusTradeCreditPlugin\Account\PaymentTermsPolicyProvider;
use Tmonier\SyliusTradeCreditPlugin\Account\TradeCreditAccountProvider;
use Tmonier\SyliusTradeCreditPlugin\Credit\CreditPositionResolver;
use Tmonier\SyliusTradeCreditPlugin\Credit\ExternalCreditUnavailableException;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccountInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditLedgerEntryInterface;
use Tmonier\SyliusTradeCreditPlugin\Exception\TradeCreditReservationException;
use Tmonier\SyliusTradeCreditPlugin\Repository\OrderPaymentTermsRepositoryInterface;

/**
 * The only writer of committed amounts, order payment terms and ledger entries, in SQL, each
 * operation in one transaction that first locks the account row (`SELECT ... FOR UPDATE`), so
 * that reservations, releases and recalculations of an account are serialized:
 *
 * - reserve: re-checks the open reservation of the order under the lock (at most one per order),
 *   then `UPDATE account SET committed = committed + :amount WHERE ... AND (allow_exceed OR
 *   limit - committed >= :amount)`; 0 affected rows means the credit is not available
 *   (portable on MySQL, MariaDB and PostgreSQL);
 * - close (settle or release): `UPDATE terms SET state = :new WHERE id = :id AND state =
 *   'reserved'`; only the request that changed the row gives the amount back, so a reservation
 *   is released at most once whatever the events;
 * - recalculate: sum of the open reservations and new committed amount under the same lock.
 *
 * The terms of a placed order never restart: a new reservation of the same order (after a failed
 * payment, a method switch) keeps the start date of its first reservation, and its due date when
 * the method is the same.
 *
 * Ids of terms and ledger entries are UUIDs generated here (no sequence needed).
 */
final class TradeCreditReserver
{
    public const REASON_PAID = 'paid';

    public const REASON_ORDER_CANCELLED = 'order_cancelled';

    public const REASON_PAYMENT_CANCELLED = 'payment_cancelled';

    public const REASON_PAYMENT_FAILED = 'payment_failed';

    public const REASON_PAYMENT_METHOD_CHANGED = 'payment_method_changed';

    /** A reservation left on an order that was not completed (failed save) or was cancelled. */
    public const REASON_STALE = 'stale';

    /** Reasons after which the order stays unpaid without reservation (see the unpaid-orders exclusion). */
    /** Translation key of a refusal because the external credit position is unknown. */
    public const UNAVAILABLE = 'tmonier_trade_credit.reservation.unavailable';

    public const LOST_RESERVATION_REASONS = [self::REASON_PAYMENT_CANCELLED, self::REASON_PAYMENT_FAILED, self::REASON_PAYMENT_METHOD_CHANGED];

    /**
     * @param class-string<TradeCreditAccountInterface> $accountClass
     * @param class-string<OrderPaymentTermsInterface> $termsClass
     * @param class-string<TradeCreditLedgerEntryInterface> $ledgerClass
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TradeCreditAccountProvider $accountProvider,
        private readonly PaymentTermsPolicyProvider $policyProvider,
        private readonly OrderPaymentTermsRepositoryInterface $termsRepository,
        private readonly OrderOwnerChecker $orderOwnerChecker,
        private readonly CreditPositionResolver $positionResolver,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly string $accountClass,
        private readonly string $termsClass,
        private readonly string $ledgerClass,
    ) {
    }

    /**
     * Reserves the order total on the account of the order owner. Does nothing when the placed
     * order already has this reservation; replaces a reservation of another method or amount
     * (method switch, cart left with a reservation by a failed save).
     *
     * `$refreshEntities` false while Doctrine is flushing (no refresh during a flush).
     *
     * @throws TradeCreditReservationException
     */
    public function reserve(OrderInterface $order, PaymentMethodInterface $paymentMethod, bool $refreshEntities = true): void
    {
        $policy = $this->policyProvider->forPaymentMethod($paymentMethod);
        if (null === $policy) {
            return;
        }
        if (null === $order->getId() || $this->entityManager->getUnitOfWork()->isScheduledForInsert($order)) {
            throw new \LogicException('An order must be saved before its trade credit is reserved.');
        }
        // Never for a guest checkout typing the e-mail of a customer with an account.
        if (!$this->orderOwnerChecker->isPlacedByItsCustomer($order)) {
            throw new TradeCreditReservationException('tmonier_trade_credit.reservation.no_account', $order->getTokenValue());
        }

        $account = $this->accountProvider->forOrder($order);
        if (null === $account || null === $account->getId() || !$account->isEnabled()) {
            throw new TradeCreditReservationException('tmonier_trade_credit.reservation.no_account', $order->getTokenValue());
        }
        if ($account->getCurrencyCode() !== $order->getCurrencyCode()) {
            throw new TradeCreditReservationException('tmonier_trade_credit.reservation.currency', $order->getTokenValue());
        }
        $now = $this->clock->now();
        if ($account->isBlockWhenOverdue() && $this->termsRepository->hasOverdue($account, $now)) {
            throw new TradeCreditReservationException('tmonier_trade_credit.reservation.overdue', $order->getTokenValue());
        }

        // External position (ERP): its limit replaces the account limit and what is owed outside
        // the shop reduces the available credit. Unknown position: refused (fail closed).
        $limitSql = 'credit_limit';
        $externalCommitted = 0;
        if (!$account->isAllowExceed()) {
            try {
                $position = $this->positionResolver->resolve($account);
            } catch (ExternalCreditUnavailableException) {
                throw new TradeCreditReservationException(self::UNAVAILABLE, $order->getTokenValue());
            }
            if ($position->externalLimit) {
                $limitSql = (string) $position->creditLimit;
            }
            $externalCommitted = $position->externalCommittedAmount;
        }

        $amount = $order->getTotal();
        $placed = OrderCheckoutStates::STATE_COMPLETED === $order->getCheckoutState();
        $accountTerms = $account->getTerms($policy->getTerms());

        $this->connection()->transactional(function (Connection $connection) use ($order, $paymentMethod, $account, $amount, $placed, $accountTerms, $now, $limitSql, $externalCommitted): void {
            $this->lockAccount($connection, $account->getId());

            $existing = $this->reservedRow($connection, $order->getId(), true);
            if (null !== $existing) {
                if ($placed && $existing['payment_method_id'] === $paymentMethod->getId() && $existing['amount'] === $amount) {
                    return;
                }
                $this->closeRow($connection, $existing, $order->getId(), OrderPaymentTermsInterface::STATE_RELEASED, $placed ? self::REASON_PAYMENT_METHOD_CHANGED : self::REASON_STALE, $now);
            }

            [$netDays, $mode, $dueDate] = $this->termsOf($connection, $order, $paymentMethod, $placed, $accountTerms, $now);

            $affected = $connection->executeStatement(
                sprintf(
                    'UPDATE %s SET committed_amount = committed_amount + :amount, updated_at = :now WHERE id = :id AND enabled = :yes AND credit_hold = :no AND (allow_exceed = :yes OR %s - committed_amount - :external >= :amount)',
                    $this->table($this->accountClass),
                    $limitSql,
                ),
                ['amount' => $amount, 'now' => $now, 'id' => $account->getId(), 'yes' => true, 'no' => false, 'external' => $externalCommitted],
                ['amount' => ParameterType::INTEGER, 'now' => Types::DATETIME_IMMUTABLE, 'id' => ParameterType::INTEGER, 'yes' => ParameterType::BOOLEAN, 'no' => ParameterType::BOOLEAN, 'external' => ParameterType::INTEGER],
            );
            if (1 !== $affected) {
                $hold = $connection->fetchOne(sprintf('SELECT credit_hold FROM %s WHERE id = ?', $this->table($this->accountClass)), [$account->getId()]);

                throw new TradeCreditReservationException(
                    (bool) $hold ? 'tmonier_trade_credit.reservation.credit_hold' : 'tmonier_trade_credit.reservation.limit_exceeded',
                    $order->getTokenValue(),
                );
            }

            $termsId = Uuid::v7()->toRfc4122();
            $connection->insert($this->table($this->termsClass), [
                'id' => $termsId,
                'order_id' => $order->getId(),
                'account_id' => $account->getId(),
                'payment_method_id' => $paymentMethod->getId(),
                'amount' => $amount,
                'currency_code' => (string) $order->getCurrencyCode(),
                'net_days' => $netDays,
                'end_of_month_mode' => $mode,
                'due_date' => $dueDate,
                'state' => OrderPaymentTermsInterface::STATE_RESERVED,
                'created_at' => $now,
            ], [
                'due_date' => Types::DATE_IMMUTABLE,
                'created_at' => Types::DATETIME_IMMUTABLE,
            ]);

            $this->addLedgerEntry($connection, $account->getId(), $order->getId(), $termsId, TradeCreditLedgerEntryInterface::TYPE_RESERVATION, $amount, null, $now);
        });

        if ($refreshEntities) {
            $this->refresh($account);
        }
    }

    /**
     * Settles (paid) or releases the open reservation of the order; false when there was none
     * left. With `$paymentMethod`, only a reservation made for that method (or whose method was
     * deleted) is closed: the decision never depends on the current state of the policy.
     */
    public function close(OrderInterface $order, string $state, string $reason, bool $refreshEntities = true, ?PaymentMethodInterface $paymentMethod = null): bool
    {
        if (!in_array($state, [OrderPaymentTermsInterface::STATE_SETTLED, OrderPaymentTermsInterface::STATE_RELEASED], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown closing state "%s".', $state));
        }
        $orderId = $order->getId();
        if (null === $orderId) {
            return false;
        }

        $preview = $this->reservedRow($this->connection(), $orderId);
        if (null === $preview || (null !== $paymentMethod && null !== $preview['payment_method_id'] && $preview['payment_method_id'] !== $paymentMethod->getId())) {
            return false;
        }

        $now = $this->clock->now();
        $closed = $this->connection()->transactional(function (Connection $connection) use ($preview, $orderId, $state, $reason, $now): bool {
            $this->lockAccount($connection, $preview['account_id']);
            $row = $this->reservedRow($connection, $orderId, true);
            if (null === $row || $row['id'] !== $preview['id']) {
                return false;
            }

            return $this->closeRow($connection, $row, $orderId, $state, $reason, $now);
        });

        if ($closed) {
            $this->logger->info(sprintf('Trade credit of order #%s %s (%s).', (string) $order->getNumber(), $state, $reason));
        }
        if ($closed && $refreshEntities) {
            $terms = $this->termsRepository->find($preview['id']);
            if (null !== $terms) {
                $this->refresh($terms);
                $this->refresh($terms->getAccount());
            }
        }

        return $closed;
    }

    /**
     * Committed amount and sum of the open reservations of the account, read in one statement;
     * with `$apply`, under the account lock, the committed amount is set to the sum (adjustment
     * ledger entry). Returns null when there is no drift.
     *
     * @return array{committed: int, expected: int}|null
     */
    public function recalculate(TradeCreditAccountInterface $account, bool $apply): ?array
    {
        $now = $this->clock->now();

        /** @var array{committed: int, expected: int}|null $drift */
        $drift = $this->connection()->transactional(function (Connection $connection) use ($account, $apply, $now): ?array {
            if ($apply) {
                $this->lockAccount($connection, $account->getId());
            }
            $row = $connection->fetchAssociative(
                sprintf(
                    'SELECT a.committed_amount AS committed, (SELECT COALESCE(SUM(t.amount), 0) FROM %s t WHERE t.account_id = a.id AND t.state = ?) AS expected FROM %s a WHERE a.id = ?',
                    $this->table($this->termsClass),
                    $this->table($this->accountClass),
                ),
                [OrderPaymentTermsInterface::STATE_RESERVED, $account->getId()],
            );
            $committed = $this->toInt($row['committed'] ?? 0);
            $expected = $this->toInt($row['expected'] ?? 0);
            if ($committed === $expected) {
                return null;
            }
            if ($apply) {
                $connection->executeStatement(
                    sprintf('UPDATE %s SET committed_amount = :amount, updated_at = :now WHERE id = :id', $this->table($this->accountClass)),
                    ['amount' => $expected, 'now' => $now, 'id' => $account->getId()],
                    ['amount' => ParameterType::INTEGER, 'now' => Types::DATETIME_IMMUTABLE, 'id' => ParameterType::INTEGER],
                );
                $this->addLedgerEntry($connection, $account->getId(), null, null, TradeCreditLedgerEntryInterface::TYPE_ADJUSTMENT, $expected - $committed, 'recalculate', $now);
            }

            return ['committed' => $committed, 'expected' => $expected];
        });

        if ($apply && null !== $drift) {
            $this->refresh($account);
        }

        return $drift;
    }

    /**
     * Open reservations, older than an hour, whose order is not a placed order (cart left by a
     * failed save) or was cancelled: order numbers (or ids), released with `$apply`.
     *
     * @return list<string>
     */
    public function staleReservations(bool $apply): array
    {
        /** @var list<array{order_id: int|string, number: string|null}> $rows */
        $rows = $this->connection()->fetchAllAssociative(
            sprintf(
                'SELECT t.order_id, o.number FROM %s t INNER JOIN %s o ON o.id = t.order_id WHERE t.state = ? AND t.created_at < ? AND (o.checkout_state <> ? OR o.state = ?)',
                $this->table($this->termsClass),
                $this->entityManager->getClassMetadata(OrderInterface::class)->getTableName(),
            ),
            // One hour of margin: a checkout in progress reserves before its order is saved.
            [OrderPaymentTermsInterface::STATE_RESERVED, $this->clock->now()->modify('-1 hour'), OrderCheckoutStates::STATE_COMPLETED, OrderInterface::STATE_CANCELLED],
            [1 => Types::DATETIME_IMMUTABLE],
        );

        $orders = [];
        foreach ($rows as $row) {
            $orders[] = (string) ($row['number'] ?? $row['order_id']);
            $order = $apply ? $this->entityManager->find(OrderInterface::class, $row['order_id']) : null;
            if ($order instanceof OrderInterface) {
                $this->close($order, OrderPaymentTermsInterface::STATE_RELEASED, self::REASON_STALE);
            }
        }

        return $orders;
    }

    /**
     * Open reservations, older than an hour, of placed orders whose current payment has another
     * method (a method switch whose save failed after the reservation was committed): order
     * numbers, reserved again for the current method when it is payment on account (same
     * terms), else released, with `$apply`.
     *
     * @return list<string>
     */
    public function mismatchedReservations(bool $apply): array
    {
        $payments = $this->entityManager->getClassMetadata(CorePaymentInterface::class)->getTableName();
        /** @var list<array{order_id: int|string, number: string|null}> $rows */
        $rows = $this->connection()->fetchAllAssociative(
            sprintf(
                'SELECT t.order_id, o.number FROM %1$s t INNER JOIN %2$s o ON o.id = t.order_id INNER JOIN %3$s p ON p.id = (SELECT MAX(lp.id) FROM %3$s lp WHERE lp.order_id = t.order_id) WHERE t.state = ? AND t.created_at < ? AND o.checkout_state = ? AND o.state = ? AND (p.method_id IS NULL OR p.method_id <> t.payment_method_id)',
                $this->table($this->termsClass),
                $this->entityManager->getClassMetadata(OrderInterface::class)->getTableName(),
                $payments,
            ),
            [OrderPaymentTermsInterface::STATE_RESERVED, $this->clock->now()->modify('-1 hour'), OrderCheckoutStates::STATE_COMPLETED, OrderInterface::STATE_NEW],
            [1 => Types::DATETIME_IMMUTABLE],
        );

        $orders = [];
        foreach ($rows as $row) {
            $orders[] = (string) ($row['number'] ?? $row['order_id']);
            $order = $apply ? $this->entityManager->find(OrderInterface::class, $row['order_id']) : null;
            if (!$order instanceof OrderInterface) {
                continue;
            }
            $method = $order->getLastPayment()?->getMethod();
            if (null !== $method && $this->policyProvider->isOnAccount($method)) {
                try {
                    $this->reserve($order, $method);

                    continue;
                } catch (TradeCreditReservationException $exception) {
                    $this->logger->warning(sprintf('Trade credit of order #%s cannot be reserved for its payment method: %s', (string) $order->getNumber(), $exception->getMessage()));
                    if (self::UNAVAILABLE === $exception->getTranslationKey()) {
                        // External position unknown: left as is until the next run.
                        continue;
                    }
                }
            }
            $this->close($order, OrderPaymentTermsInterface::STATE_RELEASED, self::REASON_PAYMENT_METHOD_CHANGED);
        }

        return $orders;
    }

    /**
     * Net days, end-of-month mode and due date of a new reservation: the first reservation of a
     * placed order gives the start date; the previous reservation with the same method gives
     * the whole terms (after a failed or cancelled payment).
     *
     * @return array{0: int, 1: string, 2: \DateTimeImmutable}
     */
    private function termsOf(Connection $connection, OrderInterface $order, PaymentMethodInterface $paymentMethod, bool $placed, \Tmonier\SyliusTradeCreditPlugin\Terms\PaymentTerms $accountTerms, \DateTimeImmutable $now): array
    {
        if (!$placed) {
            return [$accountTerms->netDays, $accountTerms->endOfMonthMode->value, $accountTerms->dueDate($now)];
        }

        /** @var list<array{payment_method_id: int|string|null, net_days: int|string, end_of_month_mode: string, due_date: string, created_at: string, closing_reason: string|null}> $previous */
        $previous = $connection->fetchAllAssociative(
            sprintf('SELECT payment_method_id, net_days, end_of_month_mode, due_date, created_at, closing_reason FROM %s WHERE order_id = ? ORDER BY created_at ASC', $this->table($this->termsClass)),
            [$order->getId()],
        );
        $previous = array_values(array_filter($previous, static fn (array $row): bool => self::REASON_STALE !== $row['closing_reason']));
        if ([] === $previous) {
            $start = null === $order->getCheckoutCompletedAt() ? $now : \DateTimeImmutable::createFromInterface($order->getCheckoutCompletedAt());

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

    /**
     * Guarded closing of a reserved row: false when another request closed it first.
     *
     * @param array{id: string, account_id: int, amount: int, payment_method_id: int|null} $row
     */
    private function closeRow(Connection $connection, array $row, mixed $orderId, string $state, string $reason, \DateTimeImmutable $now): bool
    {
        $affected = $connection->executeStatement(
            sprintf('UPDATE %s SET state = :state, closed_at = :now, closing_reason = :reason WHERE id = :id AND state = :reserved', $this->table($this->termsClass)),
            ['state' => $state, 'now' => $now, 'reason' => $reason, 'id' => $row['id'], 'reserved' => OrderPaymentTermsInterface::STATE_RESERVED],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
        if (1 !== $affected) {
            return false;
        }

        $connection->executeStatement(
            sprintf('UPDATE %s SET committed_amount = committed_amount - :amount, updated_at = :now WHERE id = :id', $this->table($this->accountClass)),
            ['amount' => $row['amount'], 'now' => $now, 'id' => $row['account_id']],
            ['amount' => ParameterType::INTEGER, 'now' => Types::DATETIME_IMMUTABLE, 'id' => ParameterType::INTEGER],
        );
        $type = OrderPaymentTermsInterface::STATE_SETTLED === $state ? TradeCreditLedgerEntryInterface::TYPE_SETTLEMENT : TradeCreditLedgerEntryInterface::TYPE_RELEASE;
        $this->addLedgerEntry($connection, $row['account_id'], $orderId, $row['id'], $type, -$row['amount'], $reason, $now);

        return true;
    }

    private function lockAccount(Connection $connection, mixed $accountId): void
    {
        $connection->fetchOne(sprintf('SELECT id FROM %s WHERE id = ? FOR UPDATE', $this->table($this->accountClass)), [$accountId]);
    }

    /**
     * `$locking` under the account lock: a locking read sees the rows committed by a concurrent
     * request, whereas a plain read may use the snapshot of an outer transaction (MySQL and
     * MariaDB REPEATABLE READ, e.g. the API message bus transaction).
     *
     * @return array{id: string, account_id: int, amount: int, payment_method_id: int|null}|null
     */
    private function reservedRow(Connection $connection, mixed $orderId, bool $locking = false): ?array
    {
        /** @var array{id: string, account_id: int|string, amount: int|string, payment_method_id: int|string|null}|false $row */
        $row = $connection->fetchAssociative(
            sprintf('SELECT id, account_id, amount, payment_method_id FROM %s WHERE order_id = ? AND state = ? ORDER BY created_at ASC%s', $this->table($this->termsClass), $locking ? ' FOR UPDATE' : ''),
            [$orderId, OrderPaymentTermsInterface::STATE_RESERVED],
        );

        return false === $row ? null : [
            'id' => (string) $row['id'],
            'account_id' => (int) $row['account_id'],
            'amount' => (int) $row['amount'],
            'payment_method_id' => null === $row['payment_method_id'] ? null : (int) $row['payment_method_id'],
        ];
    }

    private function addLedgerEntry(Connection $connection, mixed $accountId, mixed $orderId, ?string $termsId, string $type, int $amount, ?string $reason, \DateTimeImmutable $now): void
    {
        $committedAfter = $this->toInt($connection->fetchOne(sprintf('SELECT committed_amount FROM %s WHERE id = ?', $this->table($this->accountClass)), [$accountId]));
        $connection->insert($this->table($this->ledgerClass), [
            'id' => Uuid::v7()->toRfc4122(),
            'account_id' => $accountId,
            'order_id' => $orderId,
            'order_payment_terms_id' => $termsId,
            'type' => $type,
            'amount' => $amount,
            'committed_after' => $committedAfter,
            'reason' => $reason,
            'created_at' => $now,
        ], ['created_at' => Types::DATETIME_IMMUTABLE]);
    }

    /** Entities loaded before the SQL change are refreshed, so that the request sees the new amounts. */
    private function refresh(?object $entity): void
    {
        if (null !== $entity && $this->entityManager->contains($entity)) {
            $this->entityManager->refresh($entity);
        }
    }

    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /** @param class-string $class */
    private function table(string $class): string
    {
        return $this->entityManager->getClassMetadata($class)->getTableName();
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
