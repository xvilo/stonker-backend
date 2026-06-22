<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add broker_sync_run: per-attempt audit of broker (IBKR Flex) syncs.
 */
final class Version20260622152358 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add broker_sync_run table to record the outcome of every broker sync.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE broker_sync_run (id UUID NOT NULL, fetched BOOLEAN NOT NULL, imported INT NOT NULL, skipped INT NOT NULL, note TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, broker_connection_id UUID NOT NULL, account_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_B3E7A472AF47A8B3 ON broker_sync_run (broker_connection_id)');
        $this->addSql('CREATE INDEX IDX_B3E7A4729B6B5FBA ON broker_sync_run (account_id)');
        $this->addSql('CREATE INDEX idx_sync_connection ON broker_sync_run (broker_connection_id, created_at)');
        $this->addSql('ALTER TABLE broker_sync_run ADD CONSTRAINT FK_B3E7A472AF47A8B3 FOREIGN KEY (broker_connection_id) REFERENCES broker_connection (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE broker_sync_run ADD CONSTRAINT FK_B3E7A4729B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE broker_sync_run DROP CONSTRAINT FK_B3E7A472AF47A8B3');
        $this->addSql('ALTER TABLE broker_sync_run DROP CONSTRAINT FK_B3E7A4729B6B5FBA');
        $this->addSql('DROP TABLE broker_sync_run');
    }
}
