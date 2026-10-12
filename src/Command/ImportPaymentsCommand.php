<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tmonier\SyliusTradeCreditPlugin\Import\PaymentCsvReader;
use Tmonier\SyliusTradeCreditPlugin\Import\PaymentImporter;
use Tmonier\SyliusTradeCreditPlugin\Import\PaymentImportResult;

/** Settles the open items paid by bank transfer from a CSV file. Dry run unless `--apply`. */
#[AsCommand(name: 'tmonier:trade-credit:import-payments', description: 'Settle open items from a payments CSV file (order_number, amount[, currency]); dry run unless --apply')]
final class ImportPaymentsCommand extends Command
{
    public function __construct(
        private readonly PaymentCsvReader $reader,
        private readonly PaymentImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'CSV file with a header line: order_number, amount (decimal, e.g. 1234.56), optional currency')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Settle the open items (all or nothing: nothing is settled when a line has an error)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');

        $file = $input->getArgument('file');

        try {
            $read = $this->reader->read(is_string($file) ? $file : '');
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $result = $this->importer->import($read['lines'], $read['errors'], $apply);
        $rows = [];
        foreach ($result->getLines() as $lineNumber => $line) {
            $rows[] = [$lineNumber, $line['orderNumber'], null === $line['amount'] ? '' : number_format($line['amount'] / 100, 2, '.', ''), $line['status'], $line['message'] ?? ''];
        }
        $io->table(['Line', 'Order', 'Amount', 'Status', 'Message'], $rows);

        if ($result->hasErrors()) {
            $io->error(sprintf('%d line(s) with errors: nothing settled. Fix the file and run again.', count($result->linesWith(PaymentImportResult::ERROR))));

            return Command::FAILURE;
        }

        $alreadyPaid = count($result->linesWith(PaymentImportResult::ALREADY_PAID));
        if ($apply) {
            $io->success(sprintf('%d open item(s) settled, %d already paid.', count($result->linesWith(PaymentImportResult::SETTLED)), $alreadyPaid));
        } else {
            $io->note(sprintf('Dry run: %d open item(s) to settle, %d already paid. Run again with --apply.', count($result->linesWith(PaymentImportResult::TO_SETTLE)), $alreadyPaid));
        }

        return Command::SUCCESS;
    }
}
