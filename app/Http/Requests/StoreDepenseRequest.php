<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDepenseRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'date_depense' => ['required','date'],
            'categorie' => ['required','string','max:50'],
            'libelle' => ['required','string','max:190'],
            'fournisseur_nom' => ['nullable','string','max:190'],
            'reference' => ['nullable','string','max:190'],
            'montant' => ['required','numeric','min:0'],
            'mode_paiement' => ['nullable','string','max:50'],
            'statut' => ['nullable','in:paye,en_attente'],
            'reservation_id' => ['nullable','exists:reservations,id'],
            'notes' => ['nullable','string'],
        ];
    }
}
