<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Preference extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'key',
        't',
        'c',
        'flats',
        'speed',
        'size',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            't' => 'integer',
            'c' => 'integer',
            'flats' => 'boolean',
            'speed' => 'integer',
            'size' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
