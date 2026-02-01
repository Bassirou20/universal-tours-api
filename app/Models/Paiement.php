<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Paiement extends Model
{
    use HasFactory;

   protected $fillable = [
    'facture_id',
    'montant',
    'mode_paiement',
    'reference',
    'transaction_id', // seulement si colonne existe
    'date_paiement',
    'statut',
    'notes',
];

    protected $casts = [
        'date_paiement' => 'date',
        'montant' => 'decimal:2'
    ];

    public function facture()
    {
        return $this->belongsTo(Facture::class);
    }
}
