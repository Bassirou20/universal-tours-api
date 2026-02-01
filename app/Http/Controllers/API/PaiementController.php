<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Facture;
use App\Models\Paiement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class PaiementController extends Controller
{
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

            if ($facture->statut !== 'annule') {
                $facture->update(['statut' => $newStatus]);
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
}
