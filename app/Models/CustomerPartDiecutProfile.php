<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerPartDiecutProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'diecut_profile_id',
        'customer_code',
        'customer_part_number',
        'normalized_customer_part_number',
        'source_sheet',
        'source_batch',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(DiecutProfile::class, 'diecut_profile_id');
    }
}
