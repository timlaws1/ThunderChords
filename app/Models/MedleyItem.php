<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedleyItem extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'medley_id',
        'song_id',
        'position',
        't',
        'c',
        'sections',
        'body',
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
            'sections' => 'array',
        ];
    }

    public function medley(): BelongsTo
    {
        return $this->belongsTo(Medley::class);
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
        $data = [
            'songId' => $this->song?->slug ?? '',
            't' => (int) $this->t,
            'c' => (int) $this->c,
        ];

        if ($this->body !== null && $this->body !== '') {
            $data['body'] = $this->body;
            $data['sections'] = null;
        } else {
            $data['sections'] = $this->sections;
        }

        return $data;
    }
}
