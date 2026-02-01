<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePaiementRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'montant' => 'required|numeric|min:0',
            'mode_paiement' => 'required|string|max:50', // espece, carte, virement, mobile_money...
            'reference' => 'nullable|string|max:100',
            'date_paiement' => 'required|date',
            'statut' => 'required|string|in:recu,en_attente,echoue',
            'notes' => 'nullable|string'
        ];
    }
}
