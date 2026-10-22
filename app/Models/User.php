<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'legacy_password'])]
#[Hidden(['password', 'remember_token', 'legacy_password'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function songs(): HasMany
    {
        return $this->hasMany(Song::class);
    }

    public function setlists(): HasMany
    {
        return $this->hasMany(Setlist::class);
    }

    public function medleys(): HasMany
    {
        return $this->hasMany(Medley::class);
    }

    public function shares(): HasMany
    {
        return $this->hasMany(Share::class);
    }

    public function preferences(): HasMany
    {
        return $this->hasMany(Preference::class);
    }

    public function songOpens(): HasMany
    {
        return $this->hasMany(SongOpen::class);
    }
}
