<?php

use App\Http\Controllers\Api\Admin\CommissionController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\PaymentVerificationController;
use App\Http\Controllers\Api\Admin\RefundController;
use App\Http\Controllers\Api\Admin\ReportController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConfigController;
use App\Http\Controllers\Api\ContributionController;
use App\Http\Controllers\Api\DisputeController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\InstallmentPurchaseController;
use App\Http\Controllers\Api\Merchant\DashboardController as ApiMerchantDashboardController;
use App\Http\Controllers\Api\Merchant\InstallmentOrderController as ApiMerchantInstallmentOrderController;
use App\Http\Controllers\Api\Merchant\OrderController as ApiMerchantOrderController;
use App\Http\Controllers\Api\Merchant\ProductController as ApiMerchantProductController;
use App\Http\Controllers\Api\MerchantController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\TontineController;
use Illuminate\Support\Facades\Route;

// --- Webhook Mobile Money (public, protégé par signature) ---
Route::post('/webhooks/mobile-money', [PaymentController::class, 'webhook'])->middleware('throttle:30,1');

// --- Authentification ---
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

// --- Config publique (taux de commission, canaux de paiement) ---
Route::get('/config', [ConfigController::class, 'index']);

// --- Mot de passe oublié ---
Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])->middleware('throttle:5,1');
Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:5,1');

// --- Catalogue produits + profils commerçants (public) ---
Route::get('/produits', [ProductController::class, 'index']);
Route::get('/produits/{product}', [ProductController::class, 'show']);
Route::get('/commercants/{merchant}', [MerchantController::class, 'show']);

// --- Tontines (public en lecture, comme le catalogue) ---
Route::get('/tontines', [TontineController::class, 'index']);
Route::get('/tontines/{tontine}', [TontineController::class, 'show']);

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    // Liste des sessions actives (jamais la valeur des tokens) pour permettre
    // de repérer une connexion inconnue. Endpoint purement informatif.
    Route::get('/sessions', [AuthController::class, 'sessions']);
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend']);

    // --- Profil ---
    Route::get('/profil', [ProfileController::class, 'show']);
    Route::put('/profil', [ProfileController::class, 'update']);

    // --- Notifications ---
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{notificationId}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);

    Route::middleware('verified')->group(function () {
        Route::middleware('can-create-tontine')->post('/tontines', [TontineController::class, 'store']);

        // Modification : réservée au créateur (ou à l'admin), et refusée par le
        // contrôleur dès que la tontine a démarré. Volontairement hors du
        // middleware « can-create-tontine » : il filtroit la CRÉATION, alors
        // qu'un créateur dont le statut commerçant a changé doit pouvoir
        // corriger une tontine qu'il a déjà ouverte.
        Route::put('/tontines/{tontine}', [TontineController::class, 'update']);

        Route::post('/tontines/{tontine}/join', [TontineController::class, 'join']);
        Route::post('/tontines/{tontine}/review', [MerchantController::class, 'storeReview']);
        Route::get('/tontines/{tontine}/disputes', [DisputeController::class, 'index']);
        Route::post('/tontines/{tontine}/disputes', [DisputeController::class, 'store']);

        // --- Cotisations ---
        // Un client ne peut pas marteler ses paiements ni ses codes de transfert :
        // le rate limiting protège l'API des abus sans gêner l'usage normal.
        Route::get('/contributions', [ContributionController::class, 'index']);
        Route::post('/contributions/{contribution}/pay', [ContributionController::class, 'pay'])->middleware('throttle:20,1');
        Route::post('/contributions/{contribution}/soumettre-code', [ContributionController::class, 'submitTransferCode'])->middleware('throttle:10,1');
        Route::post('/contributions/{contribution}/initiate-payment', [PaymentController::class, 'initiate'])->middleware('throttle:20,1');

        // --- Achats par tranches ---
        Route::post('/produits/{product}/tranches/apercu', [InstallmentPurchaseController::class, 'preview']);
        Route::post('/produits/{product}/tranches', [InstallmentPurchaseController::class, 'store']);
        Route::get('/mes-achats', [InstallmentPurchaseController::class, 'index']);
        Route::get('/mes-achats/{purchase}', [InstallmentPurchaseController::class, 'show']);
        Route::post('/tranches/{installment}/pay', [InstallmentPurchaseController::class, 'pay'])->middleware('throttle:20,1');
        Route::post('/tranches/{installment}/soumettre-code', [InstallmentPurchaseController::class, 'submitTransferCode'])->middleware('throttle:10,1');
    });

    // --- Espace commerçant ---
    Route::middleware(['verified', 'merchant'])->prefix('merchant')->group(function () {
        Route::get('/dashboard', [ApiMerchantDashboardController::class, 'index']);

        Route::get('/products', [ApiMerchantProductController::class, 'index']);
        Route::post('/products', [ApiMerchantProductController::class, 'store']);
        Route::put('/products/{product}', [ApiMerchantProductController::class, 'update']);
        Route::delete('/products/{product}', [ApiMerchantProductController::class, 'destroy']);
        Route::patch('/products/{product}/stock', [ApiMerchantProductController::class, 'updateStock']);
        Route::delete('/products/media/{media}', [ApiMerchantProductController::class, 'destroyMedia']);

        Route::get('/orders', [ApiMerchantOrderController::class, 'index']);
        Route::post('/orders/{tontineMember}/deliver', [ApiMerchantOrderController::class, 'validateDelivery']);

        Route::get('/installment-orders', [ApiMerchantInstallmentOrderController::class, 'index']);
        Route::post('/installment-orders/{purchase}/deliver', [ApiMerchantInstallmentOrderController::class, 'validateDelivery']);
    });
});

// --- Espace admin (API) ---
Route::middleware(['auth:sanctum', 'active', 'admin'])->prefix('admin')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);

    Route::get('/merchants', [App\Http\Controllers\Api\Admin\MerchantController::class, 'index']);
    Route::post('/merchants/{merchant}/approve', [App\Http\Controllers\Api\Admin\MerchantController::class, 'approve']);
    Route::post('/merchants/{merchant}/reject', [App\Http\Controllers\Api\Admin\MerchantController::class, 'reject']);

    Route::get('/tontines', [App\Http\Controllers\Api\Admin\TontineController::class, 'index']);
    Route::post('/tontines/{tontine}/cancel', [App\Http\Controllers\Api\Admin\TontineController::class, 'cancel']);
    Route::delete('/tontines/{tontine}', [App\Http\Controllers\Api\Admin\TontineController::class, 'destroy']);

    Route::get('/disputes', [App\Http\Controllers\Api\Admin\DisputeController::class, 'index']);
    Route::get('/disputes/{dispute}', [App\Http\Controllers\Api\Admin\DisputeController::class, 'show']);
    Route::post('/disputes/{dispute}/resolve', [App\Http\Controllers\Api\Admin\DisputeController::class, 'resolve']);

    Route::get('/refunds', [RefundController::class, 'index']);
    Route::post('/refunds/{refund}/process', [RefundController::class, 'process']);

    Route::get('/products', [App\Http\Controllers\Api\Admin\ProductController::class, 'index']);
    Route::post('/products/{product}/archive', [App\Http\Controllers\Api\Admin\ProductController::class, 'archive']);
    Route::delete('/products/{product}', [App\Http\Controllers\Api\Admin\ProductController::class, 'destroy']);

    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users/{user}/block', [UserController::class, 'block']);
    Route::post('/users/{user}/unblock', [UserController::class, 'unblock']);
    Route::post('/users/{user}/verify-email', [UserController::class, 'verifyEmail']);
    Route::post('/users/{user}/unverify-email', [UserController::class, 'unverifyEmail']);
    Route::post('/users/{user}/resend-verification', [UserController::class, 'resendVerification']);

    Route::get('/reports', [ReportController::class, 'index']);
    Route::get('/reports/export', [ReportController::class, 'export']);

    Route::get('/commissions', [CommissionController::class, 'index']);
    Route::post('/commissions/rate', [CommissionController::class, 'updateRate']);
    Route::get('/payments', [PaymentVerificationController::class, 'index']);
    Route::post('/payments/contribution/{contribution}/accept', [PaymentVerificationController::class, 'acceptContribution']);
    Route::post('/payments/contribution/{contribution}/reject', [PaymentVerificationController::class, 'rejectContribution']);
    Route::post('/payments/installment/{installment}/accept', [PaymentVerificationController::class, 'acceptInstallment']);
    Route::post('/payments/installment/{installment}/reject', [PaymentVerificationController::class, 'rejectInstallment']);
});
