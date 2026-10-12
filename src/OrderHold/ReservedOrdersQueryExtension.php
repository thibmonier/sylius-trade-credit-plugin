<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\OrderHold;

use Doctrine\ORM\QueryBuilder;
use Tmonier\SyliusOrderHoldPlugin\UnpaidOrder\UnpaidOrderCandidateQueryExtensionInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;

/**
 * Keeps orders with a trade credit reservation out of `sylius:cancel-unpaid-orders` (otherwise
 * an order on 30-day terms would be cancelled after the default 5 days), in SQL.
 */
final class ReservedOrdersQueryExtension implements UnpaidOrderCandidateQueryExtensionInterface
{
    /** @param class-string<OrderPaymentTermsInterface> $termsClass */
    public function __construct(private readonly string $termsClass)
    {
    }

    public function applyToCandidateQuery(QueryBuilder $queryBuilder, string $rootAlias): void
    {
        $queryBuilder
            ->andWhere(sprintf(
                'NOT EXISTS (SELECT tmonier_tc_terms.id FROM %s tmonier_tc_terms WHERE tmonier_tc_terms.order = %s AND tmonier_tc_terms.state = :tmonier_tc_reserved)',
                $this->termsClass,
                $rootAlias,
            ))
            ->setParameter('tmonier_tc_reserved', OrderPaymentTermsInterface::STATE_RESERVED)
        ;
    }
}
