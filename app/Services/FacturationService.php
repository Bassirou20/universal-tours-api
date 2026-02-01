<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Facture;
use Illuminate\Support\Str;
use Carbon\Carbon;

class FacturationService
{
    public function genererFacture(Reservation $reservation): Facture
    {
        $numero = 'FAC-' . Carbon::now()->format('Ymd') . '-' . Str::padLeft((string)($reservation->id), 6, '0');

        $montant_ht = $reservation->montant_sous_total;
        $montant_tva = $reservation->montant_taxes;
        $montant_ttc = $reservation->montant_total;

        $facture = Facture::create([
            'reservation_id' => $reservation->id,
            'numero' => $numero,
            'date_facture' => Carbon::today(),
            'montant_ht' => $montant_ht,
            'montant_tva' => $montant_tva,
            'montant_ttc' => $montant_ttc,
            'devise' => $reservation->devise,
            'statut' => 'emis',
            'pdf_path' => null,
        ]);

        return $facture;
    }
}
