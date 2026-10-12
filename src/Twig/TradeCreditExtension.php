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
use Tmonier\SyliusTradeCreditPlugin\Repository\PaymentTermsPolicyRepositoryInterface;
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
        private readonly PaymentTermsPolicyRepositoryInterface $policyRepository,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('tmonier_trade_credit_account', $this->account(...)),
            new TwigFunction('tmonier_trade_credit_open_items', $this->openItems(...)),
            new TwigFunction('tmonier_trade_credit_is_overdue', $this->isOverdue(...)),
            new TwigFunction('tmonier_trade_credit_legal_warning', $this->legalWarning(...)),
            new TwigFunction('tmonier_trade_credit_owner_customer', $this->ownerCustomer(...)),
        ];
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

    /**
     * Legal warning of a policy (channels of its payment method) or of an account with its own
     * net days (end-of-month mode of each enabled policy when the account has none).
     *
     * @return array{message: string, parameters: array<string, int|string>}|null
     */
    public function legalWarning(TradeCreditAccountInterface|PaymentTermsPolicyInterface $subject): ?array
    {
        if ($subject instanceof PaymentTermsPolicyInterface) {
            return $this->legalTermsChecker->warning($subject->getTerms(), $this->channelCodes($subject));
        }

        $netDays = $subject->getNetDays();
        if (null === $netDays) {
            return null;
        }
        if (null !== $subject->getEndOfMonthMode()) {
            return $this->legalTermsChecker->warning($subject->getTerms(new PaymentTerms($netDays)));
        }

        $policies = array_filter($this->policyRepository->findAll(), static fn (PaymentTermsPolicyInterface $policy): bool => $policy->isEnabled());
        foreach ($policies as $policy) {
            $warning = $this->legalTermsChecker->warning($subject->getTerms($policy->getTerms()), $this->channelCodes($policy));
            if (null !== $warning) {
                return $warning;
            }
        }

        return [] === $policies ? $this->legalTermsChecker->warning(new PaymentTerms($netDays)) : null;
    }

    /** @return list<string> */
    private function channelCodes(PaymentTermsPolicyInterface $policy): array
    {
        $codes = [];
        foreach ($policy->getPaymentMethod()?->getChannels() ?? [] as $channel) {
            if (null !== $channel->getCode()) {
                $codes[] = $channel->getCode();
            }
        }

        return $codes;
    }
}
