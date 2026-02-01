<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'client_id' => 'required|exists:clients,id',
            'devise' => 'required|string|max:10',
            'participants' => 'required|array|min:1',
            'participants.*.nom' => 'required|string|max:100',
            'participants.*.prenom' => 'nullable|string|max:100',
            'participants.*.date_naissance' => 'nullable|date',
            'participants.*.passeport' => 'nullable|string|max:50',
            'participants.*.remarques' => 'nullable|string',
            'lignes' => 'required|array|min:1',
            'lignes.*.produit_id' => 'required|exists:produits,id',
            'lignes.*.designation' => 'required|string|max:255',
            'lignes.*.quantite' => 'required|integer|min:1',
            'lignes.*.prix_unitaire' => 'required|numeric|min:0',
            'lignes.*.taxe' => 'nullable|numeric|min:0',
            'lignes.*.options' => 'nullable|array',
            'notes' => 'nullable|string'
        ];
    }
}

class UpdateReservationRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'statut' => 'in:brouillon,confirmee,annulee',
            'participants' => 'array',
            'lignes' => 'array',
            'notes' => 'nullable|string'
        ];
    }
}
