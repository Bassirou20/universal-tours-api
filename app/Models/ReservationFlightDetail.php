<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationFlightDetail extends Model
{
    protected $fillable = [
        'reservation_id',
        'ville_depart',
        'ville_arrivee',
        'date_depart',
        'date_arrivee',
        'compagnie',
        'pnr',
        'classe',
    ];

    protected $casts = [
        'date_depart' => 'date',
        'date_arrivee' => 'date',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
