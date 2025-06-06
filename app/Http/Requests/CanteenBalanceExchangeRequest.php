<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CanteenBalanceExchangeRequest extends FormRequest
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
        return [
            'amount' => 'required|integer|min:500'
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Jumlah pencairan harus diisi.',
            'amount.integer' => 'Jumlah pencairan harus berupa angka.',
            'amount.min' => 'Jumlah pencairan minimal Rp 500.'
        ];
    }
}
