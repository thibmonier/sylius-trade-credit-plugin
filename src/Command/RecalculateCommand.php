<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tmonier\SyliusTradeCreditPlugin\Repository\OrderPaymentTermsRepositoryInterface;
use Tmonier\SyliusTradeCreditPlugin\Repository\TradeCreditAccountRepositoryInterface;
use Tmonier\SyliusTradeCreditPlugin\Reservation\TradeCreditReserver;

/**
 * Drift detection: the committed amount of each account must equal the sum of its reserved
 * open items. Dry run by default; `--apply` sets the committed amount and writes an adjustment
 * ledger entry.
 */
#[AsCommand(name: 'tmonier:trade-credit:recalculate', description: 'Compare committed amounts with the open items of each trade credit account (dry run unless --apply)')]
final class RecalculateCommand extends Command
{
    public function __construct(
        private readonly TradeCreditAccountRepositoryInterface $accountRepository,
        private readonly OrderPaymentTermsRepositoryInterface $termsRepository,
        private readonly TradeCreditReserver $reserver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Fix the committed amounts that drifted');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $reserved = $this->termsRepository->sumReservedByAccount();

        $rows = [];
        foreach ($this->accountRepository->findAll() as $account) {
            $expected = $reserved[(int) $account->getId()] ?? 0;
            if ($expected === $account->getCommittedAmount()) {
                continue;
            }
            $rows[] = [(string) $account->getId(), sprintf('%s:%s', (string) $account->getOwnerType(), (string) $account->getOwnerIdentifier()), $account->getCommittedAmount(), $expected];
            if ($apply) {
                $this->reserver->adjust($account, $expected, 'recalculate');
            }
        }

        if ([] === $rows) {
            $io->success('No drift: every committed amount matches the open items.');

            return Command::SUCCESS;
        }

        $io->table(['Account', 'Owner', 'Committed', 'Open items'], $rows);
        if ($apply) {
            $io->success(sprintf('%d account(s) adjusted.', count($rows)));

            return Command::SUCCESS;
        }

        $io->warning(sprintf('%d account(s) drifted. Run again with --apply to fix them.', count($rows)));

        return Command::FAILURE;
    }
}
