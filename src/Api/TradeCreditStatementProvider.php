<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Api;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Tmonier\SyliusTradeCreditPlugin\Statement\StatementBuilder;
use Tmonier\SyliusTradeCreditPlugin\Statement\StatementItem;

/** @implements ProviderInterface<TradeCreditStatementView> */
final class TradeCreditStatementProvider implements ProviderInterface
{
    public function __construct(
        private readonly StatementBuilder $statementBuilder,
        private readonly Security $security,
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

        return new TradeCreditStatementView(
            'current',
            (string) $account->getCurrencyCode(),
            $account->getCreditLimit(),
            $account->getCommittedAmount(),
            $account->getAvailableAmount(),
            $statement->getOverdueAmount(),
            $account->isCreditHold(),
            array_map(static fn (StatementItem $item): array => [
                'orderNumber' => $item->orderNumber,
                'amount' => $item->amount,
                'orderDate' => $item->orderDate->format('Y-m-d'),
                'dueDate' => $item->dueDate->format('Y-m-d'),
                'overdue' => $item->overdue,
            ], $statement->items),
        );
    }
}
