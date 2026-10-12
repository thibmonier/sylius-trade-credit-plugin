<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Grid\Filter;

use Psr\Clock\ClockInterface;
use Sylius\Component\Grid\Data\DataSourceInterface;
use Sylius\Component\Grid\Filtering\FilterInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;
use Tmonier\SyliusTradeCreditPlugin\Repository\OrderPaymentTermsRepositoryInterface;

/**
 * Open items grid state: "open" also lists the orders that left payment on account and are
 * unpaid after their due date (released terms that still block the account as overdue).
 */
final class TermsStateFilter implements FilterInterface
{
    public function __construct(
        private readonly OrderPaymentTermsRepositoryInterface $termsRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    public function apply(DataSourceInterface $dataSource, string $name, $data, array $options): void
    {
        if (!is_string($data) || '' === $data) {
            return;
        }

        $expressionBuilder = $dataSource->getExpressionBuilder();
        $leftUnpaid = $this->termsRepository->findLeftUnpaidOverdueIds($this->clock->now());
        $state = $expressionBuilder->equals('state', $data);
        if ([] === $leftUnpaid || OrderPaymentTermsInterface::STATE_SETTLED === $data) {
            $dataSource->restrict($state);

            return;
        }

        $dataSource->restrict(OrderPaymentTermsInterface::STATE_RESERVED === $data
            ? $expressionBuilder->orX($state, $expressionBuilder->in('id', $leftUnpaid))
            : $expressionBuilder->andX($state, $expressionBuilder->notIn('id', $leftUnpaid)));
    }
}
