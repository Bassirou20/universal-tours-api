<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AccessRequest;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AccessRequestController extends Controller
{
    /**
     * POST /api/access-requests
     * Endpoint PUBLIC : enregistre une demande d'accès depuis la landing page.
     */
    public function store(Request $request)
    {
        // Rate-limit basique par IP : max 3 demandes / 10 min pour éviter le spam
        $ip = $request->ip();
        $key = 'access-request:' . $ip;
        $count = (int) Cache::get($key, 0);
        if ($count >= 3) {
            return response()->json([
                'message' => 'Trop de demandes envoyées récemment. Réessayez dans quelques minutes.',
            ], 429);
        }

        $data = $request->validate([
            'nom'       => ['required', 'string', 'max:120'],
            'prenom'    => ['nullable', 'string', 'max:120'],
            'email'     => ['required', 'email', 'max:180'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'societe'   => ['nullable', 'string', 'max:180'],
            'message'   => ['nullable', 'string', 'max:2000'],
        ]);

        // Enregistrement
        $req = AccessRequest::create([
            'nom'        => trim($data['nom']),
            'prenom'     => isset($data['prenom']) ? trim($data['prenom']) : null,
            'email'      => strtolower(trim($data['email'])),
            'telephone'  => $data['telephone'] ?? null,
            'societe'    => $data['societe'] ?? null,
            'message'    => $data['message'] ?? null,
            'statut'     => AccessRequest::STATUT_PENDING,
            'ip_address' => $ip,
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        // Incrémente le compteur de rate-limit
        Cache::put($key, $count + 1, now()->addMinutes(10));

        // 🔔 Notifie tous les admins
        $fullName = trim(($req->prenom ?? '') . ' ' . $req->nom) ?: $req->email;
        $bodyParts = ["Email : {$req->email}"];
        if ($req->telephone) $bodyParts[] = "Tél : {$req->telephone}";
        if ($req->societe)   $bodyParts[] = "Société : {$req->societe}";

        NotificationService::notifyAdmins(
            type:  'access_request',
            title: "Nouvelle demande d'accès · {$fullName}",
            body:  implode(' · ', $bodyParts),
            url:   "/access-requests",
            data:  ['access_request_id' => $req->id, 'email' => $req->email],
        );

        return response()->json([
            'message' => 'Votre demande a bien été envoyée. Notre équipe vous recontactera rapidement.',
            'id'      => $req->id,
        ], 201);
    }
}
