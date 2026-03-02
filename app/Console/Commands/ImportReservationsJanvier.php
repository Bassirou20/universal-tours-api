<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Participant;
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

    protected $description = 'Import UT (billet_avion + assurance) depuis Excel avec import_hash + dates initiales.';

    public function handle(): int
    {
        $path = $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');

        $fullPath = base_path($path);
        if (!file_exists($fullPath)) {
            $this->error("Fichier introuvable: {$path}");
            $this->line("Astuce: vérifie le nom exact (espaces/accents) et le chemin.");
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

        // source (tu peux changer pour février, etc.)
        $importSource = 'excel_import_ut';

        foreach ($rows as $i => $row) {
            if ($i <= $headerRowIndex) continue;

            // Colonnes (selon tes fichiers UT):
            // A = date (ex: 3/1 ou DateTime Excel)
            // C = payeur
            // E = bénéficiaire / passager
            // F = itinéraire (ex DSS-DXB... ou ASSURANCE)
            // G = PNR
            // H = sous-total (HT)
            // J = frais
            // K = total TTC
            $dateRaw     = $row['A'] ?? null;
            $payeurRaw   = trim((string)($row['C'] ?? ''));
            $benefRaw    = trim((string)($row['E'] ?? '')); // passager ou bénéficiaire assurance
            $itineraire  = trim((string)($row['F'] ?? ''));
            $pnr         = trim((string)($row['G'] ?? ''));

            // lignes vraiment vides
            if ($payeurRaw === '' && $benefRaw === '' && $itineraire === '' && $pnr === '') {
                continue;
            }

            $dateStr = $this->parseExcelDateToYmd($dateRaw);
            if (!$dateStr) {
                $skipped++;
                $this->line("[SKIP] Date illisible ligne {$i}: " . (string)($row['A'] ?? ''));
                continue;
            }

            // Montants (corrige 12.500 => 12500)
            $sousTotal = $this->parseMoneySmart($row['H'] ?? null);
            $frais     = $this->parseMoneySmart($row['J'] ?? null);
            $totalK    = $this->parseMoneySmart($row['K'] ?? null);

            $montantTotal = $totalK !== null ? $totalK : ($sousTotal ?? 0);
            if ($sousTotal === null) $sousTotal = $montantTotal;
            if ($frais === null) $frais = 0.0;

            // Normaliser payeur/bénéficiaire
            if ($payeurRaw === '') $payeurRaw = $benefRaw;
            if ($benefRaw === '') $benefRaw = $payeurRaw;

            // Type: si itinéraire contient ASSURANCE => type assurance
            $isAssurance = $this->isAssuranceRoute($itineraire);

            // route / vd-va
            [$vd, $va] = $this->parseRoute($itineraire);
            $vd = $vd ?: ($isAssurance ? 'ASSURANCE' : 'DSS');
            $va = $va ?: ($isAssurance ? 'ASSURANCE' : 'DSS');

            // Hash stable (clé de dédup)
            $importHash = $this->makeImportHash([
                'source' => $importSource,
                'date' => $dateStr,
                'payeur' => $payeurRaw,
                'benef' => $benefRaw,
                'vd' => $vd,
                'va' => $va,
                'pnr' => $pnr ?: '-',
                'total' => $montantTotal,
                'type' => $isAssurance ? 'assurance' : 'billet_avion',
            ]);

            // Référence lisible + unique
            $referenceBase = $this->makeReservationReference($dateStr, $vd, $va, $pnr, $importHash, $isAssurance);

            if ($dryRun) {
                $this->line(
                    "[DRY] {$dateStr} | {$payeurRaw} -> {$benefRaw} | {$vd}->{$va} | PNR:" . ($pnr ?: '-') .
                    " | total:{$montantTotal} | hash:{$importHash} | type:" . ($isAssurance ? 'assurance' : 'billet_avion')
                );
                continue;
            }

            DB::transaction(function () use (
                &$created, &$updated,
                $dateStr, $payeurRaw, $benefRaw, $vd, $va, $pnr,
                $sousTotal, $frais, $montantTotal,
                $importHash, $importSource, $referenceBase, $isAssurance
            ) {
                // 1) Client payeur
                $client = $this->firstOrCreateClientByFullName($payeurRaw);

                // 2) Upsert reservation par import_hash
                $reservation = Reservation::where('import_hash', $importHash)->first();
                $isNew = false;

                if (!$reservation) {
                    $reservation = new Reservation();
                    $isNew = true;
                }

                $dt = Carbon::createFromFormat('Y-m-d', $dateStr)->startOfDay()->toDateTimeString();

                $reference = $this->ensureUniqueReference($referenceBase, $reservation->id ?? null);

                // ⚠️ IMPORTANT: garantir que created_at/updated_at restent celles du fichier
                $reservation->timestamps = false;

                $reservation->forceFill([
                    'client_id' => $client->id,
                    'type' => $isAssurance ? Reservation::TYPE_ASSURANCE : Reservation::TYPE_BILLET_AVION,
                    'reference' => $reference,
                    'statut' => Reservation::STATUT_CONFIRME,
                    'nombre_personnes' => 1,

                    'montant_sous_total' => $sousTotal,
                    'montant_taxes' => $frais,
                    'montant_total' => $montantTotal,

                    'notes' => 'Import Excel UT',
                    'import_hash' => $importHash,
                    'import_source' => $importSource,

                    'created_at' => $dt,
                    'updated_at' => $dt,
                ])->save();

                $reservation->timestamps = true;

                if ($isNew) $created++;
                else $updated++;

                // 3) Bénéficiaire / passager: on le crée TOUJOURS en participant
                // - billet avion => role=passenger + passenger_id
                // - assurance => role=beneficiary (et passenger_id inutilisé)
                $role = $isAssurance ? 'beneficiary' : 'passenger';

                $benef = $reservation->participants()
                    ->where('role', $role)
                    ->first();

                if (!$benef) {
                    [$nom, $prenom] = $this->splitName($benefRaw);

                    $benef = $reservation->participants()->create([
                        'role' => $role,
                        'nom' => $nom,
                        'prenom' => $prenom,
                    ]);
                }

                // billet avion: on set passenger_id
                if (!$isAssurance && !$reservation->passenger_id) {
                    $reservation->forceFill(['passenger_id' => $benef->id])->save();
                }

                // 4) Flight details seulement si billet avion
                if (!$isAssurance) {
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
                }

                // Si tu as une table assurance_details dédiée, dis-moi son schéma exact
                // (colonnes), et je te l’ajoute proprement ici.
            });
        }

        if ($dryRun) {
            $this->info("Terminé. (dry-run) Rien écrit en base.");
            return self::SUCCESS;
        }

        $this->info("Terminé. Créées: {$created} | Mises à jour: {$updated} | Ignorées: {$skipped}");
        return self::SUCCESS;
    }

    private function isAssuranceRoute(string $route): bool
    {
        $r = mb_strtolower(trim($route));
        if ($r === '') return false;
        return str_contains($r, 'assurance');
    }

    /**
     * Montants:
     * - "12.500" => 12500
     * - "1.006"  => 1006
     * - "413,6"  => 413.6 (si virgule)
     * - "1,234.56" ou "1.234,56" => gère au mieux
     */
    private function parseMoneySmart($v): ?float
    {
        if ($v === null) return null;
        if (is_int($v) || is_float($v)) return (float) $v;

        $s = trim((string)$v);
        if ($s === '') return null;

        $s = str_replace(["\u{00A0}", ' '], '', $s);

        // Si contient une virgule => on suppose virgule décimale, points milliers éventuels
        if (str_contains($s, ',')) {
            $s = str_replace('.', '', $s);     // retire milliers
            $s = str_replace(',', '.', $s);    // decimal
            $s = preg_replace('/[^0-9.]/', '', $s);
            if ($s === '' || $s === '.') return null;
            return (float)$s;
        }

        // Pas de virgule:
        // si points en groupes de 3 => milliers (12.500 / 1.006 / 4.780.000)
        if (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) {
            $s = str_replace('.', '', $s);
            return (float)$s;
        }

        // Sinon: on garde comme nombre "normal" (ex: 413.6)
        $s = preg_replace('/[^0-9.]/', '', $s);
        if ($s === '' || $s === '.') return null;
        return (float)$s;
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
            } catch (\Throwable $e) {}
        }

        $s = trim((string)$v);
        if ($s === '') return null;

        // Support "C13/1" -> "13/1"
        $s = preg_replace('/^[A-Za-z]+/u', '', $s);
        $s = trim($s);

        // Support "3/1" ou "03/01" ou "03/01/2026"
        if (preg_match('#^(\d{1,2})/(\d{1,2})(?:/(\d{2,4}))?$#', $s, $m)) {
            $d = (int)$m[1];
            $mo = (int)$m[2];
            $y = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : 2026;
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

        // Si assurance: on renvoie "ASSURANCE"
        if ($this->isAssuranceRoute($route)) {
            return ['ASSURANCE', 'ASSURANCE'];
        }

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
            (string)($data['source'] ?? ''),
            (string)($data['type'] ?? ''),
            (string)($data['date'] ?? ''),
            (string)($data['payeur'] ?? ''),
            (string)($data['benef'] ?? ''),
            (string)($data['vd'] ?? ''),
            (string)($data['va'] ?? ''),
            (string)($data['pnr'] ?? ''),
            (string)($data['total'] ?? ''),
        ]);

        return substr(sha1($payload), 0, 10);
    }

    private function makeReservationReference(string $dateYmd, string $vd, string $va, string $pnr, string $hash, bool $isAssurance): string
    {
        $yyyymmdd = str_replace('-', '', $dateYmd);

        if (!$isAssurance && $pnr !== '') {
            return "UT-AV-{$yyyymmdd}-" . strtoupper($pnr);
        }

        $vd = strtoupper(preg_replace('/\s+/u', '', $vd));
        $va = strtoupper(preg_replace('/\s+/u', '', $va));

        $prefix = $isAssurance ? 'UT-AS' : 'UT-IMP';
        return "{$prefix}-{$yyyymmdd}-{$vd}-{$va}-" . substr($hash, 0, 8);
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