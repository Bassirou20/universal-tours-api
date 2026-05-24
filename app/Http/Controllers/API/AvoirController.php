<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientAvoir;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AvoirController extends Controller
{
    // ─── Liste paginée ────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $q = ClientAvoir::with(['client:id,prenom,nom,email', 'user:id,nom,prenom', 'facture:id,numero'])
            ->orderByDesc('created_at');

        if ($clientId = $request->get('client_id')) {
            $q->where('client_id', (int) $clientId);
        }

        if ($type = $request->get('type')) {
            $q->where('type', $type);
        }

        if ($search = $request->get('search')) {
            $q->whereHas('client', function ($sub) use ($search) {
                $sub->where('nom', 'like', "%$search%")
                    ->orWhere('prenom', 'like', "%$search%")
                    ->orWhere('email', 'like', "%$search%");
            });
        }

        return $q->paginate(15);
    }

    // ─── Solde d'un client ────────────────────────────────────────────────────

    public function solde(Client $client)
    {
        $solde = ClientAvoir::soldeForClient($client->id);
        return response()->json([
            'client_id' => $client->id,
            'solde'     => $solde,
        ]);
    }

    // ─── Historique d'un client ───────────────────────────────────────────────

    public function clientHistory(Client $client)
    {
        $avoirs = ClientAvoir::with(['user:id,nom,prenom', 'facture:id,numero'])
            ->where('client_id', $client->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'solde'  => ClientAvoir::soldeForClient($client->id),
            'avoirs' => $avoirs,
        ]);
    }

    // ─── Créer un dépôt ou une utilisation ───────────────────────────────────

    public function store(Request $request)
    {
        $data = $request->validate([
            'client_id'  => 'required|exists:clients,id',
            'type'       => 'required|in:depot,utilisation',
            'montant'    => 'required|numeric|min:1',
            'facture_id' => 'nullable|exists:factures,id',
            'reference'  => 'nullable|string|max:100',
            'notes'      => 'nullable|string|max:1000',
            'date_avoir' => 'nullable|date',
        ]);

        return DB::transaction(function () use ($data, $request) {
            $clientId = (int) $data['client_id'];

            // Calcul du solde courant (verrouillé pour éviter la concurrence)
            $depots       = ClientAvoir::where('client_id', $clientId)->where('type', 'depot')->lockForUpdate()->sum('montant');
            $utilisations = ClientAvoir::where('client_id', $clientId)->where('type', 'utilisation')->lockForUpdate()->sum('montant');
            $soldeCourant = max(0, (float) $depots - (float) $utilisations);

            if ($data['type'] === 'utilisation') {
                if ((float) $data['montant'] > $soldeCourant) {
                    return response()->json([
                        'message'        => "Solde insuffisant. Le client dispose de " . number_format($soldeCourant, 2) . " XOF.",
                        'solde_disponible' => $soldeCourant,
                    ], 422);
                }
                $soldeApres = $soldeCourant - (float) $data['montant'];
            } else {
                $soldeApres = $soldeCourant + (float) $data['montant'];
            }

            $prefix = $data['type'] === 'depot' ? 'AV-DEP' : 'AV-UTL';
            $autoRef = $prefix . '-' . now()->format('ymd') . '-' . strtoupper(substr(uniqid(), -4));

            $avoir = ClientAvoir::create([
                'client_id'  => $clientId,
                'user_id'    => $request->user()?->id,
                'facture_id' => $data['facture_id'] ?? null,
                'type'       => $data['type'],
                'montant'    => (float) $data['montant'],
                'solde_apres'=> $soldeApres,
                'reference'  => !empty($data['reference']) ? $data['reference'] : $autoRef,
                'notes'      => $data['notes'] ?? null,
                'date_avoir' => $data['date_avoir'] ?? now()->toDateString(),
            ]);

            $avoir->load(['client:id,prenom,nom', 'user:id,nom,prenom', 'facture:id,numero']);

            return response()->json([
                'avoir'      => $avoir,
                'solde_apres'=> $soldeApres,
            ], 201);
        });
    }

    // ─── Supprimer (admin uniquement) ─────────────────────────────────────────

    public function destroy(Request $request, ClientAvoir $avoir)
    {
        if ($request->user()?->role !== 'admin') {
            return response()->json(['message' => 'Seul un administrateur peut supprimer un avoir.'], 403);
        }

        $avoir->delete();
        return response()->noContent();
    }
}
