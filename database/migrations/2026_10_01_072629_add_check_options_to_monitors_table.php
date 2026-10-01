<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quattro domande in piu' che un check puo' fare: il risultato capovolto,
     * il testo che non deve esserci, un valore dentro una risposta JSON, e un
     * database che accetta ancora connessioni.
     *
     * `connection_url` e non `target`: contiene la password, quindi va cifrata
     * e non deve finire nel testo degli alert ne' nella tabella del pannello,
     * dove invece `target` compare.
     */
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->boolean('is_inverted')->default(false)->after('is_active');
            $table->boolean('invert_keyword')->default(false)->after('expected_body_contains');
            $table->string('json_path')->nullable()->after('invert_keyword');
            $table->string('json_expected_value')->nullable()->after('json_path');
            $table->text('connection_url')->nullable()->after('target');
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn(['is_inverted', 'invert_keyword', 'json_path', 'json_expected_value', 'connection_url']);
        });
    }
};
