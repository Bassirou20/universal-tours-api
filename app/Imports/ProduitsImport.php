<?php

namespace App\Imports;

use App\Models\Produit;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ProduitsImport implements ToModel, WithHeadingRow
{
    public function model(array $row)
    {
        return new Produit([
            'type' => $row['type'] ?? 'evenement',
            'nom' => $row['nom'] ?? null,
            'description' => $row['description'] ?? null,
            'prix_base' => $row['prix_base'] ?? 0,
            'devise' => $row['devise'] ?? 'XOF',
            'actif' => ($row['actif'] ?? 1) ? true : false,
        ]);
    }
}
