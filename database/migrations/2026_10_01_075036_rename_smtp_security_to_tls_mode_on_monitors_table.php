<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La stessa scelta — in chiaro, STARTTLS o TLS dal primo byte — vale ora
     * anche per IMAP e POP3: il nome della colonna non e' piu' di SMTP.
     */
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->renameColumn('smtp_security', 'tls_mode');
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->renameColumn('tls_mode', 'smtp_security');
        });
    }
};
