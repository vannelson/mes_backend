<?php

namespace App\Http\Requests\CalibrationMaster;

class CalibrationMasterUpdateRequest extends CalibrationMasterStoreRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['name_type'] = ['sometimes', 'required', 'string'];

        return $rules;
    }
}
