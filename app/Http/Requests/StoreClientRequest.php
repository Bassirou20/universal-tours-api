<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'nom' => 'required|string|max:100',
            'prenom' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:150',
            'telephone' => 'nullable|string|max:50',
            'adresse' => 'nullable|string|max:255',
            'pays' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
        ];
    }
}
