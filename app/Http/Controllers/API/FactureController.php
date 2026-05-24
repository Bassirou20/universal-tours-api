<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Facture;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class FactureController extends Controller
{

    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 10);

        $q = Facture::query()
            ->with(['reservation.client', 'paiements'])
            ->orderByDesc('id');

        if ($s = $request->get('search')) {
            $q->where(function ($qq) use ($s) {
                $qq->where('numero', 'like', "%$s%")
                   ->orWhereHas('reservation', function ($r) use ($s) {
                       $r->where('reference', 'like', "%$s%")
                         ->orWhereHas('client', function ($c) use ($s) {
                             $c->where('nom', 'like', "%$s%")
                               ->orWhere('prenom', 'like', "%$s%")
                               ->orWhere('email', 'like', "%$s%");
                         });
                   });
            });
        }

        if ($st = $request->get('statut')) {
            // Map UI statut to all DB equivalents (handles legacy values)
            $variants = match ($st) {
                'payee'     => ['payee', 'payée', 'paye_totalement'],
                'partielle' => ['partielle', 'paye_partiellement'],
                'impayee'   => ['impayee', 'impayée'],
                'annule'    => ['annule', 'annulee', 'annulée'],
                'emis'      => ['emis', 'emise'],
                default     => [$st],
            };
            $q->whereIn('statut', $variants);
        }

        // ?follow=1 → factures impayées ou partiellement payées (vue "à suivre")
        if ($request->boolean('follow')) {
            $q->whereIn('statut', ['impayee', 'emis', 'partielle', 'paye_partiellement']);
        }

        if ($clientId = $request->get('client_id')) {
            $q->whereHas('reservation', fn ($qq) => $qq->where('client_id', $clientId));
        }

        if ($reservationId = $request->get('reservation_id')) {
            $q->where('reservation_id', $reservationId);
        }

        if ($dateFrom = $request->get('date_from')) {
            $q->whereDate('date_facture', '>=', $dateFrom);
        }

        if ($dateTo = $request->get('date_to')) {
            $q->whereDate('date_facture', '<=', $dateTo);
        }

        return $q->paginate($perPage);
    }

    // ✅ CORRECTION : méthode show() ajoutée — c'était la cause du "Impossible de charger les détails"
    public function show($id)
    {
        $facture = Facture::with([
            'reservation.client',
            'reservation.flightDetails',
            'reservation.assuranceDetails',
            'reservation.participants',
            'reservation.passenger',
            'reservation.forfait',
            'reservation.produit',
            'paiements',
        ])->findOrFail($id);

        $pay = $this->payMeta($facture);

        return response()->json(array_merge(
            $facture->toArray(),
            ['pay_meta' => $pay]
        ));
    }

    public function storeStandalone(Request $request)
    {
        $data = $request->validate([
            'numero'         => 'required|string|max:100|unique:factures,numero',
            'statut'         => 'nullable|in:impayee,emis,partielle,payee,annule,brouillon',
            'total'          => 'required|numeric|min:0',
            'due_date'       => 'nullable|date',
            'reservation_id' => 'nullable|integer|exists:reservations,id',
        ]);

        $facture = Facture::create([
            'numero'             => $data['numero'],
            'statut'             => $data['statut'] ?? 'emis',
            'montant_sous_total' => $data['total'],
            'montant_taxes'      => 0,
            'montant_total'      => $data['total'],
            'date_facture'       => now()->toDateString(),
            'due_date'           => $data['due_date'] ?? null,
            'reservation_id'     => $data['reservation_id'] ?? null,
            'pdf_path'           => null,
        ]);

        return response()->json(
            $facture->load(['reservation.client', 'paiements']),
            Response::HTTP_CREATED
        );
    }

    public function store(Request $request, \App\Models\Reservation $reservation)
    {
        if ($reservation->statut === \App\Models\Reservation::STATUT_ANNULE) {
            return response()->json([
                'message' => "Impossible de créer une facture pour une réservation annulée.",
                'errors' => ['reservation' => ["Réservation annulée."]],
            ], 422);
        }

        $data = $request->validate([
            'date_facture'  => 'nullable|date',
            'montant_total' => 'nullable|numeric|min:0.01',
        ]);

        return \DB::transaction(function () use ($reservation, $data) {
            // Idempotence : retourner la facture existante non-annulée plutôt qu'en créer une nouvelle
            $existing = $reservation->factures()
                ->whereNotIn('statut', ['annule'])
                ->latest('id')
                ->first();

            if ($existing) {
                return response()->json(
                    $existing->load(['reservation.client', 'paiements']),
                    Response::HTTP_OK
                );
            }

            $montantTotal = (float) ($data['montant_total'] ?? $reservation->montant_total);

            if ($montantTotal <= 0) {
                return response()->json([
                    'message' => "Le montant total est invalide.",
                    'errors'  => ['montant_total' => ["Montant total doit être > 0."]],
                ], 422);
            }

            $facture = \App\Models\Facture::create([
                'reservation_id'     => $reservation->id,
                'numero'             => Facture::generateNumero(),
                'date_facture'       => $data['date_facture'] ?? now()->toDateString(),
                'montant_sous_total' => $montantTotal,
                'montant_taxes'      => 0,
                'montant_total'      => $montantTotal,
                'statut'             => 'brouillon',
                'pdf_path'           => null,
            ]);

            return response()->json(
                $facture->load(['reservation.client', 'paiements']),
                Response::HTTP_CREATED
            );
        });
    }

    public function emettre(Facture $facture)
    {
        if ($facture->statut === 'annule') {
            return response()->json(['message' => "Impossible d'émettre une facture annulée."], 422);
        }

        if ($facture->statut === 'emis') {
            return response()->json(
                $facture->load(['reservation.client', 'paiements']),
                Response::HTTP_OK
            );
        }

        $facture->update(['statut' => 'emis']);

        return response()->json([
            'message' => 'Facture émise.',
            'facture' => $facture->fresh()->load(['reservation.client', 'paiements']),
        ]);
    }

    public function annulerFacture(Facture $facture)
    {
        if ($facture->paiements()->where('statut', 'recu')->exists()) {
            return response()->json([
                'message' => "Impossible d'annuler une facture avec des paiements validés.",
            ], 422);
        }

        $facture->update(['statut' => 'annule']);

        return response()->json([
            'message' => 'Facture annulée.',
            'facture' => $facture->fresh()->load(['reservation.client', 'paiements']),
        ]);
    }

    /**
     * Calcule total / paid / remaining / percent / label
     */
    private function payMeta(Facture $facture): array
    {
        $total = (float) ($facture->montant_total ?? 0);

        $paid = (float) $facture->paiements()
            ->where('statut', 'recu')
            ->sum('montant');

        if ($paid < 0) $paid = 0;
        if ($total > 0 && $paid > $total) $paid = $total;

        $remaining = max(0, $total - $paid);
        $percent   = $total > 0 ? (int) round(($paid / $total) * 100) : 0;

        if ($paid <= 0.00001)          $label = 'NON PAYÉ';
        elseif ($paid + 0.00001 < $total) $label = 'PARTIELLEMENT PAYÉ';
        else                            $label = 'PAYÉ';

        return [
            'total'     => $total,
            'paid'      => $paid,
            'remaining' => $remaining,
            'percent'   => $percent,
            'label'     => $label,
        ];
    }

    /**
     * Stream PDF — GET /factures/{facture}/pdf-stream
     */
    public function pdfStream(Facture $facture)
    {
        if (strtolower((string) $facture->statut) === 'annule') {
            return response()->json(['message' => "Impossible de générer le PDF d'une facture annulée."], 422);
        }

        $facture->load([
            'reservation.client',
            'reservation.produit',
            'reservation.forfait',
            'reservation.participants',
            'paiements',
        ]);

        $pay      = $this->payMeta($facture);
        $logoPath = public_path('assets/logounivtours.png');

        $pdf = Pdf::loadView('pdf.facture', [
            'facture'  => $facture,
            'pay'      => $pay,
            'logoPath' => $logoPath,
        ])->setPaper('a4');

        $filename = "facture-{$facture->numero}.pdf";

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
     * PDF via ID — GET /factures/{id}/pdf
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

        $pay      = $this->payMeta($facture);
        $logoPath = public_path('assets/logounivtours.png');

        $pdf = Pdf::loadView('pdf.facture', [
            'facture'  => $facture,
            'pay'      => $pay,
            'logoPath' => $logoPath,
        ])->setPaper('a4');

        return $pdf->stream("facture-{$facture->numero}.pdf", ['Attachment' => false]);
    }

    public function destroy(Request $request, Facture $facture)
    {
        $force    = $request->boolean('force', false);
        $isAdmin  = $request->user()?->role === 'admin';
        $hasPaid  = $facture->paiements()->where('statut', 'recu')->exists();

        // Blocage normal : paiements encaissés et pas de forçage demandé
        if ($hasPaid && !$force) {
            $count  = $facture->paiements()->where('statut', 'recu')->count();
            $montant = $facture->paiements()->where('statut', 'recu')->sum('montant');
            return response()->json([
                'message'          => "Cette facture contient {$count} paiement(s) encaissé(s) pour un total de {$montant} XOF.",
                'has_paid'         => true,
                'paiements_count'  => $count,
                'paiements_total'  => $montant,
            ], 422);
        }

        // Forçage : réservé aux admins
        if ($force && !$isAdmin) {
            return response()->json([
                'message' => "Seul un administrateur peut forcer la suppression d'une facture avec des paiements.",
            ], 403);
        }

        return DB::transaction(function () use ($facture) {
            $facture->paiements()->delete();

            if (!empty($facture->pdf_path) && Storage::disk('public')->exists($facture->pdf_path)) {
                Storage::disk('public')->delete($facture->pdf_path);
            }

            $facture->delete();

            return response()->json(['message' => 'Facture supprimée avec succès.']);
        });
    }

}