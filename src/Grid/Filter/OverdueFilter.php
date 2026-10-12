<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Grid\Filter;

use Psr\Clock\ClockInterface;
use Sylius\Component\Grid\Data\DataSourceInterface;
use Sylius\Component\Grid\Filter\BooleanFilter;
use Sylius\Component\Grid\Filtering\FilterInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;
use Tmonier\SyliusTradeCreditPlugin\Repository\OrderPaymentTermsRepositoryInterface;

/**
 * Open items grid: "overdue" = still reserved and due before today, or an order that left
 * payment on account and is unpaid after its due date (or the opposite).
 */
final class OverdueFilter implements FilterInterface
{
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly OrderPaymentTermsRepositoryInterface $termsRepository,
    ) {
    }

    public function apply(DataSourceInterface $dataSource, string $name, $data, array $options): void
    {
        if (BooleanFilter::TRUE !== $data && BooleanFilter::FALSE !== $data) {
            return;
        }

        $expressionBuilder = $dataSource->getExpressionBuilder();
        $now = $this->clock->now();
        $today = $now->format('Y-m-d');
        $leftUnpaid = $this->termsRepository->findLeftUnpaidOverdueIds($now);
        if (BooleanFilter::TRUE === $data) {
            $reserved = $expressionBuilder->andX(
                $expressionBuilder->equals('state', OrderPaymentTermsInterface::STATE_RESERVED),
                $expressionBuilder->lessThan('dueDate', $today),
            );
            $dataSource->restrict([] === $leftUnpaid ? $reserved : $expressionBuilder->orX($reserved, $expressionBuilder->in('id', $leftUnpaid)));

            return;
        }

        $notReserved = $expressionBuilder->orX(
            $expressionBuilder->notEquals('state', OrderPaymentTermsInterface::STATE_RESERVED),
            $expressionBuilder->greaterThanOrEqual('dueDate', $today),
        );
        $dataSource->restrict([] === $leftUnpaid ? $notReserved : $expressionBuilder->andX($notReserved, $expressionBuilder->notIn('id', $leftUnpaid)));
    }
}
