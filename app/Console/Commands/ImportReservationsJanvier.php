<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Participant;
use App\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class ImportReservationsJanvier extends Command
{
    protected $signature = 'reservations:import-janvier
        {file : Chemin du fichier Excel (ex: storage/app/imports/ETATJANVIER26.xlsx)}
        {--dry-run : Ne sauvegarde rien, affiche seulement}
        {--year=2026 : Année par défaut si la date n’a pas d’année}
        {--month=1 : Mois par défaut si la date est partielle}';

    protected $description = 'Import des réservations billet avion depuis Excel (Janvier)';

    public function handle(): int
    {
        $file = $this->argument('file');
        $dryRun = (bool) $this->option('dry-run');
        $defaultYear = (int) $this->option('year');
        $defaultMonth = (int) $this->option('month');

        if (!file_exists($file)) {
            $this->error("Fichier introuvable: {$file}");
            return self::FAILURE;
        }

        // Charger Excel
        $spreadsheet = IOFactory::load($file);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true); // A,B,C...

        $created = 0;
        $updated = 0;
        $skipped = 0;

        // Helpers
        $normalize = function (?string $s): string {
            $s = trim((string) $s);
            $s = preg_replace('/\s+/', ' ', $s);
            return $s ?? '';
        };

        $splitName = function (string $full): array {
            $full = trim(preg_replace('/\s+/', ' ', $full));
            if ($full === '') return ['', ''];

            // Cas "NOM PRENOM PRENOM"
            $parts = explode(' ', $full);
            if (count($parts) === 1) return [$parts[0], ''];

            $nom = array_shift($parts);
            $prenom = implode(' ', $parts);

            return [$nom, $prenom];
        };

        $cleanDateCell = function ($val) use ($normalize): string {
            $s = $normalize((string) $val);
            // Retire préfixes genre "C13/1" => "13/1"
            $s = preg_replace('/^[A-Za-z]+\s*/', '', $s);
            return trim($s);
        };

        $parseDate = function ($val) use ($cleanDateCell, $defaultYear, $defaultMonth): ?string {
            if ($val === null) return null;

            // Excel serial number
            if (is_numeric($val)) {
                try {
                    $dt = ExcelDate::excelToDateTimeObject($val);
                    $c = Carbon::instance($dt);
                    // Si Excel retourne 1900, on force sur l'année par défaut
                    if ((int) $c->year === 1900) {
                        $c->year($defaultYear);
                    }
                    return $c->toDateString();
                } catch (\Throwable $e) {
                    return null;
                }
            }

            $s = $cleanDateCell($val);
            if ($s === '') return null;

            // Formats: "13/1" , "13/01" , "13/1/2026" , etc.
            if (preg_match('/^(\d{1,2})\s*\/\s*(\d{1,2})(?:\s*\/\s*(\d{2,4}))?$/', $s, $m)) {
                $d = (int) $m[1];
                $mo = (int) $m[2];
                $y = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : $defaultYear;

                // 2 digits year
                if ($y > 0 && $y < 100) $y += 2000;

                try {
                    return Carbon::createFromDate($y, $mo, $d)->toDateString();
                } catch (\Throwable $e) {
                    return null;
                }
            }

            // Dernier recours: parse Carbon
            try {
                $c = Carbon::parse($s);
                if ((int) $c->year === 1900) {
                    $c->year($defaultYear)->month($defaultMonth);
                }
                return $c->toDateString();
            } catch (\Throwable $e) {
                return null;
            }
        };

        $amountToFloat = function ($val): float {
            if ($val === null) return 0.0;
            if (is_numeric($val)) return (float) $val;

            $s = trim((string) $val);
            if ($s === '') return 0.0;

            // Ex: "4 780 000" ou "4,780,000" ou "117 400"
            $s = str_replace([' ', "\u{00A0}"], '', $s);
            $s = str_replace(',', '.', $s);

            // garder chiffres + point + -
            $s = preg_replace('/[^0-9\.\-]/', '', $s);

            return is_numeric($s) ? (float) $s : 0.0;
        };

        // Parcours des lignes (on saute l'entête si besoin)
        // Ici on parcourt tout, mais on SKIP si date illisible
        foreach ($rows as $i => $row) {
            // Mapping colonnes (ajuste si ton fichier change)
            // A: Date | B: Client/Payer | C: Bénéficiaire/Passager | D: Départ | E: Arrivée | F: Référence | G: Montant
            $dateRaw = $row['A'] ?? null;
            $payerRaw = $row['B'] ?? '';
            $benefRaw = $row['C'] ?? '';
            $depRaw = $row['D'] ?? '';
            $arrRaw = $row['E'] ?? '';
            $refRaw = $row['F'] ?? '';
            $montantRaw = $row['G'] ?? 0;

            $date = $parseDate($dateRaw);

            if (!$date) {
                // Ignore les lignes de titre / vides
                $label = trim((string)($dateRaw ?? ''));
                $this->line("[SKIP] Date illisible ligne {$i}: {$label}");
                $skipped++;
                continue;
            }

            $payerName = trim((string) $payerRaw);
            $benefName = trim((string) $benefRaw);
            $villeDepart = strtoupper(trim((string) $depRaw));
            $villeArrivee = strtoupper(trim((string) $arrRaw));
            $ref = trim((string) $refRaw);
            $montantTotal = $amountToFloat($montantRaw);

            if ($ref === '') {
                $this->line("[SKIP] Référence vide ligne {$i}");
                $skipped++;
                continue;
            }

            // Cas “Pénalités …” => on force le passager = payeur (sinon tu bloques)
            $benefLower = mb_strtolower($benefName);
            $isPenalty = str_contains($benefLower, 'pénalité') || str_contains($benefLower, 'penalite');

            if ($benefName === '' || $isPenalty) {
                $benefName = $payerName; // passager = client payeur
            }

            // Split noms
            [$clientNom, $clientPrenom] = $splitName($payerName);
            $clientNom = $clientNom ?: $payerName;
            $clientPrenom = $clientPrenom ?: '-';

            [$passNom, $passPrenom] = $splitName($benefName);
            $passNom = $passNom ?: $benefName;
            $passPrenom = $passPrenom ?: '-';

            // Affichage DRY
            if ($dryRun) {
                $this->line("[DRY] {$date} | {$payerName} -> {$benefName} | {$villeDepart}->{$villeArrivee} | {$ref} | {$montantTotal}");
                $created++; // juste pour avoir un compteur “traités”
                continue;
            }

            DB::transaction(function () use (
                $ref,
                $date,
                $clientNom,
                $clientPrenom,
                $passNom,
                $passPrenom,
                $villeDepart,
                $villeArrivee,
                $montantTotal,
                &$created,
                &$updated
            ) {
                // 1) Client (clé simple: nom+prenom)
                $client = Client::firstOrCreate(
                    ['nom' => $clientNom, 'prenom' => $clientPrenom],
                    [
                        'email' => null,
                        'telephone' => null,
                        'adresse' => null,
                        'pays' => 'Sénégal',
                        'notes' => 'Import Janvier (Excel)',
                    ]
                );

                // 2) Upsert réservation par reference (évite duplicate)
                $reservation = Reservation::where('reference', $ref)->first();

                if ($reservation) {
                    // On met à jour / complète
                    $newSousTotal = (float) $reservation->montant_sous_total + (float) $montantTotal;
                    $newTotal = (float) $reservation->montant_total + (float) $montantTotal;

                    $reservation->update([
                        'client_id' => $reservation->client_id ?: $client->id,
                        'statut' => $reservation->statut ?: Reservation::STATUT_CONFIRME,
                        'nombre_personnes' => 1,
                        'montant_sous_total' => $newSousTotal,
                        'montant_taxes' => (float) ($reservation->montant_taxes ?? 0),
                        'montant_total' => $newTotal,
                        'notes' => $reservation->notes ? $reservation->notes : 'Import Janvier (Excel)',
                    ]);

                    // Passager si manquant
                    if (empty($reservation->passenger_id)) {
                        $passenger = Participant::create([
                            'reservation_id' => $reservation->id,
                            'role' => 'passenger',
                            'nom' => $passNom,
                            'prenom' => $passPrenom,
                            'passeport' => null,
                            'remarques' => null,
                            'created_at' => $date . ' 09:00:00',
                            'updated_at' => $date . ' 09:00:00',
                        ]);

                        $reservation->update(['passenger_id' => $passenger->id]);
                    }

                    // Flight details si manquant
                    if (!$reservation->flightDetails) {
                        $reservation->flightDetails()->create([
                            'ville_depart' => $villeDepart ?: 'DSS',
                            'ville_arrivee' => $villeArrivee ?: 'DSS',
                            'date_depart' => $date,
                            'date_arrivee' => null,
                            'compagnie' => null,
                            'pnr' => null,
                            'classe' => null,
                        ]);
                    }

                    $updated++;
                    return;
                }

                // 3) Créer réservation
                $reservation = Reservation::create([
                    'client_id' => $client->id,
                    'passenger_id' => null, // on met après
                    'type' => Reservation::TYPE_BILLET_AVION,
                    'produit_id' => null,
                    'forfait_id' => null,
                    'reference' => $ref, // IMPORTANT: unique
                    'statut' => Reservation::STATUT_CONFIRME,
                    'nombre_personnes' => 1,
                    'montant_sous_total' => $montantTotal,
                    'montant_taxes' => 0,
                    'montant_total' => $montantTotal,
                    'notes' => 'Import Janvier (Excel)',
                    'created_at' => $date . ' 09:00:00',
                    'updated_at' => $date . ' 09:00:00',
                ]);

                // 4) Passager (participant lié à reservation_id)
                $passenger = Participant::create([
                    'reservation_id' => $reservation->id,
                    'role' => 'passenger',
                    'nom' => $passNom,
                    'prenom' => $passPrenom,
                    'passeport' => null,
                    'remarques' => null,
                    'created_at' => $date . ' 09:00:00',
                    'updated_at' => $date . ' 09:00:00',
                ]);

                $reservation->update(['passenger_id' => $passenger->id]);

                // 5) Flight Details
                $reservation->flightDetails()->create([
                    'ville_depart' => $villeDepart ?: 'DSS',
                    'ville_arrivee' => $villeArrivee ?: 'DSS',
                    'date_depart' => $date,
                    'date_arrivee' => null,
                    'compagnie' => null,
                    'pnr' => null,
                    'classe' => null,
                    'created_at' => $date . ' 09:00:00',
                    'updated_at' => $date . ' 09:00:00',
                ]);

                $created++;
            });
        }

        $this->info("Terminé. Créées: {$created} | Mises à jour: {$updated} | Ignorées: {$skipped}");
        return self::SUCCESS;
    }
}
