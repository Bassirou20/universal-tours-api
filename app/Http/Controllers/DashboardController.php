<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Reservation;
use App\Models\Facture;
use App\Models\Paiement;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        // Périodes
        $today = Carbon::today();
        $start7 = $today->copy()->subDays(6); // 7 jours incluant aujourd'hui
        $start30 = $today->copy()->subDays(29);

        $monthStart = $today->copy()->startOfMonth();
        $monthEnd = $today->copy()->endOfMonth();

        // -----------------------------
        // KPIs
        // -----------------------------

        // Réservations créées sur 7 jours
        $reservations7d = Reservation::query()
            ->whereBetween('created_at', [$start7->copy()->startOfDay(), $today->copy()->endOfDay()])
            ->count();

        // Clients total + nouveaux ce mois
        $clientsTotal = Client::query()->count();
        $newClientsMonth = Client::query()
            ->whereBetween('created_at', [$monthStart->copy()->startOfDay(), $monthEnd->copy()->endOfDay()])
            ->count();

        // CA du mois = somme des factures "payées" (statut = payee) sur le mois
        // ✅ FIX: utiliser montant_total (car "total" n'existe pas)
        $caMonth = (float) Facture::query()
            ->where('statut', 'payee')
            ->whereBetween('created_at', [$monthStart->copy()->startOfDay(), $monthEnd->copy()->endOfDay()])
            ->sum(DB::raw('COALESCE(montant_total, 0)'));

        // Factures à suivre (impayées/partielles) du mois
        $followCount = Facture::query()
            ->whereIn('statut', ['impayee', 'partielle', 'paye_partiellement', 'paye_partiellement']) // tolérance si tu as déjà d’autres statuts
            ->whereBetween('created_at', [$monthStart->copy()->startOfDay(), $monthEnd->copy()->endOfDay()])
            ->count();

        // -----------------------------
        // Séries (charts)
        // -----------------------------

        // Série réservations sur 7 jours (count par date)
        $reservationsSeries7d = Reservation::query()
            ->selectRaw('DATE(created_at) as d, COUNT(*) as v')
            ->whereBetween('created_at', [$start7->copy()->startOfDay(), $today->copy()->endOfDay()])
            ->groupBy('d')
            ->orderBy('d')
            ->get()
            ->keyBy('d');

        $reservationsChart7d = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $start7->copy()->addDays($i);
            $key = $d->toDateString();
            $reservationsChart7d[] = [
                'date' => $key,
                'label' => $d->translatedFormat('D'),
                'value' => (int) optional($reservationsSeries7d->get($key))->v,
            ];
        }

        // Série CA 30 jours: somme des factures payées par date
        // ✅ FIX: montant_total
        $caSeries30 = Facture::query()
            ->selectRaw('DATE(created_at) as d, SUM(COALESCE(montant_total, 0)) as v')
            ->where('statut', 'payee')
            ->whereBetween('created_at', [$start30->copy()->startOfDay(), $today->copy()->endOfDay()])
            ->groupBy('d')
            ->orderBy('d')
            ->get()
            ->keyBy('d');

        $caChart30d = [];
        for ($i = 0; $i < 30; $i++) {
            $d = $start30->copy()->addDays($i);
            $key = $d->toDateString();
            $caChart30d[] = [
                'date' => $key,
                'label' => $d->translatedFormat('d M'),
                'value' => (float) optional($caSeries30->get($key))->v,
            ];
        }

        // -----------------------------
        // Lists (derniers éléments)
        // -----------------------------
        $lastReservations = Reservation::query()
            ->with(['client:id,prenom,nom,email'])
            ->orderByDesc('created_at')
            ->limit(6)
            ->get();

        $facturesToFollow = Facture::query()
            ->orderByDesc('created_at')
            ->whereIn('statut', ['impayee', 'partielle', 'paye_partiellement'])
            ->limit(6)
            ->get();

        return response()->json([
            'kpis' => [
                'reservations_7d' => $reservations7d,
                'ca_month' => $caMonth,
                'follow_count' => $followCount,
                'clients_total' => $clientsTotal,
                'new_clients_month' => $newClientsMonth,
                'range' => [
                    'start7' => $start7->toDateString(),
                    'start30' => $start30->toDateString(),
                    'monthStart' => $monthStart->toDateString(),
                    'monthEnd' => $monthEnd->toDateString(),
                ],
            ],
            'series' => [
                'reservations_7d' => $reservationsChart7d,
                'ca_30d' => $caChart30d,
            ],
            'lists' => [
                'last_reservations' => $lastReservations,
                'factures_follow' => $facturesToFollow,
            ],
        ]);
    }
}
