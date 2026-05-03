<?php

namespace App\Notifications;

use App\Models\Attendance;

class AttendanceLateNotification extends BaseNotification
{
    public function __construct(public Attendance $attendance, public int $minutesLate)
    {
        parent::__construct();
        $this->branchId = $attendance->branch_id ?? $this->branchId;
    }

    public function typeKey(): string { return 'attendance.late'; }
    public function severity(): string { return 'warning'; }
    public function icon(): string    { return 'bi-alarm-fill'; }

    public function title(): string
    {
        $name = $this->attendance->user?->name ?? 'موظف';
        return "تأخر في الحضور: {$name}";
    }

    public function body(): string
    {
        $time = $this->attendance->clock_in_at?->format('H:i') ?? '—';
        return "سجّل الحضور الساعة {$time} (متأخر {$this->minutesLate} دقيقة عن الوقت القياسي).";
    }

    public function actionUrl(): string
    {
        return route('admin.attendance.index', [
            'date'    => $this->attendance->clock_in_at?->toDateString(),
            'user_id' => $this->attendance->user_id,
        ]);
    }

    public function actionLabel(): string
    {
        return 'افتح سجل الحضور';
    }

    public function extra(): array
    {
        return [
            'attendance_id' => $this->attendance->id,
            'user_id'       => $this->attendance->user_id,
            'minutes_late'  => $this->minutesLate,
        ];
    }
}
