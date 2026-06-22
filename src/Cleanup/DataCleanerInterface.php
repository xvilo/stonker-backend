<?php

declare(strict_types=1);

namespace App\Cleanup;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A single, self-contained data-cleanup task. Broker imports occasionally leave
 * junk behind (forex pairs like EUR.USD, and other artifacts we don't model);
 * each cleaner knows how to find and remove one kind.
 *
 * Implementations are auto-discovered (tagged) and run by the `app:cleanup`
 * command. To add a new cleanup, drop a new class implementing this interface
 * in this namespace — no wiring needed.
 *
 * Contract:
 *  - clean(false) is a DRY RUN: it must only inspect and report, never write.
 *  - clean(true) performs the deletion AND flushes its own unit of work, so
 *    each cleaner is atomic and independent of the others.
 */
#[AutoconfigureTag('app.data_cleaner')]
interface DataCleanerInterface
{
    /**
     * Short, stable, lowercase identifier used to select this cleaner on the
     * CLI (e.g. "forex"). Must be unique across cleaners.
     */
    public function key(): string;

    /**
     * One-line description shown by `app:cleanup --list`.
     */
    public function description(): string;

    /**
     * Find the junk this cleaner targets and, when $apply is true, delete it
     * and flush. When $apply is false, nothing is written.
     */
    public function clean(bool $apply): CleanupReport;
}
