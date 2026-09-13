<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un target sano non risponde sempre con lo stesso codice: un endpoint
     * dietro redirect vale 200 o 301, una API può rispondere 200 o 204. Il
     * singolo `expected_status` costringeva a scegliere, quindi diventa una
     * lista.
     *
     * `expected_body_contains` copre il falso negativo opposto: un 200 che
     * serve una pagina di errore.
     */
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->json('expected_statuses')->nullable()->after('port');
            $table->text('expected_body_contains')->nullable()->after('expected_statuses');
        });

        DB::table('monitors')->orderBy('id')->each(function (object $monitor): void {
            DB::table('monitors')
                ->where('id', $monitor->id)
                ->update(['expected_statuses' => json_encode([(int) $monitor->expected_status])]);
        });

        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn('expected_status');
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->unsignedSmallInteger('expected_status')->default(200)->after('port');
        });

        DB::table('monitors')->orderBy('id')->each(function (object $monitor): void {
            $statuses = json_decode((string) $monitor->expected_statuses, true) ?: [200];

            DB::table('monitors')
                ->where('id', $monitor->id)
                ->update(['expected_status' => (int) reset($statuses)]);
        });

        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn(['expected_statuses', 'expected_body_contains']);
        });
    }
};
