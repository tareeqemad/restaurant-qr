<?php

use App\Http\Controllers\Admin;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'admin', 'branch'])->group(function () {
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
    Route::post('roles/{role}/clone', [Admin\RoleController::class, 'clone'])->name('roles.clone');

    // Multi-branch overview — partners + super admins only.
    Route::get('overview',
        [Admin\PartnerOverviewController::class, 'index'])->name('partner.overview');

    // Multi-branch full-screen LIVE monitor (TV-mode for owners).
    Route::get('live-monitor',
        [Admin\LiveMonitorController::class, 'index'])->name('partner.live-monitor');

    // System administration (Super Admin only — the controller enforces it).
    Route::get ('system',
        [Admin\SystemController::class, 'index'])->name('system.index');
    Route::post('system/reset-demo',
        [Admin\SystemController::class, 'resetDemo'])->name('system.reset-demo');

    // Branches (Super Admin only — gated by BranchPolicy)
    Route::resource('branches', Admin\BranchController::class)->except(['show']);
    Route::patch('branches/{branch}/toggle-status',
        [Admin\BranchController::class, 'toggleStatus'])->name('branches.toggle-status');
    Route::post('branches/{branch}/menu/duplicate-from',
        [Admin\BranchController::class, 'duplicateMenu'])->name('branches.menu.duplicate');

    // Branch switcher (any user with multi-branch access)
    Route::post('branches/switch/all',
        [Admin\BranchSwitchController::class, 'switchAll'])->name('branches.switch.all');
    Route::post('branches/{branch}/switch',
        [Admin\BranchSwitchController::class, 'switch'])->name('branches.switch');

    // ─── Notifications (in-app inbox + header bell endpoints) ─────────
    // The bell polls `recent` for fresh count + preview rows; `read` /
    // `read-all` are POSTed when the user opens / clears the dropdown.
    Route::get   ('notifications',                  [Admin\NotificationController::class, 'index'])->name('notifications.index');
    Route::get   ('notifications/recent',           [Admin\NotificationController::class, 'recent'])->name('notifications.recent');
    Route::post  ('notifications/{id}/read',        [Admin\NotificationController::class, 'read'])->name('notifications.read');
    Route::post  ('notifications/read-all',         [Admin\NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::delete('notifications/{id}',             [Admin\NotificationController::class, 'destroy'])->name('notifications.destroy');

    // Reviews (branch-scoped moderation)
    Route::get   ('reviews',                    [Admin\ReviewController::class, 'index'])->name('reviews.index');
    Route::post  ('reviews/{review}/hide',      [Admin\ReviewController::class, 'hide'])->name('reviews.hide');
    Route::post  ('reviews/{review}/unhide',    [Admin\ReviewController::class, 'unhide'])->name('reviews.unhide');
    Route::delete('reviews/{review}',           [Admin\ReviewController::class, 'destroy'])->name('reviews.destroy');

    // Attendance — self-service + manager admin
    Route::post  ('attendance/clock-in',          [Admin\AttendanceController::class, 'clockIn'])->name('attendance.clock-in');
    Route::post  ('attendance/clock-out',         [Admin\AttendanceController::class, 'clockOut'])->name('attendance.clock-out');
    Route::get   ('attendance',                   [Admin\AttendanceController::class, 'index'])->name('attendance.index');
    Route::get   ('attendance/export.xlsx',       [Admin\AttendanceController::class, 'export'])->name('attendance.export.xlsx');
    Route::post  ('attendance',                   [Admin\AttendanceController::class, 'store'])->name('attendance.store');
    Route::put   ('attendance/{attendance}',      [Admin\AttendanceController::class, 'update'])->name('attendance.update');
    Route::delete('attendance/{attendance}',      [Admin\AttendanceController::class, 'destroy'])->name('attendance.destroy');

    // Customers — global directory (no branch_id; the show page groups
    // their reservations/reviews by branch)
    Route::get   ('customers',                    [Admin\CustomerController::class, 'index'])->name('customers.index');

    // Customer debt ledger — declared BEFORE /customers/{customer} so the
    // literal `debts` path isn't swallowed by the wildcard model binding.
    Route::get ('customers/debts',                          [Admin\CustomerDebtController::class, 'index'])->name('customers.debts.index');
    Route::get ('customers/debts/lookup',                   [Admin\CustomerDebtController::class, 'quickLookup'])->name('customers.debts.lookup');
    Route::get ('customers/debts/{customer}',               [Admin\CustomerDebtController::class, 'show'])->name('customers.debts.show');
    Route::post('customers/debts/{customer}/payment',       [Admin\CustomerDebtController::class, 'recordPayment'])->name('customers.debts.payment');
    Route::post('customers/debts/{customer}/credit-limit',  [Admin\CustomerDebtController::class, 'updateCreditLimit'])->name('customers.debts.credit_limit');

    Route::get   ('customers/{customer}',         [Admin\CustomerController::class, 'show'])->name('customers.show');
    Route::put   ('customers/{customer}',         [Admin\CustomerController::class, 'update'])->name('customers.update');
    Route::post  ('customers/{customer}/block',   [Admin\CustomerController::class, 'block'])->name('customers.block');
    Route::post  ('customers/{customer}/unblock', [Admin\CustomerController::class, 'unblock'])->name('customers.unblock');
    Route::delete('customers/{customer}',         [Admin\CustomerController::class, 'destroy'])->name('customers.destroy');

    // Reservations (branch-scoped)
    Route::get    ('reservations',                       [Admin\ReservationController::class, 'index'])->name('reservations.index');
    Route::get    ('reservations/{reservation}/edit',    [Admin\ReservationController::class, 'edit'])->name('reservations.edit');
    Route::put    ('reservations/{reservation}',         [Admin\ReservationController::class, 'update'])->name('reservations.update');
    Route::post   ('reservations/{reservation}/confirm', [Admin\ReservationController::class, 'confirm'])->name('reservations.confirm');
    Route::post   ('reservations/{reservation}/seat',    [Admin\ReservationController::class, 'seat'])->name('reservations.seat');
    Route::post   ('reservations/{reservation}/complete',[Admin\ReservationController::class, 'complete'])->name('reservations.complete');
    Route::post   ('reservations/{reservation}/cancel',  [Admin\ReservationController::class, 'cancel'])->name('reservations.cancel');
    Route::post   ('reservations/{reservation}/no-show', [Admin\ReservationController::class, 'noShow'])->name('reservations.no-show');

    // Tables
    Route::resource('tables', Admin\TableController::class);
    Route::get('tables/{table}/qr', [Admin\TableController::class, 'qr'])->name('tables.qr');
    Route::get('tables/{table}/qr-print', [Admin\TableController::class, 'qrPrint'])->name('tables.qr-print');
    Route::post('tables/{table}/close-session', [Admin\TableController::class, 'closeSession'])->name('tables.close-session');
    Route::post('tables/{table}/transfer-session', [Admin\TableController::class, 'transferSession'])->name('tables.transfer');

    // Zones — merged into the unified Lookups admin (group='zones').
    // Old /admin/zones routes removed; see admin.lookups.* routes below.

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
    Route::post('ingredients/{ingredient}/sub-recipe', [Admin\IngredientController::class, 'updateSubRecipe'])->name('ingredients.sub_recipe.update');
    Route::post('ingredients/{ingredient}/units',      [Admin\IngredientController::class, 'updateUnits'])->name('ingredients.units.update');

    // Suppliers
    Route::resource('suppliers', Admin\SupplierController::class);

    // Vendor Price History — three lenses on the same data
    Route::get('vendor-prices/compare',
        [Admin\VendorPriceController::class, 'compare'])->name('vendor-prices.compare');
    Route::get('vendor-prices/ingredient/{ingredient}',
        [Admin\VendorPriceController::class, 'forIngredient'])->name('vendor-prices.ingredient');
    Route::get('vendor-prices/supplier/{supplier}',
        [Admin\VendorPriceController::class, 'forSupplier'])->name('vendor-prices.supplier');

    // Purchase Orders
    // A missing PO id (deleted, or a hand-typed URL) would otherwise hit the
    // generic 404 page, which doesn't tell the user *what* was missing. Bind
    // the param explicitly so every PO route — show, edit, receive, approve,
    // … — bounces back to the list with a clear Arabic message instead.
    Route::bind('purchase_order', function ($value) {
        return \App\Models\PurchaseOrder::find($value)
            ?? throw new \Illuminate\Http\Exceptions\HttpResponseException(
                redirect()->route('admin.purchase-orders.index')
                    ->with('error', "أمر الشراء رقم #{$value} غير موجود أو تم حذفه.")
            );
    });
    Route::resource('purchase-orders', Admin\PurchaseOrderController::class);
    Route::post('purchase-orders/{purchase_order}/approve', [Admin\PurchaseOrderController::class, 'approve'])->name('purchase-orders.approve');
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
    Route::get ('stock-counts/{stock_count}/export.xlsx', [Admin\StockCountController::class, 'export'])->name('stock-counts.export.xlsx');
    Route::post('stock-counts/{stock_count}/save-counts', [Admin\StockCountController::class, 'saveCounts'])->name('stock-counts.save-counts');
    Route::post('stock-counts/{stock_count}/finalize',    [Admin\StockCountController::class, 'finalize'])->name('stock-counts.finalize');
    Route::post('stock-counts/{stock_count}/cancel',      [Admin\StockCountController::class, 'cancel'])->name('stock-counts.cancel');

    // Ingredient batches (expiry tracking + FIFO)
    Route::get ('batches',        [Admin\IngredientBatchController::class, 'index'])->name('batches.index');
    Route::post('batches',        [Admin\IngredientBatchController::class, 'store'])->name('batches.store');

    // Inter-Branch Transfers — moves stock between FROM-branch and TO-branch
    Route::get ('branch-transfers',                          [Admin\BranchTransferController::class, 'index'])->name('branch-transfers.index');
    Route::get ('branch-transfers/create',                   [Admin\BranchTransferController::class, 'create'])->name('branch-transfers.create');
    Route::post('branch-transfers',                          [Admin\BranchTransferController::class, 'store'])->name('branch-transfers.store');
    Route::get ('branch-transfers/{branch_transfer}',        [Admin\BranchTransferController::class, 'show'])->name('branch-transfers.show');
    Route::post('branch-transfers/{branch_transfer}/send',   [Admin\BranchTransferController::class, 'send'])->name('branch-transfers.send');
    Route::post('branch-transfers/{branch_transfer}/receive',[Admin\BranchTransferController::class, 'receive'])->name('branch-transfers.receive');
    Route::post('branch-transfers/{branch_transfer}/cancel', [Admin\BranchTransferController::class, 'cancel'])->name('branch-transfers.cancel');

    // Storage Locations (multi-location inventory)
    Route::get ('storage-locations/transfer', [Admin\StorageLocationController::class, 'transferForm'])->name('storage-locations.transfer-form');
    Route::post('storage-locations/transfer', [Admin\StorageLocationController::class, 'transferStore'])->name('storage-locations.transfer-store');
    Route::resource('storage-locations', Admin\StorageLocationController::class);

    // Inventory command center + movements
    Route::get('inventory-dashboard', [Admin\InventoryController::class, 'dashboard'])->name('inventory.dashboard');
    Route::get('inventory', [Admin\InventoryController::class, 'index'])->name('inventory.index');
    Route::get('inventory/by-barcode', [Admin\InventoryController::class, 'lookupByBarcode'])->name('inventory.by_barcode');

    // Waste — dedicated logging surface (forces a reason + optional batch link)
    Route::get ('waste',                          [Admin\WasteController::class, 'index'])->name('waste.index');
    Route::get ('waste/create',                   [Admin\WasteController::class, 'create'])->name('waste.create');
    Route::post('waste',                          [Admin\WasteController::class, 'store'])->name('waste.store');
    Route::get ('waste/batches/{ingredient}',     [Admin\WasteController::class, 'batchesForIngredient'])->name('waste.batches');

    // Units
    Route::resource('units', Admin\UnitController::class);

    // Stations
    Route::resource('stations', Admin\StationController::class);

    // Orders (waiter/manager) — board is the default view; classic table is secondary
    Route::get ('orders',                     [Admin\OrderController::class, 'board'])->name('orders.index');       // Kanban board (default)
    Route::get ('orders/list',                [Admin\OrderController::class, 'index'])->name('orders.list');         // Classic table
    Route::get ('orders/archive',             [Admin\OrderController::class, 'archive'])->name('orders.archive');    // Comprehensive search/filter
    Route::post('orders/bulk-approve',        [Admin\OrderController::class, 'bulkApprove'])->name('orders.bulk-approve');
    Route::get ('orders/{order}',             [Admin\OrderController::class, 'show'])->name('orders.show');
    Route::post('orders/{order}/approve',     [Admin\OrderController::class, 'approve'])->name('orders.approve');
    Route::post('orders/{order}/transition',  [Admin\OrderController::class, 'transition'])->name('orders.transition');
    Route::post('orders/{order}/cancel',      [Admin\OrderController::class, 'cancel'])->name('orders.cancel');
    Route::get ('orders/{order}/source',      [Admin\OrderController::class, 'editSource'])->name('orders.edit-source');
    Route::post('orders/{order}/source',      [Admin\OrderController::class, 'updateSource'])->name('orders.update-source');
    Route::post('orders/items/{item}/cancel', [Admin\OrderController::class, 'cancelItem'])->name('orders.items.cancel');
    Route::post('orders/items/{item}/serve',  [Admin\OrderController::class, 'serveItem'])->name('orders.items.serve');

    // Waiter-side order entry — for walk-in diners who can't scan the
    // table QR. Mirrors the customer cart flow under the staff guard.
    Route::get ('waiter-orders',                         [Admin\WaiterOrderController::class, 'index'])->name('waiter-orders.index');
    Route::get ('waiter-orders/table/{table}',           [Admin\WaiterOrderController::class, 'create'])->name('waiter-orders.create');
    Route::post('waiter-orders/{session}/items',         [Admin\WaiterOrderController::class, 'addItem'])->name('waiter-orders.items.add');
    Route::delete('waiter-orders/{session}/items',       [Admin\WaiterOrderController::class, 'removeItem'])->name('waiter-orders.items.remove');
    Route::post('waiter-orders/{session}/customer',      [Admin\WaiterOrderController::class, 'linkCustomer'])->name('waiter-orders.customer.link');
    Route::post('waiter-orders/{session}/staff-mode',    [Admin\WaiterOrderController::class, 'setStaffMode'])->name('waiter-orders.staff_mode');
    Route::post('waiter-orders/{session}/submit',        [Admin\WaiterOrderController::class, 'submit'])->name('waiter-orders.submit');

    // Staff meal allowance — per-employee monthly tabs (manager view)
    Route::get ('staff-meals',                  [Admin\StaffMealController::class, 'index'])->name('staff-meals.index');
    Route::get ('staff-meals/quick-consume',    [Admin\StaffMealController::class, 'quickConsumeForm'])->name('staff-meals.quick_consume');
    Route::post('staff-meals/quick-consume',    [Admin\StaffMealController::class, 'quickConsumeStore'])->name('staff-meals.quick_consume.store');
    Route::get ('staff-meals/closures',         [Admin\StaffMealController::class, 'closures'])->name('staff-meals.closures');
    Route::post('staff-meals/close-month',      [Admin\StaffMealController::class, 'closeMonth'])->name('staff-meals.close_month');
    Route::get ('staff-meals/closures/{closure}', [Admin\StaffMealController::class, 'closureShow'])->name('staff-meals.closures.show');
    Route::get ('staff-meals/{user}',           [Admin\StaffMealController::class, 'show'])->name('staff-meals.show');
    Route::post('staff-meals/{user}/settle',    [Admin\StaffMealController::class, 'settle'])->name('staff-meals.settle');
    Route::post('staff-meals/charges/{charge}/waive', [Admin\StaffMealController::class, 'waiveCharge'])->name('staff-meals.charges.waive');

    // Station displays — one generic route handles every station code.
    // The controller checks the matching `station.{code}.view` permission
    // so access is driven entirely by the roles UI.
    Route::get('station/{code}', [Admin\KitchenDisplayController::class, 'show'])
        ->where('code', '[a-z0-9_-]+')
        ->name('station.show');
    // Back-compat aliases — old /admin/kitchen and /admin/bar links still work.
    Route::redirect('kitchen', 'admin/station/kitchen')->name('kitchen.index');
    Route::redirect('bar',     'admin/station/bar')->name('bar.index');

    Route::post('station/items/{item}/start', [Admin\KitchenDisplayController::class, 'startItem'])->name('station.items.start');
    Route::post('station/items/{item}/ready', [Admin\KitchenDisplayController::class, 'markReady'])->name('station.items.ready');

    // Cashier / Billing
    Route::get('cashier', [Admin\CashierController::class, 'index'])->name('cashier.index');
    Route::get('cashier/session/{session}', [Admin\CashierController::class, 'show'])->name('cashier.show');
    Route::post('cashier/session/{session}/issue', [Admin\CashierController::class, 'issue'])->name('cashier.issue');
    Route::post('cashier/invoice/{invoice}/pay', [Admin\CashierController::class, 'pay'])->name('cashier.pay');
    Route::post('cashier/invoice/{invoice}/writeoff', [Admin\CashierController::class, 'writeoff'])->name('cashier.writeoff');
    Route::post('cashier/invoice/{invoice}/cancel', [Admin\CashierController::class, 'cancel'])->name('cashier.cancel');
    Route::post('cashier/invoice/{invoice}/settle-on-account', [Admin\CashierController::class, 'settleOnAccount'])->name('cashier.settle_on_account');
    Route::post('cashier/invoice/{invoice}/split', [Admin\CashierController::class, 'split'])->name('cashier.split');
    Route::post('cashier/invoice/{invoice}/split/{split}/pay', [Admin\CashierController::class, 'paySplit'])->name('cashier.split.pay');
    Route::delete('cashier/invoice/{invoice}/splits', [Admin\CashierController::class, 'clearSplits'])->name('cashier.split.clear');
    Route::post('cashier/order/{order}/discount', [Admin\CashierController::class, 'applyDiscountToOrder'])->name('cashier.discount.order');
    Route::post('cashier/session/{session}/discount', [Admin\CashierController::class, 'applyDiscountToSession'])->name('cashier.discount.session');
    Route::delete('cashier/discount/{discount}', [Admin\CashierController::class, 'removeDiscount'])->name('cashier.discount.remove');
    Route::get('cashier/invoice/{invoice}/pdf', [Admin\CashierController::class, 'pdf'])->name('cashier.pdf');
    Route::get('cashier/invoice/{invoice}/print', [Admin\CashierController::class, 'print'])->name('cashier.print');

    // Pending bank transfers — waiter claims, cashier verifies
    Route::post('waiter-orders/{session}/transfer', [Admin\PendingTransferController::class, 'store'])->name('waiter-orders.transfer.store');
    Route::get('cashier/transfers', [Admin\PendingTransferController::class, 'queue'])->name('cashier.transfers.queue');
    Route::post('cashier/transfers/{transfer}/verify', [Admin\PendingTransferController::class, 'verify'])->name('cashier.transfers.verify');
    Route::post('cashier/transfers/{transfer}/reject', [Admin\PendingTransferController::class, 'reject'])->name('cashier.transfers.reject');
    Route::post('cashier/transfers/{transfer}/reopen', [Admin\PendingTransferController::class, 'reopen'])->name('cashier.transfers.reopen');
    Route::get('cashier/transfers/report', [Admin\PendingTransferController::class, 'report'])->name('cashier.transfers.report');

    // Shifts
    Route::resource('shifts', Admin\ShiftController::class)->only(['index','store']);
    Route::post('shifts/{shift}/close', [Admin\ShiftController::class, 'close'])->name('shifts.close');

    // Expenses (branch-scoped) — see ExpenseController for the approval flow
    // and the cash-movement bridge into the active shift's till.
    Route::get   ('expenses',                     [Admin\ExpenseController::class, 'index'])->name('expenses.index');
    Route::get   ('expenses/create',              [Admin\ExpenseController::class, 'create'])->name('expenses.create');
    Route::post  ('expenses',                     [Admin\ExpenseController::class, 'store'])->name('expenses.store');
    Route::get   ('expenses/{expense}/edit',      [Admin\ExpenseController::class, 'edit'])->name('expenses.edit');
    Route::put   ('expenses/{expense}',           [Admin\ExpenseController::class, 'update'])->name('expenses.update');
    Route::post  ('expenses/{expense}/approve',   [Admin\ExpenseController::class, 'approve'])->name('expenses.approve');
    Route::post  ('expenses/{expense}/reject',    [Admin\ExpenseController::class, 'reject'])->name('expenses.reject');
    Route::delete('expenses/{expense}',           [Admin\ExpenseController::class, 'destroy'])->name('expenses.destroy');

    // Marketing announcements / promo broadcasts to portal customers.
    // Publishing fans out one notification per matched customer; the
    // service enforces audience filtering and idempotency.
    Route::get   ('announcements',                          [Admin\AnnouncementController::class, 'index'])->name('announcements.index');
    Route::get   ('announcements/create',                   [Admin\AnnouncementController::class, 'create'])->name('announcements.create');
    Route::post  ('announcements',                          [Admin\AnnouncementController::class, 'store'])->name('announcements.store');
    Route::get   ('announcements/{announcement}/edit',      [Admin\AnnouncementController::class, 'edit'])->name('announcements.edit');
    Route::put   ('announcements/{announcement}',           [Admin\AnnouncementController::class, 'update'])->name('announcements.update');
    Route::post  ('announcements/{announcement}/publish',   [Admin\AnnouncementController::class, 'publish'])->name('announcements.publish');
    Route::post  ('announcements/{announcement}/unpublish', [Admin\AnnouncementController::class, 'unpublish'])->name('announcements.unpublish');
    Route::delete('announcements/{announcement}',           [Admin\AnnouncementController::class, 'destroy'])->name('announcements.destroy');

    // Reports
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/',            [Admin\ReportController::class, 'index'])->name('index');
        Route::get('sales',        [Admin\ReportController::class, 'sales'])->name('sales');
        Route::get('items',        [Admin\ReportController::class, 'items'])->name('items');
        Route::get('inventory',    [Admin\ReportController::class, 'inventory'])->name('inventory');
        Route::get('consumption-variance', [Admin\ReportController::class, 'consumptionVariance'])->name('consumption-variance');
        Route::get('shifts',       [Admin\ReportController::class, 'shifts'])->name('shifts');
        Route::get('end-of-day',            [Admin\ReportController::class, 'endOfDay'])->name('end-of-day');
        Route::get('profit-loss',           [Admin\ReportController::class, 'profitLoss'])->name('profit-loss');
        Route::get('profit-loss/export.xlsx', [Admin\ReportController::class, 'profitLossExportXlsx'])->name('profit-loss.export.xlsx');
        Route::get('profit-loss/export.pdf',  [Admin\ReportController::class, 'profitLossExportPdf'])->name('profit-loss.export.pdf');
        Route::get('menu-engineering',      [Admin\ReportController::class, 'menuEngineering'])->name('menu-engineering');
        Route::get ('reorder-suggestions',   [Admin\ReportController::class, 'reorderSuggestions'])->name('reorder-suggestions');
        Route::post('reorder-suggestions/bulk-create-pos',
            [Admin\ReportController::class, 'createBulkReorderPOs'])->name('reorder-suggestions.bulk-create');
        Route::get ('stock-valuation',       [Admin\ReportController::class, 'stockValuation'])->name('stock-valuation');
        Route::get ('branch-comparison',     [Admin\ReportController::class, 'branchComparison'])->name('branch-comparison');
        Route::get('sales-by-platform',     [Admin\ReportController::class, 'salesByPlatform'])->name('sales-by-platform');
    });

    // Accounting review
    Route::prefix('accounting')->name('accounting.')->group(function () {
        Route::get('journal', [Admin\AccountingController::class, 'journal'])->name('journal');
        Route::get('trial-balance', [Admin\AccountingController::class, 'trialBalance'])->name('trial-balance');
    });

    // Currencies (multi-currency display)
    Route::get   ('currencies',              [Admin\CurrencyController::class, 'index'])->name('currencies.index');
    Route::post  ('currencies',              [Admin\CurrencyController::class, 'store'])->name('currencies.store');
    Route::post  ('currencies/update-rates', [Admin\CurrencyController::class, 'updateRates'])->name('currencies.update-rates');
    Route::delete('currencies/{currency}',   [Admin\CurrencyController::class, 'destroy'])->name('currencies.destroy');

    // Lookups (soft-enum management — categories etc.)
    Route::get   ('lookups',                       [Admin\LookupController::class, 'index'])->name('lookups.index');
    Route::post  ('lookups',                       [Admin\LookupController::class, 'store'])->name('lookups.store');
    Route::put   ('lookups/{lookup}',              [Admin\LookupController::class, 'update'])->name('lookups.update');
    Route::delete('lookups/{lookup}',              [Admin\LookupController::class, 'destroy'])->name('lookups.destroy');
    Route::post  ('lookups/{id}/restore',          [Admin\LookupController::class, 'restore'])->name('lookups.restore');

    // Settings
    Route::get('settings', [Admin\SettingController::class, 'index'])->name('settings.index');
    Route::put('settings', [Admin\SettingController::class, 'update'])->name('settings.update');
    Route::post('settings/reset-theme', [Admin\SettingController::class, 'resetTheme'])->name('settings.reset-theme');
    Route::post('settings/sms/test',    [Admin\SettingController::class, 'testSms'])->name('settings.sms.test');
    Route::post('settings/brand', [Admin\SettingController::class, 'updateBrand'])->name('settings.brand.update');
    Route::delete('settings/brand/{key}', [Admin\SettingController::class, 'deleteBrand'])->name('settings.brand.delete');

    // Activity logs
    Route::get('activity-logs', [Admin\ActivityLogController::class, 'index'])->name('activity-logs.index');
});
