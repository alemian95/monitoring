<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Il gruppo a cui appartiene un monitor. Cancellare il gruppo libera i
     * figli invece di cancellarli: sono target veri, con il loro storico.
     */
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->foreignId('parent_id')->nullable()->after('id')->constrained('monitors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
