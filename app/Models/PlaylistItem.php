<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaylistItem extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'virtual_screen_id',
        'type',
        'content',
        'duration',
        'order',
        'schedule_start',
        'schedule_end',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'content' => 'array',
        'duration' => 'integer',
        'order' => 'integer',
        'is_active' => 'boolean',
        'schedule_start' => 'datetime',
        'schedule_end' => 'datetime',
    ];

    /**
     * Get the virtual screen that owns the playlist item.
     */
    public function virtualScreen(): BelongsTo
    {
        return $this->belongsTo(VirtualScreen::class);
    }

    /**
     * Scope a query to only include active items.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include scheduled items for current time.
     */
    public function scopeScheduled($query)
    {
        $now = now();

        return $query->where(function ($query) use ($now) {
            $query->whereNull('schedule_start')
                ->orWhere('schedule_start', '<=', $now);
        })->where(function ($query) use ($now) {
            $query->whereNull('schedule_end')
                ->orWhere('schedule_end', '>=', $now);
        });
    }

    /**
     * Scope a query to order by the order field.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }

    /**
     * Check if the item is currently scheduled.
     *
     * @return bool
     */
    public function isScheduledNow(): bool
    {
        $now = now();

        $startOk = is_null($this->schedule_start) || $this->schedule_start <= $now;
        $endOk = is_null($this->schedule_end) || $this->schedule_end >= $now;

        return $startOk && $endOk;
    }

    /**
     * Get the content value by key.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getContentValue(string $key, $default = null)
    {
        return $this->content[$key] ?? $default;
    }

    /**
     * Set a content value by key.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function setContentValue(string $key, $value): void
    {
        $content = $this->content ?? [];
        $content[$key] = $value;
        $this->content = $content;
    }
}
