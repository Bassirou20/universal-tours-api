<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Requests\UpdateReservationRequest;
use App\Models\Client;
use App\Models\Facture;
use App\Models\Forfait;
use App\Models\Participant;
use App\Models\Reservation;
use App\Models\Produit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Str;


class ReservationController extends Controller
{
    /**
     * Liste des réservations
     */
    public function index(Request $request)
{
    $perPage = (int) ($request->get('per_page', 10));

    $query = Reservation::query()
        ->with([
            'client' => fn ($q) => $q->withTrashed(),
            'produit',
            'forfait',
            'participants',
            'passenger',
            'flightDetails',
            'assuranceDetails',
            'factures.paiements',
        ])
        ->orderByDesc('created_at');

    if ($request->filled('type')) {
        $query->where('type', $request->get('type'));
    }
    if ($request->filled('statut')) {
        $query->where('statut', $request->get('statut'));
    }

    if ($request->filled('month')) {
    $month = $request->get('month'); // ex: 2026-02
    try {
        $start = \Carbon\Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = \Carbon\Carbon::createFromFormat('Y-m', $month)->endOfMonth();
        $query->whereBetween('created_at', [$start, $end]);
    } catch (\Exception $e) {
        // ignore si format invalide
    }
}


    // ✅ AJOUT: search
    if ($request->filled('search')) {
        $term = trim($request->get('search'));

        $query->where(function ($q) use ($term) {
            $q->where('reference', 'like', "%{$term}%")
              ->orWhere('id', $term)
              ->orWhereHas('client', function ($qc) use ($term) {
                  $qc->withTrashed()
                     ->where('nom', 'like', "%{$term}%")
                     ->orWhere('prenom', 'like', "%{$term}%")
                     ->orWhere('telephone', 'like', "%{$term}%")
                     ->orWhere('email', 'like', "%{$term}%");
              });
        });
    }

    return response()->json($query->paginate($perPage));
}


    /**
     * Création d'une réservation
     */
 public function store(StoreReservationRequest $request)
{
    $data = $request->validated();

    return DB::transaction(function () use ($data) {

        /* -------------------------------------------------
        | 1) CLIENT (existant ou nouveau)
        |-------------------------------------------------*/
        if (!empty($data['client_id'])) {
            $client = Client::findOrFail($data['client_id']);
        } elseif (!empty($data['client'])) {
            // Evite les doublons si email fourni
            if (!empty($data['client']['email'])) {
                $client = Client::firstOrCreate(
                    ['email' => $data['client']['email']],
                    $data['client']
                );
            } else {
                $client = Client::create($data['client']);
            }
        } else {
            return response()->json([
                'message' => 'Client requis (client_id ou client).',
                'errors' => ['client' => ['Client requis (client_id ou client).']]
            ], 422);
        }

        /* -------------------------------------------------
        | 2) COMMUN
        |-------------------------------------------------*/
        
        $type = $data['type'];
        $reference = $this->makeReservationReference($data, $type);

        // sécurité voiture
        $nombrePersonnes = (int) ($data['nombre_personnes'] ?? 1);
        if ($type === Reservation::TYPE_VOITURE) $nombrePersonnes = 1;

        // montants: on privilégie le total calculé / envoyé
        $montantSousTotal = (float) ($data['montant_sous_total'] ?? $data['montant_total'] ?? 0);
        $montantTaxes     = (float) ($data['montant_taxes'] ?? 0);
        $montantTotal     = (float) ($data['montant_total'] ?? ($montantSousTotal + $montantTaxes));

        $participants = $data['participants'] ?? [];

        // ✅ Tu veux confirmé par défaut
        $statut = Reservation::STATUT_CONFIRME;

        /* -------------------------------------------------
        | 3) Création selon type
        |-------------------------------------------------*/

        // A) BILLET AVION (1 réservation = 1 passager)
        // A) BILLET AVION (1 réservation = 1 passager)
       if ($type === Reservation::TYPE_BILLET_AVION) {
            $reservation = Reservation::create([
                'client_id' => $client->id,
                'type' => Reservation::TYPE_BILLET_AVION,
                'reference' => $reference,
                'statut' => $statut,
                'nombre_personnes' => $nombrePersonnes,
                'montant_sous_total' => $montantSousTotal,
                'montant_taxes' => $montantTaxes,
                'montant_total' => $montantTotal,
                'notes' => $data['notes'] ?? null,
            ]);

            $createdPassengers = collect();

            $passengerIsClient = array_key_exists('passenger_is_client', $data)
                ? (bool) $data['passenger_is_client']
                : true;

            $singlePassenger = !empty($data['passenger']) && is_array($data['passenger'])
                ? $data['passenger']
                : null;

            $multiplePassengers = collect($data['passengers'] ?? [])
                ->filter(fn ($p) => !empty(trim((string)($p['nom'] ?? ''))))
                ->values();

            // 1) Si le payeur fait partie des voyageurs
            if ($passengerIsClient) {
                $createdPassengers->push(
                    $reservation->participants()->create([
                        'nom' => $client->nom,
                        'prenom' => $client->prenom,
                        'role' => 'passenger',
                    ])
                );
            }

            // 2) Cas billet simple
            if ($singlePassenger) {
                $createdPassengers->push(
                    $reservation->participants()->create([
                        'nom' => $singlePassenger['nom'],
                        'prenom' => $singlePassenger['prenom'] ?? null,
                        'passport' => $singlePassenger['passport'] ?? null,
                        'sexe' => $singlePassenger['sexe'] ?? null,
                        'role' => 'passenger',
                    ])
                );
            }

            // 3) Cas billets multiples
            foreach ($multiplePassengers as $p) {
                $createdPassengers->push(
                    $reservation->participants()->create([
                        'nom' => $p['nom'],
                        'prenom' => $p['prenom'] ?? null,
                        'passport' => $p['passport'] ?? null,
                        'sexe' => $p['sexe'] ?? null,
                        'role' => 'passenger',
                    ])
                );
            }

            // 4) Fallback de sécurité
            if ($createdPassengers->isEmpty()) {
                $createdPassengers->push(
                    $reservation->participants()->create([
                        'nom' => $client->nom,
                        'prenom' => $client->prenom,
                        'role' => 'passenger',
                    ])
                );
            }

            // 5) Le premier passenger devient le passenger principal
            $reservation->update([
                'passenger_id' => $createdPassengers->first()->id,
                'nombre_personnes' => $createdPassengers->count(),
            ]);

            // 6) Flight details
            if (!empty($data['flight_details']) && is_array($data['flight_details'])) {
                $hasAny = collect($data['flight_details'])->filter(function ($v) {
                    return !is_null($v) && trim((string) $v) !== '';
                })->isNotEmpty();

                if ($hasAny) {
                    $res = collect($data['flight_details'])->only([
                        'ville_depart',
                        'ville_arrivee',
                        'date_depart',
                        'date_arrivee',
                        'compagnie',
                        'pnr',
                        'classe'
                    ])->toArray();

                    $reservation->flightDetails()->create($res);
                }
            }

            $this->ensureFactureEmise($reservation);

            return response()->json(
                $reservation->load([
                    'client',
                    'participants',
                    'passenger',
                    'flightDetails',
                    'factures.paiements'
                ]),
                Response::HTTP_CREATED
            );
        }
                if ($type === Reservation::TYPE_ASSURANCE) {

            $reservation = Reservation::create([
                'client_id' => $client->id,
                'type' => Reservation::TYPE_ASSURANCE,
                'reference' => $reference,
                'statut' => $statut,
                'nombre_personnes' => $nombrePersonnes,

                // comme billet : montants détaillés
                'montant_sous_total' => $montantSousTotal,
                'montant_taxes' => $montantTaxes,
                'montant_total' => $montantTotal,

                'notes' => $data['notes'] ?? null,
            ]);

            // ✅ Déterminer le bénéficiaire (même logique que billet)
            $passengerIsClient = array_key_exists('passenger_is_client', $data)
                ? (bool) $data['passenger_is_client']
                : true;

            if ($passengerIsClient) {
                $beneficiary = $reservation->participants()->create([
                    'nom' => $client->nom,
                    'prenom' => $client->prenom,
                    'role' => 'beneficiary',
                ]);
            } else {
                // si passenger fourni -> on le crée
                if (!empty($data['passenger']) && !empty($data['passenger']['nom'])) {
                    $beneficiary = $reservation->participants()->create([
                        'nom' => $data['passenger']['nom'],
                        'prenom' => $data['passenger']['prenom'] ?? null,
                        'passport' => $data['passenger']['passport'] ?? null,
                        'sexe' => $data['passenger']['sexe'] ?? null,
                        'role' => 'beneficiary',
                    ]);
                } else {
                    // fallback : client
                    $beneficiary = $reservation->participants()->create([
                        'nom' => $client->nom,
                        'prenom' => $client->prenom,
                        'role' => 'beneficiary',
                    ]);
                }
            }

            // ✅ Lier beneficiary à passenger_id (comme billet)
            $reservation->update(['passenger_id' => $beneficiary->id]);

            // details assurance (libellé + dates)
            $reservation->assuranceDetails()->create([
                'libelle' => $data['assurance_details']['libelle'],
                'date_debut' => $data['assurance_details']['date_debut'],
                'date_fin' => $data['assurance_details']['date_fin'] ?? null,
            ]);

            $this->ensureFactureEmise($reservation);

            return response()->json(
                $reservation->load([
                    'client',
                    'passenger',         // ✅ IMPORTANT (beneficiary)
                    'participants',      // ✅ optionnel mais utile pour debug
                    'assuranceDetails',
                    'factures.paiements',
                ]),
                Response::HTTP_CREATED
            );
        }


        // B) FORFAIT
        if ($type === Reservation::TYPE_FORFAIT) {

            $forfait = Forfait::findOrFail($data['forfait_id']);

            $reservation = Reservation::create([
                'client_id' => $client->id,
                'type' => Reservation::TYPE_FORFAIT,

                'produit_id' => null,
                'forfait_id' => $forfait->id,

                'reference' => $reference,
                'statut' => $statut,

                'nombre_personnes' => $nombrePersonnes,
                'montant_sous_total' => $montantSousTotal,
                'montant_taxes' => $montantTaxes,
                'montant_total' => $montantTotal,

                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($participants as $p) {
                $reservation->participants()->create($p);
            }

            // ✅ Facture auto (1 seule fois)
            $this->ensureFactureEmise($reservation);

            return response()->json(
                $reservation->load(['client', 'forfait', 'participants', 'factures.paiements']),
                Response::HTTP_CREATED
            );
        }

        

        // C) AUTRES TYPES (hotel/voiture/evenement)
        $produit = Produit::findOrFail($data['produit_id']);

        if ($produit->type !== $type) {
            return response()->json([
                'message' => "Le produit sélectionné ne correspond pas au type de réservation ({$type}).",
                'errors' => ['produit_id' => ["Le produit sélectionné ne correspond pas au type de réservation ({$type})."]]
            ], 422);
        }

        $reservation = Reservation::create([
            'client_id' => $client->id,
            'type' => $type,
            'produit_id' => $produit->id,
            'forfait_id' => $data['forfait_id'] ?? null,

            'reference' => $reference,
            'statut' => $statut,

            'nombre_personnes' => $nombrePersonnes,
            'montant_sous_total' => $montantSousTotal,
            'montant_taxes' => $montantTaxes,
            'montant_total' => $montantTotal,

            'notes' => $data['notes'] ?? null,
        ]);

        // Participants seulement si evenement (selon ta règle)
        if ($type === Reservation::TYPE_EVENEMENT) {
            foreach ($participants as $p) {
                $reservation->participants()->create($p);
            }
        }

        // ✅ Facture auto (1 seule fois)
        $this->ensureFactureEmise($reservation);

        return response()->json(
            $reservation->load(['client', 'produit', 'forfait', 'participants', 'factures.paiements']),
            Response::HTTP_CREATED
        );
    });
}




    /**
     * Détails d'une réservation
     */
   public function show($id)
{
    $reservation = Reservation::with([
        'client' => fn ($q) => $q->withTrashed(),
        'produit',
        'forfait',
        'participants',
        'passenger',
        'flightDetails',
        'assuranceDetails',
        'factures.paiements',
    ])->findOrFail($id);

    return response()->json($reservation);
}



    /**
     * Mise à jour d'une réservation
     */

   public function update(UpdateReservationRequest $request, Reservation $reservation)
{
    $data = $request->validated();

    // cohérence type/produit si modifié
    if (!empty($data['produit_id'])) {
        $produit = Produit::findOrFail($data['produit_id']);
        $finalType = $data['type'] ?? $reservation->type;

        if ($produit->type !== $finalType) {
            return response()->json([
                'message' => "Le produit sélectionné ne correspond pas au type de réservation ({$finalType})."
            ], 422);
        }
    }

    $finalType = $data['type'] ?? $reservation->type;

    // ✅ MAJ billet avion: flight details optionnels (on ne touche que si au moins 1 champ vol est envoyé)
    if ($finalType === Reservation::TYPE_BILLET_AVION) {
        $flightKeys = ['ville_depart', 'ville_arrivee', 'date_depart', 'date_arrivee', 'compagnie', 'pnr', 'classe'];

        $anyFlightFieldSent = false;
        foreach ($flightKeys as $k) {
            if (array_key_exists($k, $data)) {
                $anyFlightFieldSent = true;
                break;
            }
        }

        if ($anyFlightFieldSent) {
            $existing = $reservation->flightDetails;

            $payload = [
                'ville_depart'  => array_key_exists('ville_depart', $data)  ? ($data['ville_depart'] ?: null)  : ($existing->ville_depart ?? null),
                'ville_arrivee' => array_key_exists('ville_arrivee', $data) ? ($data['ville_arrivee'] ?: null) : ($existing->ville_arrivee ?? null),
                'date_depart'   => array_key_exists('date_depart', $data)   ? ($data['date_depart'] ?: null)   : ($existing->date_depart ?? null),
                'date_arrivee'  => array_key_exists('date_arrivee', $data)  ? ($data['date_arrivee'] ?: null)  : ($existing->date_arrivee ?? null),
                'compagnie'     => array_key_exists('compagnie', $data)     ? ($data['compagnie'] ?: null)     : ($existing->compagnie ?? null),
                'pnr'           => array_key_exists('pnr', $data)           ? ($data['pnr'] ?: null)           : ($existing->pnr ?? null),
                'classe'        => array_key_exists('classe', $data)        ? ($data['classe'] ?: null)        : ($existing->classe ?? null),
            ];

            $reservation->flightDetails()->updateOrCreate(
                ['reservation_id' => $reservation->id],
                $payload
            );
        }

        // On ne stocke pas ces champs sur reservations table si tu utilises la table reservation_flight_details
        foreach (['ville_depart','ville_arrivee','date_depart','date_arrivee','compagnie','pnr','classe'] as $k) {
            unset($data[$k]);
        }
    }

    // ✅ MAJ assurance details : optionnels en update (on ne touche que si assurance_details est envoyé)
    if ($finalType === Reservation::TYPE_ASSURANCE) {
        if (array_key_exists('assurance_details', $data) && is_array($data['assurance_details'])) {
            $incoming = $data['assurance_details'];
            $existing = $reservation->assuranceDetails;

            // Si le front envoie un objet vide {}, on ignore (pas de MAJ)
            $hasAny = false;
            foreach (['libelle','date_debut','date_fin'] as $k) {
                if (array_key_exists($k, $incoming)) { $hasAny = true; break; }
            }

            if ($hasAny) {
                $reservation->assuranceDetails()->updateOrCreate(
                    ['reservation_id' => $reservation->id],
                    [
                        'libelle' => array_key_exists('libelle', $incoming)
                            ? ($incoming['libelle'] ?: '')
                            : ($existing->libelle ?? ''),

                        'date_debut' => array_key_exists('date_debut', $incoming)
                            ? ($incoming['date_debut'] ?: null)
                            : ($existing->date_debut ?? null),

                        'date_fin' => array_key_exists('date_fin', $incoming)
                            ? ($incoming['date_fin'] ?: null)
                            : ($existing->date_fin ?? null),
                    ]
                );
            }
        }

        unset($data['assurance_details']);
    }

    // ✅ Sync participants si le front les envoie (FORFAIT / EVENEMENT)
    if (array_key_exists('participants', $data)) {

        // Optionnel : si billet_avion / hotel / voiture => on ignore ou on bloque
        if (!in_array($finalType, [Reservation::TYPE_FORFAIT, Reservation::TYPE_EVENEMENT], true)) {
            // soit ignore :
            unset($data['participants']);
        } else {
            $incoming = is_array($data['participants']) ? $data['participants'] : [];

            // On supprime les anciens "participants" (hors passenger/beneficiary si tu veux)
            $reservation->participants()
                ->where('role', '!=', 'passenger')   // adapte selon ta logique
                ->where('role', '!=', 'beneficiary') // assurance
                ->delete();

            // On recrée
            foreach ($incoming as $p) {
                $reservation->participants()->create([
                    'nom' => $p['nom'],
                    'prenom' => $p['prenom'] ?? null,
                    'age' => $p['age'] ?? null,
                    'passport' => $p['passport'] ?? null, // si colonne existe
                    'remarques' => $p['remarques'] ?? null, // si colonne existe
                    'role' => $p['role'] ?? 'participant',  // ou 'passenger'
                ]);
            }

            // Important : on ne veut pas que reservation->update() tente d’update "participants"
            unset($data['participants']);
        }
    }

// ✅ MAJ bénéficiaire / passager (billet_avion + assurance)
if (in_array($finalType, [Reservation::TYPE_BILLET_AVION, Reservation::TYPE_ASSURANCE], true)) {

    $reservation->loadMissing('client', 'participants', 'passenger');

    $role = $finalType === Reservation::TYPE_ASSURANCE ? 'beneficiary' : 'passenger';

    $wantsPassengerUpdate =
        array_key_exists('passenger_id', $data) ||
        array_key_exists('passenger_is_client', $data) ||
        array_key_exists('passenger', $data);

    if ($wantsPassengerUpdate) {

        // 1) Si on force un passenger_id (participant existant de la réservation)
        if (!empty($data['passenger_id'])) {
            $p = $reservation->participants()
                ->whereKey($data['passenger_id'])
                ->firstOrFail();

            // on s'assure du role
            $p->update(['role' => $role]);

            $reservation->passenger_id = $p->id;
            $reservation->save();
        } else {

            // 2) Sinon, passenger_is_client = true => client = passager
            $isClientPassenger = array_key_exists('passenger_is_client', $data)
                ? (bool) $data['passenger_is_client']
                : false;

            if ($isClientPassenger) {

                // on réutilise si possible un participant existant (role passager/bénéficiaire)
                $p = $reservation->participants()
                    ->where('role', $role)
                    ->first();

                if (!$p) {
                    $p = $reservation->participants()->create([
                        'nom' => $reservation->client->nom,
                        'prenom' => $reservation->client->prenom,
                        'role' => $role,
                    ]);
                } else {
                    // optionnel : garder synchro avec le client
                    $p->update([
                        'nom' => $reservation->client->nom,
                        'prenom' => $reservation->client->prenom,
                        'role' => $role,
                    ]);
                }

                $reservation->passenger_id = $p->id;
                $reservation->save();

            } else {

                // 3) passenger = {...} => autre personne
                if (!empty($data['passenger']) && is_array($data['passenger'])) {

                    $incoming = $data['passenger'];

                    // si un passenger existe déjà sur cette réservation, on le met à jour
                    $p = $reservation->passenger;
                    $canUpdateExisting =
                        $p &&
                        (int) $p->reservation_id === (int) $reservation->id;

                    if ($canUpdateExisting) {
                        $p->update([
                            'nom' => $incoming['nom'] ?? $p->nom,
                            'prenom' => $incoming['prenom'] ?? $p->prenom,
                            'role' => $role,
                        ]);
                    } else {
                        $p = $reservation->participants()->create([
                            'nom' => $incoming['nom'],
                            'prenom' => $incoming['prenom'] ?? null,
                            'role' => $role,
                        ]);
                    }

                    $reservation->passenger_id = $p->id;
                    $reservation->save();
                }
            }
        }
    }

    // 🔥 IMPORTANT : éviter que reservation->update() tente de gérer ces champs
    unset($data['passenger_id'], $data['passenger_is_client'], $data['passenger']);
}

// ✅ MAJ multi-passagers (billet_avion)
if ($finalType === Reservation::TYPE_BILLET_AVION && array_key_exists('passengers', $data)) {

    $incoming = is_array($data['passengers']) ? $data['passengers'] : [];

    // supprime les anciens passagers
    $reservation->participants()->where('role', 'passenger')->delete();

    $created = [];
    foreach ($incoming as $p) {
        if (empty($p['nom'])) continue;

        $created[] = $reservation->participants()->create([
            'nom' => $p['nom'],
            'prenom' => $p['prenom'] ?? null,
            'passport' => $p['passport'] ?? null,
            'sexe' => $p['sexe'] ?? null,
            'role' => 'passenger',
        ]);
    }

    // met à jour passenger_id (principal) + nombre_personnes
    $reservation->passenger_id = !empty($created) ? $created[0]->id : null;
    $reservation->nombre_personnes = max(1, count($created));
    $reservation->save();

    // ne pas laisser reservation->update() tenter de sauver passengers
    unset($data['passengers']);

    // ✅ Si on a envoyé passengers[], on ignore l’ancienne logique passenger/passenger_id
    unset($data['passenger_id'], $data['passenger_is_client'], $data['passenger']);
}

    // Update reservation (champs communs / produit / forfait / montants etc.)
    $reservation->update($data);

    if (($reservation->fresh()->statut ?? null) === Reservation::STATUT_CONFIRME) {
        $this->ensureFactureEmise($reservation->fresh());
    }

    return $reservation->fresh()->load([
        'client',
        'produit',
        'forfait',
        'participants',
        'passenger',
        'flightDetails',
        'assuranceDetails',
        'factures.paiements'
    ]);
}
    /**
     * Suppression d'une réservation
     */
    public function destroy(Reservation $reservation)
    {
        $reservation->delete();
        return response()->noContent();
    }


    public function confirmer(Reservation $reservation)
{
    if ($reservation->statut === Reservation::STATUT_ANNULE) {
        return response()->json([
            'message' => "Impossible de confirmer une réservation annulée.",
            'errors' => ['statut' => ["Réservation annulée."]]
        ], 422);
    }

    return DB::transaction(function () use ($reservation) {

        // 1) confirmer réservation
        $reservation->update(['statut' => Reservation::STATUT_CONFIRME]);

        // 2) garantir facture émise
        $facture = $this->ensureFactureEmise($reservation);

        return response()->json([
            'reservation' => $reservation->fresh()->load(['client','produit','forfait','participants','factures.paiements']),
            'facture' => $facture->fresh()->load(['paiements']),
        ], Response::HTTP_OK);
    });
}

private function makeReservationReference(array $data, string $type): string
{
    // 1) Si une référence est fournie (import Excel), on la respecte
    $incoming = trim((string)($data['reference'] ?? ''));
    if ($incoming !== '') {
        return $this->ensureUniqueReference($incoming);
    }

    // 2) Billet avion : priorité au PNR s'il existe
    if ($type === \App\Models\Reservation::TYPE_BILLET_AVION) {
        $pnr = trim((string)($data['flight_details']['pnr'] ?? ''));
        if ($pnr !== '') {
            return $this->ensureUniqueReference(Str::upper($pnr));
        }

        // Sinon génération "pro"
        $date = now()->format('Ymd');
        $rand = Str::upper(Str::random(6));
        return $this->ensureUniqueReference("UT-AV-{$date}-{$rand}");
    }

    // 3) Autres types
    $date = now()->format('Ymd');
    $typeCode = match ($type) {
        \App\Models\Reservation::TYPE_HOTEL => 'HOT',
        \App\Models\Reservation::TYPE_VOITURE => 'CAR',
        \App\Models\Reservation::TYPE_EVENEMENT => 'EVT',
        \App\Models\Reservation::TYPE_FORFAIT => 'PKG',
        default => 'RES',
    };

    $rand = Str::upper(Str::random(6));
    return $this->ensureUniqueReference("UT-{$typeCode}-{$date}-{$rand}");
}

private function ensureUniqueReference(string $ref): string
{
    $ref = trim($ref);
    $base = $ref;

    $i = 2;
    while (\App\Models\Reservation::where('reference', $ref)->exists()) {
        $ref = $base . '-' . $i;
        $i++;
    }

    return $ref;
}

    public function annuler(Request $request, Reservation $reservation)
{
    // On annule uniquement si en_attente (par défaut)
    if ($reservation->statut !== Reservation::STATUT_EN_ATTENTE) {
        return response()->json([
            'message' => "Impossible d'annuler une réservation au statut '{$reservation->statut}'.",
            'errors' => [
                'statut' => ["Seules les réservations en_attente peuvent être annulées."]
            ],
        ], 422);
    }

    // Optionnel : accepter un motif d'annulation
    // $request->validate(['motif' => 'nullable|string|max:255']);

    $reservation->update([
        'statut' => Reservation::STATUT_ANNULE,
        // 'notes' => trim(($reservation->notes ?? '')."\nAnnulation: ".$request->input('motif')) ?: $reservation->notes,
    ]);

    return response()->json(
        $reservation->load(['client', 'produit', 'forfait', 'participants', 'flightDetails']),
        Response::HTTP_OK
    );
}

 private function ensureFactureEmise(Reservation $reservation): Facture
{
    // si tu as hasMany factures()
    $facture = $reservation->factures()->latest('id')->first();

    if (!$facture) {
        $facture = $reservation->factures()->create([
            'numero' => Facture::generateNumero(), // ou ta logique
            'date_facture' => now()->toDateString(),
            'montant_sous_total' => (float) ($reservation->montant_sous_total ?? $reservation->montant_total ?? 0),
            'montant_taxes' => (float) ($reservation->montant_taxes ?? 0),
            'montant_total' => (float) ($reservation->montant_total ?? 0),
            'statut' => 'brouillon',
            'pdf_path' => null,
        ]);
    }

    // Toujours émettre si brouillon
    if ($facture->statut === 'brouillon') {
        $facture->update(['statut' => 'emis']);
    }

    return $facture;
}

public function devisPdf(Reservation $reservation)
{
    $reservation->load(['client', 'produit', 'forfait', 'participants', 'flightDetails']);

    $devis = [
        'numero' => 'DEV-' . str_pad((string) $reservation->id, 6, '0', STR_PAD_LEFT),
        'date' => now()->toDateString(),
        'validite' => now()->addDays(7)->toDateString(), // 7 jours (modifiable)
        'echeance' => 'Règlement immédiat', // comme ta facture actuelle :contentReference[oaicite:6]{index=6}
        'tva' => 0,
    ];

    // Montants : on colle à ton UI facture qui affiche montant_total partout :contentReference[oaicite:7]{index=7}
    $montantTotal = (float) ($reservation->montant_total ?? 0);

    $pdf = Pdf::loadView('devis', [
        'reservation' => $reservation,
        'devis' => $devis,
        'montant_total' => $montantTotal,
    ])->setPaper('a4');

    return $pdf->stream("devis-{$devis['numero']}.pdf");
}

}
