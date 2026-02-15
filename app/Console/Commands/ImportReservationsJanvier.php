<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Reservation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportReservationsJanvier extends Command
{
    protected $signature = 'reservations:import-janvier
        {path : Chemin du fichier xlsx (ex: storage/app/imports/ETATUTJANVIER2026.xlsx)}
        {--dry-run : Ne rien écrire en base, afficher seulement}
        {--source=ETATUTJANVIER2026 : Nom source import (ex: ETATJANVIER26, ETATUTJANVIER2026)}
        {--year=2026 : Année fallback si date type 13/1}
        {--month=1 : Mois fallback si date type 13/1}';

    protected $description = 'Import réservations billet avion depuis Excel (Janvier) avec import_hash/import_source';

    public function handle(): int
    {
        $path   = (string) $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');
        $source = (string) ($this->option('source') ?: 'ETATUTJANVIER2026');
        $year   = (int) ($this->option('year') ?: 2026);
        $month  = (int) ($this->option('month') ?: 1);

        $fullPath = base_path($path);
        if (!file_exists($fullPath)) {
            $this->error("Fichier introuvable: {$path}");
            $this->line("Astuce Windows: vérifie l'espace/le nom exact du fichier + le bon dossier storage/app/imports/");
            return self::FAILURE;
        }

        $spreadsheet = IOFactory::load($fullPath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true); // A,B,C...

        // On détecte la ligne header: colonne A == "Date"
        $headerRowIndex = null;
        foreach ($rows as $i => $row) {
            $val = trim((string) ($row['A'] ?? ''));
            if (mb_strtolower($val) === 'date') {
                $headerRowIndex = $i;
                break;
            }
        }

        // fallback si pas trouvé
        if (!$headerRowIndex) {
            $headerRowIndex = 11;
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($rows as $i => $row) {
            if ($i <= $headerRowIndex) continue;

            // Colonnes attendues (selon ton fichier):
            // A = date
            // C = payeur
            // E = passager
            // F = itinéraire (ex: DSS-DXB-CMN-DSS)
            // G = référence / PNR (souvent)
            // H = vente HT / sous-total
            // J = frais
            // K = total TTC
            $dateRaw     = $row['A'] ?? null;
            $payeurRaw   = trim((string) ($row['C'] ?? ''));
            $passagerRaw = trim((string) ($row['E'] ?? ''));
            $itineraire  = trim((string) ($row['F'] ?? ''));
            $pnr         = trim((string) ($row['G'] ?? ''));

            // skip lignes complètement vides
            if ($payeurRaw === '' && $passagerRaw === '' && $itineraire === '' && $pnr === '') {
                continue;
            }

            $date = $this->parseExcelDate($dateRaw, $year, $month);
            if (!$date) {
                $skipped++;
                $this->line("[SKIP] Date illisible ligne {$i}: " . (string) ($row['A'] ?? ''));
                continue;
            }

            // Montants
            $sousTotal = $this->parseMoney($row['H'] ?? null);
            $frais     = $this->parseMoney($row['J'] ?? null);
            $totalK    = $this->parseMoney($row['K'] ?? null);

            $montantTotal = $totalK !== null ? $totalK : ($sousTotal ?? 0);
            if ($sousTotal === null) $sousTotal = $montantTotal;

            // Normaliser payeur/passager (si passager vide -> passager = payeur)
            if ($payeurRaw === '') $payeurRaw = $passagerRaw;
            if ($passagerRaw === '') $passagerRaw = $payeurRaw;
            if ($payeurRaw === '') $payeurRaw = 'Client Import';
            if ($passagerRaw === '') $passagerRaw = $payeurRaw;

            [$vd, $va] = $this->parseRoute($itineraire);

            // ✅ IMPORT HASH : stable même si PNR vide ou dupliqué
            // On mixe: date + payeur + passager + route + total + pnr + source
            $importHash = $this->makeImportHash([
                'source'   => $source,
                'date'     => $date,
                'payeur'   => $payeurRaw,
                'passager' => $passagerRaw,
                'vd'       => $vd,
                'va'       => $va,
                'pnr'      => $pnr,
                'total'    => $montantTotal,
            ]);

            if ($dryRun) {
                $this->line("[DRY] {$date} | {$payeurRaw} -> {$passagerRaw} | " . ($vd ?: '-') . "->" . ($va ?: '-') . " | PNR:" . ($pnr ?: '-') . " | total:" . ($montantTotal ?? 0) . " | hash:" . substr($importHash, 0, 10));
                continue;
            }

            DB::transaction(function () use (
                $source, $importHash,
                $pnr, $date, $payeurRaw, $passagerRaw, $vd, $va,
                $sousTotal, $frais, $montantTotal,
                &$created, &$updated
            ) {
                // 1) Client (dédoublonnage simple nom/prenom)
                $client = $this->firstOrCreateClientByFullName($payeurRaw);

                // 2) Upsert reservation par import_hash (NOTRE clé)
                $reservation = Reservation::where('import_hash', $importHash)->first();

                // ✅ Reference : si PNR présent -> on le met, sinon on garde une référence interne si vide
                // IMPORTANT : si ta colonne "reference" est UNIQUE, il faut éviter les collisions.
                // Ici: si PNR vide, on génère une reference unique.
                $reference = $pnr !== '' ? $pnr : $this->fallbackReference($vd, $va);

                $payload = [
                    'client_id'          => $client->id,
                    'type'               => Reservation::TYPE_BILLET_AVION,
                    'statut'             => Reservation::STATUT_CONFIRME,
                    'nombre_personnes'   => 1,
                    'montant_sous_total' => $sousTotal ?? 0,
                    'montant_taxes'      => $frais ?? 0,
                    'montant_total'      => $montantTotal ?? 0,
                    'notes'              => 'Import Janvier (Excel)',
                    'import_source'      => $source,
                    'import_hash'        => $importHash,
                ];

                // Si nouvel enregistrement : reference obligatoire
                // Si update : on ne change PAS la reference si elle existe déjà (évite de casser l’unicité/historique)
                if ($reservation) {
                    $updated++;
                    $reservation->update($payload);
                } else {
                    $created++;

                    // ⚠️ sécurité unicité : si reference existe déjà, on suffixe
                    if (Reservation::where('reference', $reference)->exists()) {
                        $reference = $reference . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
                    }

                    $reservation = Reservation::create(array_merge($payload, [
                        'reference' => $reference,
                        'produit_id' => null,
                        'forfait_id' => null,
                    ]));
                }

                // 3) Passager (participant lié à la réservation) + passenger_id
                // On veut 1 passager "central". Si déjà présent, on garde.
                $passenger = $reservation->participants()
                    ->where('role', 'passenger')
                    ->first();

                if (!$passenger) {
                    [$nom, $prenom] = $this->splitName($passagerRaw);

                    $passenger = $reservation->participants()->create([
                        'role'   => 'passenger',
                        'nom'    => $nom,
                        'prenom' => $prenom,
                    ]);
                }

                if (!$reservation->passenger_id) {
                    $reservation->update(['passenger_id' => $passenger->id]);
                }

                // 4) Flight details (table reservation_flight_details) : upsert 1-1
                $reservation->flightDetails()->updateOrCreate(
                    ['reservation_id' => $reservation->id],
                    [
                        'ville_depart'  => $vd ?: 'DSS',
                        'ville_arrivee' => $va ?: ($vd ?: 'DSS'),
                        'date_depart'   => $date,
                        'date_arrivee'  => null,
                        'compagnie'     => null,
                        'pnr'           => $pnr !== '' ? $pnr : null,
                        'classe'        => null,
                    ]
                );

                // 5) Facture (optionnel)
                // Si tu veux aussi facturer à l’import, fais-le ici.
                // app(\App\Http\Controllers\API\ReservationController::class)->ensureFactureEmise($reservation);
            });
        }

        if ($dryRun) {
            $this->info("Terminé. (dry-run) Rien écrit en base.");
            return self::SUCCESS;
        }

        $this->info("Terminé. Créées: {$created} | Mises à jour: {$updated} | Ignorées: {$skipped}");
        return self::SUCCESS;
    }

    private function makeImportHash(array $data): string
    {
        // Normalisation “stable”
        $norm = function ($v) {
            $v = (string) ($v ?? '');
            $v = trim(mb_strtolower($v));
            $v = preg_replace('/\s+/u', ' ', $v);
            return $v;
        };

        $blob = implode('|', [
            $norm($data['source'] ?? ''),
            $norm($data['date'] ?? ''),
            $norm($data['payeur'] ?? ''),
            $norm($data['passager'] ?? ''),
            $norm($data['vd'] ?? ''),
            $norm($data['va'] ?? ''),
            $norm($data['pnr'] ?? ''),
            $norm($data['total'] ?? ''),
        ]);

        return hash('sha256', $blob);
    }

    private function fallbackReference(?string $vd, ?string $va): string
    {
        // Ex: UT-AV-20260215-DSS-CDG-AB12CD
        $rand = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $vd = $vd ?: 'DSS';
        $va = $va ?: $vd;
        return 'UT-AV-' . date('Ymd') . '-' . $vd . '-' . $va . '-' . $rand;
    }

    private function parseMoney($v): ?float
    {
        if ($v === null) return null;
        if (is_int($v) || is_float($v)) return (float) $v;

        $s = trim((string) $v);
        if ($s === '') return null;

        $s = str_replace(["\u{00A0}", ' '], '', $s);
        $s = str_replace([','], '.', $s);
        $s = preg_replace('/[^0-9.]/', '', $s);

        if ($s === '' || $s === '.') return null;
        return (float) $s;
    }

    private function parseExcelDate($v, int $year, int $month): ?string
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }

        $s = trim((string) $v);
        if ($s === '') return null;

        // Support "C13/1" => "13/1"
        $s = preg_replace('/^[A-Za-z]+/u', '', $s);
        $s = trim($s);

        // Support "13/1" => année/mois fournis
        if (preg_match('#^(\d{1,2})/(\d{1,2})$#', $s, $m)) {
            $d  = (int) $m[1];
            $mo = (int) $m[2];
            return sprintf('%04d-%02d-%02d', $year, $mo, $d);
        }

        // Support "2026-01-03"
        if (preg_match('#^\d{4}-\d{2}-\d{2}$#', $s)) return $s;

        // Support "03/01/2026" ou "3/1/26"
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})$#', $s, $m)) {
            $d = (int) $m[1];
            $mo = (int) $m[2];
            $y = (int) $m[3];
            if ($y < 100) $y += 2000;
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }

        $t = strtotime($s);
        if ($t === false) return null;
        return date('Y-m-d', $t);
    }

    private function parseRoute(string $route): array
    {
        $route = trim($route);
        if ($route === '') return [null, null];

        // DSS-DXB-CMN-DSS (on prend premier et dernier)
        $parts = array_values(array_filter(array_map('trim', preg_split('/[-–—]/u', $route) ?: [])));
        if (count($parts) === 0) return [null, null];
        if (count($parts) === 1) return [$parts[0], $parts[0]];

        return [$parts[0], $parts[count($parts) - 1]];
    }

    private function firstOrCreateClientByFullName(string $fullName): Client
    {
        $fullName = trim($fullName);
        if ($fullName === '') $fullName = 'Client Import';

        [$nom, $prenom] = $this->splitName($fullName);

        return Client::firstOrCreate(
            ['nom' => $nom, 'prenom' => $prenom],
            ['pays' => 'Sénégal']
        );
    }

    private function splitName(string $full): array
    {
        $full = trim(preg_replace('/\s+/u', ' ', $full));
        if ($full === '') return ['-', null];

        // entreprise (majuscule + >2 mots) => tout en "nom"
        $isAllUpper = (mb_strtoupper($full, 'UTF-8') === $full);
        $words = explode(' ', $full);

        if ($isAllUpper && count($words) > 2) {
            return [$full, null];
        }

        if (count($words) === 1) {
            return [$words[0], null];
        }

        $nom = array_pop($words);
        $prenom = trim(implode(' ', $words));
        return [$nom, $prenom !== '' ? $prenom : null];
    }
}
