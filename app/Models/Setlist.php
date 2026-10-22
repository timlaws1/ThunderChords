<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Setlist extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'slug',
        'name',
        'created',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SetlistItem::class)->orderBy('position');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->slug,
            'name' => $this->name,
            'created' => $this->created->format('Y-m-d'),
            'items' => $this->relationLoaded('items')
                ? $this->items->map(fn (SetlistItem $item) => $item->toApiArray())->values()->all()
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
            'created' => $this->created->format('Y-m-d'),
        ];
    }
}
