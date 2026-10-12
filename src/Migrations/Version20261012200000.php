<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/** Trade credit 1.1: payment reminders sent (Schema API: MySQL, MariaDB and PostgreSQL). */
final class Version20261012200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the trade credit reminders table (tmonier/sylius-trade-credit-plugin 1.1).';
    }

    public function up(Schema $schema): void
    {
        $reminder = $schema->createTable('tmonier_trade_credit_reminder');
        $reminder->addColumn('id', Types::GUID);
        $reminder->addColumn('order_id', Types::INTEGER);
        $reminder->addColumn('due_date', Types::DATE_IMMUTABLE);
        $reminder->addColumn('reminder_level', Types::INTEGER);
        $reminder->addColumn('email', Types::STRING, ['length' => 255]);
        $reminder->addColumn('sent_at', Types::DATETIME_IMMUTABLE);
        $reminder->setPrimaryKey(['id']);
        $reminder->addIndex(['order_id'], 'IDX_70CBE87E8D9F6D38');
        $reminder->addUniqueIndex(['order_id', 'due_date', 'reminder_level'], 'tmonier_trade_credit_reminder_level_uniq');
        $reminder->addForeignKeyConstraint('sylius_order', ['order_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_70CBE87E8D9F6D38');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('tmonier_trade_credit_reminder');
    }
}
