<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Reservation extends Model
{
    use HasFactory, SoftDeletes;

    // ✅ Types autorisés (valeurs techniques)
    public const TYPE_BILLET_AVION = 'billet_avion';
    public const TYPE_EVENEMENT    = 'evenement';
    public const TYPE_HOTEL        = 'hotel';
    public const TYPE_VOITURE      = 'voiture';
    public const TYPE_FORFAIT = 'forfait';
    public const STATUT_EN_ATTENTE = 'en_attente';
    public const STATUT_CONFIRME = 'confirmee';
    public const STATUT_ANNULE = 'annulee';


    public const TYPES = [
        self::TYPE_BILLET_AVION,
        self::TYPE_EVENEMENT,
        self::TYPE_HOTEL,
        self::TYPE_VOITURE,
        self::TYPE_FORFAIT,
    ];

    /**
     * Champs modifiables en masse
     */
    protected $fillable = [
        'client_id',

        // Général
        'type',                 // billet_avion, hotel, voiture, evenement
        'produit_id',           // NULL pour billet_avion
        'forfait_id',
        'reference',
        'statut',
        'nombre_personnes',
        'passenger_id',

        // Montants (sans devise)
        'montant_sous_total',
        'montant_taxes',
        'montant_total',

        // Billet avion
        'ville_depart',
        'ville_arrivee',
        'date_depart',
        'date_arrivee',
        'compagnie',

        'notes',
    ];

    /**
     * Casts
     */
    protected $casts = [
        'montant_sous_total' => 'decimal:2',
        'montant_taxes'      => 'decimal:2',
        'montant_total'      => 'decimal:2',
        'date_depart'        => 'date',
        'date_arrivee'       => 'date',
    ];

    // ✅ Label lisible (utile pour l’API / frontend)
    public function getTypeLabelAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_BILLET_AVION => "Billet d’avion",
            self::TYPE_EVENEMENT    => "Événement",
            self::TYPE_HOTEL        => "Hôtel",
            self::TYPE_VOITURE      => "Location de voiture",
            self::TYPE_FORFAIT => "Forfait",

            default                 => "Inconnu",
        };
    }

    public function client(): BelongsTo
    {
    return $this->belongsTo(\App\Models\Client::class);
    }

    protected static function booted()
{
    static::created(function (Reservation $r) {
        if (!empty($r->reference)) return;

        // Option 1 : RES-000123
        // $r->reference = 'RES-' . str_pad((string)$r->id, 6, '0', STR_PAD_LEFT);

        // Option 2 : RES-YYYYMMDD-0123 (si tu préfères)
        $date = ($r->created_at ?? now())->format('Ymd');
        $r->reference = "RES-{$date}-" . str_pad((string)$r->id, 4, '0', STR_PAD_LEFT);

        $r->saveQuietly();
    });
}   

    /* Helpers */
    public function isBilletAvion(): bool
    {
        return $this->type === self::TYPE_BILLET_AVION;
    }

    public function isEvenement(): bool
    {
        return $this->type === self::TYPE_EVENEMENT;
    }

    public function isHotel(): bool
    {
        return $this->type === self::TYPE_HOTEL;
    }

    public function isVoiture(): bool
    {
        return $this->type === self::TYPE_VOITURE;
    }
    public function produit(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Produit::class);
    }
    public function flightDetails(): HasOne
    {
        return $this->hasOne(\App\Models\ReservationFlightDetail::class);
    }

    public function forfait(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Forfait::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(\App\Models\Participant::class);
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Participant::class, 'passenger_id');
    }


    public function factures(): HasMany
    {
        return $this->hasMany(\App\Models\Facture::class);
    }
//     public function factures()
// {
//     return $this->hasMany(\App\Models\Facture::class);
// }
}
