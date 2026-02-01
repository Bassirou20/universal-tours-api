<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LigneReservation extends Model
{
    use HasFactory;

    protected $table = 'lignes_reservation';

    protected $fillable = [
        'reservation_id','produit_id','designation','quantite','prix_unitaire','taxe','total_ligne','options'
    ];

    protected $casts = [
        'options' => 'array',
        'prix_unitaire' => 'decimal:2',
        'taxe' => 'decimal:2',
        'total_ligne' => 'decimal:2'
    ];

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function produit()
    {
        return $this->belongsTo(Produit::class);
    }
}
