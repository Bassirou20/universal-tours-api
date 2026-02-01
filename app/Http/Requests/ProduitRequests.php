<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProduitRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'type' => 'required|in:billet_avion,hotel,voiture,evenement',
            'nom' => 'required|string|max:150',
            'description' => 'nullable|string',
            'prix_base' => 'required|numeric|min:0',
            'devise' => 'required|string|max:10',
            'actif' => 'boolean'
        ];
    }
}

class UpdateProduitRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'type' => 'sometimes|required|in:billet_avion,hotel,voiture,evenement',
            'nom' => 'sometimes|required|string|max:150',
            'description' => 'nullable|string',
            'prix_base' => 'sometimes|required|numeric|min:0',
            'devise' => 'sometimes|required|string|max:10',
            'actif' => 'boolean'
        ];
    }
}
