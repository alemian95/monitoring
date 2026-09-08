<?php

namespace App\Models;

use App\Enums\MonitorType;
use Database\Factories\MonitorFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Monitor extends Model
{
    /** @use HasFactory<MonitorFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * I monitor attivi da controllare adesso: mai controllati, o con la
     * scadenza già passata.
     */
    #[Scope]
    protected function due(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(function (Builder $query): void {
                $query->whereNull('next_check_at')
                    ->orWhere('next_check_at', '<=', now());
            });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MonitorType::class,
            'port' => 'integer',
            'expected_status' => 'integer',
            'timeout_seconds' => 'integer',
            'interval_minutes' => 'integer',
            'is_active' => 'boolean',
            'is_up' => 'boolean',
            'next_check_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }
}
