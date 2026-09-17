<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Due colonne, non una: `certificate_expires_at` è la scadenza letta
     * dall'ultimo giro, `certificate_alerted_at` dice se per *quella* scadenza
     * l'avviso è già partito.
     *
     * Insieme fanno la dedup: si allerta una volta sola sotto soglia, e quando
     * la scadenza letta non coincide più con quella salvata il certificato è
     * stato rinnovato, quindi l'avviso riparte da zero.
     */
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->timestamp('certificate_expires_at')->nullable()->after('last_failure_reason');
            $table->timestamp('certificate_alerted_at')->nullable()->after('certificate_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn(['certificate_expires_at', 'certificate_alerted_at']);
        });
    }
};
