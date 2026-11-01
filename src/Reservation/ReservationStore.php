<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Reservation;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccountInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditLedgerEntryInterface;

final readonly class ReservationStore
{
    /**
     * @param class-string<TradeCreditAccountInterface> $accountClass
     * @param class-string<OrderPaymentTermsInterface> $termsClass
     * @param class-string<TradeCreditLedgerEntryInterface> $ledgerClass
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private string $accountClass,
        private string $termsClass,
        private string $ledgerClass,
    ) {
    }

    public function lockAccount(Connection $connection, mixed $accountId): void
    {
        $connection->fetchOne(sprintf('SELECT id FROM %s WHERE id = ? FOR UPDATE', $this->accountTable()), [$accountId]);
    }

    /**
     * @return array{id: string, account_id: int, amount: int, payment_method_id: int|null}|null
     */
    public function reservedRow(Connection $connection, mixed $orderId, bool $locking = false): ?array
    {
        /** @var array{id: string, account_id: int|string, amount: int|string, payment_method_id: int|string|null}|false $row */
        $row = $connection->fetchAssociative(
            sprintf('SELECT id, account_id, amount, payment_method_id FROM %s WHERE order_id = ? AND state = ? ORDER BY created_at ASC%s', $this->termsTable(), $locking ? ' FOR UPDATE' : ''),
            [$orderId, OrderPaymentTermsInterface::STATE_RESERVED],
        );

        return false === $row ? null : [
            'id' => (string) $row['id'],
            'account_id' => (int) $row['account_id'],
            'amount' => (int) $row['amount'],
            'payment_method_id' => null === $row['payment_method_id'] ? null : (int) $row['payment_method_id'],
        ];
    }

    public function addLedgerEntry(Connection $connection, mixed $accountId, mixed $orderId, ?string $termsId, string $type, int $amount, ?string $reason, \DateTimeImmutable $now): void
    {
        $committedAfter = $this->toInt($connection->fetchOne(sprintf('SELECT committed_amount FROM %s WHERE id = ?', $this->accountTable()), [$accountId]));
        $connection->insert($this->ledgerTable(), [
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

    public function accountTable(): string
    {
        return $this->entityManager->getClassMetadata($this->accountClass)->getTableName();
    }

    public function termsTable(): string
    {
        return $this->entityManager->getClassMetadata($this->termsClass)->getTableName();
    }

    public function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    public function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    /**
     * @param array{id: string, account_id: int, amount: int, payment_method_id: int|null} $row
     */
    public function closeRow(Connection $connection, array $row, mixed $orderId, string $state, string $reason, \DateTimeImmutable $now): bool
    {
        $affected = $connection->executeStatement(
            sprintf('UPDATE %s SET state = :state, closed_at = :now, closing_reason = :reason WHERE id = :id AND state = :reserved', $this->termsTable()),
            ['state' => $state, 'now' => $now, 'reason' => $reason, 'id' => $row['id'], 'reserved' => OrderPaymentTermsInterface::STATE_RESERVED],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
        if (1 !== $affected) {
            return false;
        }

        $connection->executeStatement(
            sprintf('UPDATE %s SET committed_amount = committed_amount - :amount, updated_at = :now WHERE id = :id', $this->accountTable()),
            ['amount' => $row['amount'], 'now' => $now, 'id' => $row['account_id']],
            ['amount' => ParameterType::INTEGER, 'now' => Types::DATETIME_IMMUTABLE, 'id' => ParameterType::INTEGER],
        );
        $type = OrderPaymentTermsInterface::STATE_SETTLED === $state ? TradeCreditLedgerEntryInterface::TYPE_SETTLEMENT : TradeCreditLedgerEntryInterface::TYPE_RELEASE;
        $this->addLedgerEntry($connection, $row['account_id'], $orderId, $row['id'], $type, -$row['amount'], $reason, $now);

        return true;
    }

    public function refresh(?object $entity): void
    {
        if (null !== $entity && $this->entityManager->contains($entity)) {
            $this->entityManager->refresh($entity);
        }
    }

    public function entityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }

    private function ledgerTable(): string
    {
        return $this->entityManager->getClassMetadata($this->ledgerClass)->getTableName();
    }
}
