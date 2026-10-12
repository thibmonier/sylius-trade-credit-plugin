<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTradeCreditPlugin\Integration;

use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Order\OrderTransitions;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Tmonier\SyliusTradeCreditPlugin\Functional\TradeCreditTestTrait;
use Tmonier\SyliusOrderHoldPlugin\PaymentEntry\PaymentEntryBlockerInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;
use Tmonier\SyliusTradeCreditPlugin\Exception\TradeCreditReservationException;
use Tmonier\SyliusTradeCreditPlugin\Terms\EndOfMonthMode;
use Tmonier\SyliusTradeCreditPlugin\Terms\PaymentTerms;

final class ReservationTest extends KernelTestCase
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

    public function testCompletingAnOrderOnAccountReservesItsTotal(): void
    {
        $method = $this->onAccountMethod(45, EndOfMonthMode::DaysThenEndOfMonth);
        $customer = $this->customer();
        $account = $this->account($customer);

        $order = $this->order('completed', $method, $customer);

        self::assertSame($order->getTotal(), $this->committed($account));
        self::assertSame([['type' => 'reservation', 'amount' => $order->getTotal()]], $this->ledger($account));
        $terms = $this->termsRepository()->findReservedForOrder($order);
        self::assertNotNull($terms);
        self::assertSame($order->getTotal(), $terms->getAmount());
        self::assertSame(45, $terms->getNetDays());
        self::assertSame((new PaymentTerms(45, EndOfMonthMode::DaysThenEndOfMonth))->dueDate(new \DateTimeImmutable())->format('Y-m-d'), $terms->getDueDate()->format('Y-m-d'));
        self::assertSame('awaiting_payment', $order->getPaymentState());

        $blocker = $this->service(PaymentEntryBlockerInterface::class);
        self::assertInstanceOf(PaymentEntryBlockerInterface::class, $blocker);
        self::assertSame('tmonier_trade_credit.payment_entry.on_account', $blocker->getBlock($order)?->getMessage());
    }

    public function testTheAccountTermsOverrideThePolicy(): void
    {
        $method = $this->onAccountMethod(30);
        $customer = $this->customer();
        $account = $this->account($customer);
        $account->setNetDays(60);
        $this->entityManager()->flush();

        $order = $this->order('completed', $method, $customer);

        self::assertSame(60, $this->termsRepository()->findReservedForOrder($order)?->getNetDays());
    }

    public function testAnOrderAboveTheAvailableCreditCannotBeCompleted(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $account = $this->account($customer, 100);
        $order = $this->order('payment_selected', $method, $customer);

        try {
            $this->apply($order, OrderCheckoutTransitions::TRANSITION_COMPLETE);
            self::fail('The order was completed.');
        } catch (TradeCreditReservationException $exception) {
            self::assertSame('tmonier_trade_credit.reservation.limit_exceeded', $exception->getTranslationKey());
        }

        self::assertSame('payment_selected', $order->getCheckoutState());
        self::assertSame(0, $this->committed($account));
        self::assertSame([], $this->ledger($account));
    }

    public function testAllowExceedAcceptsAnOrderAboveTheLimit(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $account = $this->account($customer, 100, true);

        $order = $this->order('completed', $method, $customer);

        self::assertSame($order->getTotal(), $this->committed($account));
    }

    public function testACreditHoldRefusesTheReservation(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $account = $this->account($customer);
        $order = $this->order('payment_selected', $method, $customer);
        $account->setCreditHold(true);
        $this->entityManager()->flush();

        $this->expectException(TradeCreditReservationException::class);
        $this->expectExceptionMessage('credit_hold');
        $this->apply($order, OrderCheckoutTransitions::TRANSITION_COMPLETE);
    }

    /**
     * Two requests check the same availability; the second UPDATE affects no row although its
     * (stale) account entity still shows enough credit.
     */
    public function testAConcurrentReservationCannotOverdrawTheAccount(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $first = $this->order('payment_selected', $method, $customer);
        $second = $this->order('payment_selected', $method, $customer);
        $account = $this->account($customer, $first->getTotal() + $second->getTotal() - 1);
        self::assertSame(0, $account->getCommittedAmount());

        // Another request reserves the first order: SQL only, this entity manager is not told.
        $this->connection()->executeStatement('UPDATE tmonier_trade_credit_account SET committed_amount = committed_amount + ? WHERE id = ?', [$first->getTotal(), $account->getId()]);
        self::assertSame($first->getTotal() + $second->getTotal() - 1, $account->getAvailableAmount(), 'Stale entity');

        try {
            $this->reserver()->reserve($second, $method);
            self::fail('The account was overdrawn.');
        } catch (TradeCreditReservationException $exception) {
            self::assertSame('tmonier_trade_credit.reservation.limit_exceeded', $exception->getTranslationKey());
        }
        self::assertSame($first->getTotal(), $this->committed($account));
        self::assertSame([], $this->termsStates($second));
    }

    public function testAReservationIsMadeOnce(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $account = $this->account($customer);
        $order = $this->order('completed', $method, $customer);

        $this->reserver()->reserve($order, $method);

        self::assertSame($order->getTotal(), $this->committed($account));
        self::assertCount(1, $this->ledger($account));
    }

    public function testPaidThenRefundedSettlesOnce(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $account = $this->account($customer);
        $order = $this->order('completed', $method, $customer);
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($payment);

        $this->applyPayment($payment, PaymentTransitions::TRANSITION_COMPLETE);
        $this->applyPayment($payment, PaymentTransitions::TRANSITION_REFUND);

        self::assertSame(0, $this->committed($account));
        self::assertSame([
            ['type' => 'reservation', 'amount' => $order->getTotal()],
            ['type' => 'settlement', 'amount' => -$order->getTotal()],
        ], $this->ledger($account));
        self::assertSame([OrderPaymentTermsInterface::STATE_SETTLED], $this->termsStates($order));
    }

    public function testCancellingTheOrderReleasesOnce(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $account = $this->account($customer);
        $order = $this->order('completed', $method, $customer);

        // The order cancellation cascades to the payment cancellation: two events, one release.
        /** @var StateMachineInterface $stateMachine */
        $stateMachine = $this->service('sylius_abstraction.state_machine');
        $stateMachine->apply($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL);
        $this->entityManager()->flush();

        self::assertSame('cancelled', $order->getLastPayment()?->getState());
        self::assertSame(0, $this->committed($account));
        self::assertSame([
            ['type' => 'reservation', 'amount' => $order->getTotal()],
            ['type' => 'release', 'amount' => -$order->getTotal()],
        ], $this->ledger($account));
    }

    public function testAFailedPaymentReleasesAndTheNextPaymentOnAccountReservesAgain(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $account = $this->account($customer);
        $order = $this->order('completed', $method, $customer);
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($payment);

        $this->applyPayment($payment, PaymentTransitions::TRANSITION_FAIL);

        $newPayment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($newPayment);
        self::assertNotSame($payment, $newPayment);
        self::assertSame($method, $newPayment->getMethod());
        self::assertSame($order->getTotal(), $this->committed($account));
        self::assertSame(['reservation', 'release', 'reservation'], array_column($this->ledger($account), 'type'));
        self::assertSame([OrderPaymentTermsInterface::STATE_RELEASED, OrderPaymentTermsInterface::STATE_RESERVED], $this->termsStates($order));
    }

    /** No other method: the new payment keeps the method on account, refused, without reservation (the pay routes ask for another one). */
    public function testANewPaymentOnAccountBeyondTheLimitIsKeptWithoutReservation(): void
    {
        $method = $this->onAccountMethod();
        $this->connection()->executeStatement('UPDATE sylius_payment_method SET is_enabled = ? WHERE id <> ?', [false, $method->getId()], [\Doctrine\DBAL\ParameterType::BOOLEAN]);
        $customer = $this->customer();
        $account = $this->account($customer);
        $order = $this->order('completed', $method, $customer);
        $account->setCreditLimit(1);
        $this->entityManager()->flush();
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($payment);

        $this->applyPayment($payment, PaymentTransitions::TRANSITION_FAIL);

        self::assertSame(0, $this->committed($account));
        self::assertSame([OrderPaymentTermsInterface::STATE_RELEASED], $this->termsStates($order));
        $newPayment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($newPayment);
        self::assertNotSame($payment, $newPayment);
        self::assertSame($method, $newPayment->getMethod());
        $checker = $this->service(\Tmonier\SyliusOrderHoldPlugin\PaymentMethod\PaymentMethodEligibilityCheckerInterface::class);
        self::assertInstanceOf(\Tmonier\SyliusOrderHoldPlugin\PaymentMethod\PaymentMethodEligibilityCheckerInterface::class, $checker);
        self::assertSame('tmonier_trade_credit.payment_method.limit_exceeded', $checker->getIneligibility($method, $newPayment)?->getMessage());
    }

    public function testSwitchingThePaymentMethodAfterCheckoutReleasesAndReserves(): void
    {
        $method = $this->onAccountMethod();
        $bankTransfer = $this->paymentMethod('Bank transfer');
        $customer = $this->customer();
        $account = $this->account($customer);
        $order = $this->order('completed', $method, $customer);
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($payment);

        $payment->setMethod($bankTransfer);
        $this->entityManager()->flush();
        self::assertSame(0, $this->committed($account));

        $payment->setMethod($method);
        $this->entityManager()->flush();
        self::assertSame($order->getTotal(), $this->committed($account));
        self::assertSame(['reservation', 'release', 'reservation'], array_column($this->ledger($account), 'type'));
    }

    public function testSwitchingToPaymentOnAccountBeyondTheLimitIsNotSaved(): void
    {
        $method = $this->onAccountMethod();
        $bankTransfer = $this->paymentMethod('Bank transfer');
        $customer = $this->customer();
        $account = $this->account($customer, 100);
        $order = $this->order('completed', $bankTransfer, $customer);
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($payment);

        $payment->setMethod($method);

        try {
            $this->entityManager()->flush();
            self::fail('The switch was saved.');
        } catch (TradeCreditReservationException) {
        }

        self::assertTrue($this->entityManager()->isOpen());
        self::assertSame(0, $this->committed($account));
        self::assertSame($bankTransfer->getId(), self::intOf($this->connection()->fetchOne('SELECT method_id FROM sylius_payment WHERE id = ?', [$payment->getId()])));
    }

    public function testTheUnpaidOrdersCommandDoesNotCancelOrdersOnAccount(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $this->account($customer);
        $onAccount = $this->order('completed', $method, $customer);
        $onAccount->setCheckoutCompletedAt(new \DateTime('-40 days'));
        $other = $this->order('completed', $this->paymentMethod('Bank transfer'));
        $other->setCheckoutCompletedAt(new \DateTime('-40 days'));
        $this->entityManager()->flush();

        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('sylius:cancel-unpaid-orders'));
        self::assertSame(0, $tester->execute([]));

        self::assertSame(OrderInterface::STATE_NEW, $this->reload($onAccount)->getState());
        self::assertSame(OrderInterface::STATE_CANCELLED, $this->reload($other)->getState());
    }

    public function testTheRecalculateCommandDetectsAndFixesDrift(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $account = $this->account($customer);
        $order = $this->order('completed', $method, $customer);
        $this->connection()->executeStatement('UPDATE tmonier_trade_credit_account SET committed_amount = 999 WHERE id = ?', [$account->getId()]);

        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('tmonier:trade-credit:recalculate'));
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('999', $tester->getDisplay());
        self::assertSame(999, $this->committed($account));

        self::assertSame(0, $tester->execute(['--apply' => true]));
        self::assertSame($order->getTotal(), $this->committed($account));
        self::assertSame(['type' => 'adjustment', 'amount' => $order->getTotal() - 999], $this->ledger($account)[1]);
    }

    private function applyPayment(PaymentInterface $payment, string $transition): void
    {
        /** @var StateMachineInterface $stateMachine */
        $stateMachine = $this->service('sylius_abstraction.state_machine');
        $stateMachine->apply($payment, PaymentTransitions::GRAPH, $transition);
        $this->entityManager()->flush();
    }
}
