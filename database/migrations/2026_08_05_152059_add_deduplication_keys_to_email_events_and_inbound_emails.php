<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const EMAIL_EVENTS_INDEX = 'email_events_provider_event_id_unique';

    private const INBOUND_EMAILS_INDEX = 'inbound_emails_deduplication_key_unique';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('email_events', function (Blueprint $table) {
            $table->string('provider_event_id', 64)->nullable()->after('ses_message_id');
        });

        Schema::table('inbound_emails', function (Blueprint $table) {
            $table->string('deduplication_key', 64)->nullable()->after('message_id');
        });

        if (DB::connection()->getDriverName() === 'sqlsrv') {
            DB::statement('CREATE UNIQUE INDEX ['.self::EMAIL_EVENTS_INDEX.'] ON [email_events] ([provider_event_id]) WHERE [provider_event_id] IS NOT NULL');
            DB::statement('CREATE UNIQUE INDEX ['.self::INBOUND_EMAILS_INDEX.'] ON [inbound_emails] ([deduplication_key]) WHERE [deduplication_key] IS NOT NULL');

            return;
        }

        Schema::table('email_events', function (Blueprint $table) {
            $table->unique('provider_event_id', self::EMAIL_EVENTS_INDEX);
        });

        Schema::table('inbound_emails', function (Blueprint $table) {
            $table->unique('deduplication_key', self::INBOUND_EMAILS_INDEX);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_events', function (Blueprint $table) {
            $table->dropUnique(self::EMAIL_EVENTS_INDEX);
            $table->dropColumn('provider_event_id');
        });

        Schema::table('inbound_emails', function (Blueprint $table) {
            $table->dropUnique(self::INBOUND_EMAILS_INDEX);
            $table->dropColumn('deduplication_key');
        });
    }
};
