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
    $q = Reservation::with([
        'client',
        'passenger',
        'produit',
        'forfait',
        'participants',
        'factures.paiements', // ✅ IMPORTANT
    ]);

    if ($request->filled('client_id')) {
        $q->where('client_id', $request->client_id);
    }

    if ($request->filled('statut')) {
        $q->where('statut', $request->statut);
    }

    if ($request->filled('type')) {
        $q->where('type', $request->type);
    }

    return $q->latest()->paginate(10);
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

            // 1️⃣ Créer la réservation SANS passenger_id
            $reservation = Reservation::create([
                'client_id' => $client->id,
                'type' => Reservation::TYPE_BILLET_AVION,
                'reference' => $reference,
                'statut' => $statut,
                'nombre_personnes' => 1,
                'montant_sous_total' => $montantSousTotal,
                'montant_taxes' => $montantTaxes,
                'montant_total' => $montantTotal,
                'notes' => $data['notes'] ?? null,
            ]);

            // 2️⃣ Déterminer le passager
            if (!empty($data['passenger_id'])) {

                $passenger = Participant::where('id', $data['passenger_id'])
                    ->where('reservation_id', $reservation->id)
                    ->firstOrFail();

            } elseif (!empty($data['passenger'])) {

                $passenger = $reservation->participants()->create([
                    ...$data['passenger'],
                    'role' => 'passenger',
                ]);

            } else {
                // client = passager
                $passenger = $reservation->participants()->create([
                    'nom' => $client->nom,
                    'prenom' => $client->prenom,
                    'role' => 'passenger',
                ]);
            }

            // 3️⃣ Lier le passager à la réservation
            $reservation->update([
                'passenger_id' => $passenger->id
            ]);

            // 4️⃣ Flight details
            $reservation->flightDetails()->create($data['flight_details']);

            // 5️⃣ Facture
            $this->ensureFactureEmise($reservation);

            return response()->json(
                $reservation->load([
                    'client',
                    'passenger',
                    'flightDetails',
                    'factures.paiements'
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
    public function show(Reservation $reservation)
{
    return $reservation->load([
        'client',
        'passenger',
        'produit',
        'forfait',
        'participants',
        'flightDetails',        // utile pour billet_avion
        'factures.paiements',   // ✅ indispensable pour acompte/partiel/payé
    ]);
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

        $reservation->update($data);

        // ✅ si la réservation est confirmée (ou le devient), on s'assure d'avoir une facture
        if (($reservation->fresh()->statut ?? null) === Reservation::STATUT_CONFIRME) {
            $this->ensureFactureEmise($reservation->fresh());
        }

        return $reservation->fresh()->load(['client', 'produit', 'forfait', 'participants', 'factures.paiements']);
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
