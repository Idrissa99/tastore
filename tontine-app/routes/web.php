<?php

use App\Http\Controllers\Admin\CommissionController as AdminCommissionController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\DisputeController as AdminDisputeController;
use App\Http\Controllers\Admin\MerchantController as AdminMerchantController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\RefundController as AdminRefundController;
use App\Http\Controllers\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Admin\TontineController as AdminTontineController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\ContributionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DisputeController;
use App\Http\Controllers\InstallmentPurchaseController;
use App\Http\Controllers\Merchant\DashboardController as MerchantDashboardController;
use App\Http\Controllers\Merchant\InstallmentOrderController as MerchantInstallmentOrderController;
use App\Http\Controllers\Merchant\OrderController as MerchantOrderController;
use App\Http\Controllers\Merchant\ProductController as MerchantProductController;
use App\Http\Controllers\MerchantController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TontineController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('tontines.index');
});

// --- Authentification (invités uniquement) ---
Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:5,1');

    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:5,1');

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:5,1')->name('password.email');

    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->middleware('throttle:5,1')->name('password.update');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

// --- Vérification d'email ---
Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/email/verify', EmailVerificationPromptController::class)->name('verification.notice');

    Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])->name('verification.verify');

    Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')->name('verification.send');
});

// --- Catalogue produits (public) ---
Route::get('/produits', [ProductController::class, 'index'])->name('products.show-all');
Route::get('/produits/{product}', [ProductController::class, 'show'])->name('products.show');
Route::get('/commercants/{merchant}', [MerchantController::class, 'show'])->name('merchants.show');

// --- Tontines ---
Route::get('/tontines', [TontineController::class, 'index'])->name('tontines.index');

Route::middleware(['auth', 'active', 'verified', 'can-create-tontine'])->group(function () {
    Route::get('/tontines/create', [TontineController::class, 'create'])->name('tontines.create');
    Route::post('/tontines', [TontineController::class, 'store'])->name('tontines.store');
});

Route::get('/tontines/{tontine}', [TontineController::class, 'show'])->name('tontines.show');

Route::middleware(['auth', 'active', 'verified'])->group(function () {
    Route::post('/tontines/{tontine}/join', [TontineController::class, 'join'])->name('tontines.join');

    Route::post('/contributions/{contribution}/pay', [ContributionController::class, 'pay'])->middleware('throttle:20,1')->name('contributions.pay');
    Route::get('/mes-cotisations', [ContributionController::class, 'index'])->name('contributions.index');

    Route::get('/tontines/{tontine}/disputes/create', [DisputeController::class, 'create'])->name('disputes.create');
    Route::post('/tontines/{tontine}/disputes', [DisputeController::class, 'store'])->name('disputes.store');

    Route::post('/tontines/{tontine}/review', [MerchantController::class, 'storeReview'])->name('merchants.review');

    Route::get('/produits/{product}/acheter-en-plusieurs-fois', [InstallmentPurchaseController::class, 'create'])->name('installment-purchases.create');
    Route::post('/produits/{product}/acheter-en-plusieurs-fois', [InstallmentPurchaseController::class, 'store'])->name('installment-purchases.store');
    Route::get('/mes-achats', [InstallmentPurchaseController::class, 'index'])->name('installment-purchases.index');
    Route::get('/mes-achats/{purchase}', [InstallmentPurchaseController::class, 'show'])->name('installment-purchases.show');
    Route::post('/installments/{installment}/pay', [InstallmentPurchaseController::class, 'pay'])->middleware('throttle:20,1')->name('installments.pay');
});

// --- Tableau de bord, profil, notifications ---
Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/tableau-de-bord', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/profil', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profil', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{notificationId}/read', [NotificationController::class, 'markAsRead'])->name('notifications.mark-read');
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all-read');
});

// --- Espace commerçant ---
Route::middleware(['auth', 'active', 'verified', 'merchant'])->prefix('merchant')->name('merchant.')->group(function () {
    Route::get('/dashboard', [MerchantDashboardController::class, 'index'])->name('dashboard');

    Route::get('/products', [MerchantProductController::class, 'index'])->name('products.index');
    Route::get('/products/create', [MerchantProductController::class, 'create'])->name('products.create');
    Route::post('/products', [MerchantProductController::class, 'store'])->name('products.store');
    Route::get('/products/{product}/edit', [MerchantProductController::class, 'edit'])->name('products.edit');
    Route::put('/products/{product}', [MerchantProductController::class, 'update'])->name('products.update');
    Route::delete('/products/{product}', [MerchantProductController::class, 'destroy'])->name('products.destroy');
    Route::patch('/products/{product}/stock', [MerchantProductController::class, 'updateStock'])->name('products.stock');
    Route::delete('/products/media/{media}', [MerchantProductController::class, 'destroyMedia'])->name('products.media.destroy');

    Route::get('/orders', [MerchantOrderController::class, 'index'])->name('orders.index');
    Route::post('/orders/{tontineMember}/deliver', [MerchantOrderController::class, 'validateDelivery'])->name('orders.deliver');

    Route::get('/installment-orders', [MerchantInstallmentOrderController::class, 'index'])->name('installment-orders.index');
    Route::post('/installment-orders/{purchase}/deliver', [MerchantInstallmentOrderController::class, 'validateDelivery'])->name('installment-orders.deliver');
});

// --- Espace admin ---
// 'active' manque ici alors qu'il est présent sur TOUS les autres groupes web :
// un administrateur suspendu (is_blocked) pouvait donc continuer à administrer
// par cette surface, y compris la suppression définitive d'une tontine. L'API
// (/admin) l'exigeait déjà ; l'aligner ici ferme l'écart entre les deux.
Route::middleware(['auth', 'active', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');

    Route::get('/merchants', [AdminMerchantController::class, 'index'])->name('merchants.index');
    Route::post('/merchants/{merchant}/approve', [AdminMerchantController::class, 'approve'])->name('merchants.approve');
    Route::post('/merchants/{merchant}/reject', [AdminMerchantController::class, 'reject'])->name('merchants.reject');

    Route::get('/tontines', [AdminTontineController::class, 'index'])->name('tontines.index');
    Route::post('/tontines/{tontine}/cancel', [AdminTontineController::class, 'cancel'])->name('tontines.cancel');
    Route::delete('/tontines/{tontine}', [AdminTontineController::class, 'destroy'])->name('tontines.destroy');

    Route::get('/disputes', [AdminDisputeController::class, 'index'])->name('disputes.index');
    Route::get('/disputes/{dispute}', [AdminDisputeController::class, 'show'])->name('disputes.show');
    Route::post('/disputes/{dispute}/resolve', [AdminDisputeController::class, 'resolve'])->name('disputes.resolve');

    Route::get('/refunds', [AdminRefundController::class, 'index'])->name('refunds.index');
    Route::post('/refunds/{refund}/process', [AdminRefundController::class, 'process'])->name('refunds.process');

    Route::get('/products', [AdminProductController::class, 'index'])->name('products.index');
    Route::post('/products/{product}/archive', [AdminProductController::class, 'archive'])->name('products.archive');
    Route::delete('/products/{product}', [AdminProductController::class, 'destroy'])->name('products.destroy');

    Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
    Route::post('/users/{user}/block', [AdminUserController::class, 'block'])->name('users.block');
    Route::post('/users/{user}/unblock', [AdminUserController::class, 'unblock'])->name('users.unblock');
    Route::post('/users/{user}/verify-email', [AdminUserController::class, 'verifyEmail'])->name('users.verify-email');
    Route::post('/users/{user}/unverify-email', [AdminUserController::class, 'unverifyEmail'])->name('users.unverify-email');
    Route::post('/users/{user}/resend-verification', [AdminUserController::class, 'resendVerification'])->name('users.resend-verification');

    Route::get('/reports', [AdminReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/export', [AdminReportController::class, 'export'])->name('reports.export');

    Route::get('/commissions', [AdminCommissionController::class, 'index'])->name('commissions.index');
    Route::post('/commissions/rate', [AdminCommissionController::class, 'updateRate'])->name('commissions.update-rate');
});
