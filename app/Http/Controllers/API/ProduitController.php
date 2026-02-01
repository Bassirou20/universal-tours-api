<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProduitRequest;
use App\Http\Requests\UpdateProduitRequest;
use App\Models\Produit;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\ProduitsImport;
use App\Exports\ProduitsExport;
// use App\Http\Requests\UpdateProduitRequest;

class ProduitController extends Controller
{
    public function index(Request $request)
    {
        $q = Produit::query();
        if ($type = $request->get('type')) {
            $q->where('type', $type);
        }
        return $q->orderBy('nom')->paginate(20);
    }

    public function store(Request $request)
    {
        // dd('produit-store-called');
        // Validation des données du produit en utilisant les règles définies dans la request
        $validatedData = $request->validate([
            'type' => 'required|in:billet_avion,hotel,voiture,evenement',
            'nom' => 'required|string|max:150',
            'description' => 'nullable|string',
            'prix_base' => 'required|numeric|min:0',
            // 'devise' => 'required|string|max:10',
            'actif' => 'boolean'
        ]);

        // Création du produit avec les données validées
        $produit = Produit::create($validatedData);

        // Retourner une réponse avec le produit créé
        return response()->json([
            'message' => 'Produit créé avec succès',
            'produit' => $produit
        ], 201);
    }
    public function show(Produit $produit)
    {
        return $produit;
    }

    public function update(UpdateProduitRequest $request, Produit $produit)
{
    $data = $request->validated();
    $produit->update($data);
    return response()->json($produit);
}

    public function destroy(Produit $produit)
    {
        $produit->delete();
        return response()->noContent();
    }

    public function import(Request $request)
    {
        $request->validate(['excel' => 'required|file|mimes:xlsx,csv,xls']);
        Excel::import(new ProduitsImport, $request->file('excel'));
        return response()->json(['message' => 'Import terminé']);
    }

    public function export()
    {
        return Excel::download(new ProduitsExport, 'produits.xlsx');
    }
}
