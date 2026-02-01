<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDepenseRequest;
use App\Http\Requests\UpdateDepenseRequest;
use App\Models\Depense;
use Illuminate\Http\Request;

class DepenseController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 20);

        $q = Depense::query()->with(['reservation'])->orderByDesc('date_depense')->orderByDesc('id');

        if ($request->filled('categorie')) {
            $q->where('categorie', $request->get('categorie'));
        }

        if ($request->filled('statut')) {
            $q->where('statut', $request->get('statut'));
        }

        if ($request->filled('reservation_id')) {
            $q->where('reservation_id', (int)$request->get('reservation_id'));
        }

        if ($request->filled('date_from')) {
            $q->whereDate('date_depense', '>=', $request->get('date_from'));
        }
        if ($request->filled('date_to')) {
            $q->whereDate('date_depense', '<=', $request->get('date_to'));
        }

        if ($request->filled('q')) {
            $s = $request->get('q');
            $q->where(function($w) use ($s) {
                $w->where('libelle','like',"%$s%")
                  ->orWhere('fournisseur_nom','like',"%$s%")
                  ->orWhere('reference','like',"%$s%");
            });
        }

        return response()->json($q->paginate($perPage));
    }

    public function store(StoreDepenseRequest $request)
    {
        $depense = Depense::create($request->validated());
        return response()->json($depense->load('reservation'), 201);
    }

    public function show(Depense $depense)
    {
        return response()->json($depense->load('reservation'));
    }

    public function update(UpdateDepenseRequest $request, Depense $depense)
    {
        $depense->update($request->validated());
        return response()->json($depense->load('reservation'));
    }

    public function destroy(Depense $depense)
    {
        $depense->delete();
        return response()->json(['message' => 'Dépense supprimée']);
    }
}
