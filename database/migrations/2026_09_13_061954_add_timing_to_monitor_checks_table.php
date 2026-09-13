<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lo storico sapeva dire *se* un target era su, non cosa avesse risposto.
     * Con status e motivo del fallimento per riga la tabella diventa un
     * registro di incidenti invece che una serie di booleani, e il tempo di
     * risposta rende visibile il degrado prima del down.
     *
     * Tutte nullable: un timeout non ha status ne' tempo, e le righe gia'
     * registrate non hanno nulla di tutto questo.
     */
    public function up(): void
    {
        Schema::table('monitor_checks', function (Blueprint $table): void {
            $table->unsignedSmallInteger('status_code')->nullable()->after('is_up');
            $table->unsignedInteger('response_time_ms')->nullable()->after('status_code');
            $table->text('failure_reason')->nullable()->after('response_time_ms');
        });

        Schema::table('monitors', function (Blueprint $table): void {
            $table->unsignedInteger('max_response_time_ms')->nullable()->after('timeout_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_checks', function (Blueprint $table): void {
            $table->dropColumn(['status_code', 'response_time_ms', 'failure_reason']);
        });

        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn('max_response_time_ms');
        });
    }
};
