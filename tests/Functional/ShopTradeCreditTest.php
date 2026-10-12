<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTradeCreditPlugin\Functional;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class ShopTradeCreditTest extends WebTestCase
{
    use TradeCreditTestTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->rollBack();
        parent::tearDown();
    }

    public function testThePaymentStepOffersPaymentOnAccountWithinTheLimit(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer(true);
        $this->account($customer);
        $this->order('shipping_selected', null, $customer);
        $this->login($customer);

        $this->client->request('GET', sprintf('/%s/checkout/select-payment', $this->localeCode()));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString((string) $method->getName(), (string) $this->client->getResponse()->getContent());
    }

    public function testThePaymentStepHidesPaymentOnAccountAboveTheLimit(): void
    {
        $method = $this->onAccountMethod();
        $this->paymentMethod('Bank transfer');
        $customer = $this->customer(true);
        $this->account($customer, 100);
        $this->order('shipping_selected', null, $customer);
        $this->login($customer);

        $this->client->request('GET', sprintf('/%s/checkout/select-payment', $this->localeCode()));

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString((string) $method->getName(), (string) $this->client->getResponse()->getContent());
    }

    public function testCompletionShowsWhyPaymentOnAccountIsNoLongerAvailable(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer(true);
        $account = $this->account($customer);
        $order = $this->order('payment_selected', $method, $customer);
        $account->setCreditLimit(100);
        $this->entityManager()->flush();
        $this->login($customer);

        $crawler = $this->client->request('GET', sprintf('/%s/checkout/complete', $this->localeCode()));
        $this->client->submit($crawler->filter('form[action$="/checkout/complete"]')->form());

        self::assertStringContainsString('The order total exceeds the credit available for', (string) $this->client->getResponse()->getContent());
        self::assertSame('payment_selected', $this->reload($order)->getCheckoutState());
        self::assertSame(0, $this->committed($account));
    }

    /** The limit is reached by another request between validation and reservation: no 500. */
    public function testARefusedReservationRedirectsWithAMessage(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer(true);
        $account = $this->account($customer);
        $order = $this->order('payment_selected', $method, $customer);
        $this->login($customer);
        $crawler = $this->client->request('GET', sprintf('/%s/checkout/complete', $this->localeCode()));
        // Another request uses the whole credit after the validation of this one.
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $connection = $this->connection();
        $accountId = $account->getId();
        $dispatcher->addListener('workflow.sylius_order_checkout.transition.complete', static function () use ($connection, $accountId): void {
            $connection->executeStatement('UPDATE tmonier_trade_credit_account SET committed_amount = credit_limit WHERE id = ?', [$accountId]);
        }, 100);

        $this->client->submit($crawler->filter('form[action$="/checkout/complete"]')->form());

        self::assertResponseRedirects(sprintf('/%s/checkout/complete', $this->localeCode()));
        $this->client->followRedirect();
        self::assertStringContainsString('The order total exceeds your available credit', (string) $this->client->getResponse()->getContent());
        self::assertSame([], $this->termsStates($order));
    }

    public function testAnOrderOnAccountIsNotSentToAPayPage(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer(true);
        $this->account($customer);
        $order = $this->order('completed', $method, $customer);
        $terms = $this->termsRepository()->findReservedForOrder($order);
        self::assertNotNull($terms);

        $this->client->request('GET', sprintf('/%s/order/%s/pay', $this->localeCode(), (string) $order->getTokenValue()));

        self::assertResponseRedirects(sprintf('/%s/order/thank-you', $this->localeCode()));
        $this->client->followRedirect();
        self::assertStringContainsString(
            sprintf('This order is paid on account: please pay it by bank transfer before %s.', $terms->getDueDate()->format('Y-m-d')),
            (string) $this->client->getResponse()->getContent(),
        );
    }

    public function testTheStatementShowsTheAccountAndItsOpenItems(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer(true);
        $account = $this->account($customer, 100000);
        $open = $this->order('completed', $method, $customer);
        $overdue = $this->order('completed', $method, $customer);
        $this->connection()->executeStatement('UPDATE tmonier_trade_credit_order_terms SET due_date = ? WHERE order_id = ?', [(new \DateTimeImmutable('-5 days'))->format('Y-m-d'), $overdue->getId()]);
        $this->entityManager()->clear();
        $this->login($customer);

        $crawler = $this->client->request('GET', sprintf('/%s/account/trade-credit', $this->localeCode()));

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-test-tmonier-open-item]'));
        self::assertCount(1, $crawler->filter(sprintf('[data-test-tmonier-open-item="%s"] .badge', (string) $overdue->getNumber())));
        self::assertCount(0, $crawler->filter(sprintf('[data-test-tmonier-open-item="%s"] .badge', (string) $open->getNumber())));
        self::assertCount(1, $crawler->filter('[data-test-tmonier-overdue-alert]'));
        self::assertStringContainsString('1,000.00', $crawler->filter('[data-test-tmonier-credit-limit]')->text());
        self::assertSame($account->getId(), $this->termsRepository()->findReservedForOrder($open)?->getAccount()?->getId());
        self::assertStringContainsString('Trade credit', $crawler->filter('body')->text());
    }

    public function testTheStatementOfACustomerWithoutAccount(): void
    {
        $customer = $this->customer(true);
        $this->login($customer);

        $crawler = $this->client->request('GET', sprintf('/%s/account/trade-credit', $this->localeCode()));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-test-tmonier-trade-credit-none]'));
    }

    public function testTheStatementRequiresASignedInCustomer(): void
    {
        $this->client->request('GET', sprintf('/%s/account/trade-credit', $this->localeCode()));

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    private function login(CustomerInterface $customer): void
    {
        $user = $customer->getUser();
        self::assertInstanceOf(ShopUserInterface::class, $user);
        $this->entityManager()->flush();
        $this->client->loginUser($user, 'shop');
    }
}
