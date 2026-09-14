<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['url', 'secret', 'event_types', 'description', 'is_active'])]
final class WebhookEndpoint extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'event_types' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class)->orderByDesc('id');
    }

    public function subscribesTo(string $eventType): bool
    {
        return in_array('*', $this->event_types, true) || in_array($eventType, $this->event_types, true);
    }
}
