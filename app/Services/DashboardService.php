<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Facture;
use App\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    /**
     * Payload Dashboard (fast, no fetchAllPaged).
     *
     * @param  array{
     *   tz?: string,
     *   days_reservations?: int,
     *   days_ca?: int,
     *   month?: string, // "YYYY-MM"
     * } $opts
     */
    public function get(array $opts = []): array
    {
        $tz = $opts['tz'] ?? config('app.timezone', 'UTC');

        $daysReservations = (int)($opts['days_reservations'] ?? 7);
        $daysCA = (int)($opts['days_ca'] ?? 30);

        $now = Carbon::now($tz);
        $today = $now->copy()->startOfDay();

        // Month window (default current month)
        $monthStr = $opts['month'] ?? $today->format('Y-m');
        try {
            $monthStart = Carbon::createFromFormat('Y-m', $monthStr, $tz)->startOfMonth();
        } catch (\Throwable $e) {
            $monthStart = $today->copy()->startOfMonth();
        }
        $monthEnd = $monthStart->copy()->endOfMonth();

        // Ranges
        $start7 = $today->copy()->subDays(max(1, $daysReservations) - 1)->startOfDay();
        $start30 = $today->copy()->subDays(max(1, $daysCA) - 1)->startOfDay();

        // ---------- KPIs ----------
        $reservations7d = Reservation::query()
            ->whereBetween('created_at', [$start7->copy()->startOfDay(), $today->copy()->endOfDay()])
            ->count();

        $clientsTotal = Client::query()->count();

        $newClientsMonth = Client::query()
            ->whereBetween('created_at', [$monthStart->copy()->startOfDay(), $monthEnd->copy()->endOfDay()])
            ->count();

        // Factures à suivre (impayee / partielle) sur le mois
        $facturesToFollowCount = Facture::query()
            ->whereIn('statut', ['impayee', 'partielle'])
            ->whereBetween('created_at', [$monthStart->copy()->startOfDay(), $monthEnd->copy()->endOfDay()])
            ->count();

        // CA du mois : somme des factures payées
        // ✅ Fix: pas de colonne `total` -> on base sur `montant_total` (fallback COALESCE au cas où)
        $caMonth = (float) Facture::query()
            ->where('statut', 'payee')
            ->whereBetween('created_at', [$monthStart->copy()->startOfDay(), $monthEnd->copy()->endOfDay()])
            ->selectRaw('SUM(COALESCE(montant_total, total_ttc, total_amount, 0)) as agg')
            ->value('agg') ?? 0.0;

        // ---------- SERIES ----------
        $seriesReservations7d = $this->buildDailySeries($start7, $today, function (Carbon $from, Carbon $to) {
            $rows = Reservation::query()
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw("DATE(created_at) as d, COUNT(*) as v")
                ->groupBy('d')
                ->orderBy('d')
                ->get();

            $map = [];
            foreach ($rows as $r) {
                $map[(string)$r->d] = (int)$r->v;
            }
            return $map;
        });

        $seriesCA30d = $this->buildDailySeries($start30, $today, function (Carbon $from, Carbon $to) {
            $rows = Facture::query()
                ->where('statut', 'payee')
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw("DATE(created_at) as d, SUM(COALESCE(montant_total, total_ttc, total_amount, 0)) as v")
                ->groupBy('d')
                ->orderBy('d')
                ->get();

            $map = [];
            foreach ($rows as $r) {
                $map[(string)$r->d] = (float)$r->v;
            }
            return $map;
        });

        // ---------- LISTS (Top 6) ----------
        $lastReservations = Reservation::query()
            ->select(['id', 'reference', 'type', 'statut', 'created_at', 'client_id'])
            ->with(['client:id,prenom,nom,email'])
            ->orderByDesc('created_at')
            ->limit(6)
            ->get()
            ->map(function ($r) {
                return [
                    'id' => $r->id,
                    'reference' => $r->reference,
                    'type' => $r->type,
                    'statut' => $r->statut,
                    'created_at' => optional($r->created_at)->toISOString(),
                    'client' => $r->client ? [
                        'id' => $r->client->id,
                        'prenom' => $r->client->prenom,
                        'nom' => $r->client->nom,
                        'email' => $r->client->email,
                    ] : null,
                ];
            })
            ->values()
            ->all();

        $followTop = Facture::query()
            ->select(['id', 'numero', 'statut', 'montant_total', 'created_at', 'reservation_id'])
            ->whereIn('statut', ['impayee', 'partielle'])
            ->whereBetween('created_at', [$monthStart->copy()->startOfDay(), $monthEnd->copy()->endOfDay()])
            ->orderByDesc('created_at')
            ->limit(6)
            ->get()
            ->map(function ($f) {
                return [
                    'id' => $f->id,
                    'numero' => $f->numero,
                    'statut' => $f->statut,
                    'total' => (float)($f->montant_total ?? 0),
                    'created_at' => optional($f->created_at)->toISOString(),
                    'reservation_id' => $f->reservation_id,
                ];
            })
            ->values()
            ->all();

        // ✅ Toujours renvoyer un objet "series" complet pour éviter: d.series is undefined
        return [
            'meta' => [
                'tz' => $tz,
                'generated_at' => $now->toISOString(),
                'range' => [
                    'reservations_7d' => [$start7->toDateString(), $today->toDateString()],
                    'ca_30d' => [$start30->toDateString(), $today->toDateString()],
                    'month' => [$monthStart->toDateString(), $monthEnd->toDateString()],
                ],
            ],
            'kpis' => [
                'reservations_7d' => $reservations7d,
                'ca_month' => $caMonth,
                'factures_to_follow_month' => $facturesToFollowCount,
                'clients_total' => $clientsTotal,
                'new_clients_month' => $newClientsMonth,
            ],
            'series' => [
                'reservations_7d' => $seriesReservations7d, // [{d, v}, ...]
                'ca_30d' => $seriesCA30d, // [{d, v}, ...]
            ],
            'lists' => [
                'last_reservations' => $lastReservations,
                'factures_to_follow' => $followTop,
            ],
        ];
    }

    /**
     * Build a dense daily series with zero-fill.
     *
     * @param Carbon $from startOfDay
     * @param Carbon $to startOfDay
     * @param callable(Carbon $from, Carbon $to): array<string,int|float> $fetchMap
     * @return array<int, array{d: string, v: int|float}>
     */
    private function buildDailySeries(Carbon $from, Carbon $to, callable $fetchMap): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        $map = $fetchMap($from, $to);

        $out = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($cursor <= $end) {
            $key = $cursor->toDateString();
            $out[] = [
                'd' => $key,
                'v' => $map[$key] ?? 0,
            ];
            $cursor->addDay();
        }

        return $out;
    }
}
