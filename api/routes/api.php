<?php

use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\FileLinkController;
use App\Http\Controllers\Api\V1\AutomationController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\ErpController;
use App\Http\Controllers\Api\V1\ErpPushController;
use App\Http\Controllers\Api\V1\GoodsReceiptController;
use App\Http\Controllers\Api\V1\ImportController;
use App\Http\Controllers\Api\V1\InboundEmailController;
use App\Http\Controllers\Api\V1\IntegrationController;
use App\Http\Controllers\Api\V1\MatchController;
use App\Http\Controllers\Api\V1\MockErpController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\ReviewQueueController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\VendorController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('health', HealthController::class)->middleware('throttle:60,1')->name('health');
Route::get('docs', fn () => response(view('api-docs')->render()))->name('api.docs');
Route::get('docs/openapi.yaml', fn () => response()->file(base_path('docs/openapi.yaml'), ['Content-Type' => 'application/yaml']))->name('api.docs.spec');

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('auth/demo', [AuthController::class, 'demo'])->middleware('throttle:login');
    // Short-lived signed link to an original or a page image (the signature is the credential).
    Route::get('files/{uuid}', [FileLinkController::class, 'show'])->middleware(['signed', 'throttle:api'])->name('files.signed');
    Route::post('inbound/email', InboundEmailController::class)->middleware('throttle:60,1');
    // Signed, expiring link to an original file (sent in webhook payloads).
    Route::get('erp/files/{uuid}', [ErpController::class, 'file'])->middleware('signed')->name('erp.file');

    Route::middleware(['auth:sanctum', 'tenant', 'throttle:api', 'demo.readonly'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::get('dashboard', [DashboardController::class, 'stats']);
        Route::get('activity', [DashboardController::class, 'activity']);
        Route::get('analytics', [AnalyticsController::class, 'index']);
        Route::get('analytics/export', [AnalyticsController::class, 'export'])->middleware('throttle:exports');

        Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
        Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
        Route::get('documents/{document}/file', [DocumentController::class, 'file'])->name('documents.file');
        Route::get('documents/{document}/file-url', [FileLinkController::class, 'link'])->name('documents.file-url');
        Route::get('documents/{document}/pages', [DocumentController::class, 'pages'])->name('documents.pages');
        Route::get('documents/{document}/pages/{page}/ocr', [DocumentController::class, 'pageOcr'])->whereNumber('page')->name('documents.page-ocr');
        Route::get('documents/{document}/pages/{page}/image', [DocumentController::class, 'pageImage'])->whereNumber('page')->name('documents.page-image');
        Route::get('documents/{document}/events', [DocumentController::class, 'events'])->name('documents.events');
        Route::get('documents/{document}/matches', [DocumentController::class, 'matches'])->name('documents.matches');
        Route::get('matches', [MatchController::class, 'index']);
        Route::get('documents/{document}/match', [MatchController::class, 'show'])->name('documents.match');
        Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
        Route::get('goods-receipts', [GoodsReceiptController::class, 'index']);
        Route::get('imports/{kind}/template', [ImportController::class, 'template']);
        Route::get('settings/matching', [SettingsController::class, 'matching']);
        Route::get('review-queue', [ReviewQueueController::class, 'index']);
        Route::get('users', [UserController::class, 'index']);
        Route::get('vendors', [VendorController::class, 'index']);
        Route::get('vendors/{vendor}/hints', [VendorController::class, 'hints']);
        Route::get('purchase-orders', [PurchaseOrderController::class, 'index']);
        Route::get('erp-pushes', [ErpPushController::class, 'index']);
        Route::get('erp-pushes/{push}', [ErpPushController::class, 'show']);
        Route::get('erp/connections', [ErpController::class, 'index']);
        Route::get('erp/sync-runs', [ErpController::class, 'runs']);
        Route::get('settings/automation', [AutomationController::class, 'show']);
        Route::get('settings/providers', [SettingsController::class, 'providers']);
        Route::get('settings/approvals', [SettingsController::class, 'approvals']);
        // The Mock ERP's own screens (a stand-in for the ERP's UI).
        Route::get('mock-erp/bills', [MockErpController::class, 'bills']);
        Route::get('mock-erp/bills/{bill}', [MockErpController::class, 'showBill']);
        Route::get('mock-erp/bills/{bill}/attachment', [MockErpController::class, 'attachment']);
        Route::get('mock-erp/purchase-orders', [MockErpController::class, 'purchaseOrders']);
        Route::get('mock-erp/vendors', [MockErpController::class, 'vendors']);
        Route::get('integrations', [IntegrationController::class, 'index']);

        // Intuit redirects the browser here after consent (session cookie identifies the user).
        Route::get('erp/quickbooks/callback', [ErpController::class, 'quickbooksCallback'])->middleware('role:admin');

        Route::middleware('role:admin,reviewer')->group(function () {
            Route::post('documents', [DocumentController::class, 'store'])->middleware('throttle:uploads')->name('documents.store');
            Route::patch('documents/{document}', [DocumentController::class, 'update'])->name('documents.update');
            Route::patch('documents/{document}/extraction', [DocumentController::class, 'extraction'])->name('documents.extraction');
            Route::post('documents/{document}/reject', [DocumentController::class, 'reject'])->name('documents.reject');
            Route::post('documents/{document}/reopen', [DocumentController::class, 'reopen'])->name('documents.reopen');
            Route::post('documents/{document}/reassign', [DocumentController::class, 'reassign'])->name('documents.reassign');
            Route::post('documents/{document}/approve', [DocumentController::class, 'approve'])->name('documents.approve');
            Route::post('documents/{document}/retry', [DocumentController::class, 'retry'])->name('documents.retry');
            Route::post('documents/{document}/override-match', [DocumentController::class, 'overrideMatch'])->name('documents.override-match');
            Route::patch('documents/{document}/vendor', [DocumentController::class, 'vendor'])->name('documents.vendor');
            // Matching actions. Accepting a variance is additionally checked against the company's accept_role.
            Route::post('documents/{document}/match/accept', [MatchController::class, 'acceptAll'])->name('documents.match.accept');
            Route::post('documents/{document}/match/lines/{line}/accept', [MatchController::class, 'acceptLine'])->name('documents.match.accept-line');
            Route::post('documents/{document}/match/credit-note', [MatchController::class, 'creditNote'])->name('documents.match.credit-note');
            Route::post('documents/{document}/match/rematch', [MatchController::class, 'rematch'])->name('documents.match.rematch');
            Route::post('documents/{document}/hold', [MatchController::class, 'hold'])->name('documents.hold');
            Route::post('documents/{document}/release', [MatchController::class, 'release'])->name('documents.release');
            Route::post('vendors', [VendorController::class, 'store']);
            Route::patch('vendors/{vendor}', [VendorController::class, 'update']);
            Route::delete('vendors/{vendor}/hints/{hint}', [VendorController::class, 'destroyHint']);
            Route::post('erp/sync', [ErpController::class, 'sync']);
            Route::post('erp/connections/{connector}/test', [ErpController::class, 'test']);
            Route::post('mock-erp/bills/{bill}/post', [MockErpController::class, 'postBill']);
            Route::post('mock-erp/bills/{bill}/pay', [MockErpController::class, 'payBill']);
            Route::post('mock-erp/purchase-orders/{purchaseOrder}/receive', [MockErpController::class, 'receive']);
        });

        Route::middleware('role:admin')->group(function () {
            Route::delete('documents/{document}', [DocumentController::class, 'destroy']);
            Route::get('audit-log/export', [AuditLogController::class, 'export'])->middleware('throttle:exports');
            Route::delete('vendors/{vendor}', [VendorController::class, 'destroy']);
            Route::post('integrations/erp/test', [IntegrationController::class, 'testErp']);
            Route::post('imports/{kind}', [ImportController::class, 'store'])->whereIn('kind', ['vendors', 'purchase_orders', 'goods_receipts']);
            Route::patch('settings/matching', [SettingsController::class, 'updateMatching']);
            Route::patch('settings/automation', [AutomationController::class, 'update']);
            Route::patch('settings/providers', [SettingsController::class, 'updateProviders']);
            Route::patch('settings/approvals', [SettingsController::class, 'updateApprovals']);
            Route::patch('users/{user}', [UserController::class, 'update']);
            Route::post('erp/connections/{connector}/activate', [ErpController::class, 'activate']);
            Route::patch('erp/connections/{connector}/mapping', [ErpController::class, 'mapping']);
            Route::patch('erp/connections/{connector}/settings', [ErpController::class, 'settings']);
            Route::get('erp/quickbooks/connect', [ErpController::class, 'quickbooksConnect']);
            Route::post('erp/quickbooks/disconnect', [ErpController::class, 'quickbooksDisconnect']);
        });
    });
});
