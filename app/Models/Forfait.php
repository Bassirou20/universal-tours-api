<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Forfait extends Model
{
    use HasFactory;
     protected $fillable = [
        'nom',
        'description',
        'prix',         // nullable: pour solo/couple
        'prix_adulte',  // nullable: pour famille
        'prix_enfant',  // nullable: pour famille
        'event_id',
        'nombre_max_personnes',
        'type',         // solo, couple, famille
        'actif',
    ];

    protected $casts = [
    'actif' => 'boolean',
    ];

    // Relation avec l'événement
      public function event()
    {
        return $this->belongsTo(Produit::class, 'event_id');
    }
}
