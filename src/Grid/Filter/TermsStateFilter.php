<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Grid\Filter;

use Psr\Clock\ClockInterface;
use Sylius\Bundle\GridBundle\Doctrine\ORM\DataSource;
use Sylius\Component\Grid\Data\DataSourceInterface;
use Sylius\Component\Grid\Filtering\FilterInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;
use Tmonier\SyliusTradeCreditPlugin\Repository\OrderPaymentTermsRepositoryInterface;

/**
 * Open items grid state: "open" also lists the orders that left payment on account and are
 * unpaid after their due date (released terms that still block the account as overdue).
 */
final readonly class TermsStateFilter implements FilterInterface
{
    public function __construct(
        private OrderPaymentTermsRepositoryInterface $termsRepository,
        private ClockInterface $clock,
    ) {
    }

    public function apply(DataSourceInterface $dataSource, string $name, $data, array $options): void
    {
        // Doctrine ORM grid driver: the left unpaid items are a subquery.
        if (!$dataSource instanceof DataSource || !is_string($data) || '' === $data) {
            return;
        }

        $expressionBuilder = $dataSource->getExpressionBuilder();
        $state = $expressionBuilder->equals('state', $data);
        if (OrderPaymentTermsInterface::STATE_SETTLED === $data) {
            $dataSource->restrict($state);

            return;
        }

        $queryBuilder = $dataSource->getQueryBuilder();
        $leftUnpaid = $this->termsRepository->leftUnpaidOverdueCondition($queryBuilder, $queryBuilder->getRootAliases()[0], $this->clock->now());
        $dataSource->restrict(OrderPaymentTermsInterface::STATE_RESERVED === $data
            ? $expressionBuilder->orX($state, $leftUnpaid)
            : $expressionBuilder->andX($state, sprintf('NOT (%s)', $leftUnpaid)));
    }
}
