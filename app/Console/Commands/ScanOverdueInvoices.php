<?php

namespace App\Console\Commands;

use App\Models\Facture;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Notifie l'équipe des factures non-payées depuis plus de N jours.
 * À planifier dans Console/Kernel.php (daily).
 */
class ScanOverdueInvoices extends Command
{
    protected $signature = 'notifications:scan-overdue {--days=7 : Nombre de jours après date_facture pour considérer une facture comme échue}';
    protected $description = 'Crée des notifications pour les factures impayées échues';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days)->toDateString();

        // Factures non-payées (emis/impayee/partielle) émises avant le cutoff
        $factures = Facture::with('reservation.client')
            ->whereIn('statut', ['emis', 'impayee', 'impayée', 'partielle', 'paye_partiellement'])
            ->where(function ($q) use ($cutoff) {
                $q->whereDate('date_facture', '<=', $cutoff)
                  ->orWhereNull('date_facture'); // sécurité si date null on prend created_at
            })
            ->get();

        $count = 0;
        foreach ($factures as $f) {
            $ref = $f->numero ?? $f->reference ?? ('#' . $f->id);
            $clientName = trim((optional($f->reservation?->client)->prenom ?? '') . ' ' . (optional($f->reservation?->client)->nom ?? ''));
            $url = $f->reservation_id ? "/reservations/{$f->reservation_id}" : "/factures";

            // Dédoublonnage : ne pas re-notifier si déjà notifié dans les 24h
            $alreadyNotified = \App\Models\Notification::where('type', 'invoice_overdue')
                ->where('data->facture_id', $f->id)
                ->where('created_at', '>=', now()->subHours(24))
                ->exists();

            if ($alreadyNotified) continue;

            NotificationService::notifyAll(
                type:  'invoice_overdue',
                title: "Facture impayée échue · {$ref}",
                body:  $clientName ?: 'Relance recommandée',
                url:   $url,
                data:  ['facture_id' => $f->id],
            );
            $count++;
        }

        $this->info("Notifications envoyées pour {$count} facture(s) échue(s) (cutoff {$cutoff}, {$days}j).");
        return self::SUCCESS;
    }
}
