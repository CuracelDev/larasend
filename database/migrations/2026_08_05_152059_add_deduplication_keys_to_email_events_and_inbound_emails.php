<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('email_events', function (Blueprint $table) {
            $table->string('provider_event_id', 64)->nullable()->unique()->after('ses_message_id');
        });

        Schema::table('inbound_emails', function (Blueprint $table) {
            $table->string('deduplication_key', 64)->nullable()->unique()->after('message_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_events', function (Blueprint $table) {
            $table->dropUnique(['provider_event_id']);
            $table->dropColumn('provider_event_id');
        });

        Schema::table('inbound_emails', function (Blueprint $table) {
            $table->dropUnique(['deduplication_key']);
            $table->dropColumn('deduplication_key');
        });
    }
};
