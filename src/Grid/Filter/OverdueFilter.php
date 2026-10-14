<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Grid\Filter;

use Psr\Clock\ClockInterface;
use Sylius\Bundle\GridBundle\Doctrine\ORM\DataSource;
use Sylius\Component\Grid\Data\DataSourceInterface;
use Sylius\Component\Grid\Filter\BooleanFilter;
use Sylius\Component\Grid\Filtering\FilterInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;
use Tmonier\SyliusTradeCreditPlugin\Repository\OrderPaymentTermsRepositoryInterface;

/**
 * Open items grid: "overdue" = still reserved and due before today, or an order that left
 * payment on account and is unpaid after its due date (or the opposite).
 */
final readonly class OverdueFilter implements FilterInterface
{
    public function __construct(
        private ClockInterface $clock,
        private OrderPaymentTermsRepositoryInterface $termsRepository,
    ) {
    }

    public function apply(DataSourceInterface $dataSource, string $name, $data, array $options): void
    {
        // Doctrine ORM grid driver: the left unpaid items are a subquery.
        if (!$dataSource instanceof DataSource || (BooleanFilter::TRUE !== $data && BooleanFilter::FALSE !== $data)) {
            return;
        }

        $expressionBuilder = $dataSource->getExpressionBuilder();
        $now = $this->clock->now();
        $today = $now->format('Y-m-d');
        $queryBuilder = $dataSource->getQueryBuilder();
        $leftUnpaid = $this->termsRepository->leftUnpaidOverdueCondition($queryBuilder, $queryBuilder->getRootAliases()[0], $now);
        if (BooleanFilter::TRUE === $data) {
            $dataSource->restrict($expressionBuilder->orX($expressionBuilder->andX(
                $expressionBuilder->equals('state', OrderPaymentTermsInterface::STATE_RESERVED),
                $expressionBuilder->lessThan('dueDate', $today),
            ), $leftUnpaid));

            return;
        }

        $dataSource->restrict($expressionBuilder->andX($expressionBuilder->orX(
            $expressionBuilder->notEquals('state', OrderPaymentTermsInterface::STATE_RESERVED),
            $expressionBuilder->greaterThanOrEqual('dueDate', $today),
        ), sprintf('NOT (%s)', $leftUnpaid)));
    }
}
