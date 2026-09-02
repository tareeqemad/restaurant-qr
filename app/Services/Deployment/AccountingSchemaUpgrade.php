<?php

namespace App\Services\Deployment;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Additive, idempotent bridge for databases created before the final
 * accounting controls landed. Shared by the inspection command and the
 * normal migration pipeline so `migrate --force` is sufficient.
 */
class AccountingSchemaUpgrade
{
    /** @var list<string> */
    private array $changes = [];

    public function supportsCurrentConnection(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }

    /** @return list<string> */
    public function pendingChanges(): array
    {
        $required = [
            'invoices' => ['credited_total', 'written_off_total', 'due_date', 'payment_terms_days'],
            'payments' => ['status', 'voided_at', 'voided_by', 'void_reason'],
            'cash_reconciliations' => ['resolution_journal_entry_id', 'resolved_by', 'resolved_at', 'resolution_notes'],
            'refunds' => ['credit_note_id', 'payment_id', 'idempotency_key', 'completed_by', 'cancelled_by', 'reversed_by', 'completed_at', 'cancelled_at', 'reversed_at', 'reversal_reason'],
        ];

        $pending = [];
        foreach ($required as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $pending[] = "table {$table}";

                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $pending[] = "{$table}.{$column}";
                }
            }
        }
        foreach (['credit_notes', 'credit_note_lines', 'debt_writeoffs', 'refund_allocations'] as $table) {
            if (! Schema::hasTable($table)) {
                $pending[] = "table {$table}";
            }
        }
        if (! Schema::hasTable('customer_advance_transactions')) {
            $pending[] = 'table customer_advance_transactions';
        } else {
            foreach (['refund_id', 'reversed_transaction_id'] as $column) {
                if (! Schema::hasColumn('customer_advance_transactions', $column)) {
                    $pending[] = "customer_advance_transactions.{$column}";
                }
            }
        }

        return array_values(array_unique($pending));
    }

    /** @return list<string> */
    public function apply(): array
    {
        if (! $this->supportsCurrentConnection()) {
            throw new RuntimeException('The accounting schema upgrade supports MySQL only.');
        }

        $this->changes = [];
        $this->upgradeInvoices();
        $this->upgradePayments();
        $this->upgradeReconciliations();
        $this->createTableFromMigration('credit_notes', '2026_08_12_000093_create_credit_notes_table.php');
        $this->createTableFromMigration('credit_note_lines', '2026_08_12_000094_create_credit_note_lines_table.php');
        $this->createTableFromMigration('debt_writeoffs', '2026_08_12_000094_create_debt_writeoffs_table.php');
        $this->upgradeRefunds();
        $this->createTableFromMigration('refund_allocations', '2026_08_12_000095_create_refund_allocations_table.php');
        if (Schema::hasTable('customer_advance_transactions')) {
            $this->upgradeCustomerAdvances();
        } else {
            $this->createTableFromMigration('customer_advance_transactions', '2026_08_12_000096_create_customer_advance_transactions_table.php');
        }

        $pending = $this->pendingChanges();
        if ($pending !== []) {
            throw new RuntimeException('Accounting schema upgrade remains incomplete: '.implode(', ', $pending));
        }

        return $this->changes;
    }

    private function upgradeInvoices(): void
    {
        if (! Schema::hasTable('invoices')) {
            return;
        }
        $this->addColumn('invoices', 'credited_total', 'decimal(12,4) NOT NULL DEFAULT 0.0000 AFTER `refunded_total`');
        $this->addColumn('invoices', 'written_off_total', 'decimal(12,4) NOT NULL DEFAULT 0.0000 AFTER `credited_total`');
        $this->addColumn('invoices', 'due_date', 'date NULL AFTER `settled_on_account_at`');
        $this->addColumn('invoices', 'payment_terms_days', 'smallint unsigned NULL AFTER `due_date`');
        DB::statement("ALTER TABLE `invoices` MODIFY `status` enum('draft','issued','paid','partially_paid','cancelled','unpaid_writeoff') NOT NULL DEFAULT 'draft'");
        $this->ensureIndex('invoices', 'invoices_customer_due_idx', '`customer_id`,`due_date`');
    }

    private function upgradePayments(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }
        $this->addColumn('payments', 'status', "enum('posted','voided') NOT NULL DEFAULT 'posted' AFTER `amount`");
        $this->addColumn('payments', 'voided_at', 'timestamp NULL AFTER `paid_at`');
        $this->addColumn('payments', 'voided_by', 'bigint unsigned NULL AFTER `voided_at`');
        $this->addColumn('payments', 'void_reason', 'varchar(500) NULL AFTER `voided_by`');
        $this->ensureIndex('payments', 'payments_invoice_id_status_index', '`invoice_id`,`status`');
        $this->ensureForeignKey('payments', 'payments_voided_by_foreign', '`voided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL');
    }

    private function upgradeReconciliations(): void
    {
        if (! Schema::hasTable('cash_reconciliations')) {
            return;
        }
        $this->addColumn('cash_reconciliations', 'resolution_journal_entry_id', 'bigint unsigned NULL AFTER `status`');
        $this->addColumn('cash_reconciliations', 'resolved_by', 'bigint unsigned NULL AFTER `resolution_journal_entry_id`');
        $this->addColumn('cash_reconciliations', 'resolved_at', 'timestamp NULL AFTER `resolved_by`');
        $this->addColumn('cash_reconciliations', 'resolution_notes', 'text NULL AFTER `resolved_at`');
        DB::statement('ALTER TABLE `cash_reconciliations` MODIFY `status` varchar(24) NOT NULL');
        DB::statement("UPDATE `cash_reconciliations` SET `status` = CASE WHEN ABS(`difference`) < 0.005 THEN 'matched' ELSE 'variance' END WHERE `status` NOT IN ('matched','variance','resolved')");
        DB::statement("ALTER TABLE `cash_reconciliations` MODIFY `status` enum('matched','variance','resolved') NOT NULL DEFAULT 'matched'");
        $this->ensureForeignKey('cash_reconciliations', 'cash_reconciliations_resolution_entry_foreign', '`resolution_journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL');
        $this->ensureForeignKey('cash_reconciliations', 'cash_reconciliations_resolved_by_foreign', '`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL');
    }

    private function upgradeRefunds(): void
    {
        if (! Schema::hasTable('refunds')) {
            $this->createTableFromMigration('refunds', '2026_08_12_000094_create_refunds_table.php');
        }
        if (! Schema::hasTable('refunds')) {
            return;
        }

        $this->addColumn('refunds', 'credit_note_id', 'bigint unsigned NULL AFTER `invoice_id`');
        $this->addColumn('refunds', 'payment_id', 'bigint unsigned NULL AFTER `branch_id`');
        $this->addColumn('refunds', 'idempotency_key', 'varchar(100) NULL AFTER `reference`');
        $this->addColumn('refunds', 'completed_by', 'bigint unsigned NULL AFTER `processed_by`');
        $this->addColumn('refunds', 'cancelled_by', 'bigint unsigned NULL AFTER `completed_by`');
        $this->addColumn('refunds', 'reversed_by', 'bigint unsigned NULL AFTER `cancelled_by`');
        $this->addColumn('refunds', 'completed_at', 'timestamp NULL AFTER `refunded_at`');
        $this->addColumn('refunds', 'cancelled_at', 'timestamp NULL AFTER `completed_at`');
        $this->addColumn('refunds', 'reversed_at', 'timestamp NULL AFTER `cancelled_at`');
        $this->addColumn('refunds', 'reversal_reason', 'text NULL AFTER `reversed_at`');

        DB::statement("ALTER TABLE `refunds` MODIFY `method` enum('cash','card','transfer','app','credit','palpay','jawwal_pay','customer_advance','mixed','other') NOT NULL DEFAULT 'cash'");
        DB::statement("ALTER TABLE `refunds` MODIFY `status` enum('pending','completed','cancelled','reversed') NOT NULL DEFAULT 'completed'");
        DB::statement("UPDATE `refunds` SET `completed_at` = COALESCE(`completed_at`, `refunded_at`) WHERE `status` = 'completed'");

        $this->ensureIndex('refunds', 'refunds_idempotency_key_unique', '`idempotency_key`', true);
        $this->ensureIndex('refunds', 'refunds_invoice_id_status_index', '`invoice_id`,`status`');
        $this->ensureForeignKey('refunds', 'refunds_credit_note_id_foreign', '`credit_note_id`) REFERENCES `credit_notes` (`id`) ON DELETE SET NULL');
        $this->ensureForeignKey('refunds', 'refunds_payment_id_foreign', '`payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL');
        $this->ensureForeignKey('refunds', 'refunds_completed_by_foreign', '`completed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL');
        $this->ensureForeignKey('refunds', 'refunds_cancelled_by_foreign', '`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL');
        $this->ensureForeignKey('refunds', 'refunds_reversed_by_foreign', '`reversed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL');
    }

    private function upgradeCustomerAdvances(): void
    {
        $this->addColumn('customer_advance_transactions', 'refund_id', 'bigint unsigned NULL AFTER `payment_id`');
        $this->addColumn('customer_advance_transactions', 'reversed_transaction_id', 'bigint unsigned NULL AFTER `refund_id`');
        DB::statement("ALTER TABLE `customer_advance_transactions` MODIFY `type` enum('deposit','redemption','deposit_reversal','redemption_reversal','opening_balance','refund_credit','refund_credit_reversal') NOT NULL");
        $this->ensureForeignKey('customer_advance_transactions', 'customer_advance_transactions_refund_id_foreign', '`refund_id`) REFERENCES `refunds` (`id`) ON DELETE SET NULL');
        $this->ensureForeignKey('customer_advance_transactions', 'customer_advance_transactions_reversed_id_foreign', '`reversed_transaction_id`) REFERENCES `customer_advance_transactions` (`id`) ON DELETE SET NULL');
    }

    private function addColumn(string $table, string $column, string $definition): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, $column)) {
            return;
        }
        DB::statement("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        $this->changes[] = "added {$table}.{$column}";
    }

    private function createTableFromMigration(string $table, string $filename): void
    {
        if (Schema::hasTable($table)) {
            return;
        }
        $migration = require database_path("migrations/{$filename}");
        $migration->up();
        $this->changes[] = "created {$table}";
    }

    private function ensureIndex(string $table, string $name, string $columns, bool $unique = false): void
    {
        $exists = DB::table('information_schema.statistics')
            ->whereRaw('table_schema = DATABASE()')
            ->where('table_name', $table)
            ->where('index_name', $name)
            ->exists();
        if ($exists) {
            return;
        }
        DB::statement(sprintf(
            'ALTER TABLE `%s` ADD %sINDEX `%s` (%s)',
            $table,
            $unique ? 'UNIQUE ' : '',
            $name,
            $columns,
        ));
        $this->changes[] = "indexed {$table}.{$name}";
    }

    private function ensureForeignKey(string $table, string $name, string $definition): void
    {
        $exists = DB::table('information_schema.table_constraints')
            ->whereRaw('constraint_schema = DATABASE()')
            ->where('table_name', $table)
            ->where('constraint_name', $name)
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();
        if ($exists) {
            return;
        }
        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` FOREIGN KEY ({$definition}");
        $this->changes[] = "linked {$table}.{$name}";
    }
}
