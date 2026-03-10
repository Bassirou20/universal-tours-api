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
            'type' => 'required|in:billet_avion,hotel,voiture,evenement,forfait,assurance',

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
            'montant_sous_total' => 'nullable|numeric|min:0',
            'montant_taxes' => 'nullable|numeric|min:0',
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

            'passenger_is_client' => 'nullable|boolean',

            'passenger_id' => 'nullable|exists:participants,id',

            'passenger' => 'nullable|array',
            'passenger.nom' => 'required_with:passenger|string|max:100',
            'passenger.prenom' => 'required_with:passenger|string|max:100',
            'passenger.telephone' => 'nullable|string|max:30',
            'passenger.email' => 'nullable|email|max:255',


            'passengers' => ['sometimes', 'array', 'min:1'],
            'passengers.*.nom' => ['required_with:passengers', 'string', 'max:100'],
            'passengers.*.prenom' => ['nullable', 'string', 'max:100'],
            'passengers.*.passport' => ['nullable', 'string', 'max:50'],
            'passengers.*.sexe' => ['nullable', 'in:M,F'],


            /* ================= BILLET AVION : détails vol ================= */
            'flight_details' => ['sometimes', 'array'],
            'flight_details.ville_depart'  => ['sometimes', 'nullable', 'string', 'max:100'],
            'flight_details.ville_arrivee' => ['sometimes', 'nullable', 'string', 'max:100'],
            'flight_details.date_depart'   => ['sometimes', 'nullable', 'date'],
            'flight_details.date_arrivee'  => ['sometimes', 'nullable', 'date'],
            'flight_details.compagnie'     => ['sometimes', 'nullable', 'string', 'max:100'],
            'flight_details.pnr'           => ['sometimes', 'nullable', 'string', 'max:50'],
            'flight_details.classe'        => ['sometimes', 'nullable', 'string', 'max:50'],

            // Assurance details (conditionnel)
            'assurance_details' => ['required_if:type,assurance', 'array'],
            'assurance_details.libelle' => ['required_if:type,assurance', 'string', 'max:255'],
            'assurance_details.date_debut' => ['required_if:type,assurance', 'date'],
            'assurance_details.date_fin' => ['nullable', 'date', 'after_or_equal:assurance_details.date_debut'],
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
        if (in_array($type, [
            Reservation::TYPE_HOTEL,
            Reservation::TYPE_VOITURE,
            Reservation::TYPE_EVENEMENT
        ], true)) {

            if (!$this->filled('produit_id')) {
                $v->errors()->add('produit_id', 'Le produit est obligatoire pour ce type de réservation.');
            } else {
                $produit = Produit::find($this->input('produit_id'));
                if ($produit && $produit->type !== $type) {
                    $v->errors()->add(
                        'produit_id',
                        "Le produit sélectionné ne correspond pas au type de réservation ({$type})."
                    );
                }
            }
        }

        if ($type === Reservation::TYPE_FORFAIT) {
            if (!$this->filled('forfait_id')) {
                $v->errors()->add('forfait_id', "Le forfait est obligatoire pour une réservation de type forfait.");
            }
        }

        if ($type === Reservation::TYPE_BILLET_AVION) {
            if ($this->filled('produit_id')) {
                $v->errors()->add('produit_id', "Un billet d'avion ne nécessite pas de produit.");
            }
            if ($this->filled('forfait_id')) {
                $v->errors()->add('forfait_id', "Un billet d'avion ne doit pas être lié à un forfait.");
            }
        }

        if ($type === Reservation::TYPE_ASSURANCE) {
            if ($this->filled('produit_id')) {
                $v->errors()->add('produit_id', "Une assurance ne nécessite pas de produit.");
            }
            if ($this->filled('forfait_id')) {
                $v->errors()->add('forfait_id', "Une assurance ne doit pas être liée à un forfait.");
            }
        }

        /* -------------------------------------------------
         | 3) Participants (hors billet avion)
         |-------------------------------------------------*/
        $typesQuiAcceptentParticipants = [
            Reservation::TYPE_EVENEMENT,
            Reservation::TYPE_FORFAIT,
        ];

        if ($type === Reservation::TYPE_VOITURE) {
            if ($nb !== 1) {
                $v->errors()->add('nombre_personnes', "Pour une réservation voiture, nombre_personnes doit être 1.");
            }
            if (!empty($participants)) {
                $v->errors()->add('participants', "Participants non requis pour une réservation voiture.");
            }
        }

        if ($type === Reservation::TYPE_HOTEL && !empty($participants)) {
            $v->errors()->add('participants', "Participants non requis pour une réservation hôtel.");
        }

        if (
            in_array($type, $typesQuiAcceptentParticipants, true) &&
            $nb > 1
        ) {
            if (empty($participants)) {
                $v->errors()->add('participants', "Participants requis quand nombre_personnes > 1.");
            } elseif (count($participants) !== ($nb - 1)) {
                $v->errors()->add(
                    'participants',
                    "Le nombre de participants doit être égal à nombre_personnes - 1."
                );
            }
        }

        /* -------------------------------------------------
         | 4) BILLET AVION : simple ou multiple
         |-------------------------------------------------*/
        if ($type === Reservation::TYPE_BILLET_AVION) {

            $isClientPassenger = in_array(
                $this->input('passenger_is_client'),
                [true, 1, '1', 'true', 'on', 'yes'],
                true
            );

            $passenger = $this->input('passenger');
            $passengers = $this->input('passengers', []);

            $hasSinglePassenger = !empty($passenger) && is_array($passenger);
            $hasMultiPassengers = !empty($passengers) && is_array($passengers) && count($passengers) > 0;

            // passenger et passengers en même temps = interdit
            if ($hasSinglePassenger && $hasMultiPassengers) {
                $v->errors()->add(
                    'passengers',
                    "Choisis soit passenger, soit passengers, pas les deux."
                );
            }

            // Cas vide total
            if (!$isClientPassenger && !$hasSinglePassenger && !$hasMultiPassengers) {
                $v->errors()->add(
                    'passenger',
                    "Passager requis pour un billet d’avion (passenger, passengers ou passenger_is_client=true)."
                );
            }

            // Validation passenger simple
            if ($hasSinglePassenger) {
                if (empty($passenger['nom'])) {
                    $v->errors()->add('passenger.nom', "Nom du passager requis.");
                }
                if (empty($passenger['prenom'])) {
                    $v->errors()->add('passenger.prenom', "Prénom du passager requis.");
                }

                if ($nb !== 1) {
                    $v->errors()->add(
                        'nombre_personnes',
                        "Pour un billet simple, nombre_personnes doit être 1."
                    );
                }
            }

            // Validation passengers multiples
            if ($hasMultiPassengers) {
                foreach ($passengers as $index => $p) {
                    if (empty($p['nom'])) {
                        $v->errors()->add("passengers.$index.nom", "Nom du passager requis.");
                    }
                    if (empty($p['prenom'])) {
                        $v->errors()->add("passengers.$index.prenom", "Prénom du passager requis.");
                    }
                }

                $expected = count($passengers) + ($isClientPassenger ? 1 : 0);

                if ($nb !== $expected) {
                    $v->errors()->add(
                        'nombre_personnes',
                        "Pour un billet d'avion avec plusieurs bénéficiaires, nombre_personnes doit être égal au nombre total de voyageurs."
                    );
                }
            }

            // Cas payeur seul
            if ($isClientPassenger && !$hasSinglePassenger && !$hasMultiPassengers) {
                if ($nb !== 1) {
                    $v->errors()->add(
                        'nombre_personnes',
                        "Si seul le payeur voyage, nombre_personnes doit être 1."
                    );
                }
            }
        }

        /* -------------------------------------------------
         | 5) Assurance : bénéficiaire simple
         |-------------------------------------------------*/
        if ($type === Reservation::TYPE_ASSURANCE) {
            $isClientPassenger = in_array(
                $this->input('passenger_is_client'),
                [true, 1, '1', 'true', 'on', 'yes'],
                true
            );

            $passenger = $this->input('passenger');

            if (!$isClientPassenger && !empty($passenger)) {
                if (empty($passenger['nom'])) {
                    $v->errors()->add('passenger.nom', "Nom du bénéficiaire requis.");
                }
                if (empty($passenger['prenom'])) {
                    $v->errors()->add('passenger.prenom', "Prénom du bénéficiaire requis.");
                }
            }
        }

        /* -------------------------------------------------
         | 6) Flight details : non obligatoires
         |-------------------------------------------------*/
        if ($type === Reservation::TYPE_BILLET_AVION) {
            $fd = $this->input('flight_details');

            if (!empty($fd) && is_array($fd)) {
                if (!empty($fd['date_depart']) && !empty($fd['date_arrivee'])) {
                    if (strtotime($fd['date_arrivee']) < strtotime($fd['date_depart'])) {
                        $v->errors()->add(
                            'flight_details.date_arrivee',
                            "date_arrivee ne peut pas être avant date_depart."
                        );
                    }
                }
            }
        }
    });
}

protected function prepareForValidation(): void
{
    $type = $this->input('type');

    if ($type === \App\Models\Reservation::TYPE_VOITURE) {
        $this->merge(['nombre_personnes' => 1]);
    }

    if (!$this->filled('nombre_personnes')) {
        $this->merge(['nombre_personnes' => 1]);
    }
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
