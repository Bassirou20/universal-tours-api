<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    // Login
    public function login(Request $request)
{
    $request->validate([
        'email' => 'required|email',
        'password' => 'required|string',
    ]);

    $user = User::where('email', $request->email)->first();

    if (!$user || !Hash::check($request->password, $user->password)) {
        return response()->json(['message' => 'Identifiants incorrects.'], 401);
    }

    // (optionnel) bloquer si compte désactivé
    if (isset($user->actif) && !$user->actif) {
        return response()->json(['message' => 'Compte désactivé.'], 403);
    }

    // Token "nommé" selon le device détecté (utile pour la liste sessions)
    $ua = (string) $request->userAgent();
    $tokenName = self::guessDeviceName($ua);
    $newToken = $user->createToken($tokenName);
    $token = $newToken->plainTextToken;

    // Stocke user_agent + ip sur le token pour les sessions actives
    $newToken->accessToken->forceFill([
        'user_agent' => substr($ua, 0, 500),
        'ip_address' => $request->ip(),
    ])->save();

    // Track de la dernière connexion (Phase 1 amélioration users)
    $user->forceFill(['last_login_at' => now()])->save();

    ActivityLog::record('login', "Connexion de {$user->prenom} {$user->nom}");

    return response()->json([
        'message' => $user->role === 'admin' ? 'Bienvenue Administrateur !' : 'Bienvenue !',
        'token' => $token,
        'user' => [
            'id' => $user->id,
            'nom' => $user->nom,
            'prenom' => $user->prenom,
            'email' => $user->email,
            'role' => $user->role,
            'actif' => $user->actif ?? 1,
            'must_change_password' => (bool)($user->must_change_password ?? false),
        ],
    ]);
}

public function me(Request $request)
{
    return response()->json($request->user());
}

    // Logout (révocation du token)
    public function logout(Request $request)
    {
        $user = $request->user();
        ActivityLog::record('logout', "Déconnexion de {$user->prenom} {$user->nom}");

        $user->currentAccessToken()->delete();

        return response()->json(['message' => 'Déconnexion réussie.']);
    }

    // Renouveler le token (si nécessaire)
    public function refresh(Request $request)
    {
        $user = $request->user();
        $token = $user->createToken('API Token')->plainTextToken;

        return response()->json(['token' => $token]);
    }

    // Envoyer le lien de réinitialisation
    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        // Laravel génère un token, le stocke en BD et envoie l'email automatiquement
        $status = Password::sendResetLink($request->only('email'));

        return response()->json([
            'message' => 'Si cet email existe, un lien de réinitialisation a été envoyé.',
        ]);
    }

    // Réinitialiser le mot de passe avec validation du token
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email'                 => 'required|email',
            'token'                 => 'required|string',
            'password'              => 'required|string|min:6|confirmed',
            'password_confirmation' => 'required|string',
        ]);

        // Password::reset() vérifie que le token correspond à l'email en BD et qu'il n'a pas expiré
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
                // Révoquer tous les tokens Sanctum existants pour forcer une reconnexion
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Token invalide ou expiré. Veuillez refaire une demande de réinitialisation.',
            ], 422);
        }

        return response()->json(['message' => 'Mot de passe réinitialisé avec succès.']);
    }

    /**
     * Met à jour les informations du profil de l'utilisateur connecté.
     * (nom, prénom, email) — N'affecte PAS le mot de passe.
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        $data = $request->validate([
            'nom'       => 'sometimes|nullable|string|max:100',
            'prenom'    => 'sometimes|nullable|string|max:100',
            'email'     => 'sometimes|required|email|max:191|unique:users,email,' . $user->id,
        ]);

        $user->fill($data)->save();

        ActivityLog::record(
            'profile_update',
            "Mise à jour du profil par {$user->prenom} {$user->nom}"
        );

        return response()->json([
            'message' => 'Profil mis à jour avec succès.',
            'user' => [
                'id'     => $user->id,
                'nom'    => $user->nom,
                'prenom' => $user->prenom,
                'email'  => $user->email,
                'role'   => $user->role,
                'actif'  => $user->actif ?? 1,
            ],
        ]);
    }

    /**
     * Change le mot de passe de l'utilisateur connecté.
     * Vérifie l'ancien mot de passe avant d'appliquer le nouveau.
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password'      => 'required|string',
            'password'              => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required|string',
        ], [
            'password.min'       => 'Le nouveau mot de passe doit faire au moins 8 caractères.',
            'password.confirmed' => 'La confirmation ne correspond pas au nouveau mot de passe.',
        ]);

        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        // Vérifier l'ancien mot de passe
        if (!Hash::check($request->input('current_password'), $user->password)) {
            return response()->json([
                'message' => 'Le mot de passe actuel est incorrect.',
            ], 422);
        }

        // Empêcher de réutiliser le même mot de passe
        if (Hash::check($request->input('password'), $user->password)) {
            return response()->json([
                'message' => 'Le nouveau mot de passe doit être différent de l\'ancien.',
            ], 422);
        }

        // Mettre à jour + reset du flag must_change_password
        $user->forceFill([
            'password' => Hash::make($request->input('password')),
            'must_change_password' => false,
        ])->save();

        // Révoquer tous les autres tokens pour forcer reconnexion ailleurs (optionnel)
        // On garde le token courant pour ne pas déconnecter l'utilisateur en cours
        $currentTokenId = optional($user->currentAccessToken())->id;
        if ($currentTokenId) {
            $user->tokens()->where('id', '!=', $currentTokenId)->delete();
        }

        ActivityLog::record(
            'password_change',
            "Changement de mot de passe par {$user->prenom} {$user->nom}"
        );

        return response()->json(['message' => 'Mot de passe mis à jour avec succès.']);
    }

    /**
     * GET /me/sessions
     * Liste toutes les sessions actives de l'utilisateur connecté.
     */
    public function sessions(Request $request)
    {
        $user = $request->user();
        $currentTokenId = optional($user->currentAccessToken())->id;

        $sessions = $user->tokens()
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($t) use ($currentTokenId) {
                return [
                    'id'           => $t->id,
                    'name'         => $t->name,
                    'user_agent'   => $t->user_agent,
                    'ip_address'   => $t->ip_address,
                    'last_used_at' => $t->last_used_at,
                    'created_at'   => $t->created_at,
                    'is_current'   => $t->id === $currentTokenId,
                    'device'       => self::guessDeviceName((string) $t->user_agent),
                ];
            });

        return response()->json(['sessions' => $sessions]);
    }

    /**
     * DELETE /me/sessions/{id}
     * Révoque (supprime) une session précise. Bloque la révocation de la session courante.
     */
    public function revokeSession(Request $request, $id)
    {
        $user = $request->user();
        $currentTokenId = optional($user->currentAccessToken())->id;

        if ((int) $id === (int) $currentTokenId) {
            return response()->json([
                'message' => 'Vous ne pouvez pas révoquer votre session courante. Utilisez "Déconnexion" à la place.',
            ], 422);
        }

        $deleted = $user->tokens()->where('id', $id)->delete();
        if (!$deleted) {
            return response()->json(['message' => 'Session introuvable.'], 404);
        }

        return response()->json(['message' => 'Session révoquée.']);
    }

    /**
     * DELETE /me/sessions
     * Révoque toutes les sessions SAUF la courante.
     */
    public function revokeAllOtherSessions(Request $request)
    {
        $user = $request->user();
        $currentTokenId = optional($user->currentAccessToken())->id;

        $deleted = $user->tokens()->where('id', '!=', $currentTokenId)->delete();

        return response()->json([
            'message' => "{$deleted} session(s) révoquée(s).",
            'revoked' => $deleted,
        ]);
    }

    /**
     * Devine un nom lisible à partir du user agent (ex: "Chrome sur Windows").
     */
    protected static function guessDeviceName(string $ua): string
    {
        if ($ua === '') return 'Appareil inconnu';

        $browser = 'Navigateur';
        if (preg_match('/Edg\//i', $ua))            $browser = 'Edge';
        elseif (preg_match('/OPR\/|Opera/i', $ua))  $browser = 'Opera';
        elseif (preg_match('/Firefox\//i', $ua))    $browser = 'Firefox';
        elseif (preg_match('/Chrome\//i', $ua))     $browser = 'Chrome';
        elseif (preg_match('/Safari\//i', $ua))     $browser = 'Safari';

        $os = 'OS inconnu';
        if (preg_match('/Windows NT/i', $ua))       $os = 'Windows';
        elseif (preg_match('/Mac OS X|Macintosh/i', $ua)) $os = 'macOS';
        elseif (preg_match('/Android/i', $ua))      $os = 'Android';
        elseif (preg_match('/iPhone|iPad|iOS/i', $ua)) $os = 'iOS';
        elseif (preg_match('/Linux/i', $ua))        $os = 'Linux';

        return "{$browser} · {$os}";
    }
}
