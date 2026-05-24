<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 20);

        $q = User::query()->orderByDesc('id');

        // Rôle : accepte un seul role OU un tableau roles[]
        if ($request->filled('role')) {
            $q->where('role', $request->get('role'));
        } elseif ($request->has('roles')) {
            $roles = (array) $request->get('roles');
            $roles = array_filter(array_map('strval', $roles));
            if (!empty($roles)) {
                $q->whereIn('role', $roles);
            }
        }

        if ($request->filled('actif')) {
            $actif = $request->get('actif');
            $q->where('actif', (int) $actif);
        }

        // Filtre par date de création
        if ($request->filled('date_from')) {
            try {
                $q->whereDate('created_at', '>=', \Carbon\Carbon::parse($request->get('date_from'))->toDateString());
            } catch (\Exception $e) { /* ignore */ }
        }
        if ($request->filled('date_to')) {
            try {
                $q->whereDate('created_at', '<=', \Carbon\Carbon::parse($request->get('date_to'))->toDateString());
            } catch (\Exception $e) { /* ignore */ }
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

    /**
     * GET /users/export
     * Renvoie un CSV téléchargeable des utilisateurs (filtrable).
     */
    public function export(Request $request)
    {
        $q = User::query()->orderByDesc('id');

        if ($request->filled('role')) {
            $q->where('role', $request->get('role'));
        } elseif ($request->has('roles')) {
            $roles = array_filter((array) $request->get('roles'));
            if (!empty($roles)) $q->whereIn('role', $roles);
        }

        if ($request->filled('actif')) $q->where('actif', (int) $request->get('actif'));

        if ($request->filled('date_from')) {
            try { $q->whereDate('created_at', '>=', \Carbon\Carbon::parse($request->get('date_from'))->toDateString()); }
            catch (\Exception $e) {}
        }
        if ($request->filled('date_to')) {
            try { $q->whereDate('created_at', '<=', \Carbon\Carbon::parse($request->get('date_to'))->toDateString()); }
            catch (\Exception $e) {}
        }

        if ($request->filled('q')) {
            $s = $request->get('q');
            $q->where(function($w) use ($s) {
                $w->where('nom','like',"%$s%")
                  ->orWhere('prenom','like',"%$s%")
                  ->orWhere('email','like',"%$s%");
            });
        }

        // Si des IDs sont passés (export d'une sélection), on filtre dessus
        if ($request->has('ids')) {
            $ids = array_filter(array_map('intval', (array) $request->get('ids')));
            if (!empty($ids)) $q->whereIn('id', $ids);
        }

        $users = $q->get();

        $filename = 'utilisateurs_' . now()->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($users) {
            $out = fopen('php://output', 'w');
            // BOM UTF-8 pour Excel
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['ID', 'Prénom', 'Nom', 'Email', 'Rôle', 'Actif', 'Dernière connexion', 'Créé le'], ';');

            foreach ($users as $u) {
                fputcsv($out, [
                    $u->id,
                    $u->prenom ?? '',
                    $u->nom ?? '',
                    $u->email ?? '',
                    $u->role ?? '',
                    ($u->actif ? 'Oui' : 'Non'),
                    optional($u->last_login_at)->format('Y-m-d H:i'),
                    optional($u->created_at)->format('Y-m-d H:i'),
                ], ';');
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * POST /users/bulk
     * Actions groupées : activate | deactivate | reset-password
     * Body: { action: string, ids: int[] }
     */
    public function bulk(Request $request)
    {
        $data = $request->validate([
            'action' => ['required', 'in:activate,deactivate'],
            'ids'    => ['required', 'array', 'min:1'],
            'ids.*'  => ['integer'],
        ]);

        $action = $data['action'];
        $ids    = array_filter(array_map('intval', $data['ids']));

        // Exclure le user courant pour éviter l'auto-désactivation
        $currentUserId = $request->user()->id;
        $ids = array_values(array_filter($ids, fn ($i) => $i !== $currentUserId));

        if (empty($ids)) {
            return response()->json([
                'message' => 'Aucune action effectuée (votre compte ne peut pas être inclus).',
                'updated' => 0,
            ], 422);
        }

        if ($action === 'activate') {
            $updated = User::whereIn('id', $ids)->update(['actif' => 1]);
        } else { // deactivate
            $updated = User::whereIn('id', $ids)->update(['actif' => 0]);
        }

        return response()->json([
            'message' => "{$updated} utilisateur(s) " . ($action === 'activate' ? 'activé(s)' : 'désactivé(s)'),
            'updated' => $updated,
            'ids'     => $ids,
        ]);
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

        // 🔔 Notif aux admins
        $fullName = trim(($user->prenom ?? '') . ' ' . ($user->nom ?? '')) ?: $user->email;
        NotificationService::notifyAdmins(
            type:  'user_created',
            title: "Nouvel utilisateur · {$fullName}",
            body:  "Rôle : " . ($user->role ?? 'employee'),
            url:   "/users",
            data:  ['user_id' => $user->id],
        );

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

    /**
     * POST /users/{user}/reset-password
     * Génère un mot de passe temporaire, force le user à le changer au prochain login.
     * Retourne le mot de passe en clair UNE SEULE FOIS (à transmettre au user).
     */
    public function resetPassword(Request $request, User $user)
    {
        // Empêcher auto-reset (utiliser /password/change pour soi-même)
        if ($request->user()->id === $user->id) {
            return response()->json([
                'message' => 'Utilisez votre profil pour changer votre propre mot de passe.',
            ], 422);
        }

        // Génère un mot de passe temporaire lisible : 3 lettres + 4 chiffres + 1 symbole
        $temp = self::generateTempPassword();

        $user->forceFill([
            'password' => Hash::make($temp),
            'must_change_password' => true,
        ])->save();

        // Révoquer tous les tokens du user (force reconnexion)
        $user->tokens()->delete();

        // Notif au user concerné
        \App\Services\NotificationService::notifyUser(
            $user->id,
            'password_reset',
            "Votre mot de passe a été réinitialisé",
            "Vous devrez le changer à votre prochaine connexion.",
            "/profile",
        );

        return response()->json([
            'message'          => 'Mot de passe réinitialisé. Transmettez-le à l\'utilisateur.',
            'temp_password'    => $temp,
            'user_id'          => $user->id,
            'must_change_at_next_login' => true,
        ]);
    }

    /**
     * GET /users/{user}/stats
     * Renvoie les KPI utiles : nb de réservations créées, CA généré, dernière connexion, etc.
     */
    public function stats(User $user)
    {
        // Activity logs récents (5 dernières actions)
        $recentActivities = [];
        if (\Schema::hasTable('activity_logs')) {
            $recentActivities = \DB::table('activity_logs')
                ->where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->limit(5)
                ->get(['action', 'description', 'created_at'])
                ->toArray();
        }

        // Nombre de réservations créées par cet user (via activity_logs)
        // (on n'a pas de FK user_id sur reservations, on utilise les logs comme proxy)
        $reservationCount = 0;
        if (\Schema::hasTable('activity_logs')) {
            $reservationCount = \DB::table('activity_logs')
                ->where('user_id', $user->id)
                ->where('action', 'like', '%reservation%')
                ->count();
        }

        // Dernière activité
        $lastActivity = null;
        if (\Schema::hasTable('activity_logs')) {
            $lastActivity = \DB::table('activity_logs')
                ->where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->value('created_at');
        }

        return response()->json([
            'user_id'           => $user->id,
            'last_login_at'     => $user->last_login_at,
            'last_activity_at'  => $lastActivity,
            'reservations_count' => $reservationCount,
            'recent_activities' => $recentActivities,
            'created_at'        => $user->created_at,
            'must_change_password' => (bool)($user->must_change_password ?? false),
        ]);
    }

    /**
     * Génère un mot de passe temporaire lisible (facile à dicter).
     * Format : 3 lettres minuscules + 4 chiffres + 1 symbole sûr → ex: "abc1234!"
     */
    protected static function generateTempPassword(): string
    {
        $letters = 'abcdefghjkmnpqrstuvwxyz'; // exclure i, l, o pour la lisibilité
        $digits  = '23456789';                  // exclure 0, 1
        $symbols = '!@#$%';

        $part1 = '';
        for ($i = 0; $i < 3; $i++) $part1 .= $letters[random_int(0, strlen($letters) - 1)];

        $part2 = '';
        for ($i = 0; $i < 4; $i++) $part2 .= $digits[random_int(0, strlen($digits) - 1)];

        $part3 = $symbols[random_int(0, strlen($symbols) - 1)];

        return $part1 . $part2 . $part3;
    }
}