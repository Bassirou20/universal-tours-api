<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'nom', 'prenom', 'email', 'telephone', 'adresse', 'pays', 'notes'
    ];

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }
}
