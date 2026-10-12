<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Written with the Schema API (not raw SQL) so that it runs on MySQL, MariaDB and PostgreSQL.
 */
final class Version20261012100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the trade credit tables (tmonier/sylius-trade-credit-plugin).';
    }

    public function up(Schema $schema): void
    {
        $account = $schema->createTable('tmonier_trade_credit_account');
        $account->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $account->addColumn('owner_type', Types::STRING, ['length' => 32]);
        $account->addColumn('owner_identifier', Types::STRING, ['length' => 64]);
        $account->addColumn('currency_code', Types::STRING, ['length' => 3]);
        $account->addColumn('credit_limit', Types::INTEGER);
        $account->addColumn('committed_amount', Types::INTEGER);
        $account->addColumn('allow_exceed', Types::BOOLEAN);
        $account->addColumn('credit_hold', Types::BOOLEAN);
        $account->addColumn('block_when_overdue', Types::BOOLEAN);
        $account->addColumn('net_days', Types::INTEGER, ['notnull' => false]);
        $account->addColumn('end_of_month_mode', Types::STRING, ['length' => 32, 'notnull' => false]);
        $account->addColumn('enabled', Types::BOOLEAN);
        $account->addColumn('note', Types::TEXT, ['notnull' => false]);
        $account->addColumn('created_at', Types::DATETIME_MUTABLE);
        $account->addColumn('updated_at', Types::DATETIME_MUTABLE, ['notnull' => false]);
        $account->setPrimaryKey(['id']);
        $account->addUniqueIndex(['owner_type', 'owner_identifier'], 'tmonier_trade_credit_account_owner_uniq');

        $policy = $schema->createTable('tmonier_trade_credit_payment_terms_policy');
        $policy->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $policy->addColumn('payment_method_id', Types::INTEGER);
        $policy->addColumn('net_days', Types::INTEGER);
        $policy->addColumn('end_of_month_mode', Types::STRING, ['length' => 32]);
        $policy->addColumn('enabled', Types::BOOLEAN);
        $policy->addColumn('created_at', Types::DATETIME_MUTABLE);
        $policy->addColumn('updated_at', Types::DATETIME_MUTABLE, ['notnull' => false]);
        $policy->setPrimaryKey(['id']);
        $policy->addUniqueIndex(['payment_method_id'], 'UNIQ_F3988F5E5AA1164F');
        $policy->addForeignKeyConstraint('sylius_payment_method', ['payment_method_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_F3988F5E5AA1164F');

        $terms = $schema->createTable('tmonier_trade_credit_order_terms');
        $terms->addColumn('id', Types::GUID);
        $terms->addColumn('order_id', Types::INTEGER);
        $terms->addColumn('account_id', Types::INTEGER);
        $terms->addColumn('payment_method_id', Types::INTEGER, ['notnull' => false]);
        $terms->addColumn('amount', Types::INTEGER);
        $terms->addColumn('currency_code', Types::STRING, ['length' => 3]);
        $terms->addColumn('net_days', Types::INTEGER);
        $terms->addColumn('end_of_month_mode', Types::STRING, ['length' => 32]);
        $terms->addColumn('due_date', Types::DATE_IMMUTABLE);
        $terms->addColumn('state', Types::STRING, ['length' => 16]);
        $terms->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $terms->addColumn('closed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $terms->addColumn('closing_reason', Types::STRING, ['length' => 32, 'notnull' => false]);
        $terms->setPrimaryKey(['id']);
        $terms->addIndex(['order_id'], 'IDX_7084173F8D9F6D38');
        $terms->addIndex(['account_id'], 'IDX_7084173F9B6B5FBA');
        $terms->addIndex(['payment_method_id'], 'IDX_7084173F5AA1164F');
        $terms->addIndex(['state', 'due_date'], 'tmonier_trade_credit_order_terms_state_idx');
        $terms->addIndex(['order_id', 'state'], 'tmonier_trade_credit_order_terms_order_state_idx');
        $terms->addForeignKeyConstraint('sylius_order', ['order_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_7084173F8D9F6D38');
        $terms->addForeignKeyConstraint('tmonier_trade_credit_account', ['account_id'], ['id'], [], 'FK_7084173F9B6B5FBA');
        $terms->addForeignKeyConstraint('sylius_payment_method', ['payment_method_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_7084173F5AA1164F');

        $ledger = $schema->createTable('tmonier_trade_credit_ledger_entry');
        $ledger->addColumn('id', Types::GUID);
        $ledger->addColumn('account_id', Types::INTEGER);
        $ledger->addColumn('order_id', Types::INTEGER, ['notnull' => false]);
        $ledger->addColumn('order_payment_terms_id', Types::GUID, ['notnull' => false]);
        $ledger->addColumn('type', Types::STRING, ['length' => 16]);
        $ledger->addColumn('amount', Types::INTEGER);
        $ledger->addColumn('committed_after', Types::INTEGER);
        $ledger->addColumn('reason', Types::STRING, ['length' => 64, 'notnull' => false]);
        $ledger->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $ledger->setPrimaryKey(['id']);
        $ledger->addIndex(['account_id'], 'IDX_363470039B6B5FBA');
        $ledger->addIndex(['order_id'], 'IDX_363470038D9F6D38');
        $ledger->addIndex(['order_payment_terms_id'], 'IDX_3634700371A47953');
        $ledger->addIndex(['account_id', 'created_at'], 'tmonier_trade_credit_ledger_account_idx');
        $ledger->addForeignKeyConstraint('tmonier_trade_credit_account', ['account_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_363470039B6B5FBA');
        $ledger->addForeignKeyConstraint('sylius_order', ['order_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_363470038D9F6D38');
        $ledger->addForeignKeyConstraint('tmonier_trade_credit_order_terms', ['order_payment_terms_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_3634700371A47953');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('tmonier_trade_credit_ledger_entry');
        $schema->dropTable('tmonier_trade_credit_order_terms');
        $schema->dropTable('tmonier_trade_credit_payment_terms_policy');
        $schema->dropTable('tmonier_trade_credit_account');
    }
}
