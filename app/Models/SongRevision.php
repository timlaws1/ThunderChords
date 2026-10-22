<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SongRevision extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'song_id',
        'body',
        'key',
        'capo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capo' => 'integer',
        ];
    }

    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }
}
