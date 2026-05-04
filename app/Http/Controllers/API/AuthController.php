<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
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
        return response()->json(['message' => 'Invalid credentials'], 401);
    }

    // (optionnel) bloquer si compte désactivé
    if (isset($user->actif) && !$user->actif) {
        return response()->json(['message' => 'Compte désactivé.'], 403);
    }

    $token = $user->createToken('API Token')->plainTextToken;

    return response()->json([
        'message' => $user->role === 'admin' ? 'Welcome Admin!' : 'Welcome Employee!',
        'token' => $token,
        'user' => [
            'id' => $user->id,
            'nom' => $user->nom,
            'prenom' => $user->prenom,
            'email' => $user->email,
            'role' => $user->role,   // ✅ c’est ça qui débloque le Front
            'actif' => $user->actif ?? 1,
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
        // Revoke the user's token
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logout successful']);
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
}
