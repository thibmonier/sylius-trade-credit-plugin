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
use Tmonier\SyliusTradeCreditPlugin\Account\PaymentTermsPolicyProvider;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccountInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditLedgerEntryInterface;
use Tmonier\SyliusTradeCreditPlugin\Exception\TradeCreditReservationException;

final readonly class ReservationMaintenance
{
    public function __construct(
        private ReservationStore $store,
        private TradeCreditReserver $reserver,
        private EntityManagerInterface $entityManager,
        private PaymentTermsPolicyProvider $policyProvider,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
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
        $rows = $this->store->connection()->fetchAllAssociative(
            sprintf(
                'SELECT t.order_id, o.number FROM %s t INNER JOIN %s o ON o.id = t.order_id WHERE t.state = ? AND t.created_at < ? AND (o.checkout_state <> ? OR o.state = ?)',
                $this->store->termsTable(),
                $this->entityManager->getClassMetadata(OrderInterface::class)->getTableName(),
            ),
            [OrderPaymentTermsInterface::STATE_RESERVED, $this->clock->now()->modify('-1 hour'), OrderCheckoutStates::STATE_COMPLETED, OrderInterface::STATE_CANCELLED],
            [1 => Types::DATETIME_IMMUTABLE],
        );

        $orders = [];
        foreach ($rows as $row) {
            $orders[] = (string) ($row['number'] ?? $row['order_id']);
            $order = $apply ? $this->entityManager->find(OrderInterface::class, $row['order_id']) : null;
            if ($order instanceof OrderInterface) {
                $this->reserver->close($order, OrderPaymentTermsInterface::STATE_RELEASED, TradeCreditReserver::REASON_STALE);
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
        $rows = $this->store->connection()->fetchAllAssociative(
            sprintf(
                'SELECT t.order_id, o.number FROM %1$s t INNER JOIN %2$s o ON o.id = t.order_id INNER JOIN %3$s p ON p.id = (SELECT MAX(lp.id) FROM %3$s lp WHERE lp.order_id = t.order_id) WHERE t.state = ? AND t.created_at < ? AND o.checkout_state = ? AND o.state = ? AND (p.method_id IS NULL OR p.method_id <> t.payment_method_id)',
                $this->store->termsTable(),
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
            if ($method instanceof PaymentMethodInterface && $this->policyProvider->isOnAccount($method)) {
                try {
                    $this->reserver->reserve($order, $method);

                    continue;
                } catch (TradeCreditReservationException $exception) {
                    $this->logger->warning(sprintf('Trade credit of order #%s cannot be reserved for its payment method: %s', (string) $order->getNumber(), $exception->getMessage()));
                    if (TradeCreditReserver::UNAVAILABLE === $exception->getTranslationKey()) {
                        continue;
                    }
                }
            }
            $this->reserver->close($order, OrderPaymentTermsInterface::STATE_RELEASED, TradeCreditReserver::REASON_PAYMENT_METHOD_CHANGED);
        }

        return $orders;
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
        $drift = $this->store->connection()->transactional(function (Connection $connection) use ($account, $apply, $now): ?array {
            if ($apply) {
                $this->store->lockAccount($connection, $account->getId());
            }
            $row = $connection->fetchAssociative(
                sprintf(
                    'SELECT a.committed_amount AS committed, (SELECT COALESCE(SUM(t.amount), 0) FROM %s t WHERE t.account_id = a.id AND t.state = ?) AS expected FROM %s a WHERE a.id = ?',
                    $this->store->termsTable(),
                    $this->store->accountTable(),
                ),
                [OrderPaymentTermsInterface::STATE_RESERVED, $account->getId()],
            );
            $committed = $this->store->toInt($row['committed'] ?? 0);
            $expected = $this->store->toInt($row['expected'] ?? 0);
            if ($committed === $expected) {
                return null;
            }
            if ($apply) {
                $connection->executeStatement(
                    sprintf('UPDATE %s SET committed_amount = :amount, updated_at = :now WHERE id = :id', $this->store->accountTable()),
                    ['amount' => $expected, 'now' => $now, 'id' => $account->getId()],
                    ['amount' => ParameterType::INTEGER, 'now' => Types::DATETIME_IMMUTABLE, 'id' => ParameterType::INTEGER],
                );
                $this->store->addLedgerEntry($connection, $account->getId(), null, null, TradeCreditLedgerEntryInterface::TYPE_ADJUSTMENT, $expected - $committed, 'recalculate', $now);
            }

            return ['committed' => $committed, 'expected' => $expected];
        });

        if ($apply && null !== $drift) {
            $this->store->refresh($account);
        }

        return $drift;
    }
}
