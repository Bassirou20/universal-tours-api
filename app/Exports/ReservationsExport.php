<?php

namespace App\Exports;

use App\Models\Reservation;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use Illuminate\Http\Request;
use Carbon\Carbon;

class ReservationsExport implements
    FromQuery,
    WithHeadings,
    WithMapping,
    WithColumnWidths,
    WithStyles
{
    public function __construct(private Request $request) {}

    public function query()
    {
        $query = Reservation::query()
            ->with([
                'client'           => fn($q) => $q->withTrashed(),
                'produit',
                'forfait',
                'flightDetails',
                'assuranceDetails',
                'evisaDetails',
                'factures.paiements',
            ])
            ->orderByDesc('created_at');

        if ($this->request->filled('type')) {
            $query->where('type', $this->request->get('type'));
        }
        if ($this->request->filled('statut')) {
            $query->where('statut', $this->request->get('statut'));
        }
        if ($this->request->filled('month')) {
            try {
                $start = Carbon::createFromFormat('Y-m', $this->request->get('month'))->startOfMonth();
                $end   = Carbon::createFromFormat('Y-m', $this->request->get('month'))->endOfMonth();
                $query->whereBetween('created_at', [$start, $end]);
            } catch (\Exception) {}
        }
        if ($this->request->filled('search')) {
            $term = trim($this->request->get('search'));
            $query->where(function ($q) use ($term) {
                $q->where('reference', 'like', "%{$term}%")
                  ->orWhere('id', $term)
                  ->orWhereHas('client', fn($qc) => $qc->withTrashed()
                      ->where('nom', 'like', "%{$term}%")
                      ->orWhere('prenom', 'like', "%{$term}%")
                      ->orWhere('telephone', 'like', "%{$term}%")
                      ->orWhere('email', 'like', "%{$term}%")
                  );
            });
        }

        return $query;
    }

    public function headings(): array
    {
        return [
            'Référence',
            'Date',
            'Type',
            'Statut',
            'Client',
            'Téléphone',
            'Email',
            'Nb personnes',
            'Sous-total (FCFA)',
            'Taxes (FCFA)',
            'Total (FCFA)',
            'Payé (FCFA)',
            'Reste (FCFA)',
            'Produit / Forfait',
            'Détails',
            'Notes',
        ];
    }

    public function map($r): array
    {
        $client = $r->client;
        $clientName = trim(($client->prenom ?? '') . ' ' . ($client->nom ?? '')) ?: ($client->nom ?? '—');

        $typeLabels = [
            'billet_avion' => "Billet d'avion",
            'hotel'        => 'Hôtel',
            'voiture'      => 'Location voiture',
            'evenement'    => 'Événement',
            'forfait'      => 'Forfait',
            'assurance'    => 'Assurance',
            'evisa'        => 'E-Visa',
        ];
        $typeLabel = $typeLabels[$r->type] ?? ucfirst($r->type ?? '');

        $statutLabels = [
            'confirmee'  => 'Confirmée',
            'annulee'    => 'Annulée',
            'en_attente' => 'En attente',
            'brouillon'  => 'Brouillon',
        ];
        $statutLabel = $statutLabels[$r->statut] ?? ucfirst($r->statut ?? '');

        // Paiements depuis les factures
        $factures  = $r->factures ?? collect();
        $totalPaid = 0;
        foreach ($factures as $f) {
            if ($f->statut !== 'annulee') {
                $totalPaid += $f->paiements->where('statut', 'recu')->sum('montant');
            }
        }
        $total     = (float) ($r->montant_total ?? 0);
        $totalPaid = min($totalPaid, $total);
        $remaining = max(0, $total - $totalPaid);

        // Produit / forfait
        $produitLabel = $r->produit?->nom ?? $r->forfait?->nom ?? '';

        // Détails spécifiques par type
        $details = '';
        if ($r->type === 'billet_avion' && $r->flightDetails) {
            $fd = $r->flightDetails;
            $parts = array_filter([
                $fd->ville_depart && $fd->ville_arrivee ? "{$fd->ville_depart} → {$fd->ville_arrivee}" : null,
                $fd->compagnie,
                $fd->pnr ? "PNR: {$fd->pnr}" : null,
            ]);
            $details = implode(' · ', $parts);
        } elseif ($r->type === 'evisa' && $r->evisaDetails) {
            $ev = $r->evisaDetails;
            $details = implode(' · ', array_filter([
                $ev->pays_destination,
                $ev->type_visa,
                $ev->date_voyage ? Carbon::parse($ev->date_voyage)->format('d/m/Y') : null,
            ]));
        } elseif ($r->type === 'assurance' && $r->assuranceDetails) {
            $as = $r->assuranceDetails;
            $details = implode(' · ', array_filter([
                $as->libelle,
                $as->date_debut ? Carbon::parse($as->date_debut)->format('d/m/Y') : null,
                $as->date_fin   ? '→ ' . Carbon::parse($as->date_fin)->format('d/m/Y') : null,
            ]));
        }

        return [
            $r->reference ?? '',
            $r->created_at ? Carbon::parse($r->created_at)->format('d/m/Y') : '',
            $typeLabel,
            $statutLabel,
            $clientName,
            $client->telephone ?? '',
            $client->email ?? '',
            $r->nombre_personnes ?? 1,
            (float) ($r->montant_sous_total ?? 0),
            (float) ($r->montant_taxes ?? 0),
            $total,
            $totalPaid,
            $remaining,
            $produitLabel,
            $details,
            $r->notes ?? '',
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 16,  // Référence
            'B' => 12,  // Date
            'C' => 18,  // Type
            'D' => 14,  // Statut
            'E' => 24,  // Client
            'F' => 16,  // Téléphone
            'G' => 26,  // Email
            'H' => 12,  // Nb personnes
            'I' => 18,  // Sous-total
            'J' => 16,  // Taxes
            'K' => 18,  // Total
            'L' => 16,  // Payé
            'M' => 16,  // Reste
            'N' => 22,  // Produit
            'O' => 36,  // Détails
            'P' => 30,  // Notes
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            // En-tête en gras, fond bleu foncé, texte blanc
            1 => [
                'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill'      => ['fillType' => 'solid', 'startColor' => ['argb' => 'FF0F3460']],
                'alignment' => ['vertical' => 'center', 'wrapText' => false],
            ],
        ];
    }
}
