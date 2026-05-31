<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'journal_entry_id',
        'account_id',
        'branch_id',
        'line_no',
        'description',
        'debit',
        'credit',
        'currency_code',
        'exchange_rate',
        'foreign_debit',
        'foreign_credit',
    ];

    protected $casts = [
        'debit' => 'decimal:4',
        'credit' => 'decimal:4',
        'exchange_rate' => 'decimal:8',
        'foreign_debit' => 'decimal:4',
        'foreign_credit' => 'decimal:4',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $line) {
            $debit = round((float) $line->debit, 4);
            $credit = round((float) $line->credit, 4);
            $exchangeRate = (float) ($line->exchange_rate ?? 1);
            $foreignDebit = (float) ($line->foreign_debit ?? 0);
            $foreignCredit = (float) ($line->foreign_credit ?? 0);

            if ($debit < 0 || $credit < 0) {
                throw new \InvalidArgumentException('لا يمكن أن تكون مبالغ سطر القيد سالبة.');
            }

            if ($exchangeRate <= 0) {
                throw new \InvalidArgumentException('Exchange rate must be greater than zero.');
            }

            if ($foreignDebit < 0 || $foreignCredit < 0) {
                throw new \InvalidArgumentException('Foreign-currency journal amounts cannot be negative.');
            }

            if ($debit > 0.0001 && $credit > 0.0001) {
                throw new \InvalidArgumentException('لا يمكن أن يكون سطر القيد مدينا ودائنا في نفس الوقت.');
            }

            if ($debit <= 0.0001 && $credit <= 0.0001) {
                throw new \InvalidArgumentException('يجب أن يحتوي سطر القيد على مبلغ مدين أو دائن.');
            }

            if ($line->journal_entry_id) {
                $entryBranchId = JournalEntry::whereKey($line->journal_entry_id)->value('branch_id');
                if ((string) ($entryBranchId ?? '') !== (string) ($line->branch_id ?? '')) {
                    throw new \InvalidArgumentException('فرع سطر القيد يجب أن يطابق فرع القيد المحاسبي.');
                }
            }
        });
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
