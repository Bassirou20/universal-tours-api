<?php

namespace App\Http\Requests;

use App\Models\Produit;
use App\Models\Reservation;
use Illuminate\Foundation\Http\FormRequest;

class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /* ================= TYPE ================= */
            'type' => 'required|in:billet_avion,hotel,voiture,evenement,forfait',

            /* ================= CLIENT ================= */
            'client_id' => 'nullable|exists:clients,id',

            // Création client inline (si client_id absent)
            'client' => 'nullable|array',
            'client.nom' => 'required_with:client|string|max:100',
            'client.prenom' => 'nullable|string|max:100',
            'client.telephone' => 'nullable|string|max:30',
            'client.email' => 'nullable|email|max:255',
            'client.pays' => 'nullable|string|max:60',
            'client.adresse' => 'nullable|string|max:255',

            /* ================= OBJET VENDU =================
             * - produit requis pour hotel/voiture/evenement
             * - forfait requis pour forfait
             * - billet_avion : pas de produit_id (selon ton choix actuel)
             */
            'produit_id' => 'nullable|exists:produits,id',
            'forfait_id' => 'nullable|exists:forfaits,id',

            /* ================= COMMUN ================= */
            'nombre_personnes' => 'required|integer|min:1',
            'montant_total' => 'required|numeric|min:0',
            'notes' => 'nullable|string',

            /* ================= PARTICIPANTS =================
             * participants = "autres bénéficiaires" (le client est implicite)
             * Donc si nombre_personnes=2 -> participants doit contenir 1 personne
             */
            'participants' => 'nullable|array',
            'participants.*.nom' => 'required_with:participants|string|max:100',
            'participants.*.prenom' => 'nullable|string|max:100',
            'participants.*.age' => 'nullable|integer|min:0|max:120',

            /* ================= BILLET AVION : détails vol ================= */
            'flight_details' => 'nullable|array',
            'flight_details.ville_depart' => 'nullable|string|max:100',
            'flight_details.ville_arrivee' => 'nullable|string|max:100',
            'flight_details.date_depart' => 'nullable|date',
            'flight_details.date_arrivee' => 'nullable|date',
            'flight_details.compagnie' => 'nullable|string|max:100',
            'flight_details.pnr' => 'nullable|string|max:50',
            'flight_details.classe' => 'nullable|string|max:50',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($v) {

            $type = $this->input('type');
            $nb = (int) $this->input('nombre_personnes', 1);
            $participants = $this->input('participants', []);

            /* -------------------------------------------------
             | 1) Client requis : client_id OU client[]
             |-------------------------------------------------*/
            if (!$this->filled('client_id') && !$this->filled('client')) {
                $v->errors()->add('client', 'Client requis (client_id ou client).');
            }

            /* -------------------------------------------------
             | 2) Règles produit_id / forfait_id selon type
             |-------------------------------------------------*/
            if (in_array($type, [Reservation::TYPE_HOTEL, Reservation::TYPE_VOITURE, Reservation::TYPE_EVENEMENT], true)) {

                if (!$this->filled('produit_id')) {
                    $v->errors()->add('produit_id', 'Le produit est obligatoire pour ce type de réservation.');
                } else {
                    // Cohérence produit.type == type
                    $produit = Produit::find($this->input('produit_id'));
                    if ($produit && $produit->type !== $type) {
                        $v->errors()->add('produit_id', "Le produit sélectionné ne correspond pas au type de réservation ({$type}).");
                    }
                }
            }

            if ($type === Reservation::TYPE_FORFAIT) {
                if (!$this->filled('forfait_id')) {
                    $v->errors()->add('forfait_id', "Le forfait est obligatoire pour une réservation de type forfait.");
                }
                // Option : interdire produit_id pour forfait (si tu veux)
                // if ($this->filled('produit_id')) {
                //     $v->errors()->add('produit_id', "Une réservation forfait ne doit pas être liée à un produit.");
                // }
            }

            if ($type === Reservation::TYPE_BILLET_AVION) {
                // Selon ta logique actuelle: billet avion sans produit_id
                if ($this->filled('produit_id')) {
                    $v->errors()->add('produit_id', "Un billet d'avion ne nécessite pas de produit.");
                }
                if ($this->filled('forfait_id')) {
                    $v->errors()->add('forfait_id', "Un billet d'avion ne doit pas être lié à un forfait.");
                }
            }

            /* -------------------------------------------------
             | 3) Participants : seulement pour certains types
             |-------------------------------------------------*/
            $typesQuiAcceptentParticipants = [
                Reservation::TYPE_EVENEMENT,
                Reservation::TYPE_FORFAIT,
                Reservation::TYPE_BILLET_AVION,
            ];

            // Voiture : toujours solo + pas de participants
            if ($type === Reservation::TYPE_VOITURE) {
                if ($nb !== 1) {
                    $v->errors()->add('nombre_personnes', "Pour une réservation voiture, nombre_personnes doit être 1.");
                }
                if (!empty($participants)) {
                    $v->errors()->add('participants', "Participants non requis pour une réservation voiture.");
                }
            }

            // Hotel : participants pas nécessaires (selon ton NB)
            if ($type === Reservation::TYPE_HOTEL && !empty($participants)) {
                // Tu peux choisir de les autoriser, mais tu as dit "participants juste pour évènements/forfaits/peut-être avion"
                $v->errors()->add('participants', "Participants non requis pour une réservation hôtel.");
            }

            // Evenement/Forfait/Billet avion : participants requis si nb > 1
            if (in_array($type, $typesQuiAcceptentParticipants, true) && $nb > 1) {
                if (empty($participants)) {
                    $v->errors()->add('participants', "Participants requis quand nombre_personnes > 1.");
                } elseif (count($participants) !== ($nb - 1)) {
                    $v->errors()->add('participants', "Le nombre de participants doit être égal à nombre_personnes - 1.");
                }
            }

            /* -------------------------------------------------
             | 4) Billet avion : flight_details obligatoires
             |-------------------------------------------------*/
            if ($type === Reservation::TYPE_BILLET_AVION) {
                $fd = $this->input('flight_details');

                if (empty($fd) || !is_array($fd)) {
                    $v->errors()->add('flight_details', "Les détails du vol sont obligatoires pour un billet d'avion.");
                    return;
                }

                foreach (['ville_depart', 'ville_arrivee', 'date_depart'] as $field) {
                    if (empty($fd[$field])) {
                        $v->errors()->add("flight_details.$field", "Le champ flight_details.$field est obligatoire pour un billet d'avion.");
                    }
                }

                // Optionnel : cohérence dates
                if (!empty($fd['date_depart']) && !empty($fd['date_arrivee'])) {
                    if (strtotime($fd['date_arrivee']) < strtotime($fd['date_depart'])) {
                        $v->errors()->add("flight_details.date_arrivee", "date_arrivee ne peut pas être avant date_depart.");
                    }
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Le type de réservation est obligatoire.',
            'type.in' => 'Type de réservation invalide.',

            'nombre_personnes.required' => 'Le nombre de personnes est obligatoire.',
            'nombre_personnes.integer' => 'Le nombre de personnes doit être un entier.',
            'nombre_personnes.min' => 'Le nombre de personnes doit être au minimum 1.',

            'montant_total.required' => 'Le montant total est obligatoire.',
            'montant_total.numeric' => 'Le montant total doit être un nombre.',
            'montant_total.min' => 'Le montant total ne peut pas être négatif.',
        ];
    }
}
