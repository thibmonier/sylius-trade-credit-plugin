<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Twig;

use Psr\Clock\ClockInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Contracts\Service\ResetInterface;
use Tmonier\SyliusOrderHoldPlugin\AccountOwner\AccountOwner;
use Tmonier\SyliusTradeCreditPlugin\Account\TradeCreditAccountProvider;
use Tmonier\SyliusTradeCreditPlugin\Ageing\AgeingBuckets;
use Tmonier\SyliusTradeCreditPlugin\Credit\CreditPositionResolver;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\PaymentTermsPolicyInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccountInterface;
use Tmonier\SyliusTradeCreditPlugin\Repository\OrderPaymentTermsRepositoryInterface;
use Tmonier\SyliusTradeCreditPlugin\Repository\PaymentTermsPolicyRepositoryInterface;
use Tmonier\SyliusTradeCreditPlugin\Reservation\TradeCreditReserver;
use Tmonier\SyliusTradeCreditPlugin\Terms\LegalTermsChecker;
use Tmonier\SyliusTradeCreditPlugin\Terms\PaymentTerms;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class TradeCreditExtension extends AbstractExtension implements ResetInterface
{
    /** @var array<int, list<string|null>> ids of the left unpaid overdue items, per account object */
    private array $leftUnpaidIds = [];

    /** @param RepositoryInterface<CustomerInterface> $customerRepository */
    public function __construct(
        private readonly TradeCreditAccountProvider $accountProvider,
        private readonly OrderPaymentTermsRepositoryInterface $termsRepository,
        private readonly LegalTermsChecker $legalTermsChecker,
        private readonly ClockInterface $clock,
        private readonly RepositoryInterface $customerRepository,
        private readonly PaymentTermsPolicyRepositoryInterface $policyRepository,
        private readonly CreditPositionResolver $positionResolver,
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
            new TwigFunction('tmonier_trade_credit_position', $this->positionResolver->resolveForDisplay(...)),
            new TwigFunction('tmonier_trade_credit_ageing', $this->ageing(...)),
            new TwigFunction('tmonier_trade_credit_ageing_by_currency', $this->ageingByCurrency(...)),
        ];
    }

    /** Ageing of the open items of an account (with the left unpaid overdue items). */
    public function ageing(TradeCreditAccountInterface $account): AgeingBuckets
    {
        return AgeingBuckets::fromItems(
            array_map(static fn (OrderPaymentTermsInterface $terms): array => ['amount' => $terms->getAmount(), 'dueDate' => $terms->getDueDate()], $this->openItems($account)),
            $this->clock->now(),
        );
    }

    /** @return array<string, AgeingBuckets> ageing of every account, per currency */
    public function ageingByCurrency(): array
    {
        return $this->termsRepository->sumAgeingByCurrency($this->clock->now());
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

    public function reset(): void
    {
        $this->leftUnpaidIds = [];
    }

    /** @return list<OrderPaymentTermsInterface> */
    public function openItems(TradeCreditAccountInterface $account): array
    {
        return [...$this->termsRepository->findOpenByAccount($account), ...$this->termsRepository->findLeftUnpaidOverdueByAccount($account, $this->clock->now())];
    }

    public function isOverdue(OrderPaymentTermsInterface $terms): bool
    {
        $now = $this->clock->now();
        if ($terms->isOverdue($now)) {
            return true;
        }

        // Left unpaid after leaving payment on account: checked in PHP first (loaded order), then
        // one query per account and request (not one per grid row).
        $order = $terms->getOrder();
        $account = $terms->getAccount();
        if (
            OrderPaymentTermsInterface::STATE_RELEASED !== $terms->getState() ||
            !in_array($terms->getClosingReason(), TradeCreditReserver::LOST_RESERVATION_REASONS, true) ||
            $terms->getDueDate()->format('Y-m-d') >= $now->format('Y-m-d') ||
            !$order instanceof OrderInterface || OrderInterface::STATE_NEW !== $order->getState() ||
            in_array($order->getPaymentState(), [OrderPaymentStates::STATE_PAID, OrderPaymentStates::STATE_PARTIALLY_REFUNDED, OrderPaymentStates::STATE_REFUNDED], true) ||
            !$account instanceof \Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccountInterface
        ) {
            return false;
        }

        $key = spl_object_id($account);
        $this->leftUnpaidIds[$key] ??= array_map(
            static fn (OrderPaymentTermsInterface $leftUnpaid): ?string => $leftUnpaid->getId(),
            $this->termsRepository->findLeftUnpaidOverdueByAccount($account, $now),
        );

        return in_array($terms->getId(), $this->leftUnpaidIds[$key], true);
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
        if ($subject->getEndOfMonthMode() instanceof \Tmonier\SyliusTradeCreditPlugin\Terms\EndOfMonthMode) {
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
