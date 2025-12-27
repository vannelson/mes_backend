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
        'status',
        'country',
        'state',
        'city',
        'address',
        'address_2',
        'postal_code',
        'contact_person',
        'contact_number',
        'alt_contact_number',
        'phone_no',
        'fax_no',
        'email',
        'business_name',
        'business_registration_no',
        'tax_id',
        'industry',
        'payment_terms',
        'tax_type',
        'billing_address',
        'shipping_address',
        'customer_group',
        'sales_person_id',
        'sales_representative',
        'pricing_tier',
        'remarks',
        'created_by',
        'updated_by',
    ];

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }
}
