<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Grid\Filter;

use Psr\Clock\ClockInterface;
use Sylius\Component\Grid\Data\DataSourceInterface;
use Sylius\Component\Grid\Filter\BooleanFilter;
use Sylius\Component\Grid\Filtering\FilterInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;

/** Open items grid: "overdue" = still reserved and due before today (or the opposite). */
final class OverdueFilter implements FilterInterface
{
    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function apply(DataSourceInterface $dataSource, string $name, $data, array $options): void
    {
        if (BooleanFilter::TRUE !== $data && BooleanFilter::FALSE !== $data) {
            return;
        }

        $expressionBuilder = $dataSource->getExpressionBuilder();
        $today = $this->clock->now()->format('Y-m-d');
        if (BooleanFilter::TRUE === $data) {
            $dataSource->restrict($expressionBuilder->andX(
                $expressionBuilder->equals('state', OrderPaymentTermsInterface::STATE_RESERVED),
                $expressionBuilder->lessThan('dueDate', $today),
            ));

            return;
        }

        $dataSource->restrict($expressionBuilder->orX(
            $expressionBuilder->notEquals('state', OrderPaymentTermsInterface::STATE_RESERVED),
            $expressionBuilder->greaterThanOrEqual('dueDate', $today),
        ));
    }
}
