<?php

use App\Enums\MonitorVisibility;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Default `private`: una pagina di stato pubblica espone il nome dei
     * servizi, e pubblicarli deve essere una scelta esplicita, non la
     * conseguenza di una dimenticanza.
     */
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->string('visibility')
                ->default(MonitorVisibility::Private->value)
                ->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn('visibility');
        });
    }
};
