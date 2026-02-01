<?php

namespace App\Imports;

use App\Models\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;

class ClientsImport implements ToCollection, WithHeadingRow, WithChunkReading, WithBatchInserts, SkipsEmptyRows
{
    public int $inserted = 0;
    public int $updated = 0;
    public int $skipped = 0;

    public array $errors = []; // erreurs par ligne

    public function collection(Collection $rows)
    {
        $toUpsert = [];

        foreach ($rows as $index => $row) {
            // index est basé sur chunk; pour donner une ligne lisible:
            $excelRowNumber = $index + 2; // +2 car headingRow=1

            $data = [
                'prenom' => trim((string)($row['prenom'] ?? '')),
                'nom' => trim((string)($row['nom'] ?? '')),
                'email' => strtolower(trim((string)($row['email'] ?? ''))),
                'telephone' => trim((string)($row['telephone'] ?? '')),
                'adresse' => trim((string)($row['adresse'] ?? '')),
            ];

            // Règles: au moins nom+prenom, et au moins email OU telephone
            $validator = Validator::make($data, [
                'prenom' => 'required|string|min:1|max:100',
                'nom' => 'required|string|min:1|max:100',
                'email' => 'nullable|email|max:190',
                'telephone' => 'nullable|string|max:50',
                'adresse' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                $this->skipped++;
                $this->errors[] = [
                    'row' => $excelRowNumber,
                    'errors' => $validator->errors()->all(),
                ];
                continue;
            }

            if ($data['email'] === '' && $data['telephone'] === '') {
                $this->skipped++;
                $this->errors[] = [
                    'row' => $excelRowNumber,
                    'errors' => ["Email ou téléphone requis (au moins un des deux)."],
                ];
                continue;
            }

            // Préparer l'upsert
            $toUpsert[] = [
                'prenom' => $data['prenom'],
                'nom' => $data['nom'],
                'email' => $data['email'] ?: null,
                'telephone' => $data['telephone'] ?: null,
                'adresse' => $data['adresse'] ?: null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (empty($toUpsert)) {
            return;
        }

        /**
         * Stratégie anti-doublons:
         * - prioriser l'email si présent, sinon telephone
         * - si ton système impose unique email/tel, c'est parfait.
         *
         * Ici on fait:
         * 1) upsert par email (non null)
         * 2) upsert par telephone (non null et email null)
         */

        $byEmail = array_values(array_filter($toUpsert, fn($x) => !empty($x['email'])));
        $byPhoneOnly = array_values(array_filter($toUpsert, fn($x) => empty($x['email']) && !empty($x['telephone'])));

        if (!empty($byEmail)) {
            // ⚠️ Nécessite une contrainte unique sur email pour être 100% safe,
            // sinon upsert se base sur l'index/unique.
            Client::upsert(
                $byEmail,
                ['email'],
                ['prenom', 'nom', 'telephone', 'adresse', 'updated_at']
            );
        }

        if (!empty($byPhoneOnly)) {
            Client::upsert(
                $byPhoneOnly,
                ['telephone'],
                ['prenom', 'nom', 'adresse', 'updated_at']
            );
        }

        // Stats (approximatives sans query en plus)
        // Si tu veux exact inserted/updated, on peut le faire, mais ça coûte + queries.
        $this->inserted += count($toUpsert);
    }

    public function chunkSize(): int
    {
        return 500; // stable
    }

    public function batchSize(): int
    {
        return 500;
    }
}
