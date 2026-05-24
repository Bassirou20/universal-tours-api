<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    /**
     * Liste paginée des notifications de l'utilisateur connecté.
     */
    public function index(Request $request)
    {
        $perPage = (int) ($request->get('per_page', 15));
        $perPage = max(1, min($perPage, 50));

        $q = Notification::forUser(Auth::id())->orderByDesc('created_at');

        if ($request->boolean('unread_only')) {
            $q->unread();
        }

        return response()->json($q->paginate($perPage));
    }

    /**
     * Compteur de notifications non-lues — endpoint léger pour le polling.
     */
    public function unreadCount()
    {
        $count = Notification::forUser(Auth::id())->unread()->count();
        return response()->json(['count' => $count]);
    }

    /**
     * Marquer une notification comme lue.
     */
    public function markAsRead($id)
    {
        $n = Notification::forUser(Auth::id())->findOrFail($id);
        if (is_null($n->read_at)) {
            $n->read_at = now();
            $n->save();
        }
        return response()->json(['ok' => true]);
    }

    /**
     * Marquer toutes comme lues.
     */
    public function markAllAsRead()
    {
        Notification::forUser(Auth::id())->unread()->update(['read_at' => now()]);
        return response()->json(['ok' => true]);
    }

    /**
     * Supprimer une notification.
     */
    public function destroy($id)
    {
        $n = Notification::forUser(Auth::id())->findOrFail($id);
        $n->delete();
        return response()->json(['ok' => true]);
    }
}
