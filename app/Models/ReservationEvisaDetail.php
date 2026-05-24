<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationEvisaDetail extends Model
{
    protected $fillable = [
        'reservation_id',
        'pays_destination',
        'type_visa',
        'date_voyage',
        'duree_sejour',
    ];

    protected $casts = [
        'date_voyage' => 'date',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
