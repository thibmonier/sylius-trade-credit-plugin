<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTradeCreditPlugin\Integration;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Tmonier\SyliusTradeCreditPlugin\Functional\TradeCreditTestTrait;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;

/** `tmonier:trade-credit:import-payments`: exact match, dry run, all or nothing, idempotent. */
final class PaymentImportTest extends KernelTestCase
{
    use TradeCreditTestTrait;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->rollBack();
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testADryRunSettlesNothingAndApplySettlesTheMatchingOpenItems(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $account = $this->account($customer);
        $first = $this->order('completed', $method, $customer);
        $second = $this->order('completed', $method, $customer);
        $untouched = $this->order('completed', $method, $customer);
        $file = $this->csv([[$first, $first->getTotal()], [$second, $second->getTotal()]]);

        [$code, $output] = $this->import($file, false);
        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('to_settle', $output);
        self::assertSame([OrderPaymentTermsInterface::STATE_RESERVED], $this->termsStates($first));

        [$code] = $this->import($file, true);
        self::assertSame(Command::SUCCESS, $code);
        foreach ([$first, $second] as $order) {
            self::assertSame([OrderPaymentTermsInterface::STATE_SETTLED], $this->termsStates($order));
            self::assertSame(PaymentInterface::STATE_COMPLETED, $this->connection()->fetchOne('SELECT state FROM sylius_payment WHERE order_id = ? ORDER BY id DESC', [$order->getId()]));
        }
        self::assertSame([OrderPaymentTermsInterface::STATE_RESERVED], $this->termsStates($untouched));
        self::assertSame($untouched->getTotal(), $this->committed($account));
    }

    public function testImportingTheSameFileAgainNeverSettlesTwice(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $account = $this->account($customer);
        $order = $this->order('completed', $method, $customer);
        $file = $this->csv([[$order, $order->getTotal()]]);

        $this->import($file, true);
        [$code, $output] = $this->import($file, true);

        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('already_paid', $output);
        self::assertSame(['reservation', 'settlement'], array_column($this->ledger($account), 'type'));
        self::assertSame(1, self::intOf($this->connection()->fetchOne('SELECT COUNT(*) FROM sylius_payment WHERE order_id = ? AND state = ?', [$order->getId(), PaymentInterface::STATE_COMPLETED])));
    }

    public function testOneWrongLineAndNothingIsSettled(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $account = $this->account($customer);
        $good = $this->order('completed', $method, $customer);
        $wrongAmount = $this->order('completed', $method, $customer);

        [$code, $output] = $this->import($this->csv([[$good, $good->getTotal()], [$wrongAmount, $wrongAmount->getTotal() - 1]]), true);

        self::assertSame(Command::FAILURE, $code);
        self::assertStringContainsString('amount', $output);
        self::assertSame([OrderPaymentTermsInterface::STATE_RESERVED], $this->termsStates($good));
        self::assertSame($good->getTotal() + $wrongAmount->getTotal(), $this->committed($account));
    }

    public function testUnknownDuplicatedAndNotOnAccountOrdersAreErrors(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $this->account($customer);
        $order = $this->order('completed', $method, $customer);
        $bankTransfer = $this->order('completed', $this->paymentMethod('Bank transfer'), $customer);
        $amount = number_format($order->getTotal() / 100, 2, '.', '');

        [$code, $output] = $this->import($this->file(sprintf(
            "order_number;amount;currency\n%s;%s;\n%s;%s;\nNOPE-1;10.00;\n%s;%s;\n%s;%s;XXX\n",
            (string) $order->getNumber(),
            $amount,
            (string) $order->getNumber(),
            $amount,
            (string) $bankTransfer->getNumber(),
            number_format($bankTransfer->getTotal() / 100, 2, '.', ''),
            (string) $order->getNumber(),
            $amount,
        )), true);

        self::assertSame(Command::FAILURE, $code);
        self::assertStringContainsString('order already on line 2', $output);
        self::assertStringContainsString('unknown order', $output);
        self::assertStringContainsString('not an open item', $output);
        self::assertSame([OrderPaymentTermsInterface::STATE_RESERVED], $this->termsStates($order));
    }

    public function testAnOrderLeftUnpaidAfterItsDueDateCanBeSettled(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $this->account($customer);
        $order = $this->order('completed', $method, $customer);
        $this->connection()->executeStatement('UPDATE tmonier_trade_credit_order_terms SET due_date = ? WHERE order_id = ?', [(new \DateTimeImmutable('-3 days'))->format('Y-m-d'), $order->getId()]);
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($payment);
        $payment->setMethod($this->paymentMethod('Bank transfer'));
        $this->entityManager()->flush();

        [$code] = $this->import($this->csv([[$order, $order->getTotal()]]), true);

        self::assertSame(Command::SUCCESS, $code);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $this->connection()->fetchOne('SELECT state FROM sylius_payment WHERE order_id = ? ORDER BY id DESC', [$order->getId()]));
    }

    /** @param list<array{0: OrderInterface, 1: int}> $lines */
    private function csv(array $lines): string
    {
        $content = "order_number,amount\n";
        foreach ($lines as [$order, $amount]) {
            $content .= sprintf("%s,%s\n", (string) $order->getNumber(), number_format($amount / 100, 2, '.', ''));
        }

        return $this->file($content);
    }

    private function file(string $content): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'tmonier_tc_import');
        file_put_contents($file, $content);
        $this->files[] = $file;

        return $file;
    }

    /** @return array{0: int, 1: string} */
    private function import(string $file, bool $apply): array
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('tmonier:trade-credit:import-payments'));
        $code = $tester->execute(array_filter(['file' => $file, '--apply' => $apply]));

        return [$code, $tester->getDisplay()];
    }
}
