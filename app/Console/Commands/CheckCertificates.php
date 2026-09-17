<?php

namespace App\Console\Commands;

use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Support\AlertChannel;
use App\Support\CertificateReader;
use Illuminate\Console\Command;

/**
 * Avvisa prima che un certificato TLS scada.
 *
 * È previsione, non rilevamento: un certificato già scaduto fa fallire la
 * verifica TLS del probe e genera già un alert di down. Questo comando serve a
 * saperlo quattordici giorni prima, quando c'è ancora tempo per rinnovarlo.
 */
class CheckCertificates extends Command
{
    protected $signature = 'monitor:certificates';

    protected $description = 'Avvisa quando un certificato TLS sta per scadere';

    /** Giorni di preavviso. */
    private const ALERT_WITHIN_DAYS = 14;

    public function handle(CertificateReader $reader, AlertChannel $alerts): int
    {
        Monitor::query()
            ->where('is_active', true)
            ->where('type', MonitorType::Http)
            ->where('target', 'like', 'https://%')
            ->each(fn (Monitor $monitor) => $this->checkCertificate($monitor, $reader, $alerts));

        return self::SUCCESS;
    }

    private function checkCertificate(Monitor $monitor, CertificateReader $reader, AlertChannel $alerts): void
    {
        $expiresAt = $reader->expiresAt($monitor->target);

        if ($expiresAt === null) {
            // Un certificato illeggibile non merita un alert suo: se il target è
            // davvero irraggiungibile lo dice già il check per-minuto, e
            // duplicarlo qui vorrebbe dire due messaggi per lo stesso guasto.
            $this->warn("Certificato non leggibile per {$monitor->name} ({$monitor->target}).");

            return;
        }

        if ($monitor->certificate_expires_at?->equalTo($expiresAt) !== true) {
            $monitor->update([
                'certificate_expires_at' => $expiresAt,
                'certificate_alerted_at' => null,
            ]);
        }

        if ($expiresAt->greaterThan(now()->addDays(self::ALERT_WITHIN_DAYS))
            || $monitor->certificate_alerted_at !== null) {
            return;
        }

        $monitor->update(['certificate_alerted_at' => now()]);

        $days = (int) now()->startOfDay()->diffInDays($expiresAt);
        $when = $days <= 0 ? 'è scaduto' : "scade fra {$days} giorni";

        $alerts->send("⚠️ **{$monitor->name}** — il certificato TLS {$when} ({$expiresAt->toDateString()}) — {$monitor->target}");
    }
}
