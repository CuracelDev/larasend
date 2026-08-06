<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const IDEMPOTENCY_INDEX = 'emails_project_id_idempotency_key_unique';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->after('public_id');
            $table->string('idempotency_hash', 64)->nullable()->after('idempotency_key');
        });

        if (DB::connection()->getDriverName() === 'sqlsrv') {
            DB::statement('CREATE UNIQUE INDEX ['.self::IDEMPOTENCY_INDEX.'] ON [emails] ([project_id], [idempotency_key]) WHERE [idempotency_key] IS NOT NULL');

            return;
        }

        Schema::table('emails', function (Blueprint $table) {
            $table->unique(['project_id', 'idempotency_key'], self::IDEMPOTENCY_INDEX);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropUnique(self::IDEMPOTENCY_INDEX);
            $table->dropColumn(['idempotency_key', 'idempotency_hash']);
        });
    }
};
