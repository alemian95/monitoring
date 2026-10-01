<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Come parlare con un server SMTP: in chiaro, con STARTTLS o in TLS fin
     * dal primo byte. Una colonna sua perche' la porta non basta a dedurlo —
     * 587 senza STARTTLS e' un server configurato male, ed e' proprio cio' che
     * il check deve saper vedere.
     */
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->string('smtp_security')->nullable()->after('port');
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn('smtp_security');
        });
    }
};
