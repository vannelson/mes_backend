<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class CustomerStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_code' => ['required', 'string', 'max:50', 'unique:customers,customer_code'],
            'customer_name' => ['required', 'string', 'max:200'],
            'status' => ['required', 'in:Active,Inactive'],
            'country' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'address_2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'alt_contact_number' => ['nullable', 'string', 'max:50'],
            'phone_no' => ['nullable', 'string', 'max:50'],
            'fax_no' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:150'],
            'business_name' => ['nullable', 'string', 'max:200'],
            'business_registration_no' => ['nullable', 'string', 'max:100'],
            'tax_id' => ['nullable', 'string', 'max:100'],
            'industry' => ['nullable', 'string', 'max:120'],
            'payment_terms' => ['nullable', 'string', 'max:100'],
            'tax_type' => ['nullable', 'in:VAT,Non-VAT'],
            'billing_address' => ['nullable', 'string', 'max:255'],
            'shipping_address' => ['nullable', 'string', 'max:255'],
            'customer_group' => ['nullable', 'string', 'max:120'],
            'sales_person_id' => ['nullable', 'integer'],
            'sales_representative' => ['nullable', 'string', 'max:150'],
            'pricing_tier' => ['nullable', 'string', 'max:50'],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
