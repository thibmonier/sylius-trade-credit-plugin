<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Account;

use Sylius\Component\Core\Model\OrderInterface;

/**
 * Trade credit is B2B only: the order must be billed to a company, i.e. its billing address has
 * a non-empty company name. (The account itself is opened by staff, who check the customer.)
 */
final class BusinessCustomerChecker
{
    public function isBusinessOrder(OrderInterface $order): bool
    {
        $company = $order->getBillingAddress()?->getCompany();

        return null !== $company && '' !== trim($company);
    }
}
