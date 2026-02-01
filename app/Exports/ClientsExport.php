<?php

namespace App\Exports;

use App\Models\Client;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ClientsExport implements FromCollection, WithHeadings
{
    public function collection()
    {
        return Client::select('nom','prenom','email','telephone','adresse','pays')->get();
    }

    public function headings(): array
    {
        return ['nom','prenom','email','telephone','adresse','pays'];
    }
}
