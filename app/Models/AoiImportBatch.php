<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AoiImportBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_type',
        'source_file_name',
        'source_file_path',
        'import_status',
        'row_count',
        'imported_count',
        'duplicate_count',
        'started_by_user_id',
        'imported_at',
        'mapping',
        'notes',
    ];

    protected $casts = [
        'imported_at' => 'datetime',
        'mapping' => 'array',
    ];

    public function measurements(): HasMany
    {
        return $this->hasMany(AoiMeasurementHeader::class);
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }
}
