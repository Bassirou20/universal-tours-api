<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationAssurance extends Model
{
    protected $fillable = [
        'reservation_id',
        'libelle',
        'date_debut',
        'date_fin',
    ];

    protected $casts = [
        'date_debut' => 'date',
        'date_fin' => 'date',
    ];

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }
}