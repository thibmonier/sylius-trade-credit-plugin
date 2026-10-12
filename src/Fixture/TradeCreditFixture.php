<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Fixture;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\FixturesBundle\Fixture\AbstractFixture;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Core\Factory\PaymentMethodFactoryInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Modifier\OrderModifierInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Tmonier\SyliusOrderHoldPlugin\AccountOwner\AccountOwner;
use Tmonier\SyliusTradeCreditPlugin\Entity\PaymentTermsPolicyInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccountInterface;
use Tmonier\SyliusTradeCreditPlugin\Factory\TradeCreditAccountFactory;

/**
 * Demo data: an "On account (net 30)" Offline payment method with its policy, a verified B2B
 * customer (`b2b@example.com` / `sylius`, company "Acme Industries") with a trade credit account
 * and two orders paid on account, one of them overdue. Also verifies the shop users of the
 * fixtures (the demo channel requires a verified account to sign in).
 */
final class TradeCreditFixture extends AbstractFixture
{
    public const PAYMENT_METHOD_CODE = 'tmonier_on_account';

    public const CUSTOMER_EMAIL = 'b2b@example.com';

    /**
     * @param PaymentMethodFactoryInterface<PaymentMethodInterface> $paymentMethodFactory
     * @param FactoryInterface<PaymentTermsPolicyInterface> $policyFactory
     * @param FactoryInterface<CustomerInterface> $customerFactory
     * @param FactoryInterface<ShopUserInterface> $shopUserFactory
     * @param FactoryInterface<OrderInterface> $orderFactory
     * @param FactoryInterface<OrderItemInterface> $orderItemFactory
     * @param FactoryInterface<AddressInterface> $addressFactory
     * @param class-string $termsClass
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentMethodFactoryInterface $paymentMethodFactory,
        private readonly FactoryInterface $policyFactory,
        private readonly TradeCreditAccountFactory $accountFactory,
        private readonly FactoryInterface $customerFactory,
        private readonly FactoryInterface $shopUserFactory,
        private readonly FactoryInterface $orderFactory,
        private readonly FactoryInterface $orderItemFactory,
        private readonly FactoryInterface $addressFactory,
        private readonly OrderItemQuantityModifierInterface $quantityModifier,
        private readonly OrderModifierInterface $orderModifier,
        private readonly StateMachineInterface $stateMachine,
        private readonly string $termsClass,
    ) {
    }

    public function getName(): string
    {
        return 'tmonier_trade_credit';
    }

    /** @param array{credit_limit: int, verify_shop_users: bool} $options */
    public function load(array $options): void
    {
        if ($options['verify_shop_users']) {
            /** @var list<ShopUserInterface> $users */
            $users = $this->entityManager->getRepository(ShopUserInterface::class)->findBy(['verifiedAt' => null]);
            foreach ($users as $user) {
                $user->setVerifiedAt(new \DateTime());
            }
        }

        /** @var list<ChannelInterface> $channels */
        $channels = $this->entityManager->getRepository(ChannelInterface::class)->findBy(['enabled' => true]);
        $channel = $channels[0] ?? null;
        if (null === $channel) {
            $this->entityManager->flush();

            return;
        }

        $method = $this->paymentMethod($channels);
        $customer = $this->customer();
        $account = $this->accountFactory->createNew();
        $account->setOwner(AccountOwner::TYPE_CUSTOMER, (string) $customer->getId());
        $account->setCurrencyCode($channel->getBaseCurrency()?->getCode());
        $account->setCreditLimit($options['credit_limit']);
        $account->setBlockWhenOverdue(false);
        $account->setNote('Demo account (tmonier_trade_credit fixture).');
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        $old = $this->order($channel, $customer, $method);
        $this->order($channel, $customer, $method);
        if (null !== $old) {
            // Overdue demo item: placed 50 days ago, due 20 days ago.
            $old->setCheckoutCompletedAt(new \DateTime('-50 days'));
            $this->entityManager->flush();
            $this->entityManager->getConnection()->executeStatement(
                sprintf('UPDATE %s SET due_date = ? WHERE order_id = ?', $this->entityManager->getClassMetadata($this->termsClass)->getTableName()),
                [(new \DateTimeImmutable('-20 days'))->format('Y-m-d'), $old->getId()],
            );
        }

        $this->entityManager->refresh($account);
    }

    protected function configureOptionsNode(ArrayNodeDefinition $optionsNode): void
    {
        $optionsNode
            ->children()
                ->integerNode('credit_limit')->defaultValue(500000)->end()
                ->booleanNode('verify_shop_users')->defaultTrue()->end()
            ->end()
        ;
    }

    /** @param list<ChannelInterface> $channels */
    private function paymentMethod(array $channels): PaymentMethodInterface
    {
        $method = $this->paymentMethodFactory->createWithGateway('offline');
        $method->setCode(self::PAYMENT_METHOD_CODE);
        $method->getGatewayConfig()?->setGatewayName(self::PAYMENT_METHOD_CODE);
        foreach (['en_US' => 'On account (net 30)', 'fr_FR' => 'Paiement à 30 jours', 'de_DE' => 'Auf Rechnung (30 Tage netto)'] as $locale => $name) {
            $method->setCurrentLocale($locale);
            $method->setFallbackLocale($locale);
            $method->setName($name);
            $method->setInstructions('en_US' === $locale ? 'Pay by bank transfer before the due date shown in your account.' : null);
        }
        foreach ($channels as $channel) {
            $method->addChannel($channel);
        }
        $method->setEnabled(true);
        $method->setPosition(100);
        $this->entityManager->persist($method);

        $policy = $this->policyFactory->createNew();
        $policy->setPaymentMethod($method);
        $policy->setNetDays(30);
        $this->entityManager->persist($policy);

        return $method;
    }

    private function customer(): CustomerInterface
    {
        $customer = $this->customerFactory->createNew();
        $customer->setEmail(self::CUSTOMER_EMAIL);
        $customer->setFirstName('Ada');
        $customer->setLastName('Buyer');

        $user = $this->shopUserFactory->createNew();
        $user->setPlainPassword('sylius');
        $user->setEnabled(true);
        $user->setVerifiedAt(new \DateTime());
        $customer->setUser($user);

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }

    private function order(ChannelInterface $channel, CustomerInterface $customer, PaymentMethodInterface $method): ?OrderInterface
    {
        $variant = $this->entityManager->createQueryBuilder()
            ->select('v')
            ->from(ProductVariantInterface::class, 'v')
            ->innerJoin('v.product', 'p')
            ->innerJoin('p.channels', 'c')
            ->andWhere('c = :channel')
            ->andWhere('v.enabled = true')
            ->andWhere('p.enabled = true')
            ->setParameter('channel', $channel)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;
        $countryCode = $this->shippableCountry($channel);
        if (!$variant instanceof ProductVariantInterface || null === $countryCode) {
            return null;
        }

        $order = $this->orderFactory->createNew();
        $order->setChannel($channel);
        $order->setLocaleCode((string) $channel->getDefaultLocale()?->getCode());
        $order->setCurrencyCode((string) $channel->getBaseCurrency()?->getCode());
        $order->setCustomerWithAuthorization($customer);
        $item = $this->orderItemFactory->createNew();
        $item->setVariant($variant);
        $this->quantityModifier->modify($item, 2);
        $this->orderModifier->addToOrder($order, $item);

        $address = $this->addressFactory->createNew();
        $address->setFirstName('Ada');
        $address->setLastName('Buyer');
        $address->setCompany('Acme Industries');
        $address->setStreet('12 rue de la Paix');
        $address->setCity('Paris');
        $address->setPostcode('75002');
        $address->setCountryCode($countryCode);
        $order->setShippingAddress($address);
        $order->setBillingAddress(clone $address);
        $this->entityManager->persist($order);

        foreach ([OrderCheckoutTransitions::TRANSITION_ADDRESS, OrderCheckoutTransitions::TRANSITION_SELECT_SHIPPING] as $transition) {
            if (!$this->stateMachine->can($order, OrderCheckoutTransitions::GRAPH, $transition)) {
                return null;
            }
            $this->stateMachine->apply($order, OrderCheckoutTransitions::GRAPH, $transition);
        }
        $order->getLastPayment(PaymentInterface::STATE_CART)?->setMethod($method);
        $this->stateMachine->apply($order, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_SELECT_PAYMENT);
        $this->entityManager->flush();
        $this->stateMachine->apply($order, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_COMPLETE);
        $this->entityManager->flush();

        return $order;
    }

    /** A country of the zone of an enabled shipping method of the channel. */
    private function shippableCountry(ChannelInterface $channel): ?string
    {
        /** @var list<ShippingMethodInterface> $methods */
        $methods = $this->entityManager->getRepository(ShippingMethodInterface::class)->findBy(['enabled' => true]);
        foreach ($methods as $method) {
            $zone = $method->getZone();
            if (!$method->hasChannel($channel) || !$zone instanceof ZoneInterface || ZoneInterface::TYPE_COUNTRY !== $zone->getType()) {
                continue;
            }
            foreach ($zone->getMembers() as $member) {
                if (null !== $member->getCode()) {
                    return $member->getCode();
                }
            }
        }

        return null;
    }
}
