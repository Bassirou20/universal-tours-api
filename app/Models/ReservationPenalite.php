<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationPenalite extends Model
{
    use HasFactory;

    protected $table = 'reservation_penalites';

    // Types de pénalités
    const TYPE_ANNULATION   = 'annulation';
    const TYPE_MODIFICATION = 'modification';
    const TYPE_NO_SHOW      = 'no_show';
    const TYPE_AUTRE        = 'autre';

    // Modes de traitement
    const TRAITEMENT_DEDUIT_AVOIR    = 'deduit_avoir';
    const TRAITEMENT_FACTURE_SEPAREE = 'facture_separee';
    const TRAITEMENT_RETENU_PAIEMENT = 'retenu_paiement';

    protected $fillable = [
        'reservation_id',
        'imposed_by_user_id',
        'avoir_id',
        'facture_id',
        'type',
        'traitement',
        'montant',
        'motif',
        'imposed_at',
    ];

    protected $casts = [
        'montant'    => 'decimal:2',
        'imposed_at' => 'datetime',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function imposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imposed_by_user_id');
    }

    public function avoir(): BelongsTo
    {
        return $this->belongsTo(ClientAvoir::class, 'avoir_id');
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    public function getTypeLabelAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_ANNULATION   => 'Annulation',
            self::TYPE_MODIFICATION => 'Modification',
            self::TYPE_NO_SHOW      => 'No-show',
            self::TYPE_AUTRE        => 'Autre',
            default                 => ucfirst((string) $this->type),
        };
    }
}
