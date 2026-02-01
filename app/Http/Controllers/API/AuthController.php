<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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

    // Réinitialisation de mot de passe
    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        $user = User::where('email', $request->email)->first();
        
        if ($user) {
            $user->sendPasswordResetNotification();
        }

        return response()->json(['message' => 'If this email exists, we have sent a reset link.']);
    }

    // Réinitialiser le mot de passe
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string|confirmed',
            'token' => 'required|string',
        ]);

        // Processus de réinitialisation de mot de passe
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'Email not found'], 404);
        }

        // Logique pour valider le token de réinitialisation du mot de passe et changer le mot de passe
        // Tu peux utiliser un package Laravel pour la réinitialisation de mot de passe ou le faire manuellement
        // Exemple avec un simple changement
        $user->password = Hash::make($request->password);
        $user->save();

        return response()->json(['message' => 'Password reset successfully']);
    }
}
