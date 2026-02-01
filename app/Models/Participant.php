<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Participant extends Model
{
    use HasFactory,SoftDeletes;

     protected $fillable = [
        'nom',
        'prenom',
        'age',
        'passeport',
        'remarques',
        'produit_id',
        'reservation_id',
    ];

     public function produit()
    {
        return $this->belongsTo(Produit::class);
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }
}
