<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds retry-window tracking for the broker-sync auto-retry feature:
 * broker_connection.retry_until (open while a sync is being retried) and
 * broker_sync_run.trigger (which of scheduled/manual/retry produced the row).
 */
final class Version20260824094103 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add broker_connection.retry_until and broker_sync_run.trigger for manual re-run + auto-retry';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE broker_connection ADD retry_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        // Default backfills existing rows as scheduled runs, then is dropped — the
        // entity always sets trigger explicitly, so no app-level default is mapped.
        $this->addSql("ALTER TABLE broker_sync_run ADD trigger VARCHAR(255) NOT NULL DEFAULT 'SCHEDULED'");
        $this->addSql('ALTER TABLE broker_sync_run ALTER trigger DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE broker_connection DROP retry_until');
        $this->addSql('ALTER TABLE broker_sync_run DROP trigger');
    }
}
