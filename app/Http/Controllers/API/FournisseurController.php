<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Fournisseur;
use Illuminate\Http\Request;

class FournisseurController extends Controller
{
    /**
     * Display a listing of the suppliers.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 10);

        $q = Fournisseur::query()->orderByDesc('id');

        if ($search = $request->get('search')) {
            $q->where(function ($qq) use ($search) {
                $qq->where('nom', 'like', "%$search%")
                   ->orWhere('email', 'like', "%$search%")
                   ->orWhere('telephone', 'like', "%$search%");
            });
        }

        return $q->paginate($perPage);
    }

    /**
     * Store a newly created supplier in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        // Validation des données entrées
        $validatedData = $request->validate([
            'nom' => 'required|string|max:255',
            'email' => 'required|email|unique:fournisseurs,email', // Vérifie l'unicité de l'email
            'telephone' => 'nullable|string|max:15',
            'site_web' => 'nullable|string|max:255',
            'description' => 'nullable|string',
        ]);

        // Création du fournisseur
        $fournisseur = Fournisseur::create($validatedData);

        // Retourne une réponse avec le fournisseur créé
        return response()->json([
            'message' => 'Fournisseur créé avec succès',
            'fournisseur' => $fournisseur
        ], 201);
    }

    /**
     * Display the specified supplier.
     *
     * @param  \App\Models\Fournisseur  $fournisseur
     * @return \Illuminate\Http\Response
     */
    public function show(Fournisseur $fournisseur)
    {
        // Retourne les informations du fournisseur
        return response()->json($fournisseur);
    }

    /**
     * Update the specified supplier in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Fournisseur  $fournisseur
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Fournisseur $fournisseur)
    {
        // Validation des données entrées
        $validatedData = $request->validate([
            'nom' => 'required|string|max:255',
            'email' => 'required|email|unique:fournisseurs,email,' . $fournisseur->id, // Ignorer l'email du fournisseur actuel
            'telephone' => 'nullable|string|max:15',
            'site_web' => 'nullable|string|max:255',
            'description' => 'nullable|string',
        ]);

        // Mise à jour du fournisseur
        $fournisseur->update($validatedData);

        // Retourner la réponse avec le fournisseur mis à jour
        return response()->json([
            'message' => 'Fournisseur mis à jour avec succès',
            'fournisseur' => $fournisseur
        ], 200);
    }

    /**
     * Remove the specified supplier from storage.
     *
     * @param  \App\Models\Fournisseur  $fournisseur
     * @return \Illuminate\Http\Response
     */
    public function destroy(Fournisseur $fournisseur)
    {
        // Suppression du fournisseur
        $fournisseur->delete();

        return response()->json([
            'message' => 'Fournisseur supprimé avec succès'
        ], 200);
    }
}