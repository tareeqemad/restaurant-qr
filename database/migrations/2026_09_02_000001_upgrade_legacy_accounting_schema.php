<?php

use App\Services\Deployment\AccountingSchemaUpgrade;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $upgrade = app(AccountingSchemaUpgrade::class);

        if ($upgrade->supportsCurrentConnection() && $upgrade->pendingChanges() !== []) {
            $upgrade->apply();
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: dropping accounting/audit columns from
        // an existing restaurant database would destroy business evidence.
    }
};
