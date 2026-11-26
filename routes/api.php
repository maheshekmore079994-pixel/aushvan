<?php

use App\Enums\Permission;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BackupController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DayController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\PurchaseReturnController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\SaleReturnController;
use App\Http\Controllers\Api\V1\StockController;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public
    Route::get('app/status', [AuthController::class, 'status']);
    Route::post('setup', [AuthController::class, 'setup'])->middleware('throttle:10,1');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:30,1');

    // Signed-in users
    Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::put('auth/locale', [AuthController::class, 'updateLocale']);

        Route::get('store', [StoreController::class, 'show']);
        Route::put('store', [StoreController::class, 'update'])
            ->middleware('can:'.Permission::SETTINGS_MANAGE);

        // Products and stock. Reading is open to every signed-in user; changes are permission-checked.
        Route::get('catalog/options', [ProductController::class, 'options']);
        Route::get('products', [ProductController::class, 'index']);
        Route::get('products/similar', [ProductController::class, 'similar']);
        Route::get('products/export', [ProductController::class, 'export'])->middleware('throttle:10,1');
        Route::get('products/labels', [ProductController::class, 'labels']);
        Route::get('products/{product}', [ProductController::class, 'show']);
        Route::post('products/{product}/barcode', [ProductController::class, 'makeBarcode'])
            ->middleware('can:'.Permission::PRODUCTS_MANAGE);
        Route::get('products/{product}/movements', [StockController::class, 'movements']);
        Route::post('products', [ProductController::class, 'store'])->middleware('idempotent'); // ProductRequest authorises
        Route::put('products/{product}', [ProductController::class, 'update']);

        Route::get('stock/summary', [StockController::class, 'summary']);
        Route::get('stock/batches', [StockController::class, 'batches']);
        Route::middleware(['can:'.Permission::STOCK_ADJUST, 'idempotent:required'])->group(function () {
            Route::post('products/{product}/opening-stock', [StockController::class, 'openingStock']);
            Route::post('stock/batches/{batch}/adjust', [StockController::class, 'adjust']);
        });

        // Billing
        Route::middleware('can:'.Permission::SALES_CREATE)->group(function () {
            Route::get('pos/search', [SaleController::class, 'search']);
            Route::get('pos/pharmacists', [SaleController::class, 'pharmacists']);
            Route::post('sales/preview', [SaleController::class, 'preview']);
            Route::post('sales', [SaleController::class, 'store']); // repeat-safe through the cart's own id
            Route::get('sales', [SaleController::class, 'index']);
            Route::get('sales/{sale}', [SaleController::class, 'show']);
            Route::get('returns/{saleReturn}', [SaleReturnController::class, 'show']);
        });

        // Taking goods back and cancelling a wrong bill (both repeat-safe through the Idempotency-Key).
        Route::middleware('can:'.Permission::SALES_RETURN)->group(function () {
            Route::post('sales/{sale}/returns/preview', [SaleReturnController::class, 'preview']);
            Route::post('sales/{sale}/returns', [SaleReturnController::class, 'store'])->middleware('idempotent:required');
        });
        Route::post('sales/{sale}/cancel', [SaleReturnController::class, 'cancel'])
            ->middleware(['can:'.Permission::SALES_CANCEL, 'idempotent:required']);

        // Customers and udhar
        Route::get('customers', [CustomerController::class, 'index']);
        Route::get('customers/{customer}', [CustomerController::class, 'show']);
        Route::middleware('can:'.Permission::CUSTOMERS_MANAGE)->group(function () {
            Route::post('customers', [CustomerController::class, 'store'])->middleware('idempotent');
            Route::put('customers/{customer}', [CustomerController::class, 'update']);
        });
        Route::post('customers/{customer}/payments', [CustomerController::class, 'receivePayment'])
            ->middleware(['can:'.Permission::CREDIT_RECEIVE, 'idempotent:required']);

        // Suppliers, their MR contacts and what the shop owes them. Reading needs either purchase or supplier rights.
        Route::middleware('can:suppliers.view')->group(function () { // gate: purchases.manage or suppliers.manage
            Route::get('suppliers', [SupplierController::class, 'index']);
            Route::get('suppliers/visits', [SupplierController::class, 'visits']);
            Route::get('suppliers/{supplier}', [SupplierController::class, 'show']);
        });
        Route::middleware('can:'.Permission::SUPPLIERS_MANAGE)->group(function () {
            Route::post('suppliers', [SupplierController::class, 'store'])->middleware('idempotent');
            Route::put('suppliers/{supplier}', [SupplierController::class, 'update']);
            Route::post('suppliers/{supplier}/contacts', [SupplierController::class, 'storeContact'])->middleware('idempotent');
            Route::put('suppliers/{supplier}/contacts/{contact}', [SupplierController::class, 'updateContact']);
            Route::delete('suppliers/{supplier}/contacts/{contact}', [SupplierController::class, 'removeContact']);
        });

        // Purchases: drafts are free to edit; confirming, paying, cancelling and returning are repeat-safe.
        Route::middleware('can:'.Permission::PURCHASES_MANAGE)->group(function () {
            Route::post('suppliers/{supplier}/payments', [SupplierController::class, 'pay'])->middleware('idempotent:required');
            Route::get('purchases', [PurchaseController::class, 'index']);
            Route::post('purchases/preview', [PurchaseController::class, 'preview']);
            Route::post('purchases', [PurchaseController::class, 'store'])->middleware('idempotent');
            Route::get('purchases/{purchase}', [PurchaseController::class, 'show']);
            Route::put('purchases/{purchase}', [PurchaseController::class, 'update']);
            Route::delete('purchases/{purchase}', [PurchaseController::class, 'destroy']);
            Route::post('purchases/{purchase}/confirm', [PurchaseController::class, 'confirm'])->middleware('idempotent:required');
            Route::post('purchases/{purchase}/cancel', [PurchaseController::class, 'cancel'])->middleware('idempotent:required');
            Route::get('purchase-returns/lots', [PurchaseReturnController::class, 'lots']);
            Route::post('purchase-returns', [PurchaseReturnController::class, 'store'])->middleware('idempotent:required');
            Route::get('purchase-returns/{purchaseReturn}', [PurchaseReturnController::class, 'show']);
        });

        // The cash day: anyone who bills may look; opening, closing and moving cash need day.close; reopening day.reopen.
        Route::get('day', [DayController::class, 'show'])->middleware('can:day.view');
        Route::get('day/history', [DayController::class, 'history'])->middleware('can:day.view');
        Route::get('day/{session}', [DayController::class, 'session'])->middleware('can:day.view');
        Route::middleware(['can:'.Permission::DAY_CLOSE, 'idempotent:required'])->group(function () {
            Route::post('day/open', [DayController::class, 'open']);
            Route::post('day/close', [DayController::class, 'close']);
            Route::post('day/movements', [DayController::class, 'move']);
        });
        Route::post('day/{session}/reopen', [DayController::class, 'reopen'])->middleware(['can:'.Permission::DAY_REOPEN, 'idempotent:required']);

        // Expenses
        Route::middleware('can:'.Permission::EXPENSES_MANAGE)->group(function () {
            Route::get('expenses', [ExpenseController::class, 'index']);
            Route::post('expenses', [ExpenseController::class, 'store'])->middleware('idempotent:required');
            Route::post('expenses/{expense}/cancel', [ExpenseController::class, 'cancel'])->middleware('idempotent:required');
            Route::get('expense-categories', [ExpenseController::class, 'categories']);
            Route::post('expense-categories', [ExpenseController::class, 'storeCategory'])->middleware('idempotent');
            Route::put('expense-categories/{category}', [ExpenseController::class, 'updateCategory']);
        });

        // Home screen and reports
        Route::get('dashboard', [ReportController::class, 'dashboard']);
        Route::middleware('can:'.Permission::REPORTS_VIEW)->group(function () {
            Route::get('reports', [ReportController::class, 'index']);
            Route::get('reports/{report}', [ReportController::class, 'show']);
            Route::get('reports/{report}/export', [ReportController::class, 'export'])->middleware('throttle:60,1');
        });

        // Roles: owner only, checked in the controller.
        Route::get('roles', [RoleController::class, 'index']);
        Route::post('roles', [RoleController::class, 'store'])->middleware('idempotent');
        Route::put('roles/{role}', [RoleController::class, 'update']);
        Route::delete('roles/{role}', [RoleController::class, 'destroy']);

        // Backups: a whole-database archive, verified before it is called a backup.
        Route::middleware('can:'.Permission::BACKUP_MANAGE)->group(function () {
            Route::get('backups', [BackupController::class, 'index']);
            Route::post('backups', [BackupController::class, 'store'])->middleware(['idempotent', 'throttle:6,1']);
            Route::post('backups/auto', [BackupController::class, 'auto'])->middleware('throttle:6,1');
            Route::get('backups/{backup}/download', [BackupController::class, 'download']);
            Route::put('backups/folder', [BackupController::class, 'updateFolder']);
        });

        Route::middleware('can:'.Permission::USERS_MANAGE)->group(function () {
            Route::get('users', [UserController::class, 'index']);
            Route::post('users', [UserController::class, 'store'])->middleware('idempotent');
            Route::put('users/{user}', [UserController::class, 'update']);
        });
    });
});
