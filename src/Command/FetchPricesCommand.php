<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\PriceUpdater;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputInterface;

#[AsCommand(
    name: 'app:prices:fetch',
    description: 'Fetch latest prices for all instruments via the configured providers',
)]
final class FetchPricesCommand extends Command
{
    public function __construct(private readonly PriceUpdater $priceUpdater)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->priceUpdater->updateAll();

        $io->success(sprintf(
            '%d price(s) updated, %d skipped (no provider coverage; manual snapshots retained).',
            $result['updated'],
            $result['skipped'],
        ));

        return Command::SUCCESS;
    }
}
