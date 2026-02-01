<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDepenseRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'date_depense' => ['sometimes','date'],
            'categorie' => ['sometimes','string','max:50'],
            'libelle' => ['sometimes','string','max:190'],
            'fournisseur_nom' => ['nullable','string','max:190'],
            'reference' => ['nullable','string','max:190'],
            'montant' => ['sometimes','numeric','min:0'],
            'mode_paiement' => ['nullable','string','max:50'],
            'statut' => ['nullable','in:paye,en_attente'],
            'reservation_id' => ['nullable','exists:reservations,id'],
            'notes' => ['nullable','string'],
        ];
    }
}
