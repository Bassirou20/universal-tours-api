<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $q = ActivityLog::with('user:id,nom,prenom,email')
            ->orderByDesc('created_at');

        if ($userId = $request->get('user_id')) {
            $q->where('user_id', (int) $userId);
        }

        if ($action = $request->get('action')) {
            $q->where('action', $action);
        }

        if ($modelType = $request->get('model_type')) {
            $q->where('model_type', $modelType);
        }

        if ($from = $request->get('date_from')) {
            $q->whereDate('created_at', '>=', $from);
        }

        if ($to = $request->get('date_to')) {
            $q->whereDate('created_at', '<=', $to);
        }

        if ($search = $request->get('search')) {
            $q->where(function ($sub) use ($search) {
                $sub->where('description', 'like', "%$search%")
                    ->orWhereHas('user', function ($u) use ($search) {
                        $u->where('nom', 'like', "%$search%")
                          ->orWhere('prenom', 'like', "%$search%");
                    });
            });
        }

        return $q->paginate(25);
    }
}
