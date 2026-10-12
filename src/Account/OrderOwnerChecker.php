<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Account;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;

/**
 * Payment on account is only for orders placed by their customer signed in: Sylius attaches an
 * existing customer to a guest checkout from the e-mail typed at the address step (shop and API),
 * so a guest order with the e-mail of a customer must never use (nor reveal) its trade credit.
 */
final class OrderOwnerChecker
{
    public function isPlacedByItsCustomer(OrderInterface $order): bool
    {
        $customer = $order->getCustomer();

        return $customer instanceof CustomerInterface && null !== $customer->getUser() && !$order->isCreatedByGuest();
    }
}
