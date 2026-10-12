<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTradeCreditPlugin\Functional;

use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\PaymentTermsPolicyInterface;
use Tmonier\SyliusTradeCreditPlugin\Repository\TradeCreditAccountRepositoryInterface;

final class AdminTradeCreditTest extends WebTestCase
{
    use TradeCreditTestTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->beginTransaction();
        $admin = $this->entityManager()->getRepository(AdminUserInterface::class)->findOneBy(['username' => 'sylius']);
        self::assertInstanceOf(AdminUserInterface::class, $admin);
        $this->client->loginUser($admin, 'admin');
    }

    protected function tearDown(): void
    {
        $this->rollBack();
        parent::tearDown();
    }

    public function testAnAccountIsOpenedFromTheCustomerPage(): void
    {
        $customer = $this->customer();
        $this->entityManager()->flush();

        $crawler = $this->client->request('GET', sprintf('/admin/customers/%s', self::idOf($customer)));
        self::assertResponseIsSuccessful();
        $link = $crawler->filter('[data-test-tmonier-open-account]');
        self::assertCount(1, $link);

        $crawler = $this->client->request('GET', (string) $link->attr('href'));
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="tmonier_trade_credit_account"]')->form([
            'tmonier_trade_credit_account[creditLimit]' => '2500.00',
            'tmonier_trade_credit_account[netDays]' => '90',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects(sprintf('/admin/customers/%s', self::idOf($customer)));
        $repository = $this->service('tmonier_trade_credit.repository.account');
        self::assertInstanceOf(TradeCreditAccountRepositoryInterface::class, $repository);
        $account = $repository->findOneByOwner('customer', self::idOf($customer));
        self::assertNotNull($account);
        self::assertSame(250000, $account->getCreditLimit());
        self::assertSame(90, $account->getNetDays());

        $crawler = $this->client->request('GET', sprintf('/admin/customers/%s', self::idOf($customer)));
        self::assertStringContainsString('2,500.00', $crawler->filter('[data-test-tmonier-available]')->text());
        self::assertStringContainsString('90 days', $crawler->filter('[data-test-tmonier-trade-credit-card]')->text());

        // Legal warning on the account page (90 days > 60 days).
        $crawler = $this->client->request('GET', sprintf('/admin/trade-credit/accounts/%s/edit', self::idOf($account)));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-test-tmonier-legal-warning]'));
    }

    public function testOpenItemsCanBeFilteredAndMarkedAsPaid(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $account = $this->account($customer);
        $open = $this->order('completed', $method, $customer);
        $overdue = $this->order('completed', $method, $customer);
        $this->connection()->executeStatement('UPDATE tmonier_trade_credit_order_terms SET due_date = ? WHERE order_id = ?', [(new \DateTimeImmutable('-2 days'))->format('Y-m-d'), $overdue->getId()]);

        $crawler = $this->client->request('GET', '/admin/trade-credit/open-items');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(sprintf('[data-test-tmonier-mark-as-paid="%s"]', (string) $open->getNumber())));
        self::assertCount(1, $crawler->filter(sprintf('[data-test-tmonier-mark-as-paid="%s"]', (string) $overdue->getNumber())));

        $crawler = $this->client->request('GET', '/admin/trade-credit/open-items?criteria[overdue]=true');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter(sprintf('[data-test-tmonier-mark-as-paid="%s"]', (string) $open->getNumber())));
        $markAsPaid = $crawler->filter(sprintf('[data-test-tmonier-mark-as-paid="%s"]', (string) $overdue->getNumber()));
        self::assertCount(1, $markAsPaid);

        $this->client->submit($markAsPaid->form());
        self::assertResponseRedirects('/admin/trade-credit/open-items');

        self::assertSame([OrderPaymentTermsInterface::STATE_SETTLED], $this->termsStates($overdue));
        self::assertSame('completed', $this->connection()->fetchOne('SELECT state FROM sylius_payment WHERE order_id = ? ORDER BY id DESC', [$overdue->getId()]));
        self::assertSame($open->getTotal(), $this->committed($account));

        $crawler = $this->client->request('GET', sprintf('/admin/trade-credit/accounts/%s/ledger', self::idOf($account)));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Payment', $crawler->filter('body')->text());
    }

    public function testMarkAsPaidNeedsAValidToken(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $this->account($customer);
        $order = $this->order('completed', $method, $customer);
        $terms = $this->termsRepository()->findReservedForOrder($order);
        self::assertNotNull($terms);

        $this->client->request('POST', sprintf('/admin/trade-credit/open-items/%s/mark-as-paid', self::idOf($terms)), ['_csrf_token' => 'wrong']);

        self::assertResponseRedirects('/admin/trade-credit/open-items');
        self::assertSame([OrderPaymentTermsInterface::STATE_RESERVED], $this->termsStates($order));
        self::assertSame(PaymentInterface::STATE_NEW, $order->getLastPayment()?->getState());
    }

    public function testPaymentTermsPoliciesAndAccountsAreListed(): void
    {
        $method = $this->onAccountMethod(75);
        $customer = $this->customer();
        $this->account($customer);

        $crawler = $this->client->request('GET', '/admin/trade-credit/payment-terms-policies/');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString((string) $method->getCode(), $crawler->filter('body')->text());
        self::assertStringContainsString('L441-10', $crawler->filter('body')->text());

        $this->client->request('GET', '/admin/trade-credit/payment-terms-policies/new');
        self::assertResponseIsSuccessful();

        $crawler = $this->client->request('GET', '/admin/trade-credit/accounts/');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString((string) $customer->getEmail(), $crawler->filter('body')->text());
        self::assertGreaterThan(0, $crawler->filter('[data-test-tmonier-ledger-link]')->count());
    }

    public function testAPolicyIsCreatedForAnOfflineMethod(): void
    {
        $method = $this->paymentMethod('Pay later');
        $this->entityManager()->flush();

        $crawler = $this->client->request('GET', '/admin/trade-credit/payment-terms-policies/new');
        $form = $crawler->filter('form[name="tmonier_trade_credit_payment_terms_policy"]')->form([
            'tmonier_trade_credit_payment_terms_policy[paymentMethod]' => self::idOf($method),
            'tmonier_trade_credit_payment_terms_policy[netDays]' => '45',
            'tmonier_trade_credit_payment_terms_policy[endOfMonthMode]' => 'days_then_end_of_month',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects();
        self::assertStringStartsWith('/admin/trade-credit/payment-terms-policies/', (string) $this->client->getResponse()->headers->get('Location'));
        $policy = $this->entityManager()->getRepository(PaymentTermsPolicyInterface::class)->findOneBy(['paymentMethod' => $method]);
        self::assertInstanceOf(PaymentTermsPolicyInterface::class, $policy);
        self::assertSame(45, $policy->getNetDays());
    }
}
