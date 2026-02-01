<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProduitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'required', Rule::in(['billet_avion','hotel','voiture','evenement'])],
            'nom' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string'],
            'prix_base' => ['sometimes', 'required', 'numeric', 'min:0'],

            // si tu gardes ces champs en DB / API:
            'devise' => ['sometimes', 'nullable', 'string', 'max:10'],
            'actif' => ['sometimes', Rule::in([0,1])],
        ];
    }
}
