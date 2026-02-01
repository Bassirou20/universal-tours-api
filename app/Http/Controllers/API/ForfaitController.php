<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Forfait;
use App\Models\Produit;
use Illuminate\Http\Request;

class ForfaitController extends Controller
{
    public function index()
    {
        return Forfait::with('event')->paginate(20);
    }

    public function store(Request $request)
    {

        // dd('store-called', $request->all());

        $data = $request->validate([
            'event_id' => 'required|exists:produits,id',
            'type' => 'required|in:solo,couple,famille',
            'nom' => 'required|string|max:150',
            'prix' => 'nullable|numeric|min:0',
            'prix_adulte' => 'nullable|numeric|min:0',
            'prix_enfant' => 'nullable|numeric|min:0',
            'nombre_max_personnes' => 'required|integer|min:1',
            'actif' => 'nullable|in:0,1',
        ]);

          $data['actif'] = $request->has('actif') ? (int) $request->input('actif') : 1;

    $event = Produit::findOrFail($data['event_id']);
    if ($event->type !== 'evenement') {
        return response()->json(['message' => 'Les forfaits ne s’appliquent qu’aux événements.'], 422);
    }

    if ($data['type'] === 'famille') {
        if (!isset($data['prix_adulte']) || !isset($data['prix_enfant'])) {
            return response()->json(['message' => 'Pour un forfait famille, les prix adulte et enfant sont obligatoires.'], 422);
        }
        $data['prix'] = null;
    } else {
        if (!isset($data['prix'])) {
            return response()->json(['message' => 'Le prix est obligatoire pour les forfaits solo et couple.'], 422);
        }
        $data['prix_adulte'] = null;
        $data['prix_enfant'] = null;
    }

    $forfait = Forfait::create($data);

    return response()->json($forfait->load('event'), 201);
    }

    public function show(Forfait $forfait)
    {
        return $forfait->load('event');
    }

    public function update(Request $request, Forfait $forfait)
{
    $data = $request->validate([
        'event_id' => 'sometimes|exists:produits,id',
        'type' => 'sometimes|in:solo,couple,famille',
        'nom' => 'sometimes|string|max:150',
        'prix' => 'sometimes|nullable|numeric|min:0',
        'prix_adulte' => 'sometimes|nullable|numeric|min:0',
        'prix_enfant' => 'sometimes|nullable|numeric|min:0',
        'nombre_max_personnes' => 'sometimes|integer|min:1',
        'actif' => 'sometimes|in:0,1',
    ]);

    // Si event_id est fourni, vérifier que c'est un evenement
    if (array_key_exists('event_id', $data)) {
        $event = Produit::findOrFail($data['event_id']);
        if ($event->type !== 'evenement') {
            return response()->json(['message' => 'Les forfaits ne s’appliquent qu’aux événements.'], 422);
        }
    }

    // Si on modifie le type/prix, re-appliquer la logique de cohérence
    $type = $data['type'] ?? $forfait->type;

    if ($type === 'famille') {
        // si on passe en famille, il faut adulte+enfant (soit envoyés, soit déjà existants)
        $prixAdulte = array_key_exists('prix_adulte', $data) ? $data['prix_adulte'] : $forfait->prix_adulte;
        $prixEnfant = array_key_exists('prix_enfant', $data) ? $data['prix_enfant'] : $forfait->prix_enfant;

        if ($prixAdulte === null || $prixEnfant === null) {
            return response()->json(['message' => 'Pour un forfait famille, les prix adulte et enfant sont obligatoires.'], 422);
        }

        $data['prix'] = null;
    } else {
        // solo/couple: prix requis
        $prix = array_key_exists('prix', $data) ? $data['prix'] : $forfait->prix;
        if ($prix === null) {
            return response()->json(['message' => 'Le prix est obligatoire pour les forfaits solo et couple.'], 422);
        }

        $data['prix_adulte'] = null;
        $data['prix_enfant'] = null;
    }

    // cast actif
    if (array_key_exists('actif', $data)) {
        $data['actif'] = (int) $data['actif'];
    }

    $forfait->update($data);

    return response()->json($forfait->fresh()->load('event'));
}


    public function destroy(Forfait $forfait)
    {
        $forfait->delete();
        return response()->noContent();
    }
}
