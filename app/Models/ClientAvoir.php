<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;

class ClientAvoir extends Model
{
    use LogsActivity;
    protected $table = 'client_avoirs';

    protected $fillable = [
        'client_id',
        'user_id',
        'facture_id',
        'type',
        'montant',
        'solde_apres',
        'reference',
        'notes',
        'date_avoir',
    ];

    protected $casts = [
        'montant'     => 'decimal:2',
        'solde_apres' => 'decimal:2',
        'date_avoir'  => 'date',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function facture()
    {
        return $this->belongsTo(Facture::class);
    }

    // ─── Static helpers ──────────────────────────────────────────────────────

    public static function soldeForClient(int $clientId): float
    {
        $depots       = static::where('client_id', $clientId)->where('type', 'depot')->sum('montant');
        $utilisations = static::where('client_id', $clientId)->where('type', 'utilisation')->sum('montant');
        return max(0, (float) $depots - (float) $utilisations);
    }
}
