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
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Symfony\Component\Uid\Uuid;
use Tmonier\SyliusTradeCreditPlugin\Account\PaymentTermsPolicyProvider;
use Tmonier\SyliusTradeCreditPlugin\Account\TradeCreditAccountProvider;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccountInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditLedgerEntryInterface;
use Tmonier\SyliusTradeCreditPlugin\Exception\TradeCreditReservationException;
use Tmonier\SyliusTradeCreditPlugin\Repository\OrderPaymentTermsRepositoryInterface;

/**
 * The only writer of committed amounts, order payment terms and ledger entries, in SQL:
 *
 * - reserve: `UPDATE account SET committed = committed + :amount WHERE ... AND (allow_exceed OR
 *   limit - committed >= :amount)`; 0 affected rows means the credit is not available, whatever
 *   the other requests did meanwhile (portable on MySQL, MariaDB and PostgreSQL);
 * - close (settle or release): `UPDATE terms SET state = :new WHERE id = :id AND state =
 *   'reserved'`; only the request that changed the row gives the amount back, so a reservation
 *   is released at most once whatever the events (payment completed then refunded, order
 *   cancelled cascading to the payment...).
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
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly string $accountClass,
        private readonly string $termsClass,
        private readonly string $ledgerClass,
    ) {
    }

    /**
     * Reserves the order total on the account of the order owner. Does nothing when the order
     * already has a reservation.
     *
     * `$refreshEntities` false while Doctrine is flushing (no refresh during a flush).
     *
     * @throws TradeCreditReservationException
     */
    public function reserve(OrderInterface $order, PaymentMethodInterface $paymentMethod, bool $refreshEntities = true): void
    {
        $policy = $this->policyProvider->forPaymentMethod($paymentMethod);
        if (null === $policy || null !== $this->reservedTermsRow($order)) {
            return;
        }
        if (null === $order->getId()) {
            throw new \LogicException('An order must be saved before its trade credit is reserved.');
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

        $amount = $order->getTotal();
        $terms = $account->getTerms($policy->getTerms());
        $connection = $this->connection();

        $connection->transactional(function (Connection $connection) use ($order, $paymentMethod, $account, $amount, $terms, $now): void {
            $affected = $connection->executeStatement(
                sprintf(
                    'UPDATE %s SET committed_amount = committed_amount + :amount, updated_at = :now WHERE id = :id AND enabled = :yes AND credit_hold = :no AND (allow_exceed = :yes OR credit_limit - committed_amount >= :amount)',
                    $this->table($this->accountClass),
                ),
                ['amount' => $amount, 'now' => $now, 'id' => $account->getId(), 'yes' => true, 'no' => false],
                ['amount' => ParameterType::INTEGER, 'now' => Types::DATETIME_IMMUTABLE, 'id' => ParameterType::INTEGER, 'yes' => ParameterType::BOOLEAN, 'no' => ParameterType::BOOLEAN],
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
                'net_days' => $terms->netDays,
                'end_of_month_mode' => $terms->endOfMonthMode->value,
                'due_date' => $terms->dueDate($now),
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

    /** Settles (paid) or releases the reservation of the order; false when there was none left. */
    public function close(OrderInterface $order, string $state, string $reason, bool $refreshEntities = true): bool
    {
        if (!in_array($state, [OrderPaymentTermsInterface::STATE_SETTLED, OrderPaymentTermsInterface::STATE_RELEASED], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown closing state "%s".', $state));
        }

        $row = $this->reservedTermsRow($order);
        if (null === $row) {
            return false;
        }

        $now = $this->clock->now();
        $closed = $this->connection()->transactional(function (Connection $connection) use ($row, $order, $state, $reason, $now): bool {
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
            $this->addLedgerEntry($connection, $row['account_id'], $order->getId(), $row['id'], $type, -$row['amount'], $reason, $now);

            return true;
        });

        if ($closed) {
            $this->logger->info(sprintf('Trade credit of order #%s %s (%s).', (string) $order->getNumber(), $state, $reason));
        }
        if ($closed && $refreshEntities) {
            $terms = $this->termsRepository->find($row['id']);
            if (null !== $terms) {
                $this->refresh($terms);
                $this->refresh($terms->getAccount());
            }
        }

        return $closed;
    }

    /** Committed amount as stored (the entity may be stale within a request). */
    public function committedAmount(TradeCreditAccountInterface $account): int
    {
        return $this->toInt($this->connection()->fetchOne(sprintf('SELECT committed_amount FROM %s WHERE id = ?', $this->table($this->accountClass)), [$account->getId()]));
    }

    /** Sets the committed amount of an account (recalculation command), with an adjustment entry. */
    public function adjust(TradeCreditAccountInterface $account, int $committedAmount, string $reason): void
    {
        $now = $this->clock->now();
        $this->connection()->transactional(function (Connection $connection) use ($account, $committedAmount, $reason, $now): void {
            $current = $this->toInt($connection->fetchOne(sprintf('SELECT committed_amount FROM %s WHERE id = ?', $this->table($this->accountClass)), [$account->getId()]));
            $connection->executeStatement(
                sprintf('UPDATE %s SET committed_amount = :amount, updated_at = :now WHERE id = :id', $this->table($this->accountClass)),
                ['amount' => $committedAmount, 'now' => $now, 'id' => $account->getId()],
                ['amount' => ParameterType::INTEGER, 'now' => Types::DATETIME_IMMUTABLE, 'id' => ParameterType::INTEGER],
            );
            $this->addLedgerEntry($connection, $account->getId(), null, null, TradeCreditLedgerEntryInterface::TYPE_ADJUSTMENT, $committedAmount - $current, $reason, $now);
        });
        $this->refresh($account);
    }

    /** @return array{id: string, account_id: int, amount: int}|null */
    private function reservedTermsRow(OrderInterface $order): ?array
    {
        if (null === $order->getId()) {
            return null;
        }

        /** @var array{id: string, account_id: int|string, amount: int|string}|false $row */
        $row = $this->connection()->fetchAssociative(
            sprintf('SELECT id, account_id, amount FROM %s WHERE order_id = ? AND state = ?', $this->table($this->termsClass)),
            [$order->getId(), OrderPaymentTermsInterface::STATE_RESERVED],
        );

        return false === $row ? null : ['id' => (string) $row['id'], 'account_id' => (int) $row['account_id'], 'amount' => (int) $row['amount']];
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
