<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Models\Client;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\ClientsImport;
use App\Exports\ClientsExport;
use App\Http\Requests\ImportClientsExcelRequest;
use Symfony\Component\HttpFoundation\Response;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $q = Client::query();
        if ($s = $request->get('search')) {
            $q->where(function($w) use ($s) {
                $w->where('nom','like',"%$s%" )
                  ->orWhere('prenom','like',"%$s%" )
                  ->orWhere('email','like',"%$s%" )
                  ->orWhere('telephone','like',"%$s%" );
            });
        }
        return $q->orderBy('nom')->paginate(10);
    }

    public function store(StoreClientRequest $request)
    {
        $client = Client::create($request->validated());
        return response()->json($client, 201);
    }

    public function show(Client $client)
    {
        return $client;
    }

    public function update(UpdateClientRequest $request, Client $client)
    {
        $client->update($request->validated());
        return $client;
    }

    public function destroy(Client $client)
    {
        $client->delete();
        return response()->noContent();
    }

    public function import(Request $request)
    {
        $request->validate(['excel' => 'required|file|mimes:xlsx,csv,xls']);
        Excel::import(new ClientsImport, $request->file('excel'));
        return response()->json(['message' => 'Import terminé']);
    }

    public function export()
    {
        return Excel::download(new ClientsExport, 'clients.xlsx');
    }

    public function reservations(Client $client, Request $request)
{
    $q = $client->reservations()
        ->with([
            'produit',
            'forfait',
            'participants',
            'facture.paiements',
        ])
        ->orderByDesc('created_at');

    // Filtres optionnels
    if ($request->filled('statut')) {
        $q->where('statut', $request->input('statut'));
    }

    if ($request->filled('type')) {
        $q->where('type', $request->input('type'));
    }

    if ($request->filled('from')) {
        $q->whereDate('created_at', '>=', $request->input('from'));
    }

    if ($request->filled('to')) {
        $q->whereDate('created_at', '<=', $request->input('to'));
    }

    // Pagination
    $perPage = (int) $request->input('per_page', 20);
    $perPage = max(1, min(100, $perPage)); // limite sécurité

    return response()->json([
        'client' => $client,
        'reservations' => $q->paginate($perPage),
    ]);
}

    public function importExcel(ImportClientsExcelRequest $request)
{
    $file = $request->file('file');

    $import = new ClientsImport();

    Excel::import($import, $file);

    return response()->json([
        'message' => 'Import terminé.',
        'total_processed' => $import->inserted + $import->skipped,
        'inserted_or_updated' => $import->inserted,
        'skipped' => $import->skipped,
        'errors' => array_slice($import->errors, 0, 50), // limiter la taille de réponse
        'errors_count' => count($import->errors),
    ], Response::HTTP_OK);
}

}
