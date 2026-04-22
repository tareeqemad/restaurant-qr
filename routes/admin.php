<?php

use App\Http\Controllers\Admin;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'admin'])->group(function () {
    // Dashboard
    Route::get('/', [Admin\DashboardController::class, 'index'])->name('dashboard');

    // Profile
    Route::get('profile', [Admin\ProfileController::class, 'show'])->name('profile.show');
    Route::put('profile', [Admin\ProfileController::class, 'update'])->name('profile.update');

    // Users
    Route::resource('users', Admin\UserController::class);
    Route::patch('users/{user}/toggle-status', [Admin\UserController::class, 'toggleStatus'])->name('users.toggle-status');

    // Roles
    Route::resource('roles', Admin\RoleController::class);

    // Tables
    Route::resource('tables', Admin\TableController::class);
    Route::get('tables/{table}/qr', [Admin\TableController::class, 'qr'])->name('tables.qr');
    Route::get('tables/{table}/qr-print', [Admin\TableController::class, 'qrPrint'])->name('tables.qr-print');

    // Categories
    Route::resource('categories', Admin\CategoryController::class);

    // Menu Items
    Route::resource('menu-items', Admin\MenuItemController::class);
    Route::patch('menu-items/{menu_item}/toggle-availability', [Admin\MenuItemController::class, 'toggleAvailability'])->name('menu-items.toggle-availability');

    // Modifiers
    Route::resource('modifiers', Admin\ModifierGroupController::class);

    // Allergens
    Route::resource('allergens', Admin\AllergenController::class);

    // Ingredients
    Route::resource('ingredients', Admin\IngredientController::class);
    Route::post('ingredients/{ingredient}/adjust', [Admin\IngredientController::class, 'adjust'])->name('ingredients.adjust');

    // Suppliers
    Route::resource('suppliers', Admin\SupplierController::class);

    // Purchase Orders
    Route::resource('purchase-orders', Admin\PurchaseOrderController::class);
    Route::post('purchase-orders/{purchase_order}/send',    [Admin\PurchaseOrderController::class, 'send'])->name('purchase-orders.send');
    Route::post('purchase-orders/{purchase_order}/cancel',  [Admin\PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');
    Route::get ('purchase-orders/{purchase_order}/receive', [Admin\PurchaseOrderController::class, 'receiveForm'])->name('purchase-orders.receive-form');
    Route::post('purchase-orders/{purchase_order}/receive', [Admin\PurchaseOrderController::class, 'receive'])->name('purchase-orders.receive');

    // Menu item cost recompute (from recipes)
    Route::post('menu-items/recompute-costs', [Admin\MenuItemController::class, 'recomputeCosts'])
        ->name('menu-items.recompute-costs');

    // Refunds (issued against paid invoices)
    Route::get ('refunds',                   [Admin\RefundController::class, 'index'])->name('refunds.index');
    Route::post('invoices/{invoice}/refunds',[Admin\RefundController::class, 'store'])->name('refunds.store');
    Route::post('refunds/{refund}/complete', [Admin\RefundController::class, 'complete'])->name('refunds.complete');
    Route::post('refunds/{refund}/cancel',   [Admin\RefundController::class, 'cancel'])->name('refunds.cancel');

    // Supplier Invoices (Accounts Payable)
    Route::resource('supplier-invoices', Admin\SupplierInvoiceController::class)->except(['edit', 'update']);
    Route::post('supplier-invoices/{supplier_invoice}/pay',    [Admin\SupplierInvoiceController::class, 'pay'])->name('supplier-invoices.pay');
    Route::post('supplier-invoices/{supplier_invoice}/cancel', [Admin\SupplierInvoiceController::class, 'cancel'])->name('supplier-invoices.cancel');

    // Stock Counts (physical inventory / جرد)
    Route::resource('stock-counts', Admin\StockCountController::class)->except(['edit', 'update']);
    Route::post('stock-counts/{stock_count}/save-counts', [Admin\StockCountController::class, 'saveCounts'])->name('stock-counts.save-counts');
    Route::post('stock-counts/{stock_count}/finalize',    [Admin\StockCountController::class, 'finalize'])->name('stock-counts.finalize');
    Route::post('stock-counts/{stock_count}/cancel',      [Admin\StockCountController::class, 'cancel'])->name('stock-counts.cancel');

    // Ingredient batches (expiry tracking + FIFO)
    Route::get ('batches',        [Admin\IngredientBatchController::class, 'index'])->name('batches.index');
    Route::post('batches',        [Admin\IngredientBatchController::class, 'store'])->name('batches.store');

    // Storage Locations (multi-location inventory)
    Route::get ('storage-locations/transfer', [Admin\StorageLocationController::class, 'transferForm'])->name('storage-locations.transfer-form');
    Route::post('storage-locations/transfer', [Admin\StorageLocationController::class, 'transferStore'])->name('storage-locations.transfer-store');
    Route::resource('storage-locations', Admin\StorageLocationController::class);

    // Inventory movements
    Route::get('inventory', [Admin\InventoryController::class, 'index'])->name('inventory.index');

    // Units
    Route::resource('units', Admin\UnitController::class);

    // Stations
    Route::resource('stations', Admin\StationController::class);

    // Orders (waiter/manager) — board is the default view; classic table is secondary
    Route::get ('orders',                     [Admin\OrderController::class, 'board'])->name('orders.index');       // Kanban board (default)
    Route::get ('orders/list',                [Admin\OrderController::class, 'index'])->name('orders.list');         // Classic table
    Route::post('orders/bulk-approve',        [Admin\OrderController::class, 'bulkApprove'])->name('orders.bulk-approve');
    Route::get ('orders/{order}',             [Admin\OrderController::class, 'show'])->name('orders.show');
    Route::post('orders/{order}/approve',     [Admin\OrderController::class, 'approve'])->name('orders.approve');
    Route::post('orders/{order}/transition',  [Admin\OrderController::class, 'transition'])->name('orders.transition');
    Route::post('orders/{order}/cancel',      [Admin\OrderController::class, 'cancel'])->name('orders.cancel');
    Route::get ('orders/{order}/source',      [Admin\OrderController::class, 'editSource'])->name('orders.edit-source');
    Route::post('orders/{order}/source',      [Admin\OrderController::class, 'updateSource'])->name('orders.update-source');
    Route::post('orders/items/{item}/cancel', [Admin\OrderController::class, 'cancelItem'])->name('orders.items.cancel');
    Route::post('orders/items/{item}/serve',  [Admin\OrderController::class, 'serveItem'])->name('orders.items.serve');

    // Kitchen display
    Route::get('kitchen', [Admin\KitchenDisplayController::class, 'kitchen'])->name('kitchen.index');
    Route::get('bar', [Admin\KitchenDisplayController::class, 'bar'])->name('bar.index');
    Route::post('station/items/{item}/start', [Admin\KitchenDisplayController::class, 'startItem'])->name('station.items.start');
    Route::post('station/items/{item}/ready', [Admin\KitchenDisplayController::class, 'markReady'])->name('station.items.ready');

    // Cashier / Billing
    Route::get('cashier', [Admin\CashierController::class, 'index'])->name('cashier.index');
    Route::get('cashier/session/{session}', [Admin\CashierController::class, 'show'])->name('cashier.show');
    Route::post('cashier/session/{session}/issue', [Admin\CashierController::class, 'issue'])->name('cashier.issue');
    Route::post('cashier/invoice/{invoice}/pay', [Admin\CashierController::class, 'pay'])->name('cashier.pay');
    Route::post('cashier/invoice/{invoice}/writeoff', [Admin\CashierController::class, 'writeoff'])->name('cashier.writeoff');
    Route::post('cashier/invoice/{invoice}/cancel', [Admin\CashierController::class, 'cancel'])->name('cashier.cancel');
    Route::post('cashier/invoice/{invoice}/split', [Admin\CashierController::class, 'split'])->name('cashier.split');
    Route::post('cashier/invoice/{invoice}/split/{split}/pay', [Admin\CashierController::class, 'paySplit'])->name('cashier.split.pay');
    Route::delete('cashier/invoice/{invoice}/splits', [Admin\CashierController::class, 'clearSplits'])->name('cashier.split.clear');
    Route::get('cashier/invoice/{invoice}/pdf', [Admin\CashierController::class, 'pdf'])->name('cashier.pdf');
    Route::get('cashier/invoice/{invoice}/print', [Admin\CashierController::class, 'print'])->name('cashier.print');

    // Shifts
    Route::resource('shifts', Admin\ShiftController::class)->only(['index','store']);
    Route::post('shifts/{shift}/close', [Admin\ShiftController::class, 'close'])->name('shifts.close');

    // Reports
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('sales',        [Admin\ReportController::class, 'sales'])->name('sales');
        Route::get('items',        [Admin\ReportController::class, 'items'])->name('items');
        Route::get('inventory',    [Admin\ReportController::class, 'inventory'])->name('inventory');
        Route::get('shifts',       [Admin\ReportController::class, 'shifts'])->name('shifts');
        Route::get('end-of-day',            [Admin\ReportController::class, 'endOfDay'])->name('end-of-day');
        Route::get('profit-loss',           [Admin\ReportController::class, 'profitLoss'])->name('profit-loss');
        Route::get('menu-engineering',      [Admin\ReportController::class, 'menuEngineering'])->name('menu-engineering');
        Route::get('reorder-suggestions',   [Admin\ReportController::class, 'reorderSuggestions'])->name('reorder-suggestions');
        Route::get('sales-by-platform',     [Admin\ReportController::class, 'salesByPlatform'])->name('sales-by-platform');
    });

    // Currencies (multi-currency display)
    Route::get   ('currencies',              [Admin\CurrencyController::class, 'index'])->name('currencies.index');
    Route::post  ('currencies',              [Admin\CurrencyController::class, 'store'])->name('currencies.store');
    Route::post  ('currencies/update-rates', [Admin\CurrencyController::class, 'updateRates'])->name('currencies.update-rates');
    Route::delete('currencies/{currency}',   [Admin\CurrencyController::class, 'destroy'])->name('currencies.destroy');

    // Settings
    Route::get('settings', [Admin\SettingController::class, 'index'])->name('settings.index');
    Route::put('settings', [Admin\SettingController::class, 'update'])->name('settings.update');
    Route::post('settings/reset-theme', [Admin\SettingController::class, 'resetTheme'])->name('settings.reset-theme');

    // Activity logs
    Route::get('activity-logs', [Admin\ActivityLogController::class, 'index'])->name('activity-logs.index');
});
