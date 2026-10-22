<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Medley extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'slug',
        'name',
        'target_key',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MedleyItem::class)->orderBy('position');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->slug,
            'name' => $this->name,
            'targetKey' => $this->target_key ?? '',
            'items' => $this->relationLoaded('items')
                ? $this->items->map(fn (MedleyItem $item) => $item->toApiArray())->values()->all()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toBriefArray(): array
    {
        return [
            'id' => $this->slug,
            'name' => $this->name,
            'count' => $this->relationLoaded('items') ? $this->items->count() : $this->items()->count(),
            'created' => $this->created_at?->format('Y-m-d') ?? '',
        ];
    }
}
