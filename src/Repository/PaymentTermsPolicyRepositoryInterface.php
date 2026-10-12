<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Repository;

use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\PaymentTermsPolicyInterface;

/** @extends RepositoryInterface<PaymentTermsPolicyInterface> */
interface PaymentTermsPolicyRepositoryInterface extends RepositoryInterface
{
    public function findOneByPaymentMethod(PaymentMethodInterface $paymentMethod): ?PaymentTermsPolicyInterface;
}
