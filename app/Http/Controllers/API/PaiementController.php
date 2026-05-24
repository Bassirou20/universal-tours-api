<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Facture;
use App\Models\Paiement;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class PaiementController extends Controller
{
    public function index(Request $request)
    {
        $query = Paiement::with(['facture.reservation.client'])
            ->orderByDesc('date_paiement')
            ->orderByDesc('created_at');

        if ($request->filled('statut')) {
            $query->where('statut', $request->get('statut'));
        }
        if ($request->filled('mode_paiement')) {
            $query->where('mode_paiement', $request->get('mode_paiement'));
        }
        if ($request->filled('facture_id')) {
            $query->where('facture_id', $request->get('facture_id'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('date_paiement', '>=', $request->get('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('date_paiement', '<=', $request->get('date_to'));
        }
        if ($request->filled('search')) {
            $term = $request->get('search');
            $query->where(function ($q) use ($term) {
                $q->where('reference', 'like', "%{$term}%")
                  ->orWhere('id', $term);
            });
        }

        $perPage = min((int) ($request->get('per_page', 50)), 200);
        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request, Facture $facture)
    {
        $data = $request->validate([
            'montant'       => 'required|numeric|min:1',
            'mode_paiement' => 'required|string|max:50',
            'reference'     => 'nullable|string|max:100',
            'statut'        => 'nullable|in:recu,en_attente,annule',
            'date_paiement' => 'nullable|date',
            'notes'         => 'nullable|string|max:1000',
        ]);

        // Interdire paiement sur facture annulée
        if ($facture->statut === 'annule') {
            return response()->json([
                'message' => "Impossible d'enregistrer un paiement sur une facture annulée.",
            ], 422);
        }

        return DB::transaction(function () use ($facture, $data) {
            // 1) Créer paiement (facture_id auto si on passe par la relation)
            $paiement = $facture->paiements()->create([
                'montant'       => $data['montant'],
                'mode_paiement' => $data['mode_paiement'],
                'reference'     => $data['reference'] ?? null,
                'statut'        => $data['statut'] ?? 'recu',
                'date_paiement' => $data['date_paiement'] ?? now()->toDateString(),
                'notes'         => $data['notes'] ?? null,
            ]);

            // 2) Recalculer payé / reste
            $facture->load('paiements');
            $paid = (float) $facture->paiements
                ->whereIn('statut', ['recu']) // on compte seulement les reçus
                ->sum('montant');

            $total = (float) $facture->montant_total;
            $remaining = max(0, $total - $paid);

            // 3) Mettre à jour statut facture
            // adapte les statuts si tu préfères: 'partiel', 'paye_totalement', etc.
            if ($paid <= 0) {
                $newStatus = $facture->statut; // ne pas casser si déjà 'emis'
            } elseif ($paid + 0.00001 >= $total) {
                $newStatus = 'paye_totalement';
            } else {
                $newStatus = 'paye_partiellement';
            }

            $previousStatus = $facture->statut;
            if ($facture->statut !== 'annule') {
                $facture->update(['statut' => $newStatus]);
            }

            // 🔔 Notifications
            $reservation = $facture->reservation;
            $clientName = trim((optional($reservation?->client)->prenom ?? '') . ' ' . (optional($reservation?->client)->nom ?? ''));
            $factureRef = $facture->numero ?? $facture->reference ?? ('#' . $facture->id);
            $devise = $facture->devise ?? 'XOF';
            $montantFmt = number_format((float)$data['montant'], 0, ',', ' ') . ' ' . $devise;
            $url = $reservation ? "/reservations/{$reservation->id}" : "/factures";

            // Paiement reçu (toujours)
            NotificationService::notifyAll(
                type:  'payment_received',
                title: "Paiement reçu · {$montantFmt}",
                body:  "Facture {$factureRef}" . ($clientName ? " · {$clientName}" : ''),
                url:   $url,
                data:  ['facture_id' => $facture->id, 'paiement_id' => $paiement->id],
            );

            // Facture entièrement payée (uniquement à la transition)
            if ($newStatus === 'paye_totalement' && $previousStatus !== 'paye_totalement') {
                NotificationService::notifyAll(
                    type:  'invoice_paid',
                    title: "Facture entièrement payée · {$factureRef}",
                    body:  $clientName ?: null,
                    url:   $url,
                    data:  ['facture_id' => $facture->id],
                );
            }

            return response()->json([
                'message'   => 'Paiement enregistré',
                'paiement'  => $paiement,
                'paid'      => $paid,
                'remaining' => $remaining,
                'facture'   => $facture->fresh()->load(['reservation.client', 'paiements']),
            ], Response::HTTP_CREATED);
        });
    }

    public function update(Request $request, Paiement $paiement)
    {
        $data = $request->validate([
            'montant'       => 'sometimes|numeric|min:1',
            'mode_paiement' => 'sometimes|string|max:50',
            'reference'     => 'nullable|string|max:100',
            'statut'        => 'nullable|in:recu,en_attente,annule',
            'date_paiement' => 'nullable|date',
            'notes'         => 'nullable|string|max:1000',
        ]);

        return DB::transaction(function () use ($paiement, $data) {
            $paiement->update($data);

            $facture = $paiement->facture;
            $facture->load('paiements');
            $paid  = (float) $facture->paiements->whereIn('statut', ['recu'])->sum('montant');
            $total = (float) $facture->montant_total;

            if ($facture->statut !== 'annule') {
                if ($paid <= 0)                       $newStatut = 'emis';
                elseif ($paid + 0.00001 >= $total)    $newStatut = 'paye_totalement';
                else                                   $newStatut = 'paye_partiellement';
                $facture->update(['statut' => $newStatut]);
            }

            return response()->json([
                'message'  => 'Paiement mis à jour.',
                'paiement' => $paiement->fresh(),
                'facture'  => $facture->fresh()->load(['paiements']),
            ]);
        });
    }

    public function destroy(Paiement $paiement)
    {
        return DB::transaction(function () use ($paiement) {
            $facture = $paiement->facture;
            $paiement->delete();

            $facture->load('paiements');
            $paid  = (float) $facture->paiements->whereIn('statut', ['recu'])->sum('montant');
            $total = (float) $facture->montant_total;

            if ($facture->statut !== 'annule') {
                if ($paid <= 0)                       $newStatut = 'emis';
                elseif ($paid + 0.00001 >= $total)    $newStatut = 'paye_totalement';
                else                                   $newStatut = 'paye_partiellement';
                $facture->update(['statut' => $newStatut]);
            }

            return response()->json(['message' => 'Paiement supprimé.']);
        });
    }
}
