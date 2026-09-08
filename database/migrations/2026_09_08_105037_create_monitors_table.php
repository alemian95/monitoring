<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('monitors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('http');
            $table->string('target');
            $table->unsignedSmallInteger('port')->nullable();
            $table->unsignedSmallInteger('expected_status')->default(200);
            $table->unsignedSmallInteger('timeout_seconds')->default(10);
            $table->unsignedSmallInteger('interval_minutes')->default(1);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_up')->nullable();
            $table->timestamp('next_check_at')->nullable()->index();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_failure_reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monitors');
    }
};
