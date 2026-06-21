<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\PriceBackfiller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:prices:backfill',
    description: 'Backfill daily historical prices (EODHD) from each instrument\'s first trade date',
)]
final class BackfillPricesCommand extends Command
{
    public function __construct(private readonly PriceBackfiller $backfiller)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('from', null, InputOption::VALUE_REQUIRED, 'Earliest date to backfill (YYYY-MM-DD); defaults to each instrument\'s first trade');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $minFrom = null;
        if (null !== ($raw = $input->getOption('from'))) {
            $minFrom = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $raw) ?: null;
            if (null === $minFrom) {
                $io->error('Invalid --from date; expected YYYY-MM-DD.');

                return Command::INVALID;
            }
        }

        $io->note('Backfill draws from the EODHD 20/day budget (1 call per instrument, +1 for first-time symbol resolution).');

        $report = $this->backfiller->backfill($minFrom);
        if ([] === $report) {
            $io->warning('Nothing to backfill (no EODHD key configured, or no transacted instruments).');

            return Command::SUCCESS;
        }

        $io->table(
            ['Symbol', 'ISIN', 'From', 'To', 'Days added', 'Status'],
            array_map(
                static fn (array $r): array => [$r['symbol'], $r['isin'] ?? '—', $r['from'], $r['to'], (string) $r['inserted'], $r['status']],
                $report,
            ),
        );

        $total = array_sum(array_column($report, 'inserted'));
        $io->success(sprintf('Backfilled %d historical price point(s) across %d instrument(s).', $total, \count($report)));

        return Command::SUCCESS;
    }
}
