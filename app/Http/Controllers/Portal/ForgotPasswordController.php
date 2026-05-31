<?php

namespace App\Http\Controllers\Portal;

use App\Helpers\Brand;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Setting;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Forgot-password (customer portal).
 *
 * Mirrors the staff flow but uses the Customer model and the
 * portal-flavoured layout. Same anti-enumeration policy: the
 * response is always generic regardless of whether the phone
 * matched a real account; the activity log captures the truth
 * for audit.
 */
class ForgotPasswordController extends Controller
{
    protected const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';

    protected const TEMP_LENGTH = 8;

    public function show()
    {
        return view('portal.auth.forgot-password');
    }

    public function store(Request $request, SmsService $sms)
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
        ], [
            'phone.required' => __('portal.forgot.phone_required'),
        ]);

        $phone = trim($data['phone']);

        $ipKey = 'portal-forgot-ip:'.$request->ip();
        if (RateLimiter::tooManyAttempts($ipKey, 5)) {
            $seconds = RateLimiter::availableIn($ipKey);

            return back()
                ->withErrors(['phone' => __('portal.forgot.too_many', ['seconds' => $seconds])])
                ->withInput();
        }

        $idKey = 'portal-forgot-phone:'.$phone;
        if (RateLimiter::tooManyAttempts($idKey, 1)) {
            $seconds = RateLimiter::availableIn($idKey);

            return back()
                ->withErrors(['phone' => __('portal.forgot.sent_recently', ['seconds' => $seconds])])
                ->withInput();
        }

        $customer = Customer::findForLogin($phone);

        RateLimiter::hit($ipKey, 3600);
        RateLimiter::hit($idKey, 60);

        if (! $customer || ! $customer->isActive() || empty($customer->phone)) {
            ActivityLog::log('portal_forgot.miss', __('portal.forgot.miss_log', ['phone' => $phone]), null, [
                'phone' => $phone,
                'ip' => $request->ip(),
            ]);

            return back()->with('success', $this->successMessage());
        }

        $tempPassword = $this->generateTempPassword();

        try {
            $sms->send(
                $customer->phone,
                $this->formatMessage($customer, $tempPassword),
                "portal_forgot_password:customer:{$customer->id}"
            );
        } catch (\Throwable $e) {
            ActivityLog::log('portal_forgot.sms_failed', __('portal.forgot.sms_failed_log', ['id' => $customer->id]), $customer, [
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors(['phone' => $e->getMessage()])->withInput();
        }

        $customer->update(['password' => Hash::make($tempPassword)]);

        ActivityLog::log('portal_forgot.sent', __('portal.forgot.sent_log', ['id' => $customer->id]), $customer, [
            'phone' => $customer->phone,
        ]);

        return back()->with('success', $this->successMessage());
    }

    protected function generateTempPassword(): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < self::TEMP_LENGTH; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }

    /**
     * Template-driven body. Same {brand} / {password} / {login_url}
     * placeholders as the staff flow; restaurant admins edit the
     * customer-facing wording from /admin/settings independently.
     */
    protected function formatMessage(Customer $customer, string $password): string
    {
        $template = trim((string) Setting::get('sms_template_forgot_customer'))
            ?: "{brand}\nYour account password has been changed.\nNew password: {password}\nLogin: {login_url}";

        return strtr($template, [
            '{brand}' => Brand::name(),
            '{password}' => $password,
            '{login_url}' => route('portal.login'),
        ]);
    }

    protected function successMessage(): string
    {
        return __('portal.forgot.success');
    }
}
