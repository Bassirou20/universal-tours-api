<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Participant;
use App\Models\Reservation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportReservationsJanvier extends Command
{
    protected $signature = 'reservations:import-janvier {path : Chemin du fichier xlsx (ex: storage/app/imports/ETATJANVIER26.xlsx)} {--dry-run : Ne rien écrire en base, afficher seulement}';
    protected $description = 'Import des réservations (billets avion) depuis ETAT JANVIER 26.xlsx';

    public function handle(): int
    {
        $path = $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');

        $fullPath = base_path($path);
        if (!file_exists($fullPath)) {
            $this->error("Fichier introuvable: {$path}");
            return self::FAILURE;
        }

        $spreadsheet = IOFactory::load($fullPath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true); // A,B,C...

        // On détecte la ligne header: colonne A == "Date"
        $headerRowIndex = null;
        foreach ($rows as $i => $row) {
            $val = trim((string)($row['A'] ?? ''));
            if (mb_strtolower($val) === 'date') {
                $headerRowIndex = $i;
                break;
            }
        }

        if (!$headerRowIndex) {
            // Dans ton fichier, la date est déjà en datetime sur les vraies lignes.
            // Donc si header non trouvé, on commence après la zone "UNIVERSAL TOURS..." -> on prend 12 comme fallback.
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
            // G = référence (PNR)
            // H = vente HT / sous-total
            // J = frais (souvent)
            // K = total TTC (souvent)
            $dateRaw = $row['A'] ?? null;
            $ref = trim((string)($row['G'] ?? ''));
            $payeurRaw = trim((string)($row['C'] ?? ''));
            $passagerRaw = trim((string)($row['E'] ?? ''));
            $itineraire = trim((string)($row['F'] ?? ''));

            // skip lignes vides
            if ($ref === '' && $payeurRaw === '' && $passagerRaw === '' && $itineraire === '') {
                continue;
            }

            $date = $this->parseExcelDate($dateRaw);
            if (!$date) {
                $skipped++;
                $this->line("[SKIP] Date illisible ligne {$i}: " . (string)($row['A'] ?? ''));
                continue;
            }

            // Montants
            $sousTotal = $this->parseMoney($row['H'] ?? null);
            $frais     = $this->parseMoney($row['J'] ?? null);
            $totalK    = $this->parseMoney($row['K'] ?? null);

            // si K est vide, on prend H comme total
            $montantTotal = $totalK !== null ? $totalK : ($sousTotal ?? 0);

            // si H est vide, on peut fallback total
            if ($sousTotal === null) {
                $sousTotal = $montantTotal;
            }

            // Normaliser payeur/passager (si passager vide -> passager = payeur)
            if ($payeurRaw === '') $payeurRaw = $passagerRaw;
            if ($passagerRaw === '') $passagerRaw = $payeurRaw;

            // Parsing itinéraire
            [$vd, $va] = $this->parseRoute($itineraire);
            if (!$vd || !$va) {
                // si itinéraire vide, on fabrique quand même une ref affichable
                $vd = $vd ?: 'DSS';
                $va = $va ?: 'DSS';
            }

            // Dry output
            if ($dryRun) {
                $this->line("[DRY] {$date} | {$payeurRaw} -> {$passagerRaw} | {$vd}->{$va} | {$ref} | {$montantTotal}");
                continue;
            }

            DB::transaction(function () use (
                $ref, $date, $payeurRaw, $passagerRaw, $vd, $va,
                $sousTotal, $frais, $montantTotal,
                &$created, &$updated
            ) {
                // 1) Client
                $client = $this->firstOrCreateClientByFullName($payeurRaw);

                // 2) Upsert reservation by reference (unique)
                $reservation = Reservation::where('reference', $ref)->first();

                if ($reservation) {
                    $updated++;
                    $reservation->update([
                        'client_id' => $client->id,
                        'type' => Reservation::TYPE_BILLET_AVION,
                        'statut' => Reservation::STATUT_CONFIRME,
                        'nombre_personnes' => 1,
                        'montant_sous_total' => $sousTotal,
                        'montant_taxes' => $frais ?? 0,
                        'montant_total' => $montantTotal,
                        'notes' => 'Import Janvier (Excel)',
                    ]);
                } else {
                    $created++;
                    $reservation = Reservation::create([
                        'client_id' => $client->id,
                        'type' => Reservation::TYPE_BILLET_AVION,
                        'reference' => $ref,
                        'statut' => Reservation::STATUT_CONFIRME,
                        'nombre_personnes' => 1,
                        'montant_sous_total' => $sousTotal,
                        'montant_taxes' => $frais ?? 0,
                        'montant_total' => $montantTotal,
                        'notes' => 'Import Janvier (Excel)',
                    ]);
                }

                // 3) Passager (participant lié à la réservation) + passenger_id
                //    On évite de recréer si déjà présent
                $passenger = $reservation->participants()
                    ->where('role', 'passenger')
                    ->first();

                if (!$passenger) {
                    [$nom, $prenom] = $this->splitName($passagerRaw);

                    $passenger = $reservation->participants()->create([
                        'role' => 'passenger',
                        'nom' => $nom,
                        'prenom' => $prenom,
                    ]);
                }

                if (!$reservation->passenger_id) {
                    $reservation->update(['passenger_id' => $passenger->id]);
                }

                // 4) Flight details (table reservation_flight_details)
                //    On upsert (1-1 via unique reservation_id)
                $reservation->flightDetails()->updateOrCreate(
                    ['reservation_id' => $reservation->id],
                    [
                        'ville_depart' => $vd,
                        'ville_arrivee' => $va,
                        'date_depart' => $date,
                        'date_arrivee' => null,
                        'compagnie' => null,
                        'pnr' => $ref, // optionnel: tu peux stocker le PNR ici aussi
                        'classe' => null,
                    ]
                );

                // 5) Facture : si tu veux (optionnel)
                // (si ton controller le fait déjà, tu peux laisser l’import sans facture)
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

    private function parseMoney($v): ?float
    {
        if ($v === null) return null;

        // si c'est déjà numérique (cas PhpSpreadsheet)
        if (is_int($v) || is_float($v)) return (float)$v;

        $s = trim((string)$v);
        if ($s === '') return null;

        // enlever espaces, séparateurs
        $s = str_replace(["\u{00A0}", ' '], '', $s);
        $s = str_replace([','], '.', $s);

        // garder chiffres + point
        $s = preg_replace('/[^0-9.]/', '', $s);
        if ($s === '' || $s === '.') return null;

        return (float)$s;
    }

    private function parseExcelDate($v): ?string
    {
        // Dans ton fichier, c'est souvent un DateTime PHP
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }

        $s = trim((string)$v);
        if ($s === '') return null;

        // Support "C13/1" => 13/1
        $s = preg_replace('/^[A-Za-z]+/u', '', $s);
        $s = trim($s);

        // Support "13/1" => janvier 2026
        if (preg_match('#^(\d{1,2})/(\d{1,2})$#', $s, $m)) {
            $d = (int)$m[1];
            $mo = (int)$m[2];
            $y = 2026; // import janvier 2026
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }

        // Support "2026-01-03"
        if (preg_match('#^\d{4}-\d{2}-\d{2}$#', $s)) return $s;

        // Dernier recours
        $t = strtotime($s);
        if ($t === false) return null;
        return date('Y-m-d', $t);
    }

    private function parseRoute(string $route): array
    {
        $route = trim($route);
        if ($route === '') return [null, null];

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

        // Dédup simple: nom+prenom
        return Client::firstOrCreate(
            ['nom' => $nom, 'prenom' => $prenom],
            ['pays' => 'Sénégal']
        );
    }

    private function splitName(string $full): array
    {
        $full = trim(preg_replace('/\s+/u', ' ', $full));

        if ($full === '') return ['-', null];

        // Heuristique entreprise: tout en majuscules et > 2 mots => on garde tout en nom
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
