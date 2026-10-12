<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Api;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Psr\Clock\ClockInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Tmonier\SyliusTradeCreditPlugin\Ageing\AgeingBuckets;
use Tmonier\SyliusTradeCreditPlugin\Statement\StatementBuilder;
use Tmonier\SyliusTradeCreditPlugin\Statement\StatementItem;

/** @implements ProviderInterface<TradeCreditStatementView> */
final class TradeCreditStatementProvider implements ProviderInterface
{
    public function __construct(
        private readonly StatementBuilder $statementBuilder,
        private readonly Security $security,
        private readonly ClockInterface $clock,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?TradeCreditStatementView
    {
        $user = $this->security->getUser();
        $customer = $user instanceof ShopUserInterface ? $user->getCustomer() : null;
        $statement = $customer instanceof CustomerInterface ? $this->statementBuilder->forCustomer($customer) : null;
        if (null === $statement) {
            return null;
        }

        $account = $statement->account;
        $position = $statement->position;
        $today = $this->clock->now();

        return new TradeCreditStatementView(
            'current',
            (string) $account->getCurrencyCode(),
            $position->creditLimit,
            $position->committedAmount,
            max(0, $position->getAvailableAmount()),
            $statement->getOverdueAmount(),
            $account->isCreditHold(),
            $account->isEnabled(),
            array_map(static fn (StatementItem $item): array => [
                'orderNumber' => $item->orderNumber,
                'amount' => $item->amount,
                'orderDate' => $item->orderDate->format('Y-m-d'),
                'dueDate' => $item->dueDate->format('Y-m-d'),
                'overdue' => $item->overdue,
                'daysPastDue' => max(0, AgeingBuckets::daysPastDue($item->dueDate, $today)),
                'placedByYou' => $item->placedByViewer,
            ], $statement->items),
            $position->externalCommittedAmount,
            $statement->ageing->toArray(),
        );
    }
}
