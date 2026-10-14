<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\PaymentTermsPolicyInterface;
use Tmonier\SyliusTradeCreditPlugin\Repository\OrderPaymentTermsRepositoryInterface;

/**
 * Admin: payment terms with open items can be disabled (no new order) but neither deleted nor
 * moved to another payment method, which would turn their method into a plain Offline method.
 */
final readonly class PaymentTermsPolicyGuardListener
{
    public function __construct(
        private OrderPaymentTermsRepositoryInterface $termsRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function onPreDelete(ResourceControllerEvent $event): void
    {
        $policy = $event->getSubject();
        $method = $policy instanceof PaymentTermsPolicyInterface ? $policy->getPaymentMethod() : null;
        if ($method instanceof PaymentMethodInterface && $this->termsRepository->countReservedForPaymentMethod($method) > 0) {
            $event->stop('tmonier_trade_credit.flash.policy_has_open_items');
        }
    }

    public function onPreUpdate(ResourceControllerEvent $event): void
    {
        $policy = $event->getSubject();
        if (!$policy instanceof PaymentTermsPolicyInterface) {
            return;
        }

        $original = $this->entityManager->getUnitOfWork()->getOriginalEntityData($policy)['paymentMethod'] ?? null;
        if ($original instanceof PaymentMethodInterface && $original !== $policy->getPaymentMethod() && $this->termsRepository->countReservedForPaymentMethod($original) > 0) {
            $policy->setPaymentMethod($original);
            $event->stop('tmonier_trade_credit.flash.policy_has_open_items');
        }
    }
}
