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
use App\Http\Controllers\API\ActivityLogController;
use App\Http\Controllers\API\AvoirController;
use App\Http\Controllers\API\NotificationController;
use App\Http\Controllers\API\AccessRequestController;

// =========================
// Public
// =========================
Route::post('login', [AuthController::class, 'login']);
Route::post('password/forget', [AuthController::class, 'sendResetLink']);
Route::post('password/reset', [AuthController::class, 'resetPassword']);

// Demande d'accès depuis la landing page (rate-limited par IP côté controller)
Route::post('access-requests', [AccessRequestController::class, 'store']);

// ⚠️ refresh devrait être dans auth:sanctum (sinon $request->user() = null)
Route::middleware(['auth:sanctum'])->post('refresh', [AuthController::class, 'refresh']);


// =========================
// Authenticated
// =========================
Route::middleware(['auth:sanctum'])->group(function () {

    // session user
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('password/change', [AuthController::class, 'changePassword']);
    Route::put('profile', [AuthController::class, 'updateProfile']);

    // Sessions actives (Phase 3 sécurité)
    Route::get('me/sessions',          [AuthController::class, 'sessions']);
    Route::delete('me/sessions/{id}',  [AuthController::class, 'revokeSession']);
    Route::delete('me/sessions',       [AuthController::class, 'revokeAllOtherSessions']);

    // Notifications (in-app)
    Route::get('notifications',                   [NotificationController::class, 'index']);
    Route::get('notifications/unread-count',      [NotificationController::class, 'unreadCount']);
    Route::post('notifications/{id}/read',        [NotificationController::class, 'markAsRead']);
    Route::post('notifications/mark-all-read',    [NotificationController::class, 'markAllAsRead']);
    Route::delete('notifications/{id}',           [NotificationController::class, 'destroy']);

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
    Route::get('reservations/export', [ReservationController::class, 'export']);
    Route::apiResource('reservations', ReservationController::class);
    Route::post('reservations/{reservation}/confirmer', [ReservationController::class, 'confirmer']);
    Route::post('reservations/{reservation}/annuler', [ReservationController::class, 'annuler']);
    Route::post('reservations/{reservation}/encaisser', [ReservationController::class, 'encaisser']);

    // Pénalités sur réservations (Phase 2 Niveau 2)
    Route::get('reservations/{reservation}/penalites',  [ReservationController::class, 'penaliteIndex']);
    Route::post('reservations/{reservation}/penalize',  [ReservationController::class, 'penalize']);
    Route::delete('penalites/{penalite}',               [ReservationController::class, 'penaliteDestroy']);
    Route::get('reservations/{reservation}/devis-pdf', [ReservationController::class, 'devisPdf']);

    // Factures + PDF
    Route::get('factures', [FactureController::class, 'index']);
    Route::post('factures', [FactureController::class, 'storeStandalone']);
    Route::get('factures/{facture}', [FactureController::class, 'show']);
    Route::post('reservations/{reservation}/factures', [FactureController::class, 'store']);
    Route::post('factures/{facture}/emettre', [FactureController::class, 'emettre']);
    Route::post('factures/{facture}/annuler', [FactureController::class, 'annulerFacture']);
    Route::get('factures/{facture}/pdf', [FactureController::class, 'pdf']);
    Route::post('factures/{facture}/pdf', [FactureController::class, 'pdfStream']);
    Route::delete('/factures/{facture}', [FactureController::class, 'destroy']);

    Route::apiResource('depenses', DepenseController::class);

    // Avoirs clients (wallet / crédit prépayé)
    Route::get('avoirs', [AvoirController::class, 'index']);
    Route::post('avoirs', [AvoirController::class, 'store']);
    Route::delete('avoirs/{avoir}', [AvoirController::class, 'destroy']);
    Route::get('clients/{client}/avoirs', [AvoirController::class, 'clientHistory']);
    Route::get('clients/{client}/solde-avoir', [AvoirController::class, 'solde']);


    // Paiements
    Route::get('dashboard', [DashboardController::class, 'index']);
    Route::get('paiements',                        [PaiementController::class, 'index']);
    Route::post('factures/{facture}/paiements',    [PaiementController::class, 'store']);
    Route::put('paiements/{paiement}',             [PaiementController::class, 'update']);
    Route::delete('paiements/{paiement}',          [PaiementController::class, 'destroy']);

    // =========================
    // Admin only
    // =========================
    Route::middleware('role:admin')->group(function () {

        // Fournisseurs
        Route::apiResource('fournisseurs', FournisseurController::class);

        // Users (admin-only CRUD)
        Route::get('users/export',                 [UserController::class, 'export']);
        Route::post('users/bulk',                  [UserController::class, 'bulk']);
        Route::apiResource('users', UserController::class);
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword']);
        Route::get('users/{user}/stats',           [UserController::class, 'stats']);

        // Logs d'activité
        Route::get('activity-logs', [ActivityLogController::class, 'index']);

        // (Optionnel) si tu veux forcer Forfaits admin-only :
        // Route::apiResource('forfaits', ForfaitController::class);
    });
});
