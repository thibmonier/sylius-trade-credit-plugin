<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Repository;

use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\PaymentTermsPolicyInterface;

/** @extends EntityRepository<PaymentTermsPolicyInterface> */
class PaymentTermsPolicyRepository extends EntityRepository implements PaymentTermsPolicyRepositoryInterface
{
    public function findOneByPaymentMethod(PaymentMethodInterface $paymentMethod): ?PaymentTermsPolicyInterface
    {
        $policy = $this->findOneBy(['paymentMethod' => $paymentMethod]);

        return $policy instanceof PaymentTermsPolicyInterface ? $policy : null;
    }
}
