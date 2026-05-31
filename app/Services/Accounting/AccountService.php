<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\ActivityLog;
use Illuminate\Validation\ValidationException;

/**
 * Safe CRUD layer over the chart of accounts.
 *
 * Why this exists: the operational `AccountingService` has canonical
 * fallback codes (CASH='1000', SALES_REVENUE='4000', etc.) even when
 * accountants remap posting roles to custom accounts. If those fallback
 * codes are renamed or deactivated, future posting and historical reporting
 * can break. This service enforces invariants so the UI can give the
 * accountant a sandbox without breaking the books:
 *
 *   - Code is IMMUTABLE on system accounts (is_system=true)
 *   - System accounts can be RENAMED (display) but never DEACTIVATED
 *   - User-created accounts: full flexibility
 *   - Posted custom accounts keep their code/type/normal balance locked
 *   - Delete: only if zero history + zero children + not system
 *
 * Sub-account hierarchy:
 *   - Any account can have a parent
 *   - Parent's TYPE must match — assets only nest under assets
 *   - Cycle protection: cannot make a descendant your own parent
 */
class AccountService
{
    public function create(array $data): Account
    {
        $clean = $this->validateAndNormalize($data, null);
        // User-created accounts are never marked is_system — that flag
        // is reserved for migration-seeded codes the platform relies on.
        $clean['is_system'] = false;

        $account = Account::create($clean);

        ActivityLog::log(
            'chart_of_accounts.created',
            "إنشاء حساب {$account->code} — {$account->name}",
            $account,
            ['type' => $account->type, 'parent_id' => $account->parent_account_id],
        );
        return $account->fresh();
    }

    public function update(Account $account, array $data): Account
    {
        if ($account->isProtected()) {
            $data['code'] = $account->code;
            $data['type'] = $account->type;
            $data['normal_balance'] = $account->normal_balance;
            $data['is_active'] = true;
        } elseif ($account->hasJournalLines()) {
            $this->assertPostedAccountCoreFieldsUnchanged($account, $data);
        }

        $clean = $this->validateAndNormalize($data, $account);

        // CODE LOCK: system accounts remain the canonical fallback and
        // historical reporting anchor. Silently strip any attempt to change it.
        if ($account->isProtected()) {
            unset($clean['code'], $clean['type'], $clean['normal_balance'], $clean['is_active']);
        }
        // is_system flag is admin-only metadata; never let user UI flip it.
        unset($clean['is_system']);

        $account->update($clean);

        ActivityLog::log(
            'chart_of_accounts.updated',
            "تعديل حساب {$account->code} — {$account->name}",
            $account,
            collect($clean)->only(['name', 'description', 'parent_account_id', 'is_active', 'display_order'])->all(),
        );
        return $account->fresh();
    }

    /**
     * Soft toggle. Refuses to deactivate a SYSTEM account — those carry
     * runtime obligations (cash, bank, A/R, sales revenue, etc.) and
     * deactivating them would break invoice + payment posting.
     */
    public function setActive(Account $account, bool $active): Account
    {
        if (! $active && $account->isProtected()) {
            throw ValidationException::withMessages([
                'is_active' => "لا يمكن تعطيل حساب نظامي ({$account->code} — {$account->name}). "
                    . "النظام يعتمد عليه في القيود التشغيلية.",
            ]);
        }
        $account->update(['is_active' => $active]);
        $verb = $active ? 'تشغيل' : 'تعطيل';
        ActivityLog::log("chart_of_accounts.{$verb}", "{$verb} حساب {$account->code}", $account);
        return $account->fresh();
    }

    public function delete(Account $account): void
    {
        if (! $account->isDeletable()) {
            $reason = $account->isProtected()
                ? 'حساب نظامي — يستعمله النظام في القيود.'
                : ($account->hasJournalLines()
                    ? 'هذا الحساب عليه قيود محاسبية سابقة — استخدم التعطيل بدلاً من الحذف.'
                    : 'الحساب يحوي حسابات فرعية — احذفها أو انقلها أولاً.');
            throw ValidationException::withMessages(['delete' => $reason]);
        }
        $code = $account->code;
        $name = $account->name;
        $account->delete();
        ActivityLog::log('chart_of_accounts.deleted', "حذف حساب {$code} — {$name}", null);
    }

    // ────────────────────────────────────────────────────────────────
    // Internal: validation + normalisation
    // ────────────────────────────────────────────────────────────────

    /**
     * Common validation across create + update. Type/parent integrity
     * lives here because Laravel's stock rules can't express "parent's
     * type must equal mine" or "no cycles".
     */
    protected function validateAndNormalize(array $data, ?Account $existing): array
    {
        $rules = [
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:191'],
            'type' => ['required', 'in:asset,liability,equity,revenue,contra_revenue,expense'],
            'normal_balance'    => ['required', 'in:debit,credit'],
            'description'       => ['nullable', 'string'],
            'parent_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'is_active'         => ['sometimes', 'boolean'],
            'display_order'     => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];

        $clean = validator($data, $rules)->validate();

        // Code uniqueness (only on new accounts OR when changing code).
        $codeChanged = ! $existing || $existing->code !== $clean['code'];
        if ($codeChanged) {
            $exists = Account::where('code', $clean['code'])
                ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
                ->exists();
            if ($exists) {
                throw ValidationException::withMessages([
                    'code' => "الكود «{$clean['code']}» مستخدم مسبقاً على حساب آخر.",
                ]);
            }
        }

        // Parent integrity — same type, no cycles.
        if (! empty($clean['parent_account_id'])) {
            $parent = Account::find($clean['parent_account_id']);
            if ($parent) {
                if ($parent->type !== $clean['type']) {
                    throw ValidationException::withMessages([
                        'parent_account_id' => "الحساب الأب يجب أن يكون من نفس النوع ({$clean['type']}). "
                            . "الأب المختار «{$parent->code}» نوعه «{$parent->type}».",
                    ]);
                }
                if ($existing && $this->wouldCreateCycle($existing, $parent)) {
                    throw ValidationException::withMessages([
                        'parent_account_id' => 'هذا الاختيار ينشئ حلقة (parent ↔ descendant). اختر حساباً آخر.',
                    ]);
                }
            }
        }

        if ($existing && $existing->children()->where('type', '!=', $clean['type'])->exists()) {
            throw ValidationException::withMessages([
                'type' => 'لا يمكن تغيير نوع حساب لديه حسابات فرعية من نوع مختلف. انقل الحسابات الفرعية أو عدلها أولا.',
            ]);
        }

        $expectedNormalBalance = $this->expectedNormalBalanceForType($clean['type']);
        if ($clean['normal_balance'] !== $expectedNormalBalance) {
            throw ValidationException::withMessages([
                'normal_balance' => "ط§ظ„ط·ط¨ظٹط¹ط© ط§ظ„ظ…ط­ط§ط³ط¨ظٹط© ظ„ظ†ظˆط¹ {$clean['type']} ظٹط¬ط¨ ط£ظ† طھظƒظˆظ† {$expectedNormalBalance}.",
            ]);
        }

        $clean['is_active'] = (bool) ($clean['is_active'] ?? true);
        $clean['display_order'] = (int) ($clean['display_order'] ?? 0);
        return $clean;
    }

    protected function expectedNormalBalanceForType(string $type): string
    {
        return match ($type) {
            'asset', 'expense', 'contra_revenue' => 'debit',
            'liability', 'equity', 'revenue' => 'credit',
            default => 'debit',
        };
    }

    protected function assertPostedAccountCoreFieldsUnchanged(Account $account, array $data): void
    {
        $errors = [];

        $labels = [
            'code' => 'الكود',
            'type' => 'النوع',
            'normal_balance' => 'طبيعة الرصيد',
        ];

        foreach (['code', 'type', 'normal_balance'] as $field) {
            if (array_key_exists($field, $data) && (string) $data[$field] !== (string) $account->{$field}) {
                $errors[$field] = "لا يمكن تغيير {$labels[$field]} بعد وجود قيود محاسبية على الحساب {$account->code}.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Walk up from the proposed parent. If we hit the account we're
     * editing somewhere in the chain, the new parent is a descendant
     * and we'd create a cycle.
     */
    protected function wouldCreateCycle(Account $account, Account $proposedParent): bool
    {
        $node = $proposedParent;
        $guard = 0;
        while ($node && $guard++ < 50) {
            if ((int) $node->id === (int) $account->id) return true;
            $node = $node->parent;
        }
        return false;
    }
}
