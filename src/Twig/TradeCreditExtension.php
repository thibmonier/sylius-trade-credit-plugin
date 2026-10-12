<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Twig;

use Psr\Clock\ClockInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Tmonier\SyliusOrderHoldPlugin\AccountOwner\AccountOwner;
use Tmonier\SyliusTradeCreditPlugin\Account\TradeCreditAccountProvider;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\PaymentTermsPolicyInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccountInterface;
use Tmonier\SyliusTradeCreditPlugin\Repository\OrderPaymentTermsRepositoryInterface;
use Tmonier\SyliusTradeCreditPlugin\Terms\LegalTermsChecker;
use Tmonier\SyliusTradeCreditPlugin\Terms\PaymentTerms;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class TradeCreditExtension extends AbstractExtension
{
    /** @param RepositoryInterface<CustomerInterface> $customerRepository */
    public function __construct(
        private readonly TradeCreditAccountProvider $accountProvider,
        private readonly OrderPaymentTermsRepositoryInterface $termsRepository,
        private readonly LegalTermsChecker $legalTermsChecker,
        private readonly ClockInterface $clock,
        private readonly RepositoryInterface $customerRepository,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('tmonier_trade_credit_account', $this->account(...)),
            new TwigFunction('tmonier_trade_credit_open_items', $this->openItems(...)),
            new TwigFunction('tmonier_trade_credit_is_overdue', $this->isOverdue(...)),
            new TwigFunction('tmonier_trade_credit_legal_warning', $this->legalWarning(...)),
            new TwigFunction('tmonier_trade_credit_legal_parameters', $this->legalParameters(...)),
            new TwigFunction('tmonier_trade_credit_owner_customer', $this->ownerCustomer(...)),
        ];
    }

    /** Translation parameters of the legal warnings. @return array<string, int> */
    public function legalParameters(?string $channelCode = null): array
    {
        $limits = $this->legalTermsChecker->limits($channelCode);

        return ['%net_days%' => $limits['net_days'], '%end_of_month_days%' => $limits['end_of_month_days']];
    }

    /** The customer of an account owned by a customer (default owner model). */
    public function ownerCustomer(TradeCreditAccountInterface $account): ?CustomerInterface
    {
        if (AccountOwner::TYPE_CUSTOMER !== $account->getOwnerType()) {
            return null;
        }
        $customer = $this->customerRepository->find($account->getOwnerIdentifier());

        return $customer instanceof CustomerInterface ? $customer : null;
    }

    public function account(CustomerInterface $customer): ?TradeCreditAccountInterface
    {
        return $this->accountProvider->forCustomer($customer);
    }

    /** @return list<OrderPaymentTermsInterface> */
    public function openItems(TradeCreditAccountInterface $account): array
    {
        return $this->termsRepository->findOpenByAccount($account);
    }

    public function isOverdue(OrderPaymentTermsInterface $terms): bool
    {
        return $terms->isOverdue($this->clock->now());
    }

    /** Translation key of the legal warning of an account (own terms only) or a policy, if any. */
    public function legalWarning(TradeCreditAccountInterface|PaymentTermsPolicyInterface $subject): ?string
    {
        if ($subject instanceof PaymentTermsPolicyInterface) {
            return $this->legalTermsChecker->warning($subject->getTerms());
        }

        $netDays = $subject->getNetDays();

        return null === $netDays ? null : $this->legalTermsChecker->warning($subject->getTerms(new PaymentTerms($netDays)));
    }
}
