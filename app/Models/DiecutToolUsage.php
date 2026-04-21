<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiecutToolUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'diecut_tool_id',
        'diecut_profile_id',
        'usage_date',
        'machine_no',
        'customer_code',
        'work_order_no',
        'customer_part_number',
        'cavity',
        'printed_qty',
        'number_of_press',
        'source_sheet',
        'source_batch',
        'metadata',
    ];

    protected $casts = [
        'usage_date' => 'date',
        'cavity' => 'float',
        'printed_qty' => 'float',
        'number_of_press' => 'float',
        'metadata' => 'array',
    ];

    public function tool(): BelongsTo
    {
        return $this->belongsTo(DiecutTool::class, 'diecut_tool_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(DiecutProfile::class, 'diecut_profile_id');
    }
}
