<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Facture;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class FactureController extends Controller
{

    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 10);

        $q = Facture::query()
            ->with(['reservation.client', 'paiements'])
            ->orderByDesc('id');

        if ($s = $request->get('search')) {
            $q->where('numero', 'like', "%$s%");
        }

        if ($st = $request->get('statut')) {
            $q->where('statut', $st);
        }

        if ($clientId = $request->get('client_id')) {
            $q->whereHas('reservation', fn ($qq) => $qq->where('client_id', $clientId));
        }

        return $q->paginate($perPage);
    }

     public function store(Request $request, \App\Models\Reservation $reservation)
    {
        // Interdire de facturer une réservation annulée
        if ($reservation->statut === \App\Models\Reservation::STATUT_ANNULE) {
            return response()->json([
                'message' => "Impossible de créer une facture pour une réservation annulée.",
                'errors' => ['reservation' => ["Réservation annulée."]],
            ], 422);
        }

        $data = $request->validate([
            'date_facture'   => 'nullable|date',
            'montant_total'  => 'nullable|numeric|min:0.01', // optionnel
        ]);

        return \DB::transaction(function () use ($reservation, $data) {
            // Si montant_total pas fourni, on prend celui de la réservation (recommandé)
            $montantTotal = (float) ($data['montant_total'] ?? $reservation->montant_total);

            if ($montantTotal <= 0) {
                return response()->json([
                    'message' => "Le montant total est invalide.",
                    'errors'  => ['montant_total' => ["Montant total doit être > 0."]],
                ], 422);
            }

            $facture = \App\Models\Facture::create([
                'reservation_id'      => $reservation->id,
                'numero'              => Facture::generateNumero(),
                'date_facture'        => $data['date_facture'] ?? now()->toDateString(),

                // TVA=0 => sous_total = total, taxes = 0
                'montant_sous_total'  => $montantTotal,
                'montant_taxes'       => 0,
                'montant_total'       => $montantTotal,

                'statut'              => 'brouillon',
                'pdf_path'            => null,
            ]);

            return response()->json($facture, \Symfony\Component\HttpFoundation\Response::HTTP_CREATED);
        });
    }

    /**
     * Calcule:
     * - total
     * - paid (somme des paiements "recu")
     * - remaining
     * - percent
     * - label: NON PAYÉ / PARTIELLEMENT PAYÉ / PAYÉ
     */
    private function payMeta(Facture $facture): array
    {
        $total = (float) ($facture->montant_total ?? 0);

        // ✅ On compte uniquement les paiements reçus
        $paid = (float) $facture->paiements()
            ->where('statut', 'recu')
            ->sum('montant');

        // Sécurité
        if ($paid < 0) $paid = 0;
        if ($total > 0 && $paid > $total) $paid = $total;

        $remaining = max(0, $total - $paid);

        $percent = $total > 0 ? (int) round(($paid / $total) * 100) : 0;

        if ($paid <= 0.00001) $label = 'NON PAYÉ';
        elseif ($paid + 0.00001 < $total) $label = 'PARTIELLEMENT PAYÉ';
        else $label = 'PAYÉ';

        return [
            'total' => $total,
            'paid' => $paid,
            'remaining' => $remaining,
            'percent' => $percent,
            'label' => $label,
        ];
    }

    /**
     * Stream PDF (route type: GET /factures/{facture}/pdf-stream)
     */
    public function pdfStream(Facture $facture)
    {
        // (optionnel) bloquer si annulée
        if (strtolower((string) $facture->statut) === 'annule') {
            return response()->json(['message' => "Impossible de générer le PDF d'une facture annulée."], 422);
        }

        // Charger les relations utiles
        $facture->load([
            'reservation.client',
            'reservation.produit',
            'reservation.forfait',
            'reservation.participants',
            'paiements',
        ]);

        // ✅ Calcul payé/reste/%/label
        $pay = $this->payMeta($facture);

        // ✅ Logo DomPDF : chemin absolu recommandé
        // Mets ton logo dans public/assets/logounivtours.png
        $logoPath = public_path('assets/logounivtours.png');

        $pdf = Pdf::loadView('pdf.facture', [
            'facture' => $facture,
            'pay' => $pay,
            'logoPath' => $logoPath,
        ])->setPaper('a4');

        $filename = "facture-{$facture->numero}.pdf";

        // (optionnel) sauvegarde le PDF et stocke le chemin en DB si tu as pdf_path
        try {
            $path = "factures/{$filename}";
            Storage::disk('public')->put($path, $pdf->output());
            if (array_key_exists('pdf_path', $facture->getAttributes())) {
                $facture->update(['pdf_path' => $path]);
            }
        } catch (\Throwable $e) {
            // on ne bloque pas le stream si la sauvegarde échoue
        }

        return $pdf->stream($filename, ['Attachment' => false]);
    }

    /**
     * PDF via ID (route type: GET /factures/{id}/pdf)
     */
    public function pdf($id)
    {
        $facture = Facture::with([
            'reservation.client',
            'reservation.produit',
            'reservation.forfait',
            'reservation.participants',
            'paiements',
        ])->findOrFail($id);

        if (strtolower((string) $facture->statut) === 'annule') {
            return response()->json(['message' => "Impossible de générer le PDF d'une facture annulée."], 422);
        }

        $pay = $this->payMeta($facture);
        $logoPath = public_path('assets/logounivtours.png');

        $pdf = Pdf::loadView('pdf.facture', [
            'facture' => $facture,
            'pay' => $pay,
            'logoPath' => $logoPath,
        ])->setPaper('a4');

        return $pdf->stream("facture-{$facture->numero}.pdf", ['Attachment' => false]);
    }
}
