<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_code',
        'supplier_name',
        'status',
        'contact_person',
        'contact_number',
        'email',
        'address',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function qualityIssues(): HasMany
    {
        return $this->hasMany(QualityIssue::class);
    }
}
