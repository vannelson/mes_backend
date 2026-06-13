<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HolidayCalendar extends Model
{
    use HasFactory;

    protected $fillable = [
        'holiday_date',
        'title',
        'category',
        'is_working_day',
        'notes',
    ];

    protected $casts = [
        'holiday_date' => 'date',
        'is_working_day' => 'boolean',
    ];
}
