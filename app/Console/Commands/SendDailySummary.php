<?php

namespace App\Console\Commands;

use App\Models\Paiement;
use App\Models\Reservation;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Récap quotidien : nombre de réservations + CA encaissé sur la journée.
 * À planifier en fin de journée (ex: 19h).
 */
class SendDailySummary extends Command
{
    protected $signature = 'notifications:daily-summary';
    protected $description = 'Envoie le récapitulatif quotidien à l\'équipe';

    public function handle(): int
    {
        $today = now()->toDateString();

        $countReservations = Reservation::whereDate('created_at', $today)->count();
        $sumPaiements = (float) Paiement::whereDate('date_paiement', $today)
            ->where('statut', 'recu')
            ->sum('montant');

        if ($countReservations === 0 && $sumPaiements <= 0) {
            $this->info("Pas d'activité aujourd'hui — aucune notification envoyée.");
            return self::SUCCESS;
        }

        $sumFmt = number_format($sumPaiements, 0, ',', ' ') . ' XOF';
        $body = "{$countReservations} réservation" . ($countReservations > 1 ? 's' : '')
            . " · {$sumFmt} encaissé" . ($sumPaiements > 0 ? 's' : '');

        NotificationService::notifyAll(
            type:  'daily_summary',
            title: "Récap du jour",
            body:  $body,
            url:   "/dashboard",
            data:  ['date' => $today, 'reservations' => $countReservations, 'paiements' => $sumPaiements],
        );

        $this->info("Récap quotidien envoyé : {$body}");
        return self::SUCCESS;
    }
}
