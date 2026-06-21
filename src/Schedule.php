<?php

namespace App;

use App\Message\FetchPricesMessage;
use App\Message\RunBrokerImportsMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Recurring background work. Run a worker to execute it:
 *   php bin/console messenger:consume scheduler_default
 */
#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->cache) // ensure missed tasks are executed
            ->processOnlyLastMissedRun(true) // ensure only last missed task is run
            ->add(
                // Refresh prices every weekday morning (after EU/US close settles).
                RecurringMessage::cron('30 6 * * 1-5', new FetchPricesMessage()),
                // Pull broker statements (IBKR Flex) once a day.
                RecurringMessage::cron('0 7 * * *', new RunBrokerImportsMessage()),
            )
        ;
    }
}
