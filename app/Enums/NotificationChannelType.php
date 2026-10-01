<?php

namespace App\Enums;

/**
 * Dove puo' arrivare un alert.
 *
 * Quasi tutti sono una POST verso un webhook o un'API: cambia solo la forma
 * del payload, che sta in `SendAlert`. Slack copre anche Mattermost e
 * Rocket.Chat, che ne accettano lo stesso webhook in ingresso.
 */
enum NotificationChannelType: string
{
    case Discord = 'discord';
    case Email = 'email';
    case Webhook = 'webhook';
    case Telegram = 'telegram';
    case Slack = 'slack';
    case Teams = 'teams';
    case Ntfy = 'ntfy';
    case Gotify = 'gotify';
    case Pushover = 'pushover';

    public function label(): string
    {
        return match ($this) {
            self::Discord => 'Discord',
            self::Email => 'Email',
            self::Webhook => 'Webhook generico (JSON)',
            self::Telegram => 'Telegram',
            self::Slack => 'Slack / Mattermost / Rocket.Chat',
            self::Teams => 'Microsoft Teams (Workflows)',
            self::Ntfy => 'ntfy',
            self::Gotify => 'Gotify',
            self::Pushover => 'Pushover',
        };
    }

    /**
     * Se il canale rende il `**grassetto**` con cui sono scritti gli alert.
     * Gli altri lo riceverebbero con gli asterischi in vista, quindi lo
     * ricevono senza.
     */
    public function rendersMarkdown(): bool
    {
        return match ($this) {
            self::Discord, self::Teams, self::Ntfy, self::Gotify => true,
            default => false,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])
            ->all();
    }
}
