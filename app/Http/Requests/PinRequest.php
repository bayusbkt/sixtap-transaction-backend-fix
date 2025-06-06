<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class PinRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'pin' => 'required|digits:6'
        ];

        if ($this->has('old_pin')) {
            $rules['old_pin'] = 'required|digits:6';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'old_pin.required' => 'PIN lama wajib diisi.',
            'old_pin.digits'   => 'PIN lama harus terdiri dari 6 digit angka.',
            'pin.required' => 'PIN wajib diisi.',
            'pin.digits'   => 'PIN harus terdiri dari 6 digit angka.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'message' => 'Validasi gagal.',
            'errors' => $validator->errors()
        ], 422));
    }
}
