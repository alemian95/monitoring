<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Legge la data di scadenza del certificato TLS servito da un target.
 *
 * Sta fuori dal probe per scelta: un certificato non scade fra un minuto e
 * l'altro, e metterlo nel check per-minuto significherebbe una connessione TLS
 * in più ogni sessanta secondi per un dato che cambia una volta ogni tre mesi.
 */
class CertificateReader
{
    private const TIMEOUT_SECONDS = 10;

    /**
     * La scadenza del certificato, o `null` se non si è riusciti a leggerla —
     * host irraggiungibile, porta chiusa, risposta non TLS.
     */
    public function expiresAt(string $url): ?Carbon
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host)) {
            return null;
        }

        $port = parse_url($url, PHP_URL_PORT) ?? 443;

        // `verify_peer => false` perché qui si sta leggendo una data, non ci si
        // sta fidando della connessione: senza quel flag un certificato già
        // scaduto — il caso peggiore — non si riuscirebbe nemmeno a leggere.
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'peer_name' => $host,
            ],
        ]);

        $connection = @stream_socket_client(
            "ssl://{$host}:{$port}",
            $errno,
            $errstr,
            self::TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($connection === false) {
            return null;
        }

        $certificate = stream_context_get_params($connection)['options']['ssl']['peer_certificate'] ?? null;

        fclose($connection);

        $parsed = $certificate === null ? false : openssl_x509_parse($certificate);

        return isset($parsed['validTo_time_t'])
            ? Carbon::createFromTimestamp($parsed['validTo_time_t'])
            : null;
    }
}
