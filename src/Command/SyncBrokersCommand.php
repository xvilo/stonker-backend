<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\BrokerSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:brokers:sync',
    description: 'Pull trades now from all active broker connections (IBKR Flex)',
)]
final class SyncBrokersCommand extends Command
{
    public function __construct(private readonly BrokerSyncService $brokerSync)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dump', null, InputOption::VALUE_NONE, 'Print IBKR\'s raw response and trade count for each connection');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dump = (bool) $input->getOption('dump');
        $results = $this->brokerSync->syncAll($dump);

        if ([] === $results) {
            $io->warning('No active IBKR connections configured. Add one in Settings (token + Query ID), then re-run.');

            return Command::SUCCESS;
        }

        $rows = array_map(
            static fn (array $r): array => [
                $r['label'],
                $r['fetched'] ? 'yes' : 'no',
                (string) $r['imported'],
                (string) $r['skipped'],
                $r['error'] ?? '',
            ],
            $results,
        );
        $io->table(['Connection', 'Fetched', 'Imported', 'Skipped', 'Note'], $rows);

        if ($dump) {
            foreach ($results as $r) {
                $io->section($r['label']);
                if (\array_key_exists('raw', $r) && null !== $r['raw']) {
                    $io->writeln(sprintf('<info>%d &lt;Trade&gt; element(s)</info>', $r['tradeCount'] ?? 0));
                    $io->writeln(substr((string) $r['raw'], 0, 4000));
                } else {
                    $io->writeln('<comment>No statement fetched — '.($r['error'] ?? 'unknown').'</comment>');
                    if (str_contains((string) $r['error'], '1001') || str_contains((string) $r['error'], '1018')) {
                        $io->writeln('<comment>(IBKR throttle — wait ~10-15 min, then retry a single request.)</comment>');
                    }
                }
            }
        }

        $failed = array_filter($results, static fn (array $r): bool => null !== $r['error'] && !$r['fetched']);
        if ([] !== $failed) {
            $io->warning(sprintf('%d connection(s) could not be synced — see the Note column.', \count($failed)));

            return Command::FAILURE;
        }

        $io->success('Broker sync complete.');

        return Command::SUCCESS;
    }
}
