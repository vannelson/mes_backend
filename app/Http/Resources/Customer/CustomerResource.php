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
            'status' => $this->status,
            'country' => $this->country,
            'state' => $this->state,
            'city' => $this->city,
            'address' => $this->address,
            'address_2' => $this->address_2,
            'postal_code' => $this->postal_code,
            'contact_person' => $this->contact_person,
            'contact_number' => $this->contact_number,
            'alt_contact_number' => $this->alt_contact_number,
            'phone_no' => $this->phone_no,
            'fax_no' => $this->fax_no,
            'email' => $this->email,
            'business_name' => $this->business_name,
            'business_registration_no' => $this->business_registration_no,
            'tax_id' => $this->tax_id,
            'industry' => $this->industry,
            'payment_terms' => $this->payment_terms,
            'tax_type' => $this->tax_type,
            'billing_address' => $this->billing_address,
            'shipping_address' => $this->shipping_address,
            'customer_group' => $this->customer_group,
            'sales_person_id' => $this->sales_person_id,
            'sales_representative' => $this->sales_representative,
            'pricing_tier' => $this->pricing_tier,
            'remarks' => $this->remarks,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
