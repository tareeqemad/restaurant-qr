<?php

namespace App\Exceptions;

use App\Models\Employee;

/**
 * Thrown by `StaffMealService` when a charge would push the employee
 * past a hard limit AND the active policy says "block". The caller
 * (UI / controller) catches it and shows the rejection reason; the
 * exception carries enough context to render a useful error message
 * without re-querying the DB.
 *
 * Soft signals (warning / require-approval) are NOT thrown — they're
 * returned as preview-stage `LimitCheckResult`s so the UI can decide
 * how to surface them (banner, confirm dialog, manager PIN, etc.).
 */
class StaffMealLimitException extends \RuntimeException
{
    public function __construct(
        public readonly Employee $staff,
        public readonly string $limitType,    // 'monthly_allowance' | 'debt_ceiling'
        public readonly float $attempted,     // amount the user tried to charge
        public readonly float $headroom,      // how much room was left (negative = over)
        public readonly string $policy,       // policy that triggered the block
        string $message = '',
    ) {
        parent::__construct($message ?: $this->buildMessage());
    }

    private function buildMessage(): string
    {
        $name = $this->staff->name;
        $headroomFmt = number_format(max(0, $this->headroom), 2);
        $attemptedFmt = number_format($this->attempted, 2);

        return $this->limitType === 'debt_ceiling'
            ? "تم رفض الطلب: يتجاوز الموظف «{$name}» سقف الدين المسموح. "
                ."المتاح: {$headroomFmt} | المحاولة: {$attemptedFmt}."
            : "تم رفض الطلب: يتجاوز الموظف «{$name}» بدله الشهري المسموح. "
                ."المتاح: {$headroomFmt} | المحاولة: {$attemptedFmt}.";
    }
}
