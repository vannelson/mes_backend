<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_code',
        'customer_name',
        'customer_type',
        'status',
        'country',
        'state',
        'city',
        'address',
        'postal_code',
        'contact_person',
        'contact_number',
        'alt_contact_number',
        'email',
        'business_name',
        'business_registration_no',
        'tax_id',
        'industry',
        'currency',
        'payment_terms',
        'credit_limit',
        'tax_type',
        'billing_address',
        'shipping_address',
        'customer_group',
        'sales_representative',
        'pricing_tier',
        'discount_rate',
        'remarks',
        'created_by',
        'updated_by',
    ];

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }
}
