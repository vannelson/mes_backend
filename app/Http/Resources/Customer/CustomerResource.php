<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_code' => $this->customer_code,
            'customer_name' => $this->customer_name,
            'customer_type' => $this->customer_type,
            'status' => $this->status,
            'country' => $this->country,
            'state_province' => $this->state_province,
            'city_municipality' => $this->city_municipality,
            'address' => $this->address,
            'postal_code' => $this->postal_code,
            'contact_person' => $this->contact_person,
            'contact_number' => $this->contact_number,
            'alt_contact_number' => $this->alt_contact_number,
            'email' => $this->email,
            'business_name' => $this->business_name,
            'business_registration_no' => $this->business_registration_no,
            'tax_id' => $this->tax_id,
            'industry' => $this->industry,
            'currency' => $this->currency,
            'payment_terms' => $this->payment_terms,
            'credit_limit' => $this->credit_limit,
            'tax_type' => $this->tax_type,
            'billing_address' => $this->billing_address,
            'shipping_address' => $this->shipping_address,
            'customer_group' => $this->customer_group,
            'sales_representative' => $this->sales_representative,
            'pricing_tier' => $this->pricing_tier,
            'discount_rate' => $this->discount_rate,
            'remarks' => $this->remarks,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
