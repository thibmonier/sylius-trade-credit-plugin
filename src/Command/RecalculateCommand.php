<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tmonier\SyliusTradeCreditPlugin\Repository\TradeCreditAccountRepositoryInterface;
use Tmonier\SyliusTradeCreditPlugin\Reservation\TradeCreditReserver;

/**
 * Drift detection: the committed amount of each account must equal the sum of its open
 * reservations, and open reservations must belong to placed orders and match the method of
 * their payment. Dry run by default; `--apply` releases the stale reservations, reserves the
 * mismatched ones again for the current method (or releases them) and sets each drifted
 * committed amount under the account lock (no race with live reservations), with an
 * adjustment ledger entry.
 */
#[AsCommand(name: 'tmonier:trade-credit:recalculate', description: 'Compare committed amounts with the open items of each trade credit account (dry run unless --apply)')]
final class RecalculateCommand extends Command
{
    public function __construct(
        private readonly TradeCreditAccountRepositoryInterface $accountRepository,
        private readonly TradeCreditReserver $reserver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Release stale reservations and fix the committed amounts that drifted');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');

        $stale = $this->reserver->staleReservations($apply);
        if ([] !== $stale) {
            $io->warning(sprintf('%s reservation(s) of orders that are not placed (or cancelled): %s', $apply ? 'Released' : 'Found', implode(', ', $stale)));
        }
        $mismatched = $this->reserver->mismatchedReservations($apply);
        if ([] !== $mismatched) {
            $io->warning(sprintf('%s reservation(s) made for another method than the payment of the order: %s', $apply ? 'Reserved again or released' : 'Found', implode(', ', $mismatched)));
        }

        $rows = [];
        foreach ($this->accountRepository->findAll() as $account) {
            $drift = $this->reserver->recalculate($account, $apply);
            if (null !== $drift) {
                $rows[] = [(string) $account->getId(), sprintf('%s:%s', (string) $account->getOwnerType(), (string) $account->getOwnerIdentifier()), $drift['committed'], $drift['expected']];
            }
        }

        if ([] === $rows && [] === $stale && [] === $mismatched) {
            $io->success('No drift: every committed amount matches the open items.');

            return Command::SUCCESS;
        }

        if ([] !== $rows) {
            $io->table(['Account', 'Owner', 'Committed', 'Open items'], $rows);
        }
        if ($apply) {
            $io->success(sprintf('%d account(s) adjusted.', count($rows)));

            return Command::SUCCESS;
        }

        $io->warning('Run again with --apply to fix them.');

        return Command::FAILURE;
    }
}
