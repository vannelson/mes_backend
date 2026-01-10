<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class VirtualScreen extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'description',
        'share_token',
        'orientation',
        'aspect_ratio',
        'timezone',
        'refresh_interval',
        'is_active',
        'settings',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
        'refresh_interval' => 'integer',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($screen) {
            if (empty($screen->share_token)) {
                $screen->share_token = static::generateUniqueShareToken();
            }
        });
    }

    /**
     * Generate a unique share token.
     *
     * @return string
     */
    public static function generateUniqueShareToken(): string
    {
        do {
            $token = Str::random(32);
        } while (static::where('share_token', $token)->exists());

        return $token;
    }

    /**
     * Get the user that owns the virtual screen.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the playlist items for the virtual screen.
     */
    public function playlistItems(): HasMany
    {
        return $this->hasMany(PlaylistItem::class)->orderBy('order');
    }

    /**
     * Get the media files for the virtual screen.
     */
    public function media(): HasMany
    {
        return $this->hasMany(ScreenMedia::class);
    }

    /**
     * Scope a query to only include active screens.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to filter by user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Get the public player URL.
     *
     * @return string
     */
    public function getPlayerUrlAttribute(): string
    {
        return url("/player/{$this->share_token}");
    }

    /**
     * Get active playlist items with scheduling applied.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActivePlaylist()
    {
        $now = now();

        return $this->playlistItems()
            ->where('is_active', true)
            ->where(function ($query) use ($now) {
                $query->whereNull('schedule_start')
                    ->orWhere('schedule_start', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('schedule_end')
                    ->orWhere('schedule_end', '>=', $now);
            })
            ->orderBy('order')
            ->get();
    }
}
