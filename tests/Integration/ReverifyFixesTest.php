<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTradeCreditPlugin\Integration;

use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Tmonier\SyliusTradeCreditPlugin\Functional\TradeCreditTestTrait;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\PaymentMethodEligibilityCheckerInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;
use Tmonier\SyliusTradeCreditPlugin\Statement\StatementBuilder;

/** Regression tests of the reverification of the review fixes (2026-10-12). */
final class ReverifyFixesTest extends KernelTestCase
{
    use TradeCreditTestTrait;

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

    /** Net 30 switched to net 60: once day 30 has passed, the order is not overdue (its net 60 reservation is open). */
    public function testSwitchingBetweenTwoMethodsOnAccountIsNotOverdueAtTheFirstDueDate(): void
    {
        $net30 = $this->onAccountMethod(30);
        $net60 = $this->onAccountMethod(60);
        $bankTransfer = $this->paymentMethod('Bank transfer');
        $customer = $this->customer();
        $account = $this->account($customer);
        $account->setBlockWhenOverdue(true);
        $direct = $this->order('completed', $net30, $customer);
        $throughTransfer = $this->order('completed', $net30, $customer);

        $this->switchMethod($direct, $net60);
        $this->switchMethod($throughTransfer, $bankTransfer);
        $this->switchMethod($throughTransfer, $net60);
        // Day 31: the net 30 terms are past due, the net 60 ones are not.
        foreach ([$direct, $throughTransfer] as $order) {
            $this->connection()->executeStatement('UPDATE tmonier_trade_credit_order_terms SET due_date = ? WHERE order_id = ? AND state = ?', [(new \DateTimeImmutable('-1 day'))->format('Y-m-d'), $order->getId(), OrderPaymentTermsInterface::STATE_RELEASED]);
            self::assertSame([OrderPaymentTermsInterface::STATE_RELEASED, OrderPaymentTermsInterface::STATE_RESERVED], $this->termsStates($order));
        }

        $cart = $this->order('shipping_selected', null, $customer);
        $payment = $cart->getLastPayment(PaymentInterface::STATE_CART);
        self::assertNotNull($payment);
        self::assertNull($this->checker()->getIneligibility($net30, $payment));
        self::assertFalse($this->termsRepository()->hasOverdue($account, new \DateTimeImmutable()));
    }

    /** An order left unpaid after leaving payment on account blocks the account: it is shown as overdue everywhere. */
    public function testAnOrderLeftUnpaidAfterItsDueDateIsOverdueOnTheStatement(): void
    {
        $method = $this->onAccountMethod();
        $bankTransfer = $this->paymentMethod('Bank transfer');
        $customer = $this->customer();
        $account = $this->account($customer);
        $open = $this->order('completed', $method, $customer);
        $left = $this->order('completed', $method, $customer);
        $this->connection()->executeStatement('UPDATE tmonier_trade_credit_order_terms SET due_date = ? WHERE order_id = ?', [(new \DateTimeImmutable('-3 days'))->format('Y-m-d'), $left->getId()]);
        $this->switchMethod($left, $bankTransfer);
        self::assertTrue($this->termsRepository()->hasOverdue($account, new \DateTimeImmutable()));

        $statement = $this->statementBuilder()->forCustomer($customer);
        self::assertNotNull($statement);
        $overdue = [];
        foreach ($statement->items as $item) {
            $overdue[$item->orderNumber] = $item->overdue;
        }
        self::assertSame([(string) $open->getNumber() => false, (string) $left->getNumber() => true], $overdue);
        self::assertSame($left->getTotal(), $statement->getOverdueAmount());

        // Paid by transfer: no longer overdue, no longer listed.
        $payment = $left->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($payment);
        $this->applyPayment($payment, PaymentTransitions::TRANSITION_COMPLETE);
        self::assertFalse($this->termsRepository()->hasOverdue($account, new \DateTimeImmutable()));
        self::assertCount(1, $this->statementBuilder()->forCustomer($customer)->items ?? []);
    }

    /** The switch to another method on account was reserved, then the save of the payment failed: paying the order still settles it. */
    public function testPayingAnOrderSettlesAReservationMadeForAnotherMethod(): void
    {
        [$order, $account] = $this->orderWithAnUnsavedSwitch();
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($payment);

        $this->applyPayment($payment, PaymentTransitions::TRANSITION_COMPLETE);

        self::assertSame([OrderPaymentTermsInterface::STATE_RELEASED, OrderPaymentTermsInterface::STATE_SETTLED], $this->termsStates($order));
        self::assertSame(0, $this->committed($account));
    }

    public function testTheRecalculateCommandReservesAgainForTheMethodOfThePayment(): void
    {
        [$order, $account, $method] = $this->orderWithAnUnsavedSwitch();
        $this->connection()->executeStatement('UPDATE tmonier_trade_credit_order_terms SET created_at = ? WHERE order_id = ?', [(new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s'), $order->getId()]);

        $output = $this->command('tmonier:trade-credit:recalculate', ['--apply' => true]);

        self::assertStringContainsString((string) $order->getNumber(), $output);
        self::assertSame($method->getId(), $this->termsRepository()->findReservedForOrder($order)?->getPaymentMethod()?->getId());
        self::assertSame([OrderPaymentTermsInterface::STATE_RELEASED, OrderPaymentTermsInterface::STATE_RELEASED, OrderPaymentTermsInterface::STATE_RESERVED], $this->termsStates($order));
        self::assertSame($order->getTotal(), $this->committed($account));
    }

    /**
     * Order paid with method A (net 30) whose switch to B (net 60) was reserved (onFlush, own
     * transaction) but never saved: the payment is still on A, the reservation on B.
     *
     * @return array{0: \Sylius\Component\Core\Model\OrderInterface, 1: \Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccountInterface, 2: \Sylius\Component\Core\Model\PaymentMethodInterface}
     */
    private function orderWithAnUnsavedSwitch(): array
    {
        $net30 = $this->onAccountMethod(30);
        $net60 = $this->onAccountMethod(60);
        $customer = $this->customer();
        $account = $this->account($customer);
        $order = $this->order('completed', $net30, $customer);

        $this->reserver()->reserve($order, $net60);

        self::assertSame($net60->getId(), $this->termsRepository()->findReservedForOrder($order)?->getPaymentMethod()?->getId());
        self::assertSame($net30, $order->getLastPayment(PaymentInterface::STATE_NEW)?->getMethod());

        return [$order, $account, $net30];
    }

    private function switchMethod(\Sylius\Component\Core\Model\OrderInterface $order, \Sylius\Component\Core\Model\PaymentMethodInterface $method): void
    {
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($payment);
        $payment->setMethod($method);
        $this->entityManager()->flush();
    }

    private function checker(): PaymentMethodEligibilityCheckerInterface
    {
        $checker = $this->service(PaymentMethodEligibilityCheckerInterface::class);
        self::assertInstanceOf(PaymentMethodEligibilityCheckerInterface::class, $checker);

        return $checker;
    }

    private function statementBuilder(): StatementBuilder
    {
        $builder = $this->service('tmonier_trade_credit.statement_builder');
        self::assertInstanceOf(StatementBuilder::class, $builder);

        return $builder;
    }

    private function applyPayment(PaymentInterface $payment, string $transition): void
    {
        /** @var StateMachineInterface $stateMachine */
        $stateMachine = $this->service('sylius_abstraction.state_machine');
        $stateMachine->apply($payment, PaymentTransitions::GRAPH, $transition);
        $this->entityManager()->flush();
    }

    /** @param array<string, mixed> $input */
    private function command(string $name, array $input): string
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find($name));
        $tester->execute($input);

        return $tester->getDisplay();
    }
}
