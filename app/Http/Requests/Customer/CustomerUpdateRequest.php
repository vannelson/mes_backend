<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $customerId = $this->route('id') ?? $this->route('customer');

        return [
            'customer_code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('customers', 'customer_code')->ignore($customerId)],
            'customer_name' => ['sometimes', 'required', 'string', 'max:200'],
            'customer_type' => ['sometimes', 'required', 'in:Individual,Company'],
            'status' => ['sometimes', 'required', 'in:Active,Inactive'],
            'country' => ['nullable', 'string', 'max:100'],
            'state_province' => ['nullable', 'string', 'max:120'],
            'city_municipality' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'alt_contact_number' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:150'],
            'business_name' => ['nullable', 'string', 'max:200'],
            'business_registration_no' => ['nullable', 'string', 'max:100'],
            'tax_id' => ['nullable', 'string', 'max:100'],
            'industry' => ['nullable', 'string', 'max:120'],
            'currency' => ['nullable', 'string', 'max:10'],
            'payment_terms' => ['nullable', 'string', 'max:100'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'tax_type' => ['nullable', 'in:VAT,Non-VAT'],
            'billing_address' => ['nullable', 'string', 'max:255'],
            'shipping_address' => ['nullable', 'string', 'max:255'],
            'customer_group' => ['nullable', 'string', 'max:120'],
            'sales_representative' => ['nullable', 'string', 'max:150'],
            'pricing_tier' => ['nullable', 'string', 'max:50'],
            'discount_rate' => ['nullable', 'numeric', 'between:0,100'],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
