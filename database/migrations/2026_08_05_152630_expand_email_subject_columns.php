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
        Schema::table('emails', function (Blueprint $table) {
            $table->text('subject')->change();
        });

        Schema::table('templates', function (Blueprint $table) {
            $table->text('subject')->change();
        });

        Schema::table('inbound_emails', function (Blueprint $table) {
            $table->text('subject')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->string('subject')->change();
        });

        Schema::table('templates', function (Blueprint $table) {
            $table->string('subject')->change();
        });

        Schema::table('inbound_emails', function (Blueprint $table) {
            $table->string('subject')->nullable()->change();
        });
    }
};
