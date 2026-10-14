<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTradeCreditPlugin\Integration;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mime\Email;
use Tests\Tmonier\SyliusTradeCreditPlugin\Functional\TradeCreditTestTrait;
use Tmonier\SyliusTradeCreditPlugin\Reminder\ReminderSender;

/** `tmonier:trade-credit:send-reminders`: levels -5, 1, 15 and 30 days (default configuration). */
final class ReminderTest extends KernelTestCase
{
    use TradeCreditTestTrait;

    /** @var list<array{code: string, recipients: array<mixed>, data: array<mixed>}> */
    private array $sent = [];

    private bool $failing = false;

    private MockClock $clock;

    /** @var array<string, true> orders created by the test */
    private array $orderNumbers = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->rollBack();
        parent::tearDown();
    }

    public function testOneReminderPerLevelAndOnlyTheHighestLevelReached(): void
    {
        $this->spySender();
        $this->clock = new MockClock();
        self::getContainer()->set('clock', $this->clock);
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $this->account($customer)->setBlockWhenOverdue(false);
        $soon = $this->order('completed', $method, $customer);
        $late = $this->order('completed', $method, $customer);
        $notYet = $this->order('completed', $method, $customer);
        $this->dueIn($soon, 3);
        $this->dueIn($late, -20);
        $this->dueIn($notYet, 10);

        $this->sender()->send(false);

        $expected = [(string) $soon->getNumber() => -5, (string) $late->getNumber() => 15];
        ksort($expected);
        self::assertSame($expected, $this->sentLevels());
        $data = $this->sentFor($late)['data'];
        self::assertTrue($data['overdue'] ?? null);
        self::assertSame(20, $data['daysPastDue'] ?? null);
        self::assertSame([(string) $customer->getEmail()], $this->sentFor($late)['recipients']);

        // Run again the same day: nothing.
        $this->sent = [];
        $this->sender()->send(false);
        self::assertSame([], $this->sentLevels());

        // Eleven days later: 31 days past due (level 30, not 15 again), 8 and 1 days past due.
        $this->sent = [];
        $this->clock->modify('+11 days');
        $this->sender()->send(false);
        $expected = [(string) $soon->getNumber() => 1, (string) $late->getNumber() => 30, (string) $notYet->getNumber() => 1];
        ksort($expected);
        self::assertSame($expected, $this->sentLevels());
    }

    public function testADryRunSendsNothingAndAPaidOrderGetsNoReminder(): void
    {
        $this->spySender();
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $this->account($customer);
        $paid = $this->order('completed', $method, $customer);
        $this->dueIn($paid, -2);

        self::assertSame(['to_send'], $this->statuses($this->sender()->send(true), $paid));
        self::assertSame([], $this->sentLevels());

        $payment = $paid->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($payment);
        /** @var \Sylius\Abstraction\StateMachine\StateMachineInterface $stateMachine */
        $stateMachine = $this->service('sylius_abstraction.state_machine');
        $stateMachine->apply($payment, 'sylius_payment', 'complete');
        $this->entityManager()->flush();

        self::assertSame([], $this->statuses($this->sender()->send(false), $paid));
    }

    public function testAFailedEmailIsSentByTheNextRun(): void
    {
        $this->spySender();
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $this->account($customer);
        $order = $this->order('completed', $method, $customer);
        $this->dueIn($order, -1);

        $this->failing = true;
        self::assertSame(['failed'], $this->statuses($this->sender()->send(false), $order));
        self::assertSame(0, self::intOf($this->connection()->fetchOne('SELECT COUNT(*) FROM tmonier_trade_credit_reminder WHERE order_id = ?', [$order->getId()])));

        $this->failing = false;
        self::assertSame(['sent'], $this->statuses($this->sender()->send(false), $order));
        self::assertSame(1, self::intOf($this->connection()->fetchOne('SELECT COUNT(*) FROM tmonier_trade_credit_reminder WHERE order_id = ?', [$order->getId()])));
    }

    /** The real mailer renders the e-mail in the order locale, with the L441-10 mentions when overdue. */
    public function testTheReminderEmail(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $this->account($customer);
        $order = $this->order('completed', $method, $customer);
        $this->dueIn($order, -16);

        self::assertSame(['sent'], $this->statuses($this->sender()->send(false), $order));

        $messages = array_values(array_filter(self::getMailerMessages(), static fn (\Symfony\Component\Mime\RawMessage $message): bool => $message instanceof Email && str_contains((string) $message->getSubject(), (string) $order->getNumber())));
        self::assertCount(1, $messages);
        $email = $messages[0];
        self::assertInstanceOf(Email::class, $email);
        self::assertStringContainsString((string) $order->getNumber(), (string) $email->getSubject());
        self::assertStringContainsString('16', (string) $email->getHtmlBody());
        self::assertStringContainsString('40 EUR', (string) $email->getHtmlBody());
    }

    private function spySender(): void
    {
        $test = $this;
        self::getContainer()->set('sylius.email_sender', new class($test) implements SenderInterface {
            public function __construct(private readonly ReminderTest $test)
            {
            }

            public function send(string $code, array $recipients, array $data = [], array $attachments = [], array $replyTo = [], array $ccRecipients = [], array $bccRecipients = []): void
            {
                $this->test->record($code, $recipients, $data);
            }
        });
    }

    /**
     * @param array<mixed> $recipients
     * @param array<mixed> $data
     */
    public function record(string $code, array $recipients, array $data): void
    {
        if ($this->failing) {
            throw new \RuntimeException('SMTP down');
        }
        $this->sent[] = ['code' => $code, 'recipients' => $recipients, 'data' => $data];
    }

    /**
     * Statuses of the report lines of an order (the fixtures have orders on account too).
     *
     * @param list<array{order: string, status: string}> $report
     *
     * @return list<string>
     */
    private function statuses(array $report, OrderInterface $order): array
    {
        return array_column(array_filter($report, static fn (array $row): bool => $row['order'] === $order->getNumber()), 'status');
    }

    /** @return array{code: string, recipients: array<mixed>, data: array<mixed>} */
    private function sentFor(OrderInterface $order): array
    {
        foreach ($this->sent as $email) {
            $sentOrder = $email['data']['order'] ?? null;
            if ($sentOrder instanceof OrderInterface && $sentOrder->getNumber() === $order->getNumber()) {
                return $email;
            }
        }
        self::fail(sprintf('No reminder for order #%s.', (string) $order->getNumber()));
    }

    /** @return array<string, int> levels sent for the orders of the test (by number) */
    private function sentLevels(): array
    {
        $levels = [];
        foreach ($this->sent as $email) {
            self::assertSame(ReminderSender::EMAIL, $email['code']);
            $order = $email['data']['order'] ?? null;
            self::assertInstanceOf(OrderInterface::class, $order);
            self::assertIsInt($email['data']['level'] ?? null);
            $levels[(string) $order->getNumber()] = $email['data']['level'];
        }

        $levels = array_intersect_key($levels, $this->orderNumbers);
        ksort($levels);

        return $levels;
    }

    private function dueIn(OrderInterface $order, int $days): void
    {
        $this->orderNumbers[(string) $order->getNumber()] = true;
        $this->connection()->executeStatement('UPDATE tmonier_trade_credit_order_terms SET due_date = ? WHERE order_id = ?', [(new \DateTimeImmutable(sprintf('%+d days', $days)))->format('Y-m-d'), $order->getId()]);
        $this->entityManager()->clear();
    }

    private function sender(): ReminderSender
    {
        $sender = $this->service('tmonier_trade_credit.reminder_sender');
        self::assertInstanceOf(ReminderSender::class, $sender);

        return $sender;
    }
}
