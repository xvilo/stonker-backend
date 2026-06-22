<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\InstrumentRepository;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cleanup:forex',
    description: 'Remove forex/currency-pair instruments (e.g. EUR.USD) and their transactions left behind by earlier IBKR imports',
)]
final class CleanupForexCommand extends Command
{
    public function __construct(
        private readonly InstrumentRepository $instruments,
        private readonly TransactionRepository $transactions,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview the changes without deleting anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = !$input->getOption('dry-run');

        $pairs = $this->instruments->findCurrencyPairs();
        if ([] === $pairs) {
            $io->success('No forex/currency-pair instruments found — nothing to clean up.');

            return Command::SUCCESS;
        }

        $rows = [];
        $txTotal = 0;
        foreach ($pairs as $instrument) {
            // Transaction -> Instrument has no DB cascade, so remove the
            // transactions first; PriceSnapshots cascade on the instrument delete.
            $txns = $this->transactions->findBy(['instrument' => $instrument]);
            $txTotal += \count($txns);
            $rows[] = [$instrument->getSymbol(), $instrument->getName(), \count($txns)];

            if ($apply) {
                foreach ($txns as $txn) {
                    $this->em->remove($txn);
                }
                $this->em->remove($instrument);
            }
        }

        if ($apply) {
            $this->em->flush();
        }

        $io->table(['Symbol', 'Name', 'Transactions'], $rows);
        $io->success(sprintf(
            '%s %d instrument(s) and %d transaction(s).',
            $apply ? 'Removed' : '(dry-run) Would remove',
            \count($pairs),
            $txTotal,
        ));

        return Command::SUCCESS;
    }
}
