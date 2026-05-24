<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccessRequest extends Model
{
    use HasFactory;

    const STATUT_PENDING   = 'pending';
    const STATUT_CONTACTED = 'contacted';
    const STATUT_APPROVED  = 'approved';
    const STATUT_REFUSED   = 'refused';

    protected $fillable = [
        'nom',
        'prenom',
        'email',
        'telephone',
        'societe',
        'message',
        'statut',
        'admin_notes',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function getFullNameAttribute(): string
    {
        return trim(($this->prenom ?? '') . ' ' . ($this->nom ?? ''));
    }
}
