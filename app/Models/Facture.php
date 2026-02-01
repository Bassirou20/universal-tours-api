<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Facture extends Model
{
    use HasFactory;

    protected $fillable = [
     'reservation_id',
    'numero',
    'date_facture',
    'montant_sous_total',
    'montant_taxes',
    'montant_total',
    'statut',
    'pdf_path',
];


    protected $casts = [
    'date_facture' => 'date',
    'montant_sous_total' => 'decimal:2',
    'montant_taxes' => 'decimal:2',
    'montant_total' => 'decimal:2',
    ];
    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function paiements()
    {
        return $this->hasMany(Paiement::class);
    }

      public static function generateNumero(): string
    {
        // Format: FAC-YYYYMMDD-XXXXXX
        return 'FAC-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
    }
}
