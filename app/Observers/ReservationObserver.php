<?php

namespace App\Observers;

use App\Models\Reservation;
use App\Services\NotificationService;

class ReservationObserver
{
    /**
     * Déclenché APRÈS création — quelle que soit la branche du contrôleur.
     */
    public function created(Reservation $reservation): void
    {
        $reservation->loadMissing('client');
        $clientName = trim((optional($reservation->client)->prenom ?? '') . ' ' . (optional($reservation->client)->nom ?? ''));

        NotificationService::notifyAll(
            type:  'reservation_created',
            title: "Nouvelle réservation · {$reservation->reference}",
            body:  trim(($clientName ? "Client : {$clientName} · " : '') . "Type : {$reservation->type}"),
            url:   "/reservations/{$reservation->id}",
            data:  ['reservation_id' => $reservation->id],
        );
    }

    /**
     * Déclenché APRÈS update — on détecte les transitions de statut.
     */
    public function updated(Reservation $reservation): void
    {
        if (!$reservation->wasChanged('statut')) return;

        $newStatut = $reservation->statut;
        $reservation->loadMissing('client');
        $clientName = trim((optional($reservation->client)->prenom ?? '') . ' ' . (optional($reservation->client)->nom ?? ''));
        $url = "/reservations/{$reservation->id}";

        if ($newStatut === Reservation::STATUT_CONFIRME) {
            NotificationService::notifyAll(
                type:  'reservation_confirmed',
                title: "Réservation confirmée · {$reservation->reference}",
                body:  $clientName ?: null,
                url:   $url,
                data:  ['reservation_id' => $reservation->id],
            );
        } elseif ($newStatut === Reservation::STATUT_ANNULE) {
            NotificationService::notifyAll(
                type:  'reservation_cancelled',
                title: "Réservation annulée · {$reservation->reference}",
                body:  $clientName ?: null,
                url:   $url,
                data:  ['reservation_id' => $reservation->id],
            );
        }
    }
}
