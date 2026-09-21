<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Tre tipi di check in piu' sulla stessa tabella: HTTP con metodo, header e
     * corpo; DNS; e il push monitor, che e' l'unico invertito — non lo
     * chiamiamo noi, ci chiama lui.
     *
     * `target` diventa nullable perche' un push monitor non ha un indirizzo da
     * contattare: il job che sorveglia e' identificato dal nome e dal token.
     */
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->string('http_method')->default('GET')->after('target');
            $table->text('http_headers')->nullable()->after('expected_body_contains');
            $table->text('http_body')->nullable()->after('http_headers');
            $table->string('dns_record_type')->nullable()->after('http_body');
            $table->string('ping_token')->nullable()->unique()->after('dns_record_type');
            $table->unsignedSmallInteger('grace_minutes')->nullable()->after('interval_minutes');
            $table->timestamp('last_ping_at')->nullable()->after('last_checked_at');
            $table->text('last_ping_failure')->nullable()->after('last_ping_at');
        });

        // Il token esiste su ogni monitor, non solo sui push: cosi' cambiare
        // tipo a un monitor esistente non richiede di generarlo al volo, e
        // `pingUrl()` resta una lettura pura.
        DB::table('monitors')->orderBy('id')->each(function (object $monitor): void {
            DB::table('monitors')
                ->where('id', $monitor->id)
                ->update(['ping_token' => Str::random(40)]);
        });

        Schema::table('monitors', function (Blueprint $table): void {
            $table->string('target')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn([
                'http_method',
                'http_headers',
                'http_body',
                'dns_record_type',
                'ping_token',
                'grace_minutes',
                'last_ping_at',
                'last_ping_failure',
            ]);
        });

        DB::table('monitors')->whereNull('target')->update(['target' => '']);

        Schema::table('monitors', function (Blueprint $table): void {
            $table->string('target')->nullable(false)->change();
        });
    }
};
