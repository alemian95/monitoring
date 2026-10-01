<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ogni monitor sceglie i suoi canali.
     *
     * Il webhook Discord che prima stava in `DISCORD_ALERT_WEBHOOK` diventa il
     * primo canale, predefinito e collegato a tutti i monitor esistenti: chi
     * aggiorna continua a ricevere gli alert dove li riceveva, senza dover
     * passare dal pannello.
     */
    public function up(): void
    {
        Schema::create('monitor_notification_channel', function (Blueprint $table): void {
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('notification_channel_id')->constrained()->cascadeOnDelete();
            $table->primary(['monitor_id', 'notification_channel_id']);
        });

        $webhook = config('services.discord.legacy_webhook_url');

        if (blank($webhook)) {
            return;
        }

        $channelId = DB::table('notification_channels')->insertGetId([
            'name' => 'Discord',
            'type' => 'discord',
            'settings' => Crypt::encryptString(json_encode(['webhook_url' => $webhook], JSON_THROW_ON_ERROR)),
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('monitors')->orderBy('id')->each(function (object $monitor) use ($channelId): void {
            DB::table('monitor_notification_channel')->insert([
                'monitor_id' => $monitor->id,
                'notification_channel_id' => $channelId,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_notification_channel');
    }
};
