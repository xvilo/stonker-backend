<?php

declare(strict_types=1);

namespace App\Command;

use App\Cleanup\DataCleanerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Removes junk left behind by broker imports (forex pairs like EUR.USD, and
 * future artifacts) by running the registered data cleaners.
 *
 * Safe by default: a dry-run preview. Pass --apply to actually delete.
 *
 *   bin/console app:cleanup --list            # show available cleaners
 *   bin/console app:cleanup                    # dry-run every cleaner
 *   bin/console app:cleanup forex --apply      # delete forex junk
 *   bin/console app:cleanup --apply -n         # apply all, no confirmation
 */
#[AsCommand(
    name: 'app:cleanup',
    description: 'Remove junk left behind by broker imports (forex pairs, …) via the registered data cleaners',
)]
final class CleanupCommand extends Command
{
    /** @var array<string, DataCleanerInterface> keyed by cleaner key */
    private array $cleaners = [];

    /**
     * @param iterable<DataCleanerInterface> $cleaners
     */
    public function __construct(
        #[AutowireIterator('app.data_cleaner')]
        iterable $cleaners,
    ) {
        // Populate before parent::__construct(), which calls configure() — the
        // argument help lists the available cleaner names.
        foreach ($cleaners as $cleaner) {
            $this->cleaners[$cleaner->key()] = $cleaner;
        }

        parent::__construct();
    }

    protected function configure(): void
    {
        $names = implode(', ', array_keys($this->cleaners));

        $this
            ->addArgument('cleaners', InputArgument::IS_ARRAY, "Cleaners to run (default: all). Available: {$names}")
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Actually delete. Without it the command is a dry-run preview.')
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'List the available cleaners and exit.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('list')) {
            $io->table(
                ['Name', 'Description'],
                array_map(
                    static fn (DataCleanerInterface $c): array => [$c->key(), $c->description()],
                    array_values($this->cleaners),
                ),
            );

            return Command::SUCCESS;
        }

        if ([] === $this->cleaners) {
            $io->warning('No data cleaners are registered.');

            return Command::SUCCESS;
        }

        /** @var list<string> $requested */
        $requested = $input->getArgument('cleaners');
        $unknown = array_diff($requested, array_keys($this->cleaners));
        if ([] !== $unknown) {
            $io->error(sprintf(
                'Unknown cleaner(s): %s. Available: %s.',
                implode(', ', $unknown),
                implode(', ', array_keys($this->cleaners)),
            ));

            return Command::INVALID;
        }

        $selected = [] === $requested ? array_keys($this->cleaners) : $requested;
        $apply = (bool) $input->getOption('apply');

        if ($apply && $input->isInteractive()
            && !$io->confirm('This permanently deletes data. Continue?', false)) {
            $io->warning('Aborted — nothing was deleted.');

            return Command::SUCCESS;
        }

        $totalAffected = 0;
        foreach ($selected as $name) {
            $cleaner = $this->cleaners[$name];
            $io->section($name);

            $report = $cleaner->clean($apply);
            if ($report->isEmpty()) {
                $io->writeln('Nothing to clean.');

                continue;
            }

            ++$totalAffected;
            $io->table($report->headers, $report->rows);
            $io->writeln(sprintf(
                '%s %s.',
                $apply ? '<info>Removed</info>' : '<comment>Would remove (dry-run)</comment>',
                $report->summary,
            ));
        }

        if (0 === $totalAffected) {
            $io->success('Everything is already clean — nothing to do.');

            return Command::SUCCESS;
        }

        if (!$apply) {
            $io->note('Dry-run only. Re-run with --apply to delete.');
        }

        $io->success($apply ? 'Cleanup complete.' : 'Dry-run complete.');

        return Command::SUCCESS;
    }
}
