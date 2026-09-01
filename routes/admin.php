<?php

use App\Http\Controllers\Admin;
use App\Models\PurchaseOrder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth', 'setup.complete', 'admin', 'branch'])->group(function () {
    // Dashboard
    Route::get('/', [Admin\DashboardController::class, 'index'])->name('dashboard');

    // Built-in operating manual. Every active back-office role can open it;
    // the page itself only links to workspaces that role is allowed to use.
    Route::get('usage-guide', Admin\UsageGuideController::class)->name('usage-guide');

    // ── تجربة Inertia+Vue (MIGRATION-PILOT.md §5) ─────────────────────────
    // المرحلة 0 — البوابة: هذه الصفحة تثبت السلسلة كاملة (route → middleware
    // → inertia.blade → Vite → Vue). sol لا يبدأ المرحلة 2 قبل أن تشتغل.
    Route::get('inertia-ping', fn () => Inertia::render('Ping', [
        'now' => now()->toDateTimeString(),
        'userName' => auth()->user()?->name,
    ]))->name('inertia.ping');

    // شاشة طلب الجرسون (Vue) — مورد واحد نظيف: الصفحة وكل الـ API تبعها
    // على نفس المسار `waiter-orders/table/{table}` بلا أي بادئة تقنية.
    Route::post('waiter-orders/table/{table}/preview-stock', [Admin\WaiterPosVueController::class, 'previewStock'])
        ->name('waiter-orders.preview-stock');
    Route::post('waiter-orders/table/{table}/submit', [Admin\WaiterPosVueController::class, 'submit'])
        ->name('waiter-orders.submit');
    Route::post('waiter-orders/table/{table}/review/{order}', [Admin\WaiterPosVueController::class, 'review'])
        ->name('waiter-orders.review');
    Route::post('waiter-orders/table/{table}/covers', [Admin\WaiterPosVueController::class, 'covers'])
        ->name('waiter-orders.covers');
    Route::post('waiter-orders/table/{table}/customer', [Admin\WaiterPosVueController::class, 'customer'])
        ->name('waiter-orders.customer');
    Route::post('waiter-orders/table/{table}/transfer', [Admin\WaiterPosVueController::class, 'transfer'])
        ->name('waiter-orders.transfer-claim');

    // Profile
    Route::get('profile', [Admin\ProfileController::class, 'show'])->name('profile.show');
    Route::put('profile', [Admin\ProfileController::class, 'update'])->name('profile.update');

    // Users
    Route::resource('users', Admin\UserController::class);
    Route::patch('users/{user}/toggle-status', [Admin\UserController::class, 'toggleStatus'])->name('users.toggle-status');

    // Roles
    Route::resource('roles', Admin\RoleController::class);
    Route::post('roles/{role}/clone', [Admin\RoleController::class, 'clone'])->name('roles.clone');

    // Standalone permissions management — pick a user from a dropdown,
    // tweak their grants/revokes without going through the full user form.
    // Gated by roles.update permission inside the controller.
    Route::get('permissions', [Admin\PermissionManagementController::class, 'index'])->name('permissions.index');
    Route::put('permissions/roles/{role}', [Admin\PermissionManagementController::class, 'syncRole'])->name('permissions.roles.sync');
    Route::put('permissions/{user}/sync', [Admin\PermissionManagementController::class, 'sync'])->name('permissions.sync');

    // Multi-branch overview — partners + super admins only.
    Route::get('overview',
        [Admin\PartnerOverviewController::class, 'index'])->name('partner.overview');
    Route::get('overview/pulse',
        [Admin\PartnerOverviewController::class, 'pulse'])->name('partner.overview.pulse');

    // Multi-branch full-screen LIVE monitor (TV-mode for owners).
    Route::get('live-monitor',
        [Admin\LiveMonitorController::class, 'index'])->name('partner.live-monitor');
    Route::get('live-monitor/pulse',
        [Admin\LiveMonitorController::class, 'pulse'])->name('partner.live-monitor.pulse');

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
    Route::get('notifications', [Admin\NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/recent', [Admin\NotificationController::class, 'recent'])->name('notifications.recent');
    Route::post('notifications/{id}/read', [Admin\NotificationController::class, 'read'])->name('notifications.read');
    Route::post('notifications/read-all', [Admin\NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::delete('notifications/{id}', [Admin\NotificationController::class, 'destroy'])->name('notifications.destroy');

    // Reviews (branch-scoped moderation)
    Route::get('reviews', [Admin\ReviewController::class, 'index'])->name('reviews.index');
    Route::post('reviews/{review}/hide', [Admin\ReviewController::class, 'hide'])->name('reviews.hide');
    Route::post('reviews/{review}/unhide', [Admin\ReviewController::class, 'unhide'])->name('reviews.unhide');
    Route::delete('reviews/{review}', [Admin\ReviewController::class, 'destroy'])->name('reviews.destroy');

    // Attendance — self-service + manager admin
    Route::post('attendance/clock-in', [Admin\AttendanceController::class, 'clockIn'])->name('attendance.clock-in');
    Route::post('attendance/clock-out', [Admin\AttendanceController::class, 'clockOut'])->name('attendance.clock-out');
    Route::get('attendance', [Admin\AttendanceController::class, 'index'])->name('attendance.index');
    Route::get('attendance/export.xlsx', [Admin\AttendanceController::class, 'export'])->name('attendance.export.xlsx');
    Route::post('attendance', [Admin\AttendanceController::class, 'store'])->name('attendance.store');
    Route::put('attendance/{attendance}', [Admin\AttendanceController::class, 'update'])->name('attendance.update');
    Route::delete('attendance/{attendance}', [Admin\AttendanceController::class, 'destroy'])->name('attendance.destroy');

    // Customers — global directory (no branch_id; the show page groups
    // their reservations/reviews by branch)
    Route::get('customers', [Admin\CustomerController::class, 'index'])->name('customers.index');

    // Customer debt ledger — declared BEFORE /customers/{customer} so the
    // literal `debts` path isn't swallowed by the wildcard model binding.
    Route::get('customers/debts', [Admin\CustomerDebtController::class, 'index'])->name('customers.debts.index');
    Route::get('customers/debts/lookup', [Admin\CustomerDebtController::class, 'quickLookup'])->name('customers.debts.lookup');
    Route::get('customers/debts/{customer}', [Admin\CustomerDebtController::class, 'show'])->name('customers.debts.show');
    Route::post('customers/debts/{customer}/payment', [Admin\CustomerDebtController::class, 'recordPayment'])->name('customers.debts.payment');
    Route::post('customers/debts/{customer}/adjustment', [Admin\CustomerDebtController::class, 'adjustDebt'])->name('customers.debts.adjustment');
    Route::post('customers/debts/{customer}/writeoff', [Admin\CustomerDebtController::class, 'writeoffDebt'])->name('customers.debts.writeoff');
    Route::post('customers/debts/credit-notes/{creditNote}/reverse', [Admin\CustomerDebtController::class, 'reverseCreditNote'])->name('customers.debts.credit_notes.reverse');
    Route::post('customers/debts/writeoffs/{writeoff}/reverse', [Admin\CustomerDebtController::class, 'reverseWriteoff'])->name('customers.debts.writeoffs.reverse');
    Route::post('customers/debts/{customer}/credit-limit', [Admin\CustomerDebtController::class, 'updateCreditLimit'])->name('customers.debts.credit_limit');

    Route::get('customers/{customer}', [Admin\CustomerController::class, 'show'])->name('customers.show');
    Route::put('customers/{customer}', [Admin\CustomerController::class, 'update'])->name('customers.update');
    Route::post('customers/{customer}/sms', [Admin\CustomerController::class, 'sendSms'])->name('customers.sms');
    Route::post('customers/{customer}/block', [Admin\CustomerController::class, 'block'])->name('customers.block');
    Route::post('customers/{customer}/unblock', [Admin\CustomerController::class, 'unblock'])->name('customers.unblock');
    Route::delete('customers/{customer}', [Admin\CustomerController::class, 'destroy'])->name('customers.destroy');

    // Reservations (branch-scoped)
    Route::get('reservations', [Admin\ReservationController::class, 'index'])->name('reservations.index');
    Route::get('reservations/{reservation}/edit', [Admin\ReservationController::class, 'edit'])->name('reservations.edit');
    Route::put('reservations/{reservation}', [Admin\ReservationController::class, 'update'])->name('reservations.update');
    Route::post('reservations/{reservation}/confirm', [Admin\ReservationController::class, 'confirm'])->name('reservations.confirm');
    Route::post('reservations/{reservation}/seat', [Admin\ReservationController::class, 'seat'])->name('reservations.seat');
    Route::post('reservations/{reservation}/complete', [Admin\ReservationController::class, 'complete'])->name('reservations.complete');
    Route::post('reservations/{reservation}/cancel', [Admin\ReservationController::class, 'cancel'])->name('reservations.cancel');
    Route::post('reservations/{reservation}/no-show', [Admin\ReservationController::class, 'noShow'])->name('reservations.no-show');

    // Tables — the triage board is Inertia/Vue (Wave 1, TablesBoardController);
    // classic CRUD forms stay on TableController. Literal `board`/`sessions`
    // segments are declared BEFORE the resource so tables/{table} wildcard
    // binding can't swallow them. resource excludes: index (the board owns
    // the URL) and show (was a dead route — resource declared it but the
    // controller never had a show method).
    Route::get('tables', [Admin\TablesBoardController::class, 'show'])->name('tables.index');
    Route::get('tables/board/pulse', [Admin\TablesBoardController::class, 'pulse'])->name('tables.board-pulse');
    Route::post('tables/board/close-stale', [Admin\TablesBoardController::class, 'closeStale'])->name('tables.close-stale');
    Route::post('tables/sessions/{session}/serve', [Admin\TablesBoardController::class, 'serve'])->name('tables.serve');
    Route::post('tables/sessions/{session}/ack-help', [Admin\TablesBoardController::class, 'ackHelp'])->name('tables.ack-help');
    Route::resource('tables', Admin\TableController::class)->except(['index', 'show']);
    Route::patch('tables/{table}/quick', [Admin\TableController::class, 'quickUpdate'])->name('tables.quick-update');
    Route::get('tables/{table}/qr', [Admin\TableController::class, 'qr'])->name('tables.qr');
    Route::get('tables/{table}/qr-print', [Admin\TableController::class, 'qrPrint'])->name('tables.qr-print');
    Route::post('tables/{table}/close-session', [Admin\TableController::class, 'closeSession'])->name('tables.close-session');
    Route::post('tables/{table}/transfer-session', [Admin\TableController::class, 'transferSession'])->name('tables.transfer');
    Route::post('tables/{table}/mark-clean', [Admin\TableController::class, 'markClean'])->name('tables.mark-clean');

    // Section roster — who covers which part of the floor today (Inertia/Vue,
    // Wave 1). The controller guards the dedicated section-assignment
    // permission (manager/cashier by default); every mutating action returns
    // the fresh roster map.
    Route::get('section-assignments', [Admin\SectionAssignmentController::class, 'show'])->name('section-assignments.index');
    Route::post('section-assignments/toggle', [Admin\SectionAssignmentController::class, 'toggle'])->name('section-assignments.toggle');
    Route::post('section-assignments/copy-previous', [Admin\SectionAssignmentController::class, 'copyPrevious'])->name('section-assignments.copy');
    Route::post('section-assignments/clear-day', [Admin\SectionAssignmentController::class, 'clearDay'])->name('section-assignments.clear');

    // Zones — merged into the unified Lookups admin (group='zones').
    // Old /admin/zones routes removed; see admin.lookups.* routes below.

    // Categories
    Route::patch('categories/{category}/toggle', [Admin\CategoryController::class, 'toggle'])->name('categories.toggle');
    Route::post('categories/{category}/move', [Admin\CategoryController::class, 'move'])->name('categories.move');
    Route::resource('categories', Admin\CategoryController::class)->except(['show']);

    // Menu Items
    Route::resource('menu-items', Admin\MenuItemController::class);
    Route::patch('menu-items/{menu_item}/toggle-availability', [Admin\MenuItemController::class, 'toggleAvailability'])->name('menu-items.toggle-availability');

    // Menu-item promotions — permanent sale prices, scheduled campaigns,
    // happy-hour windows. Full CRUD + a quick toggle so the manager can
    // pause/resume without editing the schedule.
    Route::resource('promotions', Admin\PromotionController::class)
        ->parameters(['promotions' => 'promotion'])
        ->except(['show']);
    Route::patch('promotions/{promotion}/toggle', [Admin\PromotionController::class, 'toggle'])->name('promotions.toggle');

    // Modifiers
    Route::resource('modifiers', Admin\ModifierGroupController::class);

    // Allergens
    Route::resource('allergens', Admin\AllergenController::class);

    // Ingredients
    Route::resource('ingredients', Admin\IngredientController::class);
    Route::post('ingredients/{ingredient}/adjust', [Admin\IngredientController::class, 'adjust'])->name('ingredients.adjust');
    Route::post('ingredients/{ingredient}/sub-recipe', [Admin\IngredientController::class, 'updateSubRecipe'])->name('ingredients.sub_recipe.update');
    Route::post('ingredients/{ingredient}/units', [Admin\IngredientController::class, 'updateUnits'])->name('ingredients.units.update');

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
        return PurchaseOrder::find($value)
            ?? throw new HttpResponseException(
                redirect()->route('admin.purchase-orders.index')
                    ->with('error', "أمر الشراء رقم #{$value} غير موجود أو تم حذفه.")
            );
    });
    Route::resource('purchase-orders', Admin\PurchaseOrderController::class);
    Route::post('purchase-orders/{purchase_order}/approve', [Admin\PurchaseOrderController::class, 'approve'])->name('purchase-orders.approve');
    Route::post('purchase-orders/{purchase_order}/send', [Admin\PurchaseOrderController::class, 'send'])->name('purchase-orders.send');
    Route::post('purchase-orders/{purchase_order}/cancel', [Admin\PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');
    Route::get('purchase-orders/{purchase_order}/receive', [Admin\PurchaseOrderController::class, 'receiveForm'])->name('purchase-orders.receive-form');
    Route::post('purchase-orders/{purchase_order}/receive', [Admin\PurchaseOrderController::class, 'receive'])->name('purchase-orders.receive');

    // Menu item cost recompute (from recipes)
    Route::post('menu-items/recompute-costs', [Admin\MenuItemController::class, 'recomputeCosts'])
        ->name('menu-items.recompute-costs');

    // Refunds (issued against paid invoices)
    Route::get('refunds', [Admin\RefundController::class, 'index'])->name('refunds.index');
    Route::post('invoices/{invoice}/refunds', [Admin\RefundController::class, 'store'])->name('refunds.store');
    Route::post('refunds/{refund}/complete', [Admin\RefundController::class, 'complete'])->name('refunds.complete');
    Route::post('refunds/{refund}/cancel', [Admin\RefundController::class, 'cancel'])->name('refunds.cancel');
    Route::post('refunds/{refund}/reverse', [Admin\RefundController::class, 'reverse'])->name('refunds.reverse');

    // Supplier Invoices (Accounts Payable)
    Route::resource('supplier-invoices', Admin\SupplierInvoiceController::class)->except(['edit', 'update']);
    Route::post('supplier-invoices/{supplier_invoice}/pay', [Admin\SupplierInvoiceController::class, 'pay'])->name('supplier-invoices.pay');
    Route::post('supplier-invoices/{supplier_invoice}/cancel', [Admin\SupplierInvoiceController::class, 'cancel'])->name('supplier-invoices.cancel');

    // Stock Counts (physical inventory / جرد)
    Route::resource('stock-counts', Admin\StockCountController::class)->except(['edit', 'update']);
    Route::get('stock-counts/{stock_count}/export.xlsx', [Admin\StockCountController::class, 'export'])->name('stock-counts.export.xlsx');
    Route::post('stock-counts/{stock_count}/save-counts', [Admin\StockCountController::class, 'saveCounts'])->name('stock-counts.save-counts');
    Route::post('stock-counts/{stock_count}/finalize', [Admin\StockCountController::class, 'finalize'])->name('stock-counts.finalize');
    Route::post('stock-counts/{stock_count}/cancel', [Admin\StockCountController::class, 'cancel'])->name('stock-counts.cancel');

    // Ingredient batches (expiry tracking + FIFO)
    Route::get('batches', [Admin\IngredientBatchController::class, 'index'])->name('batches.index');
    Route::post('batches', [Admin\IngredientBatchController::class, 'store'])->name('batches.store');

    // Inter-Branch Transfers — moves stock between FROM-branch and TO-branch
    Route::get('branch-transfers', [Admin\BranchTransferController::class, 'index'])->name('branch-transfers.index');
    Route::get('branch-transfers/create', [Admin\BranchTransferController::class, 'create'])->name('branch-transfers.create');
    Route::post('branch-transfers', [Admin\BranchTransferController::class, 'store'])->name('branch-transfers.store');
    Route::get('branch-transfers/{branch_transfer}', [Admin\BranchTransferController::class, 'show'])->name('branch-transfers.show');
    Route::post('branch-transfers/{branch_transfer}/send', [Admin\BranchTransferController::class, 'send'])->name('branch-transfers.send');
    Route::post('branch-transfers/{branch_transfer}/receive', [Admin\BranchTransferController::class, 'receive'])->name('branch-transfers.receive');
    Route::post('branch-transfers/{branch_transfer}/cancel', [Admin\BranchTransferController::class, 'cancel'])->name('branch-transfers.cancel');

    // Storage Locations (multi-location inventory)
    Route::get('storage-locations/transfer', [Admin\StorageLocationController::class, 'transferForm'])->name('storage-locations.transfer-form');
    Route::post('storage-locations/transfer', [Admin\StorageLocationController::class, 'transferStore'])->name('storage-locations.transfer-store');
    Route::resource('storage-locations', Admin\StorageLocationController::class);

    // Inventory command center + movements
    Route::get('inventory-dashboard', [Admin\InventoryController::class, 'dashboard'])->name('inventory.dashboard');
    Route::get('inventory', [Admin\InventoryController::class, 'index'])->name('inventory.index');
    Route::get('inventory/by-barcode', [Admin\InventoryController::class, 'lookupByBarcode'])->name('inventory.by_barcode');

    // Waste — dedicated logging surface (forces a reason + optional batch link)
    Route::get('waste', [Admin\WasteController::class, 'index'])->name('waste.index');
    Route::get('waste/create', [Admin\WasteController::class, 'create'])->name('waste.create');
    Route::post('waste', [Admin\WasteController::class, 'store'])->name('waste.store');
    Route::get('waste/batches/{ingredient}', [Admin\WasteController::class, 'batchesForIngredient'])->name('waste.batches');

    // Units
    Route::resource('units', Admin\UnitController::class);

    // Stations
    Route::resource('stations', Admin\StationController::class);

    // Orders (waiter/manager) — board is the default view; classic table is secondary
    // Service board — Inertia/Vue since Wave 3. Literal segments come
    // BEFORE orders/{order} so the wildcard can't swallow them.
    Route::get('orders', [Admin\ServiceBoardController::class, 'show'])->name('orders.index');
    Route::get('orders/board/pulse', [Admin\ServiceBoardController::class, 'pulse'])->name('orders.board-pulse');
    Route::post('orders/board/action', [Admin\ServiceBoardController::class, 'action'])->name('orders.board-action');
    Route::get('orders/list', [Admin\OrderController::class, 'index'])->name('orders.list');         // Classic table
    Route::get('orders/archive', [Admin\OrderController::class, 'archive'])->name('orders.archive');    // Comprehensive search/filter
    Route::post('orders/bulk-approve', [Admin\OrderController::class, 'bulkApprove'])->name('orders.bulk-approve');
    Route::get('orders/{order}', [Admin\OrderController::class, 'show'])->name('orders.show');
    Route::post('orders/{order}/approve', [Admin\OrderController::class, 'approve'])->name('orders.approve');
    Route::post('orders/{order}/unapprove', [Admin\OrderController::class, 'unapprove'])->name('orders.unapprove');
    Route::post('orders/{order}/transition', [Admin\OrderController::class, 'transition'])->name('orders.transition');
    Route::post('orders/{order}/cancel', [Admin\OrderController::class, 'cancel'])->name('orders.cancel');
    Route::post('orders/items/{item}/cancel', [Admin\OrderController::class, 'cancelItem'])->name('orders.items.cancel');
    Route::post('orders/items/{item}/serve', [Admin\OrderController::class, 'serveItem'])->name('orders.items.serve');
    Route::post('order-change-requests/{changeRequest}/resolve', [Admin\OrderChangeRequestController::class, 'resolve'])->name('order-change-requests.resolve');

    // The tables board is the one floor entry point. Keep the old picker URL
    // as a redirect for saved bookmarks; the per-table order builder below
    // remains the actual staff order-entry screen.
    Route::redirect('waiter-orders', '/admin/tables')->name('waiter-orders.index');

    // شاشة الطلب الرسمية (Vue). الشاشة القديمة ومساراتها حُذفت نهائياً
    // بقرار صاحب المشروع 2026-08-11 — كل الفلو (وجبة موظف، زبون، حوالة)
    // صار في WaiterPosVueController والـ API أعلى الملف.
    Route::get('waiter-orders/table/{table}', [Admin\WaiterPosVueController::class, 'show'])->name('waiter-orders.create');

    // Staff meal allowance — per-employee monthly tabs (manager view)
    Route::get('staff-meals', [Admin\StaffMealController::class, 'index'])->name('staff-meals.index');
    Route::get('staff-meals/quick-consume', [Admin\StaffMealController::class, 'quickConsumeForm'])->name('staff-meals.quick_consume');
    Route::post('staff-meals/quick-consume', [Admin\StaffMealController::class, 'quickConsumeStore'])->name('staff-meals.quick_consume.store');
    Route::post('staff-meals/employees', [Admin\StaffMealController::class, 'storeEmployee'])->name('staff-meals.employees.store');
    Route::get('staff-meals/closures', [Admin\StaffMealController::class, 'closures'])->name('staff-meals.closures');
    Route::post('staff-meals/close-month', [Admin\StaffMealController::class, 'closeMonth'])->name('staff-meals.close_month');
    Route::get('staff-meals/closures/{closure}', [Admin\StaffMealController::class, 'closureShow'])->name('staff-meals.closures.show');
    Route::get('staff-meals/{employee}', [Admin\StaffMealController::class, 'show'])->name('staff-meals.show');
    Route::post('staff-meals/{employee}/settle', [Admin\StaffMealController::class, 'settle'])->name('staff-meals.settle');
    Route::post('staff-meals/charges/{charge}/waive', [Admin\StaffMealController::class, 'waiveCharge'])->name('staff-meals.charges.waive');

    // Station displays — Inertia/Vue KDS since Wave 3. One generic route
    // handles every station code; the controller checks the matching
    // `station.{code}.view` permission so access is driven by the roles UI.
    Route::get('station/{code}', [Admin\KitchenBoardController::class, 'show'])
        ->where('code', '[a-z0-9_-]+')
        ->name('station.show');
    Route::get('station/{code}/pulse', [Admin\KitchenBoardController::class, 'pulse'])
        ->where('code', '[a-z0-9_-]+')
        ->name('station.pulse');
    Route::post('station/{code}/action', [Admin\KitchenBoardController::class, 'action'])
        ->where('code', '[a-z0-9_-]+')
        ->name('station.action');
    // Back-compat aliases — old /admin/kitchen and /admin/bar links still work.
    Route::redirect('kitchen', 'admin/station/kitchen')->name('kitchen.index');
    Route::redirect('bar', 'admin/station/bar')->name('bar.index');
    // NOTE: every item transition rides POST station/{code}/action
    // (KitchenBoardController) since the Wave-3 Vue migration.

    // Cashier / Billing
    require base_path('routes/cashier-vue.php');
    Route::post('cashier/session/{session}/issue', [Admin\CashierController::class, 'issue'])->name('cashier.issue');
    Route::post('cashier/invoice/{invoice}/pay', [Admin\CashierController::class, 'pay'])->name('cashier.pay');
    Route::post('cashier/payments/{payment}/void', [Admin\CashierController::class, 'voidPayment'])->name('cashier.payments.void');
    Route::post('cashier/invoice/{invoice}/writeoff', [Admin\CashierController::class, 'writeoff'])->name('cashier.writeoff');
    Route::post('cashier/invoice/{invoice}/cancel', [Admin\CashierController::class, 'cancel'])->name('cashier.cancel');
    Route::post('cashier/invoice/{invoice}/settle-on-account', [Admin\CashierController::class, 'settleOnAccount'])->name('cashier.settle_on_account');
    Route::post('cashier/invoice/{invoice}/unpark', [Admin\CashierController::class, 'unpark'])->name('cashier.unpark');
    Route::post('cashier/invoice/{invoice}/split', [Admin\CashierController::class, 'split'])->name('cashier.split');
    Route::post('cashier/invoice/{invoice}/split/{split}/pay', [Admin\CashierController::class, 'paySplit'])->name('cashier.split.pay');
    Route::delete('cashier/invoice/{invoice}/splits', [Admin\CashierController::class, 'clearSplits'])->name('cashier.split.clear');
    Route::post('cashier/order/{order}/discount', [Admin\CashierController::class, 'applyDiscountToOrder'])->name('cashier.discount.order');
    Route::post('cashier/session/{session}/discount', [Admin\CashierController::class, 'applyDiscountToSession'])->name('cashier.discount.session');
    Route::delete('cashier/discount/{discount}', [Admin\CashierController::class, 'removeDiscount'])->name('cashier.discount.remove');
    Route::get('cashier/invoice/{invoice}/pdf', [Admin\CashierController::class, 'pdf'])->name('cashier.pdf');
    Route::get('cashier/invoice/{invoice}/print', [Admin\CashierController::class, 'print'])->name('cashier.print');

    // Pending bank transfers — waiter/cashier/customer claim, cashier verifies
    Route::post('waiter-orders/{session}/transfer', [Admin\PendingTransferController::class, 'store'])->name('waiter-orders.transfer.store');
    Route::post('cashier/session/{session}/transfer', [Admin\PendingTransferController::class, 'store'])->name('cashier.transfers.store');
    Route::get('cashier/transfers', [Admin\PendingTransferController::class, 'queue'])->name('cashier.transfers.queue');
    Route::get('cashier/transfers/{transfer}/proof', [Admin\PendingTransferController::class, 'proof'])->name('cashier.transfers.proof');
    Route::post('cashier/transfers/{transfer}/verify', [Admin\PendingTransferController::class, 'verify'])->name('cashier.transfers.verify');
    Route::post('cashier/transfers/{transfer}/reject', [Admin\PendingTransferController::class, 'reject'])->name('cashier.transfers.reject');
    Route::post('cashier/transfers/{transfer}/reopen', [Admin\PendingTransferController::class, 'reopen'])->name('cashier.transfers.reopen');
    Route::get('cashier/transfers/report', [Admin\PendingTransferController::class, 'report'])->name('cashier.transfers.report');

    // Expenses (branch-scoped) — see ExpenseController for the approval flow.
    Route::get('expenses', [Admin\ExpenseController::class, 'index'])->name('expenses.index');
    Route::get('expenses/create', [Admin\ExpenseController::class, 'create'])->name('expenses.create');
    Route::post('expenses', [Admin\ExpenseController::class, 'store'])->name('expenses.store');
    Route::get('expenses/{expense}/edit', [Admin\ExpenseController::class, 'edit'])->name('expenses.edit');
    Route::put('expenses/{expense}', [Admin\ExpenseController::class, 'update'])->name('expenses.update');
    Route::post('expenses/{expense}/approve', [Admin\ExpenseController::class, 'approve'])->name('expenses.approve');
    Route::post('expenses/{expense}/reject', [Admin\ExpenseController::class, 'reject'])->name('expenses.reject');
    Route::delete('expenses/{expense}', [Admin\ExpenseController::class, 'destroy'])->name('expenses.destroy');

    // Reports
    Route::prefix('reports')->name('reports.')->middleware('permission:reports.viewAny')->group(function () {
        Route::get('/', [Admin\ReportController::class, 'index'])->name('index');
        Route::get('sales', [Admin\ReportController::class, 'sales'])->name('sales');
        Route::get('items', [Admin\ReportController::class, 'items'])->name('items');
        Route::get('inventory', [Admin\ReportController::class, 'inventory'])->name('inventory');
        Route::get('consumption-variance', [Admin\ReportController::class, 'consumptionVariance'])->name('consumption-variance');
        Route::get('end-of-day', [Admin\ReportController::class, 'endOfDay'])->name('end-of-day');
        Route::get('profit-loss', [Admin\ReportController::class, 'profitLoss'])->name('profit-loss');
        Route::get('profit-loss/export.xlsx', [Admin\ReportController::class, 'profitLossExportXlsx'])
            ->middleware('permission:reports.export')->name('profit-loss.export.xlsx');
        Route::get('profit-loss/export.pdf', [Admin\ReportController::class, 'profitLossExportPdf'])
            ->middleware('permission:reports.export')->name('profit-loss.export.pdf');
        Route::get('menu-engineering', [Admin\ReportController::class, 'menuEngineering'])->name('menu-engineering');
        Route::get('reorder-suggestions', [Admin\ReportController::class, 'reorderSuggestions'])->name('reorder-suggestions');
        Route::post('reorder-suggestions/bulk-create-pos',
            [Admin\ReportController::class, 'createBulkReorderPOs'])->name('reorder-suggestions.bulk-create');
        Route::get('stock-valuation', [Admin\ReportController::class, 'stockValuation'])->name('stock-valuation');
        Route::get('branch-comparison', [Admin\ReportController::class, 'branchComparison'])->name('branch-comparison');
    });

    // Accounting review
    Route::prefix('accounting')->name('accounting.')->group(function () {
        Route::get('/', [Admin\AccountingController::class, 'index'])->name('index');
        Route::post('tax-configuration', [Admin\AccountingController::class, 'storeTaxConfiguration'])->name('tax-configuration.store');
        Route::delete('tax-configuration/{taxRate}', [Admin\AccountingController::class, 'destroyCustomerSalesTaxRate'])->name('tax-configuration.destroy');
        Route::get('guide', [Admin\AccountingController::class, 'guide'])->name('guide');
        Route::get('journal', [Admin\AccountingController::class, 'journal'])->name('journal');
        Route::get('ledger', [Admin\AccountingController::class, 'ledger'])->name('ledger');
        Route::get('journal/export.csv', [Admin\AccountingController::class, 'exportJournalCsv'])->name('journal.export.csv');
        Route::get('trial-balance', [Admin\AccountingController::class, 'trialBalance'])->name('trial-balance');
        Route::get('balance-sheet', [Admin\AccountingController::class, 'balanceSheet'])->name('balance-sheet');
        Route::get('tax-report', [Admin\AccountingController::class, 'taxReport'])->name('tax-report');
        Route::get('aging', [Admin\AccountingController::class, 'aging'])->name('aging');
        Route::get('fixed-assets', [Admin\FixedAssetController::class, 'index'])->name('fixed-assets.index');
        Route::get('fixed-assets/create', [Admin\FixedAssetController::class, 'create'])->name('fixed-assets.create');
        Route::post('fixed-assets', [Admin\FixedAssetController::class, 'store'])->name('fixed-assets.store');
        Route::post('fixed-assets/depreciation/run', [Admin\FixedAssetController::class, 'runDepreciation'])->name('fixed-assets.depreciation-run');
        Route::get('fixed-assets/{fixedAsset}', [Admin\FixedAssetController::class, 'show'])->name('fixed-assets.show');
        Route::post('fixed-assets/{fixedAsset}/depreciation', [Admin\FixedAssetController::class, 'storeDepreciation'])->name('fixed-assets.depreciation');
        Route::post('fixed-assets/{fixedAsset}/dispose', [Admin\FixedAssetController::class, 'dispose'])->name('fixed-assets.dispose');
        Route::get('opening-balances', [Admin\AccountingController::class, 'openingBalances'])->name('opening-balances');
        Route::post('opening-balances', [Admin\AccountingController::class, 'storeOpeningBalances'])->name('opening-balances.store');
        Route::post('opening-balances/customer-debt', [Admin\AccountingController::class, 'storeCustomerOpeningDebt'])->name('opening-balances.customer-debt.store');
        Route::post('opening-balances/customer-advance', [Admin\AccountingController::class, 'storeCustomerAdvanceOpeningBalance'])->name('opening-balances.customer-advance.store');
        Route::post('opening-balances/supplier-debt', [Admin\AccountingController::class, 'storeSupplierOpeningDebt'])->name('opening-balances.supplier-debt.store');
        Route::get('periods', [Admin\AccountingController::class, 'periods'])->name('periods');
        Route::post('periods', [Admin\AccountingController::class, 'storePeriod'])->name('periods.store');
        Route::put('periods/{period}', [Admin\AccountingController::class, 'updatePeriod'])->name('periods.update');
        Route::delete('periods/{period}', [Admin\AccountingController::class, 'destroyPeriod'])->name('periods.destroy');
        Route::post('periods/{period}/close', [Admin\AccountingController::class, 'closePeriod'])->name('periods.close');
        Route::post('periods/{period}/reopen', [Admin\AccountingController::class, 'reopenPeriod'])->name('periods.reopen');
        Route::get('fiscal-years', [Admin\AccountingController::class, 'fiscalYears'])->name('fiscal-years');
        Route::post('fiscal-years', [Admin\AccountingController::class, 'storeFiscalYear'])->name('fiscal-years.store');
        Route::put('fiscal-years/{year}', [Admin\AccountingController::class, 'updateFiscalYear'])->name('fiscal-years.update');
        Route::delete('fiscal-years/{year}', [Admin\AccountingController::class, 'destroyFiscalYear'])->name('fiscal-years.destroy');
        Route::post('fiscal-years/{year}/close', [Admin\AccountingController::class, 'closeFiscalYear'])->name('fiscal-years.close');
        Route::post('fiscal-years/{year}/reopen', [Admin\AccountingController::class, 'reopenFiscalYear'])->name('fiscal-years.reopen');
        Route::get('reconciliations', [Admin\AccountingController::class, 'reconciliations'])->name('reconciliations');
        Route::post('reconciliations', [Admin\AccountingController::class, 'storeReconciliation'])->name('reconciliations.store');
        Route::post('reconciliations/{reconciliation}/resolve', [Admin\AccountingController::class, 'resolveReconciliation'])->name('reconciliations.resolve');
        Route::get('settlements', [Admin\AccountingController::class, 'settlements'])->name('settlements');
        Route::post('settlements/tax-payment', [Admin\AccountingController::class, 'storeTaxPayment'])->name('settlements.tax-payment');
        Route::post('settlements/tips-payout', [Admin\AccountingController::class, 'storeTipPayout'])->name('settlements.tips-payout');
        Route::post('settlements/wallet-transfer', [Admin\AccountingController::class, 'storeWalletTransfer'])->name('settlements.wallet-transfer');
        // Manual journal entry — the bridge that lets accountants actually
        // post to their custom chart accounts (otherwise the chart is read-only).
        Route::get('manual-entry', [Admin\AccountingController::class, 'createManualEntry'])->name('manual-entry.create');
        Route::post('manual-entry', [Admin\AccountingController::class, 'storeManualEntry'])->name('manual-entry.store');
        Route::get('mappings', [Admin\AccountingController::class, 'accountMappings'])->name('mappings');
        Route::post('mappings', [Admin\AccountingController::class, 'storeAccountMappings'])->name('mappings.store');
        Route::get('journal/{entry}/adjust', [Admin\AccountingController::class, 'createEntryAdjustment'])->name('journal.adjust.create');
        Route::post('journal/{entry}/adjust', [Admin\AccountingController::class, 'storeEntryAdjustment'])->name('journal.adjust.store');
    });

    // Chart of accounts — accountant CRUD with system-account guards.
    // The tree UI hits the JSON endpoint via fetch() for the edit modal;
    // store/update/destroy auto-detect XHR via request()->wantsJson()
    // and return JSON instead of redirects.
    Route::resource('accounts', Admin\AccountController::class)->except(['show']);
    Route::get('accounts/{account}/json', [Admin\AccountController::class, 'showJson'])->name('accounts.show_json');
    Route::patch('accounts/{account}/toggle', [Admin\AccountController::class, 'toggle'])->name('accounts.toggle');

    // Currencies (multi-currency display)
    Route::get('currencies', [Admin\CurrencyController::class, 'index'])->name('currencies.index');
    Route::post('currencies', [Admin\CurrencyController::class, 'store'])->name('currencies.store');
    Route::post('currencies/update-rates', [Admin\CurrencyController::class, 'updateRates'])->name('currencies.update-rates');
    Route::post('currencies/exchange-rates', [Admin\CurrencyController::class, 'storeExchangeRate'])->name('currencies.exchange-rates.store');
    Route::delete('currencies/exchange-rates/{exchangeRate}', [Admin\CurrencyController::class, 'destroyExchangeRate'])->name('currencies.exchange-rates.destroy');
    Route::delete('currencies/{currency}', [Admin\CurrencyController::class, 'destroy'])->name('currencies.destroy');

    // Lookups (soft-enum management — categories etc.)
    Route::get('lookups', [Admin\LookupController::class, 'index'])->name('lookups.index');
    Route::post('lookups', [Admin\LookupController::class, 'store'])->name('lookups.store');
    Route::put('lookups/{lookup}', [Admin\LookupController::class, 'update'])->name('lookups.update');
    Route::delete('lookups/{lookup}', [Admin\LookupController::class, 'destroy'])->name('lookups.destroy');
    Route::post('lookups/{id}/restore', [Admin\LookupController::class, 'restore'])->name('lookups.restore');

    // Settings
    Route::get('settings', [Admin\SettingController::class, 'index'])->name('settings.index');
    Route::put('settings', [Admin\SettingController::class, 'update'])->name('settings.update');
    Route::post('settings/reset-theme', [Admin\SettingController::class, 'resetTheme'])->name('settings.reset-theme');
    Route::post('settings/sms/test', [Admin\SettingController::class, 'testSms'])->name('settings.sms.test');
    Route::post('settings/brand', [Admin\SettingController::class, 'updateBrand'])->name('settings.brand.update');
    Route::delete('settings/brand/{key}', [Admin\SettingController::class, 'deleteBrand'])->name('settings.brand.delete');

    // Activity logs
    Route::get('activity-logs', [Admin\ActivityLogController::class, 'index'])->name('activity-logs.index');

    // Cloud sync status + manual trigger (auto-sync still runs every minute).
    Route::get('sync', [Admin\SyncStatusController::class, 'index'])->name('sync.index');
    Route::post('sync/run', [Admin\SyncStatusController::class, 'run'])->name('sync.run');
});
