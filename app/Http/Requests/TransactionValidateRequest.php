<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class TransactionValidateRequest extends FormRequest
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
            'card_uid' => 'required|string|max:255',
            'amount' => 'required|integer|min:500',
        ];
    }

    public function messages()
    {
        return [
            'card_uid.required' => 'UID kartu harus diisi.',
            'card_uid.string' => 'UID kartu harus berupa string.',
            'amount.required' => 'Jumlah transaksi harus diisi.',
            'amount.integer' => 'Jumlah transaksi harus berupa angka.',
            'amount.min' => 'Jumlah transaksi minimal Rp 500.',
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
