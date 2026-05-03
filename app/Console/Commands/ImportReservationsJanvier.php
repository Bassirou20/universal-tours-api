<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Facture;
use App\Models\Participant;
use App\Models\Reservation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Carbon\Carbon;

class ImportReservationsJanvier extends Command
{
    protected $signature = 'reservations:import
        {path : Chemin du fichier xlsx (ex: storage/app/imports/ETATUTMARS2026.xlsx)}
        {--dry-run : Ne rien écrire en base, afficher seulement}
        {--source=excel_import_ut : Identifiant source pour le hash de déduplication}';

    protected $description = 'Import UT (billet_avion + assurance) depuis Excel avec déduplication par import_hash.';

    public function handle(): int
    {
        $path     = $this->argument('path');
        $dryRun   = (bool) $this->option('dry-run');
        $importSource = (string) $this->option('source');

        $fullPath = base_path($path);
        if (!file_exists($fullPath)) {
            $this->error("Fichier introuvable: {$path}");
            $this->line("Astuce: vérifie le nom exact (espaces/accents) et le chemin.");
            return self::FAILURE;
        }

        // ✅ CORRECTION : vérifier que les colonnes import_hash existent en base
        if (!\Schema::hasColumn('reservations', 'import_hash')) {
            $this->error("La colonne import_hash n'existe pas en base.");
            $this->line("Lance: php artisan migrate pour créer la migration.");
            return self::FAILURE;
        }

        $spreadsheet = IOFactory::load($fullPath);
        $sheet       = $spreadsheet->getActiveSheet();
        $rows        = $sheet->toArray(null, true, true, true);

        // Détecter ligne header (colonne A == "Date")
        $headerRowIndex = null;
        foreach ($rows as $i => $row) {
            $val = mb_strtolower(trim((string)($row['A'] ?? '')));
            if ($val === 'date') {
                $headerRowIndex = $i;
                break;
            }
        }
        if (!$headerRowIndex) $headerRowIndex = 11;

        // ✅ CORRECTION : mapper les colonnes dynamiquement depuis la ligne header
        // Évite les bugs si une colonne est décalée d'un fichier à l'autre
        $headerRow = $rows[$headerRowIndex] ?? [];
        $colMap    = [];
        foreach ($headerRow as $col => $val) {
            $key = mb_strtolower(trim((string)$val));
            if ($key !== '') {
                $colMap[$key] = $col;
            }
        }

        $this->line("Colonnes détectées : " . implode(', ', array_keys($colMap)));

        // Colonnes avec fallback sur position fixe si header non trouvé
        $colDate    = $colMap['date']    ?? 'A';
        $colPayeur  = $colMap['payeur']  ?? 'C';
        $colBenef   = $colMap['bénéficiaire'] ?? $colMap['beneficiaire'] ?? $colMap['passager'] ?? 'E';
        $colItin    = $colMap['itinéraire'] ?? $colMap['itineraire'] ?? $colMap['trajet'] ?? 'F';
        $colPnr     = $colMap['pnr']     ?? 'G';
        $colHT      = $colMap['ht']      ?? $colMap['sous-total'] ?? $colMap['achat'] ?? 'H';
        $colFrais   = $colMap['frais']   ?? $colMap['commission'] ?? 'J';
        $colTotal   = $colMap['total']   ?? $colMap['ttc'] ?? 'K';

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($rows as $i => $row) {
            if ($i <= $headerRowIndex) continue;

            $dateRaw    = $row[$colDate]   ?? null;
            $payeurRaw  = trim((string)($row[$colPayeur]  ?? ''));
            $benefRaw   = trim((string)($row[$colBenef]   ?? ''));
            $itineraire = trim((string)($row[$colItin]    ?? ''));
            $pnr        = trim((string)($row[$colPnr]     ?? ''));

            // Lignes vraiment vides
            if ($payeurRaw === '' && $benefRaw === '' && $itineraire === '' && $pnr === '') {
                continue;
            }

            $dateStr = $this->parseExcelDateToYmd($dateRaw);
            if (!$dateStr) {
                $skipped++;
                $this->line("[SKIP] Date illisible ligne {$i}: " . (string)($row[$colDate] ?? ''));
                continue;
            }

            $sousTotal    = $this->parseMoneySmart($row[$colHT]    ?? null);
            $frais        = $this->parseMoneySmart($row[$colFrais]  ?? null);
            $totalK       = $this->parseMoneySmart($row[$colTotal]  ?? null);

            $montantTotal = $totalK ?? ($sousTotal ?? 0);
            if ($sousTotal === null) $sousTotal = $montantTotal;
            if ($frais === null) $frais = 0.0;

            if ($payeurRaw === '') $payeurRaw = $benefRaw;
            if ($benefRaw === '')  $benefRaw  = $payeurRaw;

            $isAssurance = $this->isAssuranceRoute($itineraire);

            [$vd, $va] = $this->parseRoute($itineraire);
            $vd = $vd ?: ($isAssurance ? 'ASSURANCE' : 'DSS');
            $va = $va ?: ($isAssurance ? 'ASSURANCE' : 'DSS');

            $importHash = $this->makeImportHash([
                'source' => $importSource,
                'date'   => $dateStr,
                'payeur' => $payeurRaw,
                'benef'  => $benefRaw,
                'vd'     => $vd,
                'va'     => $va,
                'pnr'    => $pnr ?: '-',
                'total'  => $montantTotal,
                'type'   => $isAssurance ? 'assurance' : 'billet_avion',
            ]);

            $referenceBase = $this->makeReservationReference(
                $dateStr, $vd, $va, $pnr, $importHash, $isAssurance
            );

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
                $importHash, $importSource, $referenceBase, $isAssurance, $itineraire
            ) {
                // 1) Client payeur avec déduplication robuste
                $client = $this->firstOrCreateClientByFullName($payeurRaw);

                // 2) Upsert reservation par import_hash
                $reservation = Reservation::where('import_hash', $importHash)->first();
                $isNew = !$reservation;

                if ($isNew) {
                    $reservation = new Reservation();
                }

                $dt = Carbon::createFromFormat('Y-m-d', $dateStr)
                    ->startOfDay()
                    ->toDateTimeString();

                $reference = $this->ensureUniqueReference($referenceBase, $reservation->id ?? null);

                // Préserver les dates d'origine du fichier Excel
                $reservation->timestamps = false;

                $reservation->forceFill([
                    'client_id'      => $client->id,
                    'type'           => $isAssurance
                        ? Reservation::TYPE_ASSURANCE
                        : Reservation::TYPE_BILLET_AVION,
                    'reference'      => $reference,
                    'statut'         => Reservation::STATUT_CONFIRME,
                    'nombre_personnes' => 1,

                    'montant_sous_total' => $sousTotal,
                    'montant_taxes'      => $frais,
                    'montant_total'      => $montantTotal,

                    'notes'          => 'Import Excel UT',
                    'import_hash'    => $importHash,
                    'import_source'  => $importSource,

                    'created_at'     => $dt,
                    'updated_at'     => $dt,
                ])->save();

                $reservation->timestamps = true;

                if ($isNew) $created++;
                else        $updated++;

                // 3) Bénéficiaire / passager
                $role = $isAssurance ? 'beneficiary' : 'passenger';

                $benef = $reservation->participants()
                    ->where('role', $role)
                    ->first();

                if (!$benef) {
                    [$nom, $prenom] = $this->splitName($benefRaw);
                    $benef = $reservation->participants()->create([
                        'role'   => $role,
                        'nom'    => $nom,
                        'prenom' => $prenom,
                    ]);
                }

                // billet avion: lier passenger_id
                if (!$isAssurance && !$reservation->passenger_id) {
                    $reservation->forceFill(['passenger_id' => $benef->id])->save();
                }

                // 4) Flight details (billet avion seulement)
                if (!$isAssurance) {
                    $reservation->flightDetails()->updateOrCreate(
                        ['reservation_id' => $reservation->id],
                        [
                            'ville_depart'  => $vd,
                            'ville_arrivee' => $va,
                            'date_depart'   => $dateStr,
                            'date_arrivee'  => null,
                            'compagnie'     => null,
                            'pnr'           => $pnr ?: null,
                            'classe'        => null,
                        ]
                    );
                }

                // ✅ CORRECTION : assurance_details créés (manquaient complètement avant)
                if ($isAssurance) {
                    $reservation->assuranceDetails()->updateOrCreate(
                        ['reservation_id' => $reservation->id],
                        [
                            'libelle'     => $itineraire ?: 'Assurance Import',
                            'date_debut'  => $dateStr,
                            'date_fin'    => null,
                        ]
                    );
                }

                // ✅ CORRECTION : créer la facture à l'import avec la date Excel
                // Avant : aucune facture créée → doublons quand ensureFactureEmise() appelé plus tard
                Facture::firstOrCreate(
                    ['reservation_id' => $reservation->id],
                    [
                        'numero'             => Facture::generateNumero(),
                        'date_facture'       => $dateStr, // ← date Excel, pas today()
                        'montant_sous_total' => $sousTotal,
                        'montant_taxes'      => $frais,
                        'montant_total'      => $montantTotal,
                        'statut'             => 'emis',
                        'pdf_path'           => null,
                    ]
                );
            });
        }

        if ($dryRun) {
            $this->info("Terminé (dry-run). Rien écrit en base.");
            return self::SUCCESS;
        }

        $this->info("Terminé. Créées: {$created} | Mises à jour: {$updated} | Ignorées: {$skipped}");
        return self::SUCCESS;
    }

    // ✅ CORRECTION : déduplication client en 3 niveaux
    private function firstOrCreateClientByFullName(string $fullName): Client
    {
        $fullName = trim($fullName);
        if ($fullName === '') $fullName = 'Client Import';

        [$nom, $prenom] = $this->splitName($fullName);

        $nomNorm    = $this->normalizeName($nom);
        $prenomNorm = $this->normalizeName($prenom ?? '');

        // Niveau 1 : nom + prénom exacts normalisés (insensible casse)
        $query = Client::whereRaw('LOWER(TRIM(nom)) = ?', [mb_strtolower($nomNorm)]);
        if ($prenomNorm !== '') {
            $query->whereRaw('LOWER(TRIM(prenom)) = ?', [mb_strtolower($prenomNorm)]);
        }
        $matches = $query->get();

        if ($matches->count() === 1) {
            return $matches->first();
        }

        // Niveau 2 : homonymes → log + utiliser le plus récent
        if ($matches->count() > 1) {
            $ids = $matches->pluck('id')->join(', ');
            $this->warn(
                "[AMBIGU] \"{$fullName}\" → {$matches->count()} clients trouvés (IDs: {$ids}). " .
                "Client le plus récent utilisé. Vérifier manuellement."
            );
            return $matches->sortByDesc('created_at')->first();
        }

        // Niveau 3 : prénom commence par (cas prénoms tronqués dans Excel)
        if ($prenomNorm !== '') {
            $client = Client::whereRaw('LOWER(TRIM(nom)) = ?', [mb_strtolower($nomNorm)])
                ->whereRaw('LOWER(prenom) LIKE ?', [mb_strtolower($prenomNorm) . '%'])
                ->first();
            if ($client) return $client;
        }

        // Niveau 4 : aucun match → créer
        return Client::create([
            'nom'    => $nomNorm,
            'prenom' => $prenomNorm !== '' ? $prenomNorm : null,
            'pays'   => 'Sénégal',
        ]);
    }

    // Normalise en Title Case, retire espaces multiples
    private function normalizeName(string $s): string
    {
        $s = trim($s);
        if ($s === '') return '';
        $s = preg_replace('/\s+/u', ' ', $s);
        return mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
    }

    private function isAssuranceRoute(string $route): bool
    {
        return str_contains(mb_strtolower(trim($route)), 'assurance');
    }

    private function parseMoneySmart($v): ?float
    {
        if ($v === null) return null;
        if (is_int($v) || is_float($v)) return (float) $v;

        $s = trim((string)$v);
        if ($s === '') return null;

        $s = str_replace(["\u{00A0}", ' '], '', $s);

        if (str_contains($s, ',')) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
            $s = preg_replace('/[^0-9.]/', '', $s);
            if ($s === '' || $s === '.') return null;
            return (float)$s;
        }

        if (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) {
            return (float) str_replace('.', '', $s);
        }

        $s = preg_replace('/[^0-9.]/', '', $s);
        if ($s === '' || $s === '.') return null;
        return (float)$s;
    }

    private function parseExcelDateToYmd($v): ?string
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }

        if (is_numeric($v)) {
            try {
                return ExcelDate::excelToDateTimeObject($v)->format('Y-m-d');
            } catch (\Throwable $e) {}
        }

        $s = trim((string)$v);
        if ($s === '') return null;

        $s = preg_replace('/^[A-Za-z]+/u', '', $s);
        $s = trim($s);

        if (preg_match('#^(\d{1,2})/(\d{1,2})(?:/(\d{2,4}))?$#', $s, $m)) {
            $d  = (int)$m[1];
            $mo = (int)$m[2];
            $y  = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : 2026;
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

        if ($this->isAssuranceRoute($route)) {
            return ['ASSURANCE', 'ASSURANCE'];
        }

        $parts = array_values(
            array_filter(
                array_map('trim', preg_split('/[-–—]/u', $route) ?: [])
            )
        );

        if (count($parts) === 0) return [null, null];
        if (count($parts) === 1) return [$parts[0], $parts[0]];
        return [$parts[0], $parts[count($parts) - 1]];
    }

    private function splitName(string $full): array
    {
        $full = trim(preg_replace('/\s+/u', ' ', $full));
        if ($full === '') return ['-', null];

        $isAllUpper = (mb_strtoupper($full, 'UTF-8') === $full);
        $words      = explode(' ', $full);

        // Noms africains en majuscules avec 3+ mots → garder ensemble
        if ($isAllUpper && count($words) > 2) {
            return [$full, null];
        }

        if (count($words) === 1) return [$words[0], null];

        $nom    = array_pop($words);
        $prenom = trim(implode(' ', $words));
        return [$nom, $prenom !== '' ? $prenom : null];
    }

    private function makeImportHash(array $data): string
    {
        $payload = implode('|', [
            (string)($data['source'] ?? ''),
            (string)($data['type']   ?? ''),
            (string)($data['date']   ?? ''),
            (string)($data['payeur'] ?? ''),
            (string)($data['benef']  ?? ''),
            (string)($data['vd']     ?? ''),
            (string)($data['va']     ?? ''),
            (string)($data['pnr']    ?? ''),
            (string)($data['total']  ?? ''),
        ]);

        return substr(sha1($payload), 0, 10);
    }

    private function makeReservationReference(
        string $dateYmd,
        string $vd,
        string $va,
        string $pnr,
        string $hash,
        bool $isAssurance
    ): string {
        $yyyymmdd = str_replace('-', '', $dateYmd);

        if (!$isAssurance && $pnr !== '') {
            return "UT-AV-{$yyyymmdd}-" . strtoupper($pnr);
        }

        $vd     = strtoupper(preg_replace('/\s+/u', '', $vd));
        $va     = strtoupper(preg_replace('/\s+/u', '', $va));
        $prefix = $isAssurance ? 'UT-AS' : 'UT-IMP';

        return "{$prefix}-{$yyyymmdd}-{$vd}-{$va}-" . substr($hash, 0, 8);
    }

    private function ensureUniqueReference(string $baseRef, ?int $ignoreId = null): string
    {
        $ref = $baseRef;
        $n   = 2;

        while (
            Reservation::where('reference', $ref)
                ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $ref = $baseRef . '-' . $n;
            $n++;
        }

        return $ref;
    }
}