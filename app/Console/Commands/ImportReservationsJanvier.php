<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Reservation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class ImportReservationsJanvier extends Command
{
    protected $signature = 'reservations:import-janvier 
        {path : Chemin du fichier xlsx}
        {--dry-run : Ne rien écrire en base}';

    protected $description = 'Import des réservations billets avion depuis Excel';

    public function handle(): int
    {
        $path = $this->argument('path');
        $dryRun = $this->option('dry-run');

        $fullPath = base_path($path);

        if (!file_exists($fullPath)) {
            $this->error("Fichier introuvable: {$path}");
            return self::FAILURE;
        }

        $spreadsheet = IOFactory::load($fullPath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        // détecter ligne header
        $headerRowIndex = 11;
        foreach ($rows as $i => $row) {
            if (mb_strtolower(trim((string)$row['A'])) === 'date') {
                $headerRowIndex = $i;
                break;
            }
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($rows as $i => $row) {

            if ($i <= $headerRowIndex) continue;

            $dateRaw      = $row['A'] ?? null;
            $payeurRaw    = trim((string)($row['C'] ?? ''));
            $passagerRaw  = trim((string)($row['E'] ?? ''));
            $itineraire   = trim((string)($row['F'] ?? ''));
            $ref          = trim((string)($row['G'] ?? ''));

            if ($ref === '' && $payeurRaw === '' && $passagerRaw === '') {
                continue;
            }

            $date = $this->parseExcelDate($dateRaw);

            if (!$date) {
                $skipped++;
                $this->line("[SKIP] Date illisible ligne {$i}: {$dateRaw}");
                continue;
            }

            $sousTotal = $this->parseMoney($row['H'] ?? null);
            $frais     = $this->parseMoney($row['J'] ?? null);
            $totalK    = $this->parseMoney($row['K'] ?? null);

            $montantTotal = $totalK ?? $sousTotal ?? 0;

            if ($sousTotal === null) {
                $sousTotal = $montantTotal;
            }

            if ($payeurRaw === '') $payeurRaw = $passagerRaw;
            if ($passagerRaw === '') $passagerRaw = $payeurRaw;

            [$vd, $va] = $this->parseRoute($itineraire);

            $hash = md5($ref.'|'.$date.'|'.$payeurRaw.'|'.$passagerRaw.'|'.$montantTotal);

            if ($dryRun) {
                $this->line("[DRY] {$date} | {$payeurRaw} -> {$passagerRaw} | {$vd}-{$va} | {$ref} | {$montantTotal}");
                continue;
            }

            DB::transaction(function () use (
                $ref,$date,$payeurRaw,$passagerRaw,$vd,$va,
                $sousTotal,$frais,$montantTotal,$hash,
                &$created,&$updated
            ) {

                $client = $this->firstOrCreateClient($payeurRaw);

                $reservation = Reservation::where('reference',$ref)->first();

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
                        'import_hash' => $hash,
                        'import_source' => 'excel_janvier',
                        'notes' => 'Import Excel Janvier',
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
                        'import_hash' => $hash,
                        'import_source' => 'excel_janvier',
                        'notes' => 'Import Excel Janvier',
                    ]);
                }

                // PASSAGER
                $passenger = $reservation->participants()
                    ->where('role','passenger')
                    ->first();

                if (!$passenger) {
                    [$nom,$prenom] = $this->splitName($passagerRaw);

                    $passenger = $reservation->participants()->create([
                        'role' => 'passenger',
                        'nom' => $nom,
                        'prenom' => $prenom,
                    ]);
                }

                if (!$reservation->passenger_id) {
                    $reservation->update([
                        'passenger_id' => $passenger->id
                    ]);
                }

                // FLIGHT DETAILS
                $reservation->flightDetails()->updateOrCreate(
                    ['reservation_id'=>$reservation->id],
                    [
                        'ville_depart'=>$vd,
                        'ville_arrivee'=>$va,
                        'date_depart'=>$date,
                        'pnr'=>$ref,
                    ]
                );
            });
        }

        if ($dryRun) {
            $this->info("Dry-run terminé.");
            return self::SUCCESS;
        }

        $this->info("Import terminé. Créées={$created} | Mises à jour={$updated} | Ignorées={$skipped}");
        return self::SUCCESS;
    }

    private function parseExcelDate($value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_numeric($value)) {
            try {
                return ExcelDate::excelToDateTimeObject($value)->format('Y-m-d');
            } catch (\Exception $e) {}
        }

        $s = trim((string)$value);

        if ($s === '') return null;

        $s = preg_replace('/^[A-Za-z]+/','',$s);

        if (preg_match('#^(\d{1,2})/(\d{1,2})$#',$s,$m)) {
            return sprintf('2026-%02d-%02d',$m[2],$m[1]);
        }

        if (preg_match('#^\d{4}-\d{2}-\d{2}$#',$s)) {
            return $s;
        }

        $t = strtotime($s);
        return $t ? date('Y-m-d',$t) : null;
    }

    private function parseMoney($v): ?float
    {
        if ($v === null) return null;

        if (is_numeric($v)) return (float)$v;

        $s = str_replace([' ', "\u{00A0}"],'',(string)$v);
        $s = str_replace(',','.',$s);
        $s = preg_replace('/[^0-9.]/','',$s);

        return $s === '' ? null : (float)$s;
    }

    private function parseRoute(string $route): array
    {
        $parts = preg_split('/[-–—]/',$route);

        if (!$parts) return ['DSS','DSS'];

        $parts = array_values(array_filter(array_map('trim',$parts)));

        if (count($parts) === 1) return [$parts[0],$parts[0]];

        return [$parts[0],$parts[count($parts)-1]];
    }

    private function firstOrCreateClient(string $name): Client
    {
        [$nom,$prenom] = $this->splitName($name);

        return Client::firstOrCreate(
            ['nom'=>$nom,'prenom'=>$prenom],
            ['pays'=>'Sénégal']
        );
    }

    private function splitName(string $full): array
    {
        $full = trim(preg_replace('/\s+/',' ',$full));

        if ($full === '') return ['-',''];

        $words = explode(' ',$full);

        if (count($words) === 1) return [$words[0],''];

        $nom = array_pop($words);
        $prenom = implode(' ',$words);

        return [$nom,$prenom];
    }
}
