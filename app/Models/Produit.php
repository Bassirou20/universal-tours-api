<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Produit extends Model
{
    use HasFactory;

    // type: billet_avion, hotel, voiture, evenement
    protected $fillable = [
        'type','nom','description','prix_base','devise','actif'
    ];

    protected $casts = [
        'actif' => 'boolean',
        'prix_base' => 'decimal:2'
    ];

    public function lignes()
    {
        return $this->hasMany(LigneReservation::class);
    }

     public function fournisseur()
    {
        return $this->belongsTo(Fournisseur::class);
    }
}
