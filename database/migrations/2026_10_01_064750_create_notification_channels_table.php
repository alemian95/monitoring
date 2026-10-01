<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Le destinazioni degli alert. `settings` e' cifrata: dentro ci sono
     * webhook e token, che valgono quanto una password.
     */
    public function up(): void
    {
        Schema::create('notification_channels', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type');
            $table->text('settings');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_channels');
    }
};
