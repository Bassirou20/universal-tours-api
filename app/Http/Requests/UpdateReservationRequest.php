<?php

namespace App\Http\Requests;

use App\Models\Produit;
use App\Models\Reservation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // ✅ permettre de changer le client en update
            'client_id' => ['sometimes', 'nullable', 'exists:clients,id'],

            // type optionnel en update
            'type' => ['sometimes', Rule::in(Reservation::TYPES)],

            // produit/forfait optionnels en update (contrôlés ensuite)
            'produit_id' => ['sometimes', 'nullable', 'exists:produits,id'],
            'forfait_id' => ['sometimes', 'nullable', 'exists:forfaits,id'],

            // Statut
            'statut' => ['sometimes', Rule::in(['en_attente', 'confirmee', 'annulee'])],

            // Participants
            'participants' => ['sometimes', 'array'],
            'participants.*.nom' => ['required_with:participants', 'string', 'max:100'],
            'participants.*.prenom' => ['nullable', 'string', 'max:100'],
            'participants.*.age' => ['nullable', 'integer', 'min:0', 'max:120'],

            // Passager / bénéficiaire (UPDATE)
            'passenger_is_client' => ['sometimes', 'boolean'],

            'passenger_id' => ['sometimes', 'nullable', 'exists:participants,id'],

            'passenger' => ['sometimes', 'array'],
            'passenger.nom' => ['required_with:passenger', 'string', 'max:100'],
            'passenger.prenom' => ['nullable', 'string', 'max:100'],
            'passenger.passport' => ['nullable', 'string', 'max:80'],
            'passenger.sexe' => ['nullable', 'in:M,F'],

            // ✅ Champs vol (root) - tous optionnels + NULLABLE
            'ville_depart'  => ['sometimes', 'nullable', 'string', 'max:100'],
            'ville_arrivee' => ['sometimes', 'nullable', 'string', 'max:100'],
            'date_depart'   => ['sometimes', 'nullable', 'date'], // ✅ nullable
            'date_arrivee'  => ['sometimes', 'nullable', 'date'], // ✅ nullable
            'compagnie'     => ['sometimes', 'nullable', 'string', 'max:100'],
            'pnr'           => ['sometimes', 'nullable', 'string', 'max:50'],
            'classe'        => ['sometimes', 'nullable', 'string', 'max:50'],

            // Montants
            'montant_sous_total' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'montant_taxes'      => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'montant_total'      => ['sometimes', 'nullable', 'numeric', 'min:0'],

            // Autres
            'nombre_personnes' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'notes' => ['sometimes', 'nullable', 'string'],

            // Assurance details (conditionnel)
            'assurance_details' => ['sometimes', 'array'],
            'assurance_details.libelle' => ['required_with:assurance_details', 'string', 'max:255'],
            'assurance_details.date_debut' => ['sometimes','nullable' , 'date'],
            'assurance_details.date_fin' => ['sometimes','nullable', 'date'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($v) {
            /** @var \App\Models\Reservation|null $reservation */
            $reservation = $this->route('reservation');

            $finalType = $this->input('type', $reservation?->type);

            $finalProduitId = $this->exists('produit_id')
                ? $this->input('produit_id')
                : ($reservation?->produit_id);

            $finalForfaitId = $this->exists('forfait_id')
                ? $this->input('forfait_id')
                : ($reservation?->forfait_id);

            // -----------------------------
            // 1) BILLET AVION
            // -----------------------------
            if ($finalType === Reservation::TYPE_BILLET_AVION) {

                if (!is_null($finalProduitId)) {
                    $v->errors()->add('produit_id', "Un billet d'avion ne doit pas être lié à un produit.");
                }
                if (!is_null($finalForfaitId)) {
                    $v->errors()->add('forfait_id', "Un billet d'avion ne doit pas être lié à un forfait.");
                }

                /**
                 * ✅ Nouvelle règle:
                 * - En UPDATE, si l'utilisateur envoie AU MOINS 1 champ vol,
                 *   on accepte que date_depart / date_arrivee soient NULL (ton souhait),
                 *   mais on exige au minimum ville_depart + ville_arrivee (et compagnie si tu veux).
                 */
                $flightFields = ['ville_depart', 'ville_arrivee', 'date_depart', 'date_arrivee', 'compagnie', 'pnr', 'classe'];

                $anyFlightFieldSent = false;
                foreach ($flightFields as $field) {
                    if ($this->exists($field)) {
                        $anyFlightFieldSent = true;
                        break;
                    }
                }

                if ($anyFlightFieldSent) {
                    // ✅ on exige juste les villes (le reste peut être null)
                    if (!$this->filled('ville_depart')) {
                        $v->errors()->add('ville_depart', "Le champ ville_depart est requis si tu modifies le vol.");
                    }
                    if (!$this->filled('ville_arrivee')) {
                        $v->errors()->add('ville_arrivee', "Le champ ville_arrivee est requis si tu modifies le vol.");
                    }

                    // Optionnel: exiger compagnie si tu veux
                    // if (!$this->filled('compagnie')) {
                    //     $v->errors()->add('compagnie', "Le champ compagnie est requis si tu modifies le vol.");
                    // }
                }

                // ✅ cohérence dates seulement si les 2 sont présentes et non null
                if ($this->filled('date_depart') && $this->filled('date_arrivee')) {
                    try {
                        $depart = new \DateTime($this->input('date_depart'));
                        $arrivee = new \DateTime($this->input('date_arrivee'));
                        if ($arrivee < $depart) {
                            $v->errors()->add('date_arrivee', "La date d'arrivée doit être >= à la date de départ.");
                        }
                    } catch (\Exception $e) {
                        // rules date couvrent déjà
                    }
                }

                return;
            }

            // -----------------------------
            // 2) FORFAIT
            // -----------------------------
            if ($finalType === Reservation::TYPE_FORFAIT) {
                if (is_null($finalForfaitId)) {
                    $v->errors()->add('forfait_id', "Le forfait est obligatoire pour une réservation de type forfait.");
                }
                if (!is_null($finalProduitId)) {
                    $v->errors()->add('produit_id', "Un forfait ne doit pas être lié à un produit.");
                }
                return;
            }

            // -----------------------------
            // 3) ASSURANCE
            // -----------------------------
            if ($finalType === Reservation::TYPE_ASSURANCE) {
                // produit/forfait interdits
                if (!is_null($finalProduitId)) {
                    $v->errors()->add('produit_id', "Une assurance ne doit pas être liée à un produit.");
                }
                if (!is_null($finalForfaitId)) {
                    $v->errors()->add('forfait_id', "Une assurance ne doit pas être liée à un forfait.");
                }
                return;
            }

            if ($this->exists('passenger_id') && $this->input('passenger_id')) {
                $pid = $this->input('passenger_id');
                $reservation = $this->route('reservation');

                $ok = \App\Models\Participant::where('id', $pid)
                    ->where('reservation_id', $reservation->id)
                    ->exists();

                if (!$ok) {
                    $v->errors()->add('passenger_id', "Ce passenger_id n'appartient pas à cette réservation.");
                }
            }

            // -----------------------------
            // 4) HOTEL / VOITURE / EVENEMENT
            // -----------------------------
            if (is_null($finalProduitId)) {
                $v->errors()->add('produit_id', "Le produit est obligatoire pour ce type de réservation.");
                return;
            }

            $produit = Produit::find($finalProduitId);
            if ($produit && $produit->type !== $finalType) {
                $v->errors()->add('produit_id', "Le produit sélectionné ne correspond pas au type de réservation ({$finalType}).");
            }

            if (!is_null($finalForfaitId) && $finalType !== Reservation::TYPE_EVENEMENT) {
                $v->errors()->add('forfait_id', "Le forfait n'est autorisé que pour les réservations de type événement.");
            }
        });
    }

    public function messages(): array
    {
        return [
            'type.in' => 'Type de réservation invalide.',
            'statut.in' => 'Statut invalide.',
        ];
    }
}