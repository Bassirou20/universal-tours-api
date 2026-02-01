<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 20);

        $q = User::query()->orderByDesc('id');

        // Filtres optionnels (si tu veux)
        if ($request->filled('role')) {
            $q->where('role', $request->get('role'));
        }
        if ($request->filled('actif')) {
            $actif = $request->get('actif');
            $q->where('actif', (int) $actif);
        }
        if ($request->filled('q')) {
            $s = $request->get('q');
            $q->where(function($w) use ($s) {
                $w->where('nom','like',"%$s%")
                  ->orWhere('prenom','like',"%$s%")
                  ->orWhere('email','like',"%$s%");
            });
        }

        return response()->json($q->paginate($perPage));
    }

    public function store(StoreUserRequest $request)
    {
        $data = $request->validated();

        $user = new User();
        $user->prenom = $data['prenom'] ?? null;
        $user->nom = $data['nom'] ?? null;
        $user->email = $data['email'];
        $user->role = $data['role'] ?? 'employee';
        $user->actif = isset($data['actif']) ? (int) !!$data['actif'] : 1;
        $user->password = Hash::make($data['password']);
        $user->save();

        return response()->json($user, 201);
    }

    public function show(User $user)
    {
        return response()->json($user);
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $data = $request->validated();

        if (array_key_exists('prenom', $data)) $user->prenom = $data['prenom'];
        if (array_key_exists('nom', $data)) $user->nom = $data['nom'];
        if (array_key_exists('email', $data)) $user->email = $data['email'];
        if (array_key_exists('role', $data)) $user->role = $data['role'];
        if (array_key_exists('actif', $data)) $user->actif = (int) !!$data['actif'];

        if (!empty($data['password'] ?? null)) {
            $user->password = Hash::make($data['password']);
        }

        if (array_key_exists('actif', $data)) {
            if ($request->user()->id === $user->id && (int)!!$data['actif'] === 0) {
                return response()->json(['message' => 'Vous ne pouvez pas désactiver votre propre compte.'], 422);
            }
            $user->actif = (int) !!$data['actif'];
        }


        $user->save();

        return response()->json($user);
    }

    // au lieu de supprimer: désactiver (option très pro)
        public function destroy(Request $request, User $user)
    {
        // Empêcher auto-désactivation
        if ($request->user()->id === $user->id) {
            return response()->json(['message' => 'Vous ne pouvez pas désactiver votre propre compte.'], 422);
        }

        $user->actif = 0;
        $user->save();

        return response()->json(['message' => 'Utilisateur désactivé']);
    }
}