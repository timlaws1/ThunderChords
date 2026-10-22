<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Song extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'slug',
        'title',
        'artist',
        'key',
        'capo',
        'tags',
        'source',
        'body',
        'added',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'added' => 'date',
            'capo' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(SongRevision::class);
    }

    public function songOpens(): HasMany
    {
        return $this->hasMany(SongOpen::class);
    }

    public function ownerSongOpen(): HasOne
    {
        return $this->hasOne(SongOpen::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->slug,
            'title' => $this->title,
            'artist' => $this->artist,
            'key' => $this->key,
            'capo' => (int) $this->capo,
            'tags' => $this->tags ?? [],
            'source' => $this->source ?? '',
            'added' => $this->added->format('Y-m-d'),
            'body' => $this->body,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toBriefArray(): array
    {
        $data = $this->toApiArray();
        unset($data['body']);

        $open = null;
        if ($this->relationLoaded('ownerSongOpen')) {
            $open = $this->ownerSongOpen;
        } elseif ($this->relationLoaded('songOpens')) {
            $open = $this->songOpens->firstWhere('user_id', $this->user_id);
        }

        $data['lastOpened'] = $open?->last_opened?->toIso8601String() ?? '';
        $data['openCount'] = $open ? (int) $open->count : 0;

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        $data = $this->toApiArray();
        unset($data['tags'], $data['source']);

        return $data;
    }
}
