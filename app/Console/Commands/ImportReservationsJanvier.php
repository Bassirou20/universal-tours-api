<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Reservation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Carbon\Carbon;

class ImportReservationsJanvier extends Command
{
    protected $signature = 'reservations:import-janvier
        {path : Chemin du fichier xlsx (ex: storage/app/imports/ETATUTJANVIER2026.xlsx)}
        {--dry-run : Ne rien écrire en base, afficher seulement}';

    protected $description = 'Import des réservations (billets avion) depuis un fichier Excel UT Janvier 2026 (avec import_hash).';

    public function handle(): int
    {
        $path = $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');

        $fullPath = base_path($path);
        if (!file_exists($fullPath)) {
            $this->error("Fichier introuvable: {$path}");
            $this->line("Astuce Windows: vérifie l'espace/le nom exact du fichier");
            return self::FAILURE;
        }

        $spreadsheet = IOFactory::load($fullPath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true); // A,B,C...

        // détecter ligne header: A == "Date"
        $headerRowIndex = null;
        foreach ($rows as $i => $row) {
            $val = trim((string)($row['A'] ?? ''));
            if (mb_strtolower($val) === 'date') {
                $headerRowIndex = $i;
                break;
            }
        }
        if (!$headerRowIndex) $headerRowIndex = 11;

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($rows as $i => $row) {
            if ($i <= $headerRowIndex) continue;

            // Colonnes attendues (ajuste si ton fichier change)
            // A = date
            // C = payeur
            // E = passager
            // F = itinéraire (ex DSS-DXB-CMN-DSS / ASSURANCE)
            // G = PNR
            // H = vente HT (sous-total)
            // J = frais
            // K = total TTC
            $dateRaw     = $row['A'] ?? null;
            $payeurRaw   = trim((string)($row['C'] ?? ''));
            $passagerRaw = trim((string)($row['E'] ?? ''));
            $itineraire  = trim((string)($row['F'] ?? ''));
            $pnr         = trim((string)($row['G'] ?? ''));

            // lignes vraiment vides
            if ($payeurRaw === '' && $passagerRaw === '' && $itineraire === '' && $pnr === '') {
                continue;
            }

            $dateStr = $this->parseExcelDateToYmd($dateRaw);
            if (!$dateStr) {
                $skipped++;
                $this->line("[SKIP] Date illisible ligne {$i}: " . (string)($row['A'] ?? ''));
                continue;
            }

            // Montants
            $sousTotal = $this->parseMoney($row['H'] ?? null);
            $frais     = $this->parseMoney($row['J'] ?? null);
            $totalK    = $this->parseMoney($row['K'] ?? null);

            // total = K sinon H sinon 0
            $montantTotal = $totalK !== null ? $totalK : ($sousTotal ?? 0);
            if ($sousTotal === null) $sousTotal = $montantTotal;

            // Normaliser payeur/passager
            if ($payeurRaw === '') $payeurRaw = $passagerRaw;
            if ($passagerRaw === '') $passagerRaw = $payeurRaw;

            // Route
            [$vd, $va] = $this->parseRoute($itineraire);
            $vd = $vd ?: 'DSS';
            $va = $va ?: 'DSS';

            // Hash stable (sert de clé de dédup)
            $importSource = 'excel_janvier_2026';
            $importHash = $this->makeImportHash([
                'date' => $dateStr,
                'payeur' => $payeurRaw,
                'passager' => $passagerRaw,
                'vd' => $vd,
                'va' => $va,
                'pnr' => $pnr ?: '-',
                'total' => $montantTotal,
                'source' => $importSource,
            ]);

            // Référence lisible + unique
            $reference = $this->makeReservationReference($dateStr, $vd, $va, $pnr, $importHash);

            if ($dryRun) {
                $this->line("[DRY] {$dateStr} | {$payeurRaw} -> {$passagerRaw} | {$vd}->{$va} | PNR:" . ($pnr ?: '-') . " | total:{$montantTotal} | hash:{$importHash}");
                continue;
            }

            DB::transaction(function () use (
                &$created, &$updated,
                $dateStr, $payeurRaw, $passagerRaw, $vd, $va, $pnr,
                $sousTotal, $frais, $montantTotal,
                $importHash, $importSource, $reference
            ) {
                // 1) Client
                $client = $this->firstOrCreateClientByFullName($payeurRaw);

                // 2) Upsert par import_hash (PAS par reference)
                $reservation = Reservation::where('import_hash', $importHash)->first();
                $isNew = false;

                if (!$reservation) {
                    $reservation = new Reservation();
                    $isNew = true;
                }

                $dt = Carbon::createFromFormat('Y-m-d', $dateStr)->startOfDay()->toDateTimeString();

                // On force les champs, même si $fillable ne les contient pas
                $reservation->forceFill([
                    'client_id' => $client->id,
                    'type' => Reservation::TYPE_BILLET_AVION,
                    'reference' => $this->ensureUniqueReference($reference, $reservation->id ?? null),
                    'statut' => Reservation::STATUT_CONFIRME,
                    'nombre_personnes' => 1,
                    'montant_sous_total' => $sousTotal,
                    'montant_taxes' => $frais ?? 0,
                    'montant_total' => $montantTotal,
                    'notes' => 'Import Janvier (Excel)',
                    'import_hash' => $importHash,
                    'import_source' => $importSource,

                    // ✅ date initiale (très important)
                    'created_at' => $dt,
                    'updated_at' => $dt,
                ])->save();

                if ($isNew) $created++;
                else $updated++;

                // 3) Passager + passenger_id (1 réservation = 1 passenger)
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
                    $reservation->forceFill(['passenger_id' => $passenger->id])->save();
                }

                // 4) Flight details (table reservation_flight_details)
                $reservation->flightDetails()->updateOrCreate(
                    ['reservation_id' => $reservation->id],
                    [
                        'ville_depart' => $vd,
                        'ville_arrivee' => $va,
                        'date_depart' => $dateStr,
                        'date_arrivee' => null,
                        'compagnie' => null,
                        'pnr' => $pnr ?: null,
                        'classe' => null,
                    ]
                );
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
        if (is_int($v) || is_float($v)) return (float) $v;

        $s = trim((string) $v);
        if ($s === '') return null;

        $s = str_replace(["\u{00A0}", ' '], '', $s);
        $s = str_replace([','], '.', $s);
        $s = preg_replace('/[^0-9.]/', '', $s);

        if ($s === '' || $s === '.') return null;
        return (float) $s;
    }

    private function parseExcelDateToYmd($v): ?string
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }

        // Excel serial number
        if (is_numeric($v)) {
            try {
                return ExcelDate::excelToDateTimeObject($v)->format('Y-m-d');
            } catch (\Throwable $e) {
                // continue
            }
        }

        $s = trim((string) $v);
        if ($s === '') return null;

        // Support "C13/1" -> "13/1"
        $s = preg_replace('/^[A-Za-z]+/u', '', $s);
        $s = trim($s);

        // Support "3/1" ou "03/01"
        if (preg_match('#^(\d{1,2})/(\d{1,2})(?:/(\d{2,4}))?$#', $s, $m)) {
            $d = (int) $m[1];
            $mo = (int) $m[2];
            $y = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 2026;
            if ($y < 100) $y += 2000;
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }

        if (preg_match('#^\d{4}-\d{2}-\d{2}$#', $s)) return $s;

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

        return Client::firstOrCreate(
            ['nom' => $nom, 'prenom' => $prenom],
            ['pays' => 'Sénégal']
        );
    }

    private function splitName(string $full): array
    {
        $full = trim(preg_replace('/\s+/u', ' ', $full));
        if ($full === '') return ['-', null];

        $isAllUpper = (mb_strtoupper($full, 'UTF-8') === $full);
        $words = explode(' ', $full);

        if ($isAllUpper && count($words) > 2) {
            return [$full, null];
        }

        if (count($words) === 1) return [$words[0], null];

        $nom = array_pop($words);
        $prenom = trim(implode(' ', $words));
        return [$nom, $prenom !== '' ? $prenom : null];
    }

    private function makeImportHash(array $data): string
    {
        $payload = implode('|', [
            $data['source'] ?? '',
            $data['date'] ?? '',
            $data['payeur'] ?? '',
            $data['passager'] ?? '',
            $data['vd'] ?? '',
            $data['va'] ?? '',
            $data['pnr'] ?? '',
            (string)($data['total'] ?? ''),
        ]);

        return substr(sha1($payload), 0, 10);
    }

    private function makeReservationReference(string $dateYmd, string $vd, string $va, string $pnr, string $hash): string
    {
        $yyyymmdd = str_replace('-', '', $dateYmd);

        if ($pnr !== '') {
            // Ex: UT-AV-20260116-Y5PZXZ
            return "UT-AV-{$yyyymmdd}-" . strtoupper($pnr);
        }

        // Ex: UT-IMP-20260107-ASSURANCE-ASSURANCE-e8979dad
        $vd = strtoupper(preg_replace('/\s+/u', '', $vd));
        $va = strtoupper(preg_replace('/\s+/u', '', $va));
        return "UT-IMP-{$yyyymmdd}-{$vd}-{$va}-" . substr($hash, 0, 8);
    }

    private function ensureUniqueReference(string $baseRef, ?int $ignoreId = null): string
    {
        $ref = $baseRef;
        $n = 2;

        while (Reservation::where('reference', $ref)
            ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $ref = $baseRef . '-' . $n;
            $n++;
        }

        return $ref;
    }
}
