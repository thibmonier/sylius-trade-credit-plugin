<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Account;

use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\PaymentTermsPolicyInterface;
use Tmonier\SyliusTradeCreditPlugin\Repository\PaymentTermsPolicyRepositoryInterface;

/** Enabled policy of a payment method, memoized per method for the request (resolvers call it often). */
final class PaymentTermsPolicyProvider
{
    /** @var array<string, PaymentTermsPolicyInterface|null> */
    private array $policies = [];

    public function __construct(private readonly PaymentTermsPolicyRepositoryInterface $policyRepository)
    {
    }

    public function forPaymentMethod(?PaymentMethodInterface $paymentMethod): ?PaymentTermsPolicyInterface
    {
        if (null === $paymentMethod || null === $paymentMethod->getId()) {
            return null;
        }

        $key = (string) $paymentMethod->getCode() . '#' . (is_scalar($paymentMethod->getId()) ? (string) $paymentMethod->getId() : '');
        if (!array_key_exists($key, $this->policies)) {
            $policy = $this->policyRepository->findOneByPaymentMethod($paymentMethod);
            $this->policies[$key] = null !== $policy && $policy->isEnabled() ? $policy : null;
        }

        return $this->policies[$key];
    }

    public function isOnAccount(?PaymentMethodInterface $paymentMethod): bool
    {
        return null !== $this->forPaymentMethod($paymentMethod);
    }

    public function reset(): void
    {
        $this->policies = [];
    }
}
