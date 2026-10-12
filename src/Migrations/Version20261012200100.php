<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PostgreSQL only: the 1.0 migration created the integer ids of the accounts and policies as
 * IDENTITY (DBAL 4) or SERIAL (DBAL 3) columns, whereas the ORM mapping uses sequences (like
 * Sylius): plain integer columns fed by `<table>_id_seq`. Existing ids are kept; the sequence
 * restarts after the highest one. Nothing to do on MySQL and MariaDB.
 */
final class Version20261012200100 extends AbstractMigration
{
    private const TABLES = ['tmonier_trade_credit_account', 'tmonier_trade_credit_payment_terms_policy'];

    public function getDescription(): string
    {
        return 'PostgreSQL: sequence-based ids for the trade credit accounts and policies (tmonier/sylius-trade-credit-plugin 1.1).';
    }

    public function up(Schema $schema): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $this->write('Not PostgreSQL: nothing to do.');

            return;
        }

        foreach (self::TABLES as $table) {
            // Identity first (DROP DEFAULT is refused on an identity column), then the SERIAL default.
            $this->addSql(sprintf('ALTER TABLE %s ALTER id DROP IDENTITY IF EXISTS', $table));
            $this->addSql(sprintf('ALTER TABLE %s ALTER id DROP DEFAULT', $table));
            $this->addSql(sprintf('CREATE SEQUENCE IF NOT EXISTS %s_id_seq INCREMENT BY 1 MINVALUE 1 START 1', $table));
            $this->addSql(sprintf("SELECT setval('%1\$s_id_seq', COALESCE((SELECT MAX(id) FROM %1\$s), 0) + 1, false)", $table));
        }
    }

    public function down(Schema $schema): void
    {
        // The 1.0 columns worked too: nothing to restore.
    }
}
