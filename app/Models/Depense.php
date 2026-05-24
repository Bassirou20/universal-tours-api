<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Depense extends Model
{
    use HasFactory, LogsActivity;

     protected $fillable = [
        'date_depense',
        'categorie',
        'libelle',
        'fournisseur_nom',
        'reference',
        'montant',
        'mode_paiement',
        'statut',
        'reservation_id',
        'notes',
    ];

    protected $casts = [
        'date_depense' => 'date',
        'montant' => 'decimal:2',
    ];

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }
}
