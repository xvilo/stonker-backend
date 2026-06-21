<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\InstrumentResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:instruments:resolve',
    description: 'Resolve instrument ISINs to real tickers via OpenFIGI (fixes broker-import symbols)',
)]
final class ResolveInstrumentsCommand extends Command
{
    public function __construct(private readonly InstrumentResolver $resolver)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview the changes without writing them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = !$input->getOption('dry-run');

        $report = $this->resolver->resolveAll($apply);
        if ([] === $report) {
            $io->warning('No instruments with an ISIN to resolve.');

            return Command::SUCCESS;
        }

        $io->table(
            ['ISIN', 'Old symbol', 'New symbol', 'Exch', 'Status'],
            array_map(
                static fn (array $r): array => [
                    $r['isin'],
                    $r['oldSymbol'],
                    $r['newSymbol'] ?? '—',
                    $r['exchange'] ?? '',
                    $r['status'],
                ],
                $report,
            ),
        );

        $changed = \count(array_filter($report, static fn (array $r): bool => \in_array($r['status'], ['updated', 'would update'], true)));
        $io->success(sprintf('%s %d instrument(s).', $apply ? 'Updated' : '(dry-run) Would update', $changed));

        return Command::SUCCESS;
    }
}
