<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Reservation;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Symfony\Component\Uid\Uuid;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditLedgerEntryInterface;
use Tmonier\SyliusTradeCreditPlugin\Exception\TradeCreditReservationException;
use Tmonier\SyliusTradeCreditPlugin\Repository\OrderPaymentTermsRepositoryInterface;

/**
 * The only writer of committed amounts, order payment terms and ledger entries, in SQL, each
 * operation in one transaction that first locks the account row (`SELECT ... FOR UPDATE`), so
 * that reservations, releases and recalculations of an account are serialized.
 *
 * The terms of a placed order never restart: a new reservation of the same order (after a failed
 * payment, a method switch) keeps the start date of its first reservation, and its due date when
 * the method is the same. Ids of terms and ledger entries are UUIDs generated here.
 */
final readonly class TradeCreditReserver
{
    public const REASON_PAID = 'paid';

    public const REASON_ORDER_CANCELLED = 'order_cancelled';

    public const REASON_PAYMENT_CANCELLED = 'payment_cancelled';

    public const REASON_PAYMENT_FAILED = 'payment_failed';

    public const REASON_PAYMENT_METHOD_CHANGED = 'payment_method_changed';

    public const REASON_STALE = 'stale';

    public const UNAVAILABLE = 'tmonier_trade_credit.reservation.unavailable';

    public const LOST_RESERVATION_REASONS = [self::REASON_PAYMENT_CANCELLED, self::REASON_PAYMENT_FAILED, self::REASON_PAYMENT_METHOD_CHANGED];

    public function __construct(
        private ReservationStore $store,
        private ReservationValidator $validator,
        private PaymentTermsResolver $termsResolver,
        private OrderPaymentTermsRepositoryInterface $termsRepository,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Reserves the order total on the account of the order owner. Does nothing when the placed
     * order already has this reservation; replaces a reservation of another method or amount.
     *
     * `$refreshEntities` false while Doctrine is flushing (no refresh during a flush).
     *
     * @throws TradeCreditReservationException
     */
    public function reserve(OrderInterface $order, PaymentMethodInterface $paymentMethod, bool $refreshEntities = true): void
    {
        $context = $this->validator->validate($order, $paymentMethod);
        if (null === $context) {
            return;
        }

        $account = $context->account;
        $amount = $context->amount;
        $placed = $context->placed;
        $now = $this->clock->now();

        $this->store->connection()->transactional(function (Connection $connection) use ($order, $paymentMethod, $account, $amount, $placed, $context, $now): void {
            $this->store->lockAccount($connection, $account->getId());

            $existing = $this->store->reservedRow($connection, $order->getId(), true);
            if (null !== $existing) {
                if ($placed && $existing['payment_method_id'] === $paymentMethod->getId() && $existing['amount'] === $amount) {
                    return;
                }
                $this->store->closeRow($connection, $existing, $order->getId(), OrderPaymentTermsInterface::STATE_RELEASED, $placed ? self::REASON_PAYMENT_METHOD_CHANGED : self::REASON_STALE, $now);
            }

            [$netDays, $mode, $dueDate] = $this->termsResolver->resolve($connection, $order, $paymentMethod, $placed, $context->accountTerms, $now);

            $affected = $connection->executeStatement(
                sprintf(
                    'UPDATE %s SET committed_amount = committed_amount + :amount, updated_at = :now WHERE id = :id AND enabled = :yes AND credit_hold = :no AND (allow_exceed = :yes OR %s - committed_amount - :external >= :amount)',
                    $this->store->accountTable(),
                    $context->limitSql,
                ),
                ['amount' => $amount, 'now' => $now, 'id' => $account->getId(), 'yes' => true, 'no' => false, 'external' => $context->externalCommitted],
                ['amount' => ParameterType::INTEGER, 'now' => Types::DATETIME_IMMUTABLE, 'id' => ParameterType::INTEGER, 'yes' => ParameterType::BOOLEAN, 'no' => ParameterType::BOOLEAN, 'external' => ParameterType::INTEGER],
            );
            if (1 !== $affected) {
                $hold = $connection->fetchOne(sprintf('SELECT credit_hold FROM %s WHERE id = ?', $this->store->accountTable()), [$account->getId()]);

                throw new TradeCreditReservationException(
                    (bool) $hold ? 'tmonier_trade_credit.reservation.credit_hold' : 'tmonier_trade_credit.reservation.limit_exceeded',
                    $order->getTokenValue(),
                );
            }

            $termsId = Uuid::v7()->toRfc4122();
            $connection->insert($this->store->termsTable(), [
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

            $this->store->addLedgerEntry($connection, $account->getId(), $order->getId(), $termsId, TradeCreditLedgerEntryInterface::TYPE_RESERVATION, $amount, null, $now);
        });

        if ($refreshEntities) {
            $this->store->refresh($account);
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

        $preview = $this->store->reservedRow($this->store->connection(), $orderId);
        if (null === $preview || ($paymentMethod instanceof PaymentMethodInterface && null !== $preview['payment_method_id'] && $preview['payment_method_id'] !== $paymentMethod->getId())) {
            return false;
        }

        $now = $this->clock->now();
        $closed = $this->store->connection()->transactional(function (Connection $connection) use ($preview, $orderId, $state, $reason, $now): bool {
            $this->store->lockAccount($connection, $preview['account_id']);
            $row = $this->store->reservedRow($connection, $orderId, true);
            if (null === $row || $row['id'] !== $preview['id']) {
                return false;
            }

            return $this->store->closeRow($connection, $row, $orderId, $state, $reason, $now);
        });

        if ($closed) {
            $this->logger->info(sprintf('Trade credit of order #%s %s (%s).', (string) $order->getNumber(), $state, $reason));
        }
        if ($closed && $refreshEntities) {
            $terms = $this->termsRepository->find($preview['id']);
            if (null !== $terms) {
                $this->store->refresh($terms);
                $this->store->refresh($terms->getAccount());
            }
        }

        return $closed;
    }
}
