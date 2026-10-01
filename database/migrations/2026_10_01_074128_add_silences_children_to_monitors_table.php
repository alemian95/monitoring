<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un gruppo che parla per i suoi figli: quaranta target dietro lo stesso
     * router che cade sono un alert, non quaranta.
     */
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->boolean('silences_children')->default(false)->after('parent_id');
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn('silences_children');
        });
    }
};
