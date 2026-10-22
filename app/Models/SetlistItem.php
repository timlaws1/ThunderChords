<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SetlistItem extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'setlist_id',
        'song_id',
        'position',
        't',
        'c',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            't' => 'integer',
            'c' => 'integer',
            'position' => 'integer',
        ];
    }

    public function setlist(): BelongsTo
    {
        return $this->belongsTo(Setlist::class);
    }

    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'songId' => $this->song?->slug ?? '',
            't' => (int) $this->t,
            'c' => (int) $this->c,
        ];
    }
}
