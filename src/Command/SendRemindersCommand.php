<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tmonier\SyliusTradeCreditPlugin\Reminder\ReminderSender;

/** Sends the payment reminders due today (run it daily). Idempotent: see `ReminderSender`. */
#[AsCommand(name: 'tmonier:trade-credit:send-reminders', description: 'Send the payment reminders of the open items (one per item and level; run it daily)')]
final class SendRemindersCommand extends Command
{
    public function __construct(private readonly ReminderSender $reminderSender)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the reminders that would be sent');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $report = $this->reminderSender->send($dryRun);
        if ([] === $report) {
            $io->success('No reminder to send.');

            return Command::SUCCESS;
        }

        $io->table(['Order', 'E-mail', 'Due date', 'Level (days)', 'Status'], array_map(array_values(...), $report));
        $failed = count(array_filter($report, static fn (array $row): bool => 'failed' === $row['status']));
        if ($dryRun) {
            $io->note(sprintf('Dry run: %d reminder(s) to send.', count($report)));

            return Command::SUCCESS;
        }
        if ($failed > 0) {
            $io->warning(sprintf('%d reminder(s) not sent (see the logs): they will be sent by the next run.', $failed));

            return Command::FAILURE;
        }
        $io->success(sprintf('%d reminder(s) sent.', count($report)));

        return Command::SUCCESS;
    }
}
