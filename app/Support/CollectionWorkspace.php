<?php

namespace App\Support;

use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;

class CollectionWorkspace
{
    public static function navigation(?User $user = null): array
    {
        $user ??= auth()->user();

        if (! $user) {
            return [];
        }

        $items = [];

        if ($user->can('viewAny', Payment::class)) {
            $items[] = [
                'key' => 'debts',
                'label' => 'ديون الزبائن',
                'hint' => 'تحصيل ومتابعة',
                'icon' => 'bi-wallet2',
                'url' => route('admin.customers.debts.index'),
            ];
        }

        if ($user->can('create', Payment::class)) {
            $items[] = [
                'key' => 'transfers',
                'label' => 'تحويلات تنتظر',
                'hint' => 'تأكيد من البنك',
                'icon' => 'bi-bank',
                'url' => route('admin.cashier.transfers.queue'),
            ];
            $items[] = [
                'key' => 'transfer-report',
                'label' => 'مطابقة البنك',
                'hint' => 'سجل يومي',
                'icon' => 'bi-clipboard-data',
                'url' => route('admin.cashier.transfers.report'),
            ];
        }

        if ($user->can('viewAny', Refund::class)) {
            $items[] = [
                'key' => 'refunds',
                'label' => 'الاستردادات',
                'hint' => 'مراجعة وتصحيح',
                'icon' => 'bi-arrow-counterclockwise',
                'url' => route('admin.refunds.index'),
            ];
        }

        return $items;
    }
}
