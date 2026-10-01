<?php

namespace App\Models;

use App\Enums\NotificationChannelType;
use Database\Factories\NotificationChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class NotificationChannel extends Model
{
    /** @use HasFactory<NotificationChannelFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return BelongsToMany<Monitor, $this>
     */
    public function monitors(): BelongsToMany
    {
        return $this->belongsToMany(Monitor::class);
    }

    /**
     * I canali che un monitor nuovo riceve senza doverli scegliere.
     */
    #[Scope]
    protected function isDefault(Builder $query): void
    {
        $query->where('is_default', true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => NotificationChannelType::class,
            // Webhook e token: chi li ha puo' scrivere a nome nostro.
            'settings' => 'encrypted:array',
            'is_default' => 'boolean',
        ];
    }
}
