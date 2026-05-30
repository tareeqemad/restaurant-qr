<?php

namespace App\Sync;

use App\Models\Account;
use App\Models\Allergen;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\BranchTransfer;
use App\Models\BranchTransferItem;
use App\Models\CashMovement;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Discount;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\IngredientStock;
use App\Models\IngredientUnit;
use App\Models\InventoryMovement;
use App\Models\InventorySnapshot;
use App\Models\Invoice;
use App\Models\InvoiceSplit;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Lookup;
use App\Models\LoyaltyCustomer;
use App\Models\LoyaltyTransaction;
use App\Models\MenuItem;
use App\Models\MenuPromotion;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\ModifierRecipeItem;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\Payment;
use App\Models\PendingTransfer;
use App\Models\Permission;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use App\Models\RecipeItem;
use App\Models\Refund;
use App\Models\Reservation;
use App\Models\Review;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\StaffMealCharge;
use App\Models\StaffMealMonthClosure;
use App\Models\Station;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\StorageLocation;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\SupplierPayment;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Unit;
use App\Models\User;

class SyncRegistry
{
    public const UUID_COLUMN = 'uuid';

    /**
     * Cloud-owned reference/config tables. Branches pull these down.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function downTables(): array
    {
        return [
            'branches' => [
                'natural' => ['code'],
            ],
            'settings' => [
                'natural' => ['key'],
            ],
            'currencies' => [
                'natural' => ['code'],
            ],
            'lookups' => [
                'foreigns' => ['branch_id' => 'branches'],
                'natural' => ['group', 'branch_id', 'code'],
            ],
            'units' => [
                'natural' => ['code'],
            ],
            'allergens' => [
                'natural' => ['code'],
            ],
            'suppliers' => [
                'natural' => ['name'],
            ],
            'branch_supplier' => [
                'foreigns' => ['branch_id' => 'branches', 'supplier_id' => 'suppliers'],
                'natural' => ['branch_id', 'supplier_id'],
            ],
            'storage_locations' => [
                'foreigns' => ['branch_id' => 'branches'],
                'natural' => ['branch_id', 'code'],
            ],
            'stations' => [
                'foreigns' => ['branch_id' => 'branches', 'storage_location_id' => 'storage_locations'],
                'natural' => ['branch_id', 'code'],
            ],
            'roles' => [
                'foreigns' => ['branch_id' => 'branches'],
                'natural' => ['name'],
            ],
            'permissions' => [
                'natural' => ['name'],
            ],
            'role_permission' => [
                'foreigns' => ['role_id' => 'roles', 'permission_id' => 'permissions'],
                'natural' => ['role_id', 'permission_id'],
            ],
            'users' => [
                'foreigns' => ['station_id' => 'stations'],
                'natural' => ['username'],
                'exclude' => ['remember_token'],
            ],
            'branch_user' => [
                'foreigns' => ['branch_id' => 'branches', 'user_id' => 'users', 'role_id' => 'roles'],
                'natural' => ['branch_id', 'user_id'],
            ],
            'user_permission' => [
                'foreigns' => ['user_id' => 'users', 'permission_id' => 'permissions'],
                'natural' => ['user_id', 'permission_id'],
            ],
            'categories' => [
                'foreigns' => ['branch_id' => 'branches', 'default_station_id' => 'stations'],
                'natural' => ['branch_id', 'slug'],
            ],
            'menu_items' => [
                'foreigns' => ['branch_id' => 'branches', 'category_id' => 'categories', 'station_id' => 'stations'],
                'natural' => ['branch_id', 'sku'],
            ],
            'menu_item_allergens' => [
                'foreigns' => ['menu_item_id' => 'menu_items', 'allergen_id' => 'allergens'],
                'natural' => ['menu_item_id', 'allergen_id'],
            ],
            'modifier_groups' => [
                'foreigns' => ['branch_id' => 'branches'],
                'natural' => ['branch_id', 'slug'],
            ],
            'modifiers' => [
                'foreigns' => ['modifier_group_id' => 'modifier_groups'],
                'natural' => ['modifier_group_id', 'name'],
            ],
            'menu_item_modifier_group' => [
                'foreigns' => ['menu_item_id' => 'menu_items', 'modifier_group_id' => 'modifier_groups'],
                'natural' => ['menu_item_id', 'modifier_group_id'],
            ],
            'ingredients' => [
                'foreigns' => ['base_unit_id' => 'units', 'supplier_id' => 'suppliers'],
                'natural' => ['sku'],
            ],
            'ingredient_units' => [
                'foreigns' => ['ingredient_id' => 'ingredients', 'unit_id' => 'units'],
                'natural' => ['ingredient_id', 'unit_id', 'barcode'],
            ],
            'recipe_items' => [
                'foreigns' => [
                    'menu_item_id' => 'menu_items',
                    'ingredient_id' => 'ingredients',
                    'unit_id' => 'units',
                    'parent_ingredient_id' => 'ingredients',
                    'ingredient_unit_id' => 'ingredient_units',
                ],
                'natural' => ['menu_item_id', 'parent_ingredient_id', 'ingredient_id'],
            ],
            'modifier_recipe_items' => [
                'foreigns' => ['modifier_id' => 'modifiers', 'ingredient_id' => 'ingredients', 'unit_id' => 'units'],
                'natural' => ['modifier_id', 'ingredient_id'],
            ],
            'discounts' => [
                'natural' => ['code'],
            ],
            'menu_promotions' => [
                'foreigns' => ['branch_id' => 'branches', 'created_by_user_id' => 'users'],
            ],
            'accounts' => [
                'foreigns' => ['parent_account_id' => 'accounts'],
                'natural' => ['code'],
            ],
            'tables' => [
                'foreigns' => ['branch_id' => 'branches', 'zone_lookup_id' => 'lookups'],
                'natural' => ['branch_id', 'number'],
            ],
        ];
    }

    /**
     * Branch-owned operational tables. Branches push these up.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function upTables(): array
    {
        return [
            'loyalty_customers' => [
                'natural' => ['phone'],
            ],
            'customers' => [
                'foreigns' => ['default_branch_id' => 'branches', 'loyalty_customer_id' => 'loyalty_customers'],
                'natural' => ['phone'],
            ],
            'customer_addresses' => [
                'foreigns' => ['customer_id' => 'customers'],
            ],
            'shifts' => [
                'foreigns' => ['branch_id' => 'branches', 'user_id' => 'users', 'closed_by_user_id' => 'users'],
            ],
            'table_sessions' => [
                'foreigns' => [
                    'branch_id' => 'branches',
                    'table_id' => 'tables',
                    'customer_id' => 'customers',
                    'opened_by_user_id' => 'users',
                    'assigned_waiter_id' => 'users',
                ],
                'natural' => ['token'],
            ],
            'orders' => [
                'foreigns' => [
                    'branch_id' => 'branches',
                    'table_id' => 'tables',
                    'table_session_id' => 'table_sessions',
                    'customer_id' => 'customers',
                    'customer_address_id' => 'customer_addresses',
                    'created_by_user_id' => 'users',
                    'approved_by_user_id' => 'users',
                    'cancelled_by_user_id' => 'users',
                    'staff_consumer_user_id' => 'users',
                ],
                'natural' => ['number'],
            ],
            'order_items' => [
                'foreigns' => [
                    'order_id' => 'orders',
                    'menu_item_id' => 'menu_items',
                    'station_id' => 'stations',
                    'prepared_by_user_id' => 'users',
                    'served_by_user_id' => 'users',
                    'cancelled_by_user_id' => 'users',
                    'promotion_id' => 'menu_promotions',
                ],
            ],
            'order_item_modifiers' => [
                'foreigns' => ['order_item_id' => 'order_items', 'modifier_id' => 'modifiers'],
            ],
            'order_discounts' => [
                'foreigns' => ['branch_id' => 'branches', 'order_id' => 'orders', 'discount_id' => 'discounts', 'applied_by_user_id' => 'users'],
            ],
            'branch_transfers' => [
                'foreigns' => [
                    'from_branch_id' => 'branches',
                    'to_branch_id' => 'branches',
                    'created_by_user_id' => 'users',
                    'sent_by_user_id' => 'users',
                    'received_by_user_id' => 'users',
                ],
                'natural' => ['number'],
            ],
            'branch_transfer_items' => [
                'foreigns' => [
                    'branch_transfer_id' => 'branch_transfers',
                    'ingredient_id' => 'ingredients',
                    'from_location_id' => 'storage_locations',
                    'to_location_id' => 'storage_locations',
                ],
            ],
            'ingredient_batches' => [
                'foreigns' => ['branch_id' => 'branches', 'ingredient_id' => 'ingredients', 'storage_location_id' => 'storage_locations'],
            ],
            'ingredient_stock' => [
                'foreigns' => ['ingredient_id' => 'ingredients', 'storage_location_id' => 'storage_locations'],
                'natural' => ['ingredient_id', 'storage_location_id'],
            ],
            'inventory_movements' => [
                'foreigns' => [
                    'branch_id' => 'branches',
                    'ingredient_id' => 'ingredients',
                    'batch_id' => 'ingredient_batches',
                    'storage_location_id' => 'storage_locations',
                    'unit_id' => 'units',
                    'user_id' => 'users',
                    'waste_reason_lookup_id' => 'lookups',
                ],
                'morphs' => ['reference' => ['type' => 'reference_type', 'id' => 'reference_id']],
            ],
            'invoices' => [
                'foreigns' => [
                    'branch_id' => 'branches',
                    'table_session_id' => 'table_sessions',
                    'order_id' => 'orders',
                    'customer_id' => 'customers',
                    'issued_by_user_id' => 'users',
                    'loyalty_customer_id' => 'loyalty_customers',
                    'settled_on_account_by_user_id' => 'users',
                ],
                'natural' => ['number'],
            ],
            'invoice_splits' => [
                'foreigns' => ['branch_id' => 'branches', 'invoice_id' => 'invoices'],
            ],
            'payments' => [
                'foreigns' => ['branch_id' => 'branches', 'invoice_id' => 'invoices', 'received_by_user_id' => 'users', 'shift_id' => 'shifts'],
            ],
            'refunds' => [
                'foreigns' => ['branch_id' => 'branches', 'invoice_id' => 'invoices', 'payment_id' => 'payments', 'processed_by' => 'users', 'shift_id' => 'shifts'],
                'natural' => ['number'],
            ],
            'loyalty_transactions' => [
                'foreigns' => ['loyalty_customer_id' => 'loyalty_customers', 'invoice_id' => 'invoices', 'order_id' => 'orders', 'user_id' => 'users'],
            ],
            'cash_movements' => [
                'foreigns' => ['branch_id' => 'branches', 'shift_id' => 'shifts', 'user_id' => 'users'],
            ],
            'expenses' => [
                'foreigns' => [
                    'branch_id' => 'branches',
                    'expense_category_id' => 'lookups',
                    'supplier_id' => 'suppliers',
                    'shift_id' => 'shifts',
                    'cash_movement_id' => 'cash_movements',
                    'created_by_user_id' => 'users',
                    'approved_by_user_id' => 'users',
                ],
                'natural' => ['branch_id', 'expense_number'],
            ],
            'purchase_orders' => [
                'foreigns' => ['branch_id' => 'branches', 'supplier_id' => 'suppliers', 'created_by' => 'users', 'approved_by' => 'users', 'received_by' => 'users'],
                'natural' => ['number'],
            ],
            'purchase_order_items' => [
                'foreigns' => ['purchase_order_id' => 'purchase_orders', 'ingredient_id' => 'ingredients', 'unit_id' => 'units', 'ingredient_unit_id' => 'ingredient_units'],
            ],
            'purchase_receipts' => [
                'foreigns' => ['branch_id' => 'branches', 'purchase_order_id' => 'purchase_orders', 'supplier_id' => 'suppliers', 'received_by' => 'users'],
                'natural' => ['number'],
            ],
            'purchase_receipt_items' => [
                'foreigns' => [
                    'purchase_receipt_id' => 'purchase_receipts',
                    'purchase_order_item_id' => 'purchase_order_items',
                    'ingredient_id' => 'ingredients',
                    'unit_id' => 'units',
                    'storage_location_id' => 'storage_locations',
                    'batch_id' => 'ingredient_batches',
                    'ingredient_unit_id' => 'ingredient_units',
                ],
            ],
            'supplier_invoices' => [
                'foreigns' => ['branch_id' => 'branches', 'supplier_id' => 'suppliers', 'purchase_order_id' => 'purchase_orders', 'created_by' => 'users'],
                'natural' => ['branch_id', 'supplier_id', 'number'],
            ],
            'supplier_invoice_items' => [
                'foreigns' => ['supplier_invoice_id' => 'supplier_invoices', 'purchase_order_item_id' => 'purchase_order_items', 'ingredient_id' => 'ingredients', 'unit_id' => 'units', 'ingredient_unit_id' => 'ingredient_units'],
            ],
            'supplier_payments' => [
                'foreigns' => ['branch_id' => 'branches', 'supplier_invoice_id' => 'supplier_invoices', 'paid_by' => 'users', 'shift_id' => 'shifts'],
            ],
            'stock_counts' => [
                'foreigns' => ['branch_id' => 'branches', 'storage_location_id' => 'storage_locations', 'created_by' => 'users', 'finalized_by' => 'users'],
                'natural' => ['number'],
            ],
            'stock_count_items' => [
                'foreigns' => ['stock_count_id' => 'stock_counts', 'ingredient_id' => 'ingredients', 'unit_id' => 'units'],
            ],
            'attendances' => [
                'foreigns' => ['branch_id' => 'branches', 'user_id' => 'users', 'edited_by_user_id' => 'users'],
            ],
            'reservations' => [
                'foreigns' => ['branch_id' => 'branches', 'customer_id' => 'customers', 'table_id' => 'tables', 'created_by_user_id' => 'users', 'confirmed_by_user_id' => 'users', 'seated_by_user_id' => 'users', 'cancelled_by_user_id' => 'users'],
                'natural' => ['reference'],
            ],
            'reviews' => [
                'foreigns' => ['branch_id' => 'branches', 'customer_id' => 'customers', 'reservation_id' => 'reservations', 'hidden_by_user_id' => 'users'],
            ],
            'staff_meal_month_closures' => [
                'foreigns' => ['branch_id' => 'branches', 'closed_by_user_id' => 'users'],
                'natural' => ['branch_id', 'month'],
            ],
            'staff_meal_charges' => [
                'foreigns' => ['branch_id' => 'branches', 'user_id' => 'users', 'order_id' => 'orders', 'settled_by_user_id' => 'users', 'approved_by_user_id' => 'users', 'month_closure_id' => 'staff_meal_month_closures'],
            ],
            'pending_transfers' => [
                'foreigns' => ['branch_id' => 'branches', 'table_session_id' => 'table_sessions', 'invoice_id' => 'invoices', 'payment_id' => 'payments', 'customer_id' => 'customers', 'recorded_by_user_id' => 'users', 'verified_by_user_id' => 'users'],
            ],
            'journal_entries' => [
                'foreigns' => ['branch_id' => 'branches', 'created_by' => 'users'],
                'morphs' => ['source' => ['type' => 'source_type', 'id' => 'source_id']],
            ],
            'journal_lines' => [
                'foreigns' => ['journal_entry_id' => 'journal_entries', 'account_id' => 'accounts', 'branch_id' => 'branches'],
            ],
            'inventory_snapshots' => [
                'foreigns' => ['branch_id' => 'branches', 'ingredient_id' => 'ingredients'],
                'natural' => ['branch_id', 'ingredient_id', 'snapshot_date'],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function allTables(): array
    {
        return self::downTables() + self::upTables();
    }

    /**
     * @return array<int, string>
     */
    public static function tableNames(): array
    {
        return array_keys(self::allTables());
    }

    /**
     * @return array<class-string, string>
     */
    public static function modelTableMap(): array
    {
        return [
            Account::class => 'accounts',
            Allergen::class => 'allergens',
            Attendance::class => 'attendances',
            Branch::class => 'branches',
            BranchTransfer::class => 'branch_transfers',
            BranchTransferItem::class => 'branch_transfer_items',
            CashMovement::class => 'cash_movements',
            Category::class => 'categories',
            Currency::class => 'currencies',
            Customer::class => 'customers',
            CustomerAddress::class => 'customer_addresses',
            Discount::class => 'discounts',
            Expense::class => 'expenses',
            Ingredient::class => 'ingredients',
            IngredientBatch::class => 'ingredient_batches',
            IngredientStock::class => 'ingredient_stock',
            IngredientUnit::class => 'ingredient_units',
            InventoryMovement::class => 'inventory_movements',
            InventorySnapshot::class => 'inventory_snapshots',
            Invoice::class => 'invoices',
            InvoiceSplit::class => 'invoice_splits',
            JournalEntry::class => 'journal_entries',
            JournalLine::class => 'journal_lines',
            Lookup::class => 'lookups',
            LoyaltyCustomer::class => 'loyalty_customers',
            LoyaltyTransaction::class => 'loyalty_transactions',
            MenuItem::class => 'menu_items',
            MenuPromotion::class => 'menu_promotions',
            Modifier::class => 'modifiers',
            ModifierGroup::class => 'modifier_groups',
            ModifierRecipeItem::class => 'modifier_recipe_items',
            Order::class => 'orders',
            OrderDiscount::class => 'order_discounts',
            OrderItem::class => 'order_items',
            OrderItemModifier::class => 'order_item_modifiers',
            Payment::class => 'payments',
            PendingTransfer::class => 'pending_transfers',
            Permission::class => 'permissions',
            PurchaseOrder::class => 'purchase_orders',
            PurchaseOrderItem::class => 'purchase_order_items',
            PurchaseReceipt::class => 'purchase_receipts',
            PurchaseReceiptItem::class => 'purchase_receipt_items',
            RecipeItem::class => 'recipe_items',
            Refund::class => 'refunds',
            Reservation::class => 'reservations',
            Review::class => 'reviews',
            Role::class => 'roles',
            Setting::class => 'settings',
            Shift::class => 'shifts',
            StaffMealCharge::class => 'staff_meal_charges',
            StaffMealMonthClosure::class => 'staff_meal_month_closures',
            Station::class => 'stations',
            StockCount::class => 'stock_counts',
            StockCountItem::class => 'stock_count_items',
            StorageLocation::class => 'storage_locations',
            Supplier::class => 'suppliers',
            SupplierInvoice::class => 'supplier_invoices',
            SupplierInvoiceItem::class => 'supplier_invoice_items',
            SupplierPayment::class => 'supplier_payments',
            Table::class => 'tables',
            TableSession::class => 'table_sessions',
            Unit::class => 'units',
            User::class => 'users',
        ];
    }
}
