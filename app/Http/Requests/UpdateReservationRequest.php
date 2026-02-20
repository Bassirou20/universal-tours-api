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

            // Champs vol (root) - optionnels en update
            'ville_depart'  => ['sometimes', 'nullable', 'string', 'max:100'],
            'ville_arrivee' => ['sometimes', 'nullable', 'string', 'max:100'],
            'date_depart'   => ['sometimes', 'nullable', 'date'],
            'date_arrivee'  => ['sometimes', 'nullable', 'date'],
            'compagnie'     => ['sometimes', 'nullable', 'string', 'max:100'],

            // Montants
            'montant_sous_total' => ['sometimes', 'nullable', 'numeric', 'min:0'], // achat/hors fees pour billet_avion
            'montant_taxes'      => ['sometimes', 'nullable', 'numeric', 'min:0'], // fees pour billet_avion
            'montant_total'      => ['sometimes', 'nullable', 'numeric', 'min:0'],

            // Autres
            'nombre_personnes' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($v) {

            /** @var \App\Models\Reservation|null $reservation */
            $reservation = $this->route('reservation'); // route-model binding

            // Type final (si non fourni, on garde l’existant)
            $finalType = $this->input('type', $reservation?->type);

            // Produit final
            $finalProduitId = $this->exists('produit_id')
                ? $this->input('produit_id')
                : ($reservation?->produit_id);

            // Forfait final
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
                 * IMPORTANT:
                 * En UPDATE, on NE doit PAS exiger les champs vol si on ne les modifie pas.
                 * Mais si le client en envoie au moins 1, on exige le bloc complet.
                 */
                $flightFields = ['ville_depart', 'ville_arrivee', 'date_depart', 'date_arrivee', 'compagnie'];

                $anyFlightFieldSent = false;
                foreach ($flightFields as $field) {
                    if ($this->exists($field)) { // présent dans la requête (même null)
                        $anyFlightFieldSent = true;
                        break;
                    }
                }

                if ($anyFlightFieldSent) {
                    foreach ($flightFields as $field) {
                        // si on met à jour le vol, chaque champ devient obligatoire dans la requête
                        if (!$this->filled($field)) {
                            $v->errors()->add($field, "Le champ {$field} est obligatoire pour un billet d'avion.");
                        }
                    }
                }

                // Bonus cohérence date (seulement si on a les deux dates dans la requête)
                if ($this->filled('date_depart') && $this->filled('date_arrivee')) {
                    try {
                        $depart = new \DateTime($this->input('date_depart'));
                        $arrivee = new \DateTime($this->input('date_arrivee'));
                        if ($arrivee < $depart) {
                            $v->errors()->add('date_arrivee', "La date d'arrivée doit être >= à la date de départ.");
                        }
                    } catch (\Exception $e) {
                        // Les rules 'date' couvriront déjà les erreurs de format
                    }
                }

                return;
            }

            // -----------------------------
            // 2) FORFAIT
            // -----------------------------
            if ($finalType === Reservation::TYPE_FORFAIT) {

                // forfait obligatoire
                if (is_null($finalForfaitId)) {
                    $v->errors()->add('forfait_id', "Le forfait est obligatoire pour une réservation de type forfait.");
                }

                // produit interdit
                if (!is_null($finalProduitId)) {
                    $v->errors()->add('produit_id', "Un forfait ne doit pas être lié à un produit.");
                }

                return;
            }

            // -----------------------------
            // 3) HOTEL / VOITURE / EVENEMENT
            // -----------------------------

            // Produit obligatoire pour tout type restant
            if (is_null($finalProduitId)) {
                $v->errors()->add('produit_id', "Le produit est obligatoire pour ce type de réservation.");
                return;
            }

            // Produit doit correspondre au type
            $produit = Produit::find($finalProduitId);
            if ($produit && $produit->type !== $finalType) {
                $v->errors()->add('produit_id', "Le produit sélectionné ne correspond pas au type de réservation ({$finalType}).");
            }

            // Forfait autorisé uniquement si type = evenement
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