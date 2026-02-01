<?php

namespace App\Exports;

use App\Models\Produit;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ProduitsExport implements FromCollection, WithHeadings
{
    public function collection()
    {
        return Produit::select('type','nom','description','prix_base','devise','actif')->get();
    }

    public function headings(): array
    {
        return ['type','nom','description','prix_base','devise','actif'];
    }
}
