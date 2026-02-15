<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\API\DepenseController;
use App\Http\Controllers\API\ProduitController;
use App\Http\Controllers\API\ReservationController;
use App\Http\Controllers\API\FactureController;
use App\Http\Controllers\API\ForfaitController;
use App\Http\Controllers\API\PaiementController;
use App\Http\Controllers\API\FournisseurController;
use App\Http\Controllers\API\UserController;

// =========================
// Public
// =========================
Route::post('login', [AuthController::class, 'login']);
Route::post('password/forget', [AuthController::class, 'sendResetLink']);
Route::post('password/reset', [AuthController::class, 'resetPassword']);

// ⚠️ refresh devrait être dans auth:sanctum (sinon $request->user() = null)
Route::middleware(['auth:sanctum'])->post('refresh', [AuthController::class, 'refresh']);


// =========================
// Authenticated
// =========================
Route::middleware(['auth:sanctum'])->group(function () {

    // session user
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);

    // Clients
    Route::apiResource('clients', ClientController::class);
    Route::post('clients/import', [ClientController::class, 'import']);
    Route::post('clients/import-excel', [ClientController::class, 'importExcel']);
    Route::get('clients/export', [ClientController::class, 'export']);
    Route::get('clients/{client}/reservations', [ClientController::class, 'reservations']);

    // Produits
    Route::apiResource('produits', ProduitController::class);
    Route::post('produits/import', [ProduitController::class, 'import']);
    Route::get('produits/export', [ProduitController::class, 'export']);

    // Forfaits  ✅ (mets-le ici si employees doivent y accéder)
    Route::apiResource('forfaits', ForfaitController::class);

    // Réservations
    Route::apiResource('reservations', ReservationController::class);
    Route::post('reservations/{reservation}/confirmer', [ReservationController::class, 'confirmer']);
    Route::post('reservations/{reservation}/annuler', [ReservationController::class, 'annuler']);
    Route::get('reservations/{reservation}/devis-pdf', [ReservationController::class, 'devisPdf']);

    // Factures + PDF
    Route::get('factures', [FactureController::class, 'index']);
    Route::post('reservations/{reservation}/factures', [FactureController::class, 'store']); // créer facture
    Route::post('factures/{facture}/emettre', [FactureController::class, 'emettre']);
    Route::post('factures/{facture}/annuler', [FactureController::class, 'annuler']);
    Route::post('factures/{facture}/pdf', [FactureController::class, 'pdfStream']);
    Route::get('factures/{facture}/pdf', [FactureController::class, 'pdf']);

    Route::apiResource('depenses', DepenseController::class);


    // Paiements (création rattachée facture)
    Route::get('dashboard', [DashboardController::class, 'index']);
    Route::post('factures/{facture}/paiements', [PaiementController::class, 'store']);

    // =========================
    // Admin only
    // =========================
    Route::middleware('role:admin')->group(function () {

        // Fournisseurs
        Route::apiResource('fournisseurs', FournisseurController::class);

        // Users (admin-only CRUD)
        Route::apiResource('users', UserController::class);

        // (Optionnel) si tu veux forcer Forfaits admin-only :
        // Route::apiResource('forfaits', ForfaitController::class);
    });
});
