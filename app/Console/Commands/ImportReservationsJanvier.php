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
        {--dry-run : Ne rien écrire en base, afficher seulement}
        {--source= : Identifiant de source (ex: excel_janvier_2026). Par défaut: nom du fichier}';

    protected $description = 'Import réservations depuis Excel UT (billet_avion + assurance) avec import_hash, dates initiales, et références uniques.';

    public function handle(): int
    {
        $path   = (string) $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');

        $fullPath = base_path($path);
        if (!file_exists($fullPath)) {
            $this->error("Fichier introuvable: {$path}");
            $this->line("Astuce Windows: vérifie l'espace/le nom exact du fichier");
            return self::FAILURE;
        }

        $importSource = (string) ($this->option('source') ?: $this->defaultSourceFromPath($path));

        $spreadsheet = IOFactory::load($fullPath);
        $sheet = $spreadsheet->getActiveSheet();

        // A,B,C...
        $rows = $sheet->toArray(null, true, true, true);

        // Trouver la ligne header "Date" en colonne A
        $headerRowIndex = $this->detectHeaderRowIndex($rows);

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($rows as $i => $row) {
            if ($i <= $headerRowIndex) continue;

            // Colonnes attendues (selon ton format UT)
            // A = date
            // C = payeur
            // E = passager
            // F = itinéraire (ex DSS-DXB-CMN-DSS / ASSURANCE / HOTEL)
            // G = PNR
            // H = HT (sous-total)
            // J = frais
            // K = TTC (total)
            $dateRaw     = $row['A'] ?? null;
            $payeurRaw   = trim((string)($row['C'] ?? ''));
            $passagerRaw = trim((string)($row['E'] ?? ''));
            $itineraire  = trim((string)($row['F'] ?? ''));
            $pnr         = trim((string)($row['G'] ?? ''));

            // ligne vide
            if ($payeurRaw === '' && $passagerRaw === '' && $itineraire === '' && $pnr === '' && trim((string)($row['H'] ?? '')) === '') {
                continue;
            }

            $dateYmd = $this->parseExcelDateToYmd($dateRaw);
            if (!$dateYmd) {
                $skipped++;
                $this->line("[SKIP] Date illisible ligne {$i}: " . (string)($row['A'] ?? ''));
                continue;
            }

            // Montants
            $sousTotal = $this->parseMoney($row['H'] ?? null);
            $frais     = $this->parseMoney($row['J'] ?? null);
            $totalK    = $this->parseMoney($row['K'] ?? null);

            $montantTotal = $totalK !== null ? $totalK : ($sousTotal ?? 0.0);
            if ($sousTotal === null) $sousTotal = $montantTotal;

            // Normaliser payeur/passager
            if ($payeurRaw === '') $payeurRaw = $passagerRaw;
            if ($passagerRaw === '') $passagerRaw = $payeurRaw;

            // Type selon itinéraire
            $type = $this->detectTypeFromItineraire($itineraire);

            // Route
            [$vd, $va] = $this->parseRoute($itineraire);
            if ($type === Reservation::TYPE_ASSURANCE) {
                $vd = 'ASSURANCE';
                $va = 'ASSURANCE';
                $pnr = ''; // pas de PNR
            } else {
                $vd = $vd ?: 'DSS';
                $va = $va ?: 'DSS';
            }

            // Hash stable (clé upsert) : on inclut les champs "load-bearing"
            // IMPORTANT: on inclut aussi sousTotal + frais + total pour éviter collisions.
            $importHash = $this->makeImportHash([
                'source'    => $importSource,
                'date'      => $dateYmd,
                'type'      => $type,
                'payeur'    => $payeurRaw,
                'passager'  => $passagerRaw,
                'itin'      => $itineraire,
                'vd'        => $vd,
                'va'        => $va,
                'pnr'       => $pnr ?: '-',
                'ht'        => $sousTotal,
                'frais'     => $frais ?? 0,
                'total'     => $montantTotal,
            ]);

            $referenceBase = $this->makeReservationReference($dateYmd, $type, $vd, $va, $pnr, $importHash);

            if ($dryRun) {
                $this->line(
                    "[DRY] {$dateYmd} | {$payeurRaw} -> {$passagerRaw} | {$vd}->{$va} | PNR:" . ($pnr ?: '-') .
                    " | total:{$montantTotal} | type:{$type} | hash:{$importHash}"
                );
                continue;
            }

            DB::transaction(function () use (
                &$created, &$updated,
                $dateYmd, $payeurRaw, $passagerRaw,
                $itineraire, $vd, $va, $pnr, $type,
                $sousTotal, $frais, $montantTotal,
                $importHash, $importSource, $referenceBase
            ) {
                // 1) Client
                $client = $this->firstOrCreateClientByFullName($payeurRaw);

                // 2) Upsert par import_hash (inclure soft-deleted)
                $reservation = Reservation::withTrashed()->where('import_hash', $importHash)->first();
                $isNew = false;

                if (!$reservation) {
                    $reservation = new Reservation();
                    $isNew = true;
                }

                // Date initiale = date du fichier (00:00:00)
                $dt = Carbon::createFromFormat('Y-m-d', $dateYmd)->startOfDay()->toDateTimeString();

                // Référence unique (prendre en compte soft-deleted)
                $reference = $this->ensureUniqueReference($referenceBase, $reservation->id ?? null);

                // Empêcher Laravel d’écraser created_at/updated_at
                $reservation->timestamps = false;

                $reservation->forceFill([
                    'client_id'         => $client->id,
                    'type'              => $type,
                    'reference'         => $reference,
                    'statut'            => Reservation::STATUT_CONFIRME,
                    'nombre_personnes'  => 1,
                    'montant_sous_total'=> $sousTotal,
                    'montant_taxes'     => $frais ?? 0,
                    'montant_total'     => $montantTotal,
                    'notes'             => 'Import Janvier (Excel)',

                    'import_hash'       => $importHash,
                    'import_source'     => $importSource,

                    // ✅ dates initiales
                    'created_at'        => $dt,
                    'updated_at'        => $dt,
                ])->save();

                // remettre timestamps pour la suite (participants/relations)
                $reservation->timestamps = true;

                if ($isNew) $created++;
                else $updated++;

                // 3) Si billet avion => passenger + passenger_id + flightDetails
                if ($type === Reservation::TYPE_BILLET_AVION) {
                    // passenger (role = passenger)
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
                        $reservation->forceFill(['passenger_id' => $passenger->id])->save();
                    }

                    // flightDetails (champs nullable selon ta dernière migration)
                    $reservation->flightDetails()->updateOrCreate(
                        ['reservation_id' => $reservation->id],
                        [
                            'ville_depart'  => $vd,
                            'ville_arrivee' => $va,
                            'date_depart'   => $dateYmd,
                            'date_arrivee'  => null,
                            'compagnie'     => null,
                            'pnr'           => $pnr !== '' ? $pnr : null,
                            'classe'        => null,
                        ]
                    );
                }

                // 4) Si assurance => (optionnel) tu pourras créer assuranceDetails ici
                //    En attendant : on ne crée pas flight_details.
                //    Exemple (si tu as relation assuranceDetails()):
                //    if ($type === Reservation::TYPE_ASSURANCE && method_exists($reservation, 'assuranceDetails')) { ... }
            });
        }

        if ($dryRun) {
            $this->info("Terminé. (dry-run) Rien écrit en base.");
            return self::SUCCESS;
        }

        $this->info("Terminé. Créées: {$created} | Mises à jour: {$updated} | Ignorées: {$skipped}");
        return self::SUCCESS;
    }

    private function defaultSourceFromPath(string $path): string
    {
        $base = basename(str_replace('\\', '/', $path));
        $base = preg_replace('/\.(xlsx|xls|csv)$/i', '', $base);
        return strtolower(preg_replace('/\s+/u', '_', $base));
    }

    private function detectHeaderRowIndex(array $rows): int
    {
        foreach ($rows as $i => $row) {
            $val = trim((string)($row['A'] ?? ''));
            if (mb_strtolower($val) === 'date') return (int)$i;
        }
        // fallback (tes fichiers UT ont souvent 11 lignes de header)
        return 11;
    }

    private function detectTypeFromItineraire(string $itineraire): string
    {
        $s = mb_strtoupper(trim($itineraire), 'UTF-8');

        if ($s === '') return Reservation::TYPE_BILLET_AVION;

        // règle demandée : "Assurance" => type assurance
        if (str_contains($s, 'ASSURANCE')) return Reservation::TYPE_ASSURANCE;

        // optionnel : si tu veux, tu peux déduire HOTEL ici
        // if (str_contains($s, 'HOTEL')) return Reservation::TYPE_HOTEL;

        return Reservation::TYPE_BILLET_AVION;
    }

    private function parseMoney($v): ?float
    {
        if ($v === null) return null;

        if (is_int($v) || is_float($v)) {
            return (float)$v;
        }

        $s = trim((string)$v);
        if ($s === '') return null;

        $s = str_replace(["\u{00A0}", ' '], '', $s);
        $s = str_replace([','], '.', $s);
        $s = preg_replace('/[^0-9.]/', '', $s);

        if ($s === '' || $s === '.') return null;

        return (float)$s;
    }

    private function parseExcelDateToYmd($v): ?string
    {
        // DateTime déjà converti
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

        $s = trim((string)$v);
        if ($s === '') return null;

        // "C13/1" -> "13/1"
        $s = preg_replace('/^[A-Za-z]+/u', '', $s);
        $s = trim($s);

        // "3/1" ou "03/01" ou "03/01/2026"
        if (preg_match('#^(\d{1,2})/(\d{1,2})(?:/(\d{2,4}))?$#', $s, $m)) {
            $d  = (int)$m[1];
            $mo = (int)$m[2];
            $y  = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : 2026;
            if ($y < 100) $y += 2000;

            // sécurité (évite dates invalides)
            if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31) return null;

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

        // entreprise (souvent en majuscules) => tout en nom
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

    private function makeImportHash(array $data): string
    {
        // hash plus long pour éviter collisions
        $payload = implode('|', [
            (string)($data['source'] ?? ''),
            (string)($data['date'] ?? ''),
            (string)($data['type'] ?? ''),
            (string)($data['payeur'] ?? ''),
            (string)($data['passager'] ?? ''),
            (string)($data['itin'] ?? ''),
            (string)($data['vd'] ?? ''),
            (string)($data['va'] ?? ''),
            (string)($data['pnr'] ?? ''),
            (string)($data['ht'] ?? ''),
            (string)($data['frais'] ?? ''),
            (string)($data['total'] ?? ''),
        ]);

        return substr(sha1($payload), 0, 20);
    }

    private function makeReservationReference(string $dateYmd, string $type, string $vd, string $va, string $pnr, string $hash): string
    {
        $yyyymmdd = str_replace('-', '', $dateYmd);

        if ($type === Reservation::TYPE_BILLET_AVION && trim($pnr) !== '') {
            return "UT-AV-{$yyyymmdd}-" . strtoupper(trim($pnr));
        }

        $vd = strtoupper(preg_replace('/\s+/u', '', $vd));
        $va = strtoupper(preg_replace('/\s+/u', '', $va));

        $prefix = $type === Reservation::TYPE_ASSURANCE ? 'UT-AS' : 'UT-IMP';
        return "{$prefix}-{$yyyymmdd}-{$vd}-{$va}-" . substr($hash, 0, 8);
    }

    private function ensureUniqueReference(string $baseRef, ?int $ignoreId = null): string
    {
        $ref = $baseRef;
        $n = 2;

        // IMPORTANT: withTrashed() car soft delete n'empêche pas l'unicité en DB
        while (Reservation::withTrashed()
            ->where('reference', $ref)
            ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $ref = $baseRef . '-' . $n;
            $n++;
        }

        return $ref;
    }
}