<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTradeCreditPlugin\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Tmonier\SyliusTradeCreditPlugin\Functional\TradeCreditTestTrait;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\PaymentMethodEligibilityCheckerInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\PaymentTermsPolicyInterface;
use Tmonier\SyliusTradeCreditPlugin\Exception\TradeCreditReservationException;
use Tmonier\SyliusTradeCreditPlugin\Reservation\TradeCreditReserver;
use Tmonier\SyliusTradeCreditPlugin\Statement\StatementBuilder;

/** Regression tests of the review of 2026-10-12. */
final class ReviewFixesTest extends KernelTestCase
{
    use TradeCreditTestTrait;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->beginTransaction();
    }

    /** Rows committed by a second connection, deleted once the test transaction is rolled back. */
    private ?Connection $otherConnection = null;

    /** @var list<string> */
    private array $otherTermsIds = [];

    protected function tearDown(): void
    {
        $this->rollBack();
        if ($this->otherConnection instanceof \Doctrine\DBAL\Connection) {
            foreach ($this->otherTermsIds as $id) {
                $this->otherConnection->executeStatement('DELETE FROM tmonier_trade_credit_order_terms WHERE id = ?', [$id]);
            }
            $this->otherConnection->close();
            $this->otherConnection = null;
        }
        parent::tearDown();
    }

    /** A guest typing the e-mail of a customer with an account gets neither the method nor any detail. */
    public function testAGuestCheckoutWithTheEmailOfACustomerCannotUseItsCredit(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $account = $this->account($customer);
        $account->setCreditHold(true);
        $this->entityManager()->flush();
        $order = $this->order('payment_selected', $method, $customer, true);

        $payment = $order->getLastPayment(PaymentInterface::STATE_CART);
        self::assertNotNull($payment);
        $ineligibility = $this->checker()->getIneligibility($method, $payment);
        self::assertSame('tmonier_trade_credit.payment_method.no_account', $ineligibility?->getMessage(), 'Generic refusal, not "on hold"');
        self::assertSame([], $ineligibility->getParameters());

        $account->setCreditHold(false);
        $this->entityManager()->flush();
        $this->expectException(TradeCreditReservationException::class);
        $this->expectExceptionMessage('no_account');
        $this->apply($order, OrderCheckoutTransitions::TRANSITION_COMPLETE);
    }

    public function testDisablingThePolicyKeepsTheOpenItemsSettleableAndRefusesTheMethod(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $account = $this->account($customer);
        $order = $this->order('completed', $method, $customer);
        $this->policy($method)->setEnabled(false);
        $this->entityManager()->flush();
        $this->policyProvider()->reset();

        // Not a plain Offline method for new orders.
        $cart = $this->order('shipping_selected', null, $customer);
        $payment = $cart->getLastPayment(PaymentInterface::STATE_CART);
        self::assertNotNull($payment);
        // The signed-in customer is told the method is unavailable, not that they have no account.
        self::assertSame('tmonier_trade_credit.payment_method.unavailable', $this->checker()->getIneligibility($method, $payment)?->getMessage());

        // The open item is still settled when paid.
        $orderPayment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($orderPayment);
        $this->applyPayment($orderPayment, PaymentTransitions::TRANSITION_COMPLETE);
        self::assertSame([OrderPaymentTermsInterface::STATE_SETTLED], $this->termsStates($order));
        self::assertSame(0, $this->committed($account));
    }

    public function testDeletingThePaymentMethodPolicyStillLetsTheOrderCancelRelease(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $account = $this->account($customer);
        $order = $this->order('completed', $method, $customer);
        $orderPayment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($orderPayment);
        $this->entityManager()->remove($this->policy($method));
        $this->entityManager()->flush();
        $this->policyProvider()->reset();

        $this->applyPayment($orderPayment, PaymentTransitions::TRANSITION_CANCEL);

        self::assertSame([OrderPaymentTermsInterface::STATE_RELEASED], $this->termsStates($order));
        self::assertSame(0, $this->committed($account));
    }

    public function testSwitchingAwayAndBackKeepsTheDueDate(): void
    {
        $method = $this->onAccountMethod();
        $bankTransfer = $this->paymentMethod('Bank transfer');
        $customer = $this->customer();
        $this->account($customer)->setBlockWhenOverdue(false);
        $order = $this->order('completed', $method, $customer);
        $this->connection()->executeStatement('UPDATE tmonier_trade_credit_order_terms SET due_date = ? WHERE order_id = ?', ['2026-01-15', $order->getId()]);
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($payment);

        $payment->setMethod($bankTransfer);
        $this->entityManager()->flush();
        $payment->setMethod($method);
        $this->entityManager()->flush();

        self::assertSame('2026-01-15', $this->termsRepository()->findReservedForOrder($order)?->getDueDate()->format('Y-m-d'));
    }

    public function testAReReservationAfterAFailedPaymentKeepsTheTerms(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $this->account($customer);
        $order = $this->order('completed', $method, $customer);
        $this->connection()->executeStatement('UPDATE tmonier_trade_credit_order_terms SET due_date = ?, net_days = 30 WHERE order_id = ?', ['2026-11-01', $order->getId()]);
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($payment);

        $this->applyPayment($payment, PaymentTransitions::TRANSITION_FAIL);

        self::assertSame([OrderPaymentTermsInterface::STATE_RELEASED, OrderPaymentTermsInterface::STATE_RESERVED], $this->termsStates($order));
        self::assertSame('2026-11-01', $this->termsRepository()->findReservedForOrder($order)?->getDueDate()->format('Y-m-d'));
    }

    public function testSwitchingBetweenTwoMethodsOnAccountTakesTheNewTermsFromTheSameStart(): void
    {
        $net30 = $this->onAccountMethod(30);
        $net60 = $this->onAccountMethod(60);
        $customer = $this->customer();
        $account = $this->account($customer);
        // Just enough for one order: the switch must count the reservation it replaces.
        $order = $this->order('completed', $net30, $customer);
        $account->setCreditLimit($order->getTotal());
        $this->entityManager()->flush();
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($payment);
        self::assertNull($this->checker()->getIneligibility($net60, $payment));

        $payment->setMethod($net60);
        $this->entityManager()->flush();

        $terms = $this->termsRepository()->findReservedForOrder($order);
        self::assertNotNull($terms);
        self::assertSame($net60->getId(), $terms->getPaymentMethod()?->getId());
        self::assertSame(60, $terms->getNetDays());
        $start = \DateTimeImmutable::createFromInterface($order->getCheckoutCompletedAt() ?? new \DateTime())->setTime(0, 0);
        self::assertSame($start->modify('+60 days')->format('Y-m-d'), $terms->getDueDate()->format('Y-m-d'));
        self::assertSame($order->getTotal(), $this->committed($account));
        self::assertSame(['reservation', 'release', 'reservation'], array_column($this->ledger($account), 'type'));
    }

    public function testAnOrderNeverGetsTwoOpenReservations(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $account = $this->account($customer);
        $order = $this->order('completed', $method, $customer);

        // Same order completed twice (double submission): the second call sees the first reservation under the lock.
        $this->reserver()->reserve($order, $method);

        self::assertSame([OrderPaymentTermsInterface::STATE_RESERVED], $this->termsStates($order));
        self::assertSame($order->getTotal(), $this->committed($account));
    }

    public function testACartLeftWithAReservationIsReservedAgainWithItsCurrentTotal(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $account = $this->account($customer);
        $cart = $this->order('payment_selected', $method, $customer);
        // Reserved, then the save of the completed order failed: still a cart.
        $this->reserver()->reserve($cart, $method);
        $firstTotal = $cart->getTotal();
        /** @var \Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface $modifier */
        $modifier = $this->service('sylius.modifier.order_item_quantity');
        $item = $cart->getItems()->first();
        self::assertNotFalse($item);
        $modifier->modify($item, 5);
        /** @var \Sylius\Component\Order\Processor\OrderProcessorInterface $processor */
        $processor = $this->service('sylius.order_processing.order_processor');
        $processor->process($cart);
        $this->entityManager()->flush();
        self::assertGreaterThan($firstTotal, $cart->getTotal());

        $this->apply($cart, OrderCheckoutTransitions::TRANSITION_COMPLETE);
        $this->entityManager()->flush();

        self::assertSame([OrderPaymentTermsInterface::STATE_RELEASED, OrderPaymentTermsInterface::STATE_RESERVED], $this->termsStates($cart));
        self::assertSame($cart->getTotal(), $this->committed($account));
    }

    public function testTheRecalculateCommandReleasesReservationsLeftOnCarts(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $account = $this->account($customer);
        $cart = $this->order('payment_selected', $method, $customer);
        $this->reserver()->reserve($cart, $method);
        $this->connection()->executeStatement('UPDATE tmonier_trade_credit_order_terms SET created_at = ? WHERE order_id = ?', [(new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s'), $cart->getId()]);

        $this->command('tmonier:trade-credit:recalculate', ['--apply' => true]);

        self::assertSame([OrderPaymentTermsInterface::STATE_RELEASED], $this->termsStates($cart));
        self::assertSame(0, $this->committed($account));
    }

    /** The SQL guard of close() is what makes a release unique when two requests race. */
    public function testAReservationIsReleasedAtMostOnceEvenWithAStaleRow(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $account = $this->account($customer);
        $order = $this->order('completed', $method, $customer);
        $reserver = $this->reserver();
        $connection = $this->connection();
        $reservedRow = (new \ReflectionMethod($reserver, 'reservedRow'))->invoke($reserver, $connection, $order->getId());
        self::assertIsArray($reservedRow);

        self::assertTrue($reserver->close($order, OrderPaymentTermsInterface::STATE_RELEASED, TradeCreditReserver::REASON_ORDER_CANCELLED));
        // A concurrent request that read the row before the first release.
        $second = (new \ReflectionMethod($reserver, 'closeRow'))->invoke($reserver, $connection, $reservedRow, $order->getId(), OrderPaymentTermsInterface::STATE_SETTLED, TradeCreditReserver::REASON_PAID, new \DateTimeImmutable());

        self::assertFalse($second);
        self::assertSame(0, $this->committed($account));
        self::assertSame(['reservation', 'release'], array_column($this->ledger($account), 'type'));
    }

    public function testTheRecalculationUsesTheOpenItemsReadUnderTheLock(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $account = $this->account($customer);
        $order = $this->order('completed', $method, $customer);
        $this->connection()->executeStatement('UPDATE tmonier_trade_credit_account SET committed_amount = 1 WHERE id = ?', [$account->getId()]);

        self::assertSame(['committed' => 1, 'expected' => $order->getTotal()], $this->reserver()->recalculate($account, true));
        self::assertSame($order->getTotal(), $this->committed($account));
        self::assertNull($this->reserver()->recalculate($account, false));
    }

    public function testAnOrderThatLostItsReservationIsNotCancelledRightAway(): void
    {
        $method = $this->onAccountMethod();
        $bankTransfer = $this->paymentMethod('Bank transfer');
        $customer = $this->customer();
        $this->account($customer);
        $switched = $this->order('completed', $method, $customer);
        $old = $this->order('completed', $method, $customer);
        foreach ([$switched, $old] as $order) {
            $order->setCheckoutCompletedAt(new \DateTime('-40 days'));
            $order->getLastPayment(PaymentInterface::STATE_NEW)?->setMethod($bankTransfer);
        }
        $this->entityManager()->flush();
        $this->connection()->executeStatement('UPDATE tmonier_trade_credit_order_terms SET closed_at = ? WHERE order_id = ?', [(new \DateTimeImmutable('-10 days'))->format('Y-m-d H:i:s'), $old->getId()]);

        $this->command('sylius:cancel-unpaid-orders', []);

        self::assertSame(OrderInterface::STATE_NEW, $this->reload($switched)->getState());
        self::assertSame(OrderInterface::STATE_CANCELLED, $this->reload($old)->getState());
    }

    /** Released before the core creates the next payment: the kernel replaces a method on account that is no longer eligible. */
    public function testTheNextPaymentDoesNotKeepAMethodOnAccountBeyondTheLimit(): void
    {
        $method = $this->onAccountMethod();
        $this->paymentMethod('Bank transfer');
        $customer = $this->customer();
        $account = $this->account($customer);
        $order = $this->order('completed', $method, $customer);
        $account->setCreditLimit(1);
        $this->entityManager()->flush();
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($payment);

        $this->applyPayment($payment, PaymentTransitions::TRANSITION_FAIL);

        $newPayment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($newPayment);
        self::assertNotSame($payment, $newPayment);
        self::assertNotSame($method, $newPayment->getMethod());
        self::assertSame('tmonier_trade_credit.payment_method.limit_exceeded', $this->checker()->getIneligibility($method, $newPayment)?->getMessage());
        self::assertSame([OrderPaymentTermsInterface::STATE_RELEASED], $this->termsStates($order));
        self::assertSame(0, $this->committed($account));
    }

    /**
     * A concurrent request (second connection) reserved the order and committed after this
     * transaction read its snapshot (API bus transaction, MySQL REPEATABLE READ): the re-check
     * under the lock must still see it.
     */
    public function testAReservationCommittedByAConcurrentRequestIsSeenUnderTheLock(): void
    {
        $method = $this->onAccountMethod();
        $bankTransfer = $this->paymentMethod('Bank transfer');
        $customer = $this->customer();
        $account = $this->account($customer);
        $order = $this->order('completed', $bankTransfer, $customer);
        $connection = $this->connection();
        // Snapshot of this transaction.
        $connection->fetchOne('SELECT COUNT(*) FROM tmonier_trade_credit_order_terms');

        $other = DriverManager::getConnection($connection->getParams());
        $this->otherConnection = $other;
        // The order and the account only exist in the (uncommitted) test transaction.
        $other->executeStatement($other->getDatabasePlatform() instanceof PostgreSQLPlatform ? 'SET session_replication_role = replica' : 'SET FOREIGN_KEY_CHECKS = 0');
        $id = \Symfony\Component\Uid\Uuid::v7()->toRfc4122();
        $this->otherTermsIds[] = $id;
        $other->insert('tmonier_trade_credit_order_terms', [
            'id' => $id,
            'order_id' => $order->getId(),
            'account_id' => $account->getId(),
            'payment_method_id' => $method->getId(),
            'amount' => $order->getTotal(),
            'currency_code' => (string) $order->getCurrencyCode(),
            'net_days' => 30,
            'end_of_month_mode' => 'none',
            'due_date' => '2030-01-01',
            'state' => OrderPaymentTermsInterface::STATE_RESERVED,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $this->reserver()->reserve($order, $method);

        $reserved = $connection->fetchFirstColumn('SELECT id FROM tmonier_trade_credit_order_terms WHERE order_id = ? AND state = ? FOR UPDATE', [$order->getId(), OrderPaymentTermsInterface::STATE_RESERVED]);
        self::assertSame([$id], $reserved, 'One open reservation per order');
        self::assertSame(0, $this->committed($account), 'Nothing reserved twice');
    }

    /** Switching an overdue order to another method gives the customer no new credit. */
    public function testSwitchingAwayFromPaymentOnAccountDoesNotClearAnOverdueItem(): void
    {
        $method = $this->onAccountMethod();
        $bankTransfer = $this->paymentMethod('Bank transfer');
        $customer = $this->customer();
        $account = $this->account($customer);
        $account->setBlockWhenOverdue(true);
        $order = $this->order('completed', $method, $customer);
        $this->connection()->executeStatement('UPDATE tmonier_trade_credit_order_terms SET due_date = ? WHERE order_id = ?', [(new \DateTimeImmutable('-3 days'))->format('Y-m-d'), $order->getId()]);
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($payment);

        $payment->setMethod($bankTransfer);
        $this->entityManager()->flush();
        self::assertSame([OrderPaymentTermsInterface::STATE_RELEASED], $this->termsStates($order));

        $cart = $this->order('shipping_selected', null, $customer);
        $cartPayment = $cart->getLastPayment(PaymentInterface::STATE_CART);
        self::assertNotNull($cartPayment);
        self::assertSame('tmonier_trade_credit.payment_method.overdue', $this->checker()->getIneligibility($method, $cartPayment)?->getMessage());

        // Once the order is paid, the account is no longer blocked.
        $this->applyPayment($payment, PaymentTransitions::TRANSITION_COMPLETE);
        self::assertNull($this->checker()->getIneligibility($method, $cartPayment));
    }

    /** Shared (company-owned) account: orders of other buyers are listed without link nor invoice. */
    public function testTheStatementOfASharedAccountDoesNotLinkTheOrdersOfOtherBuyers(): void
    {
        $method = $this->onAccountMethod();
        $viewer = $this->customer();
        $account = $this->account($viewer);
        $colleague = $this->customer();
        $colleague->setFirstName('Grace');
        $this->account($colleague);
        $own = $this->order('completed', $method, $viewer);
        $theirs = $this->order('completed', $method, $colleague);
        // As if both customers resolved to the same owner (company accounts).
        $this->connection()->executeStatement('UPDATE tmonier_trade_credit_order_terms SET account_id = ? WHERE order_id = ?', [$account->getId(), $theirs->getId()]);
        $this->entityManager()->clear();
        $viewer = $this->entityManager()->find(\Sylius\Component\Core\Model\CustomerInterface::class, $viewer->getId());
        self::assertInstanceOf(\Sylius\Component\Core\Model\CustomerInterface::class, $viewer);

        $builder = $this->service('tmonier_trade_credit.statement_builder');
        self::assertInstanceOf(StatementBuilder::class, $builder);
        $statement = $builder->forCustomer($viewer);
        self::assertNotNull($statement);
        $items = [];
        foreach ($statement->items as $item) {
            $items[$item->orderNumber] = $item;
        }

        self::assertTrue($items[(string) $own->getNumber()]->placedByViewer);
        self::assertNull($items[(string) $own->getNumber()]->buyerName);
        self::assertFalse($items[(string) $theirs->getNumber()]->placedByViewer);
        self::assertNull($items[(string) $theirs->getNumber()]->invoiceUrl);
        self::assertSame('Grace Buyer', $items[(string) $theirs->getNumber()]->buyerName);
    }

    public function testAmountsAboveTwoBillionMinorUnits(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $account = $this->account($customer, 5_000_000_000);
        $this->connection()->executeStatement('UPDATE tmonier_trade_credit_account SET committed_amount = 3000000000 WHERE id = ?', [$account->getId()]);

        $order = $this->order('completed', $method, $customer);

        self::assertSame(3_000_000_000 + $order->getTotal(), $this->committed($account));
        $this->entityManager()->refresh($account);
        self::assertSame(5_000_000_000 - 3_000_000_000 - $order->getTotal(), $account->getAvailableAmount());
    }

    private function policy(\Sylius\Component\Core\Model\PaymentMethodInterface $method): PaymentTermsPolicyInterface
    {
        $policy = $this->entityManager()->getRepository(PaymentTermsPolicyInterface::class)->findOneBy(['paymentMethod' => $method]);
        self::assertInstanceOf(PaymentTermsPolicyInterface::class, $policy);

        return $policy;
    }

    private function checker(): PaymentMethodEligibilityCheckerInterface
    {
        $checker = $this->service(PaymentMethodEligibilityCheckerInterface::class);
        self::assertInstanceOf(PaymentMethodEligibilityCheckerInterface::class, $checker);

        return $checker;
    }

    private function applyPayment(PaymentInterface $payment, string $transition): void
    {
        /** @var StateMachineInterface $stateMachine */
        $stateMachine = $this->service('sylius_abstraction.state_machine');
        $stateMachine->apply($payment, PaymentTransitions::GRAPH, $transition);
        $this->entityManager()->flush();
    }

    /** @param array<string, mixed> $input */
    private function command(string $name, array $input): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        (new CommandTester((new Application($kernel))->find($name)))->execute($input);
    }
}
