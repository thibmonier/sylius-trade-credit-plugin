<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Reminder;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;
use Tmonier\SyliusTradeCreditPlugin\Ageing\AgeingBuckets;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditReminder;
use Tmonier\SyliusTradeCreditPlugin\Repository\OrderPaymentTermsRepositoryInterface;

/**
 * Payment reminders of the open items (and of the orders left unpaid after leaving payment on
 * account), by e-mail to the customer of the order. Levels are days relative to the due date
 * (negative: before it). For each item, only the highest level reached is sent, once: a level is
 * recorded before the e-mail is sent (unique per order, due date and level) and a lower level
 * is never sent after a higher one, so running the command again (or concurrently) never sends
 * a reminder twice. A failed e-mail is forgotten, so that the next run sends it.
 */
final class ReminderSender
{
    public const EMAIL = 'tmonier_trade_credit_payment_reminder';

    /** @param list<int> $levels */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OrderPaymentTermsRepositoryInterface $termsRepository,
        private readonly SenderInterface $sender,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly array $levels,
    ) {
    }

    /** @return list<array{order: string, email: string, dueDate: string, level: int, status: string}> status: sent, to_send (dry run) or failed */
    public function send(bool $dryRun): array
    {
        if ([] === $this->levels) {
            return [];
        }
        $today = $this->clock->now();
        $report = [];
        foreach ($this->termsRepository->findRemindable($today, -min($this->levels)) as $terms) {
            $order = $terms->getOrder();
            $email = $order instanceof OrderInterface ? $order->getCustomer()?->getEmail() : null;
            if (!$order instanceof OrderInterface || null === $email || '' === $email) {
                continue;
            }
            $daysPastDue = AgeingBuckets::daysPastDue($terms->getDueDate(), $today);
            $level = $this->levelFor($daysPastDue);
            if (null === $level || $this->highestSentLevel($order, $terms->getDueDate()) >= $level) {
                continue;
            }

            $status = $dryRun ? 'to_send' : $this->sendOne($terms, $order, $email, $level, $daysPastDue);
            if (null !== $status) {
                $report[] = ['order' => (string) $order->getNumber(), 'email' => $email, 'dueDate' => $terms->getDueDate()->format('Y-m-d'), 'level' => $level, 'status' => $status];
            }
        }

        return $report;
    }

    /** The highest level reached `$daysPastDue` days after the due date, if any. */
    public function levelFor(int $daysPastDue): ?int
    {
        $reached = array_filter($this->levels, static fn (int $level): bool => $level <= $daysPastDue);

        return [] === $reached ? null : max($reached);
    }

    /** `sent`, `failed`, or null when a concurrent run recorded this level first. */
    private function sendOne(OrderPaymentTermsInterface $terms, OrderInterface $order, string $email, int $level, int $daysPastDue): ?string
    {
        $id = Uuid::v7()->toRfc4122();
        $connection = $this->entityManager->getConnection();

        try {
            // Own transaction (a savepoint when nested): a duplicate never aborts the caller's one.
            $connection->transactional(fn (Connection $connection): int|string => $connection->insert($this->table(), [
                'id' => $id,
                'order_id' => $order->getId(),
                'due_date' => $terms->getDueDate(),
                'reminder_level' => $level,
                'email' => $email,
                'sent_at' => $this->clock->now(),
            ], ['due_date' => Types::DATE_IMMUTABLE, 'sent_at' => Types::DATETIME_IMMUTABLE]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        try {
            $this->sender->send(self::EMAIL, [$email], [
                'order' => $order,
                'channel' => $order->getChannel(),
                'localeCode' => $order->getLocaleCode(),
                'amount' => $terms->getAmount(),
                'currencyCode' => $terms->getCurrencyCode(),
                'dueDate' => $terms->getDueDate(),
                'daysPastDue' => $daysPastDue,
                'overdue' => $daysPastDue > 0,
                'level' => $level,
                'statementUrl' => $this->statementUrl($order),
            ]);
        } catch (\Throwable $exception) {
            $connection->delete($this->table(), ['id' => $id]);
            $this->logger->error(sprintf('Payment reminder of order #%s not sent: %s', (string) $order->getNumber(), $exception->getMessage()), ['exception' => $exception]);

            return 'failed';
        }

        return 'sent';
    }

    private function highestSentLevel(OrderInterface $order, \DateTimeImmutable $dueDate): int
    {
        $level = $this->entityManager->getConnection()->fetchOne(
            sprintf('SELECT MAX(reminder_level) FROM %s WHERE order_id = ? AND due_date = ?', $this->table()),
            [$order->getId(), $dueDate],
            [1 => Types::DATE_IMMUTABLE],
        );

        return is_numeric($level) ? (int) $level : \PHP_INT_MIN;
    }

    /** Absolute URL of the statement page when the channel has a hostname. */
    private function statementUrl(OrderInterface $order): ?string
    {
        $hostname = $order->getChannel()?->getHostname();
        if (null === $hostname || '' === $hostname) {
            return null;
        }

        return sprintf('https://%s%s', $hostname, $this->urlGenerator->generate('tmonier_trade_credit_shop_statement', ['_locale' => $order->getLocaleCode()]));
    }

    private function table(): string
    {
        return $this->entityManager->getClassMetadata(TradeCreditReminder::class)->getTableName();
    }
}
