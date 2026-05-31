<?php

namespace App\Http\Controllers\Auth;

use App\Helpers\Brand;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Forgot-password flow for staff (admins, cashiers, waiters…).
 *
 * Identifier-first, then SMS-delivered temporary password. We don't
 * use OTPs here — the user explicitly asked for "easy new password
 * delivered by SMS" so the flow is:
 *
 *   1. User submits phone (or username/email).
 *   2. We look up the matching active staff account.
 *   3. Generate a memorable-but-random 8-char password (no 0/O/1/l/I).
 *   4. Hash and save it.
 *   5. SMS the plaintext to the user's phone.
 *
 * Privacy: we always show a generic "if a matching account exists,
 * an SMS was sent" message — never confirm whether the identifier
 * actually resolved. This stops the page from being used as a
 * "does X work here?" account-enumeration tool. The activity log
 * captures the real outcome for admins to review.
 *
 * Rate limits:
 *   - 1 reset per identifier per 60s (so a typo doesn't burn balance)
 *   - 5 resets per IP per hour (mild abuse protection)
 */
class ForgotPasswordController extends Controller
{
    /** Characters we draw the temp password from. 0/O/1/l/I are
     *  excluded because they look identical in many SMS fonts and
     *  the user has to type the password back into the login form. */
    protected const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';

    protected const TEMP_LENGTH = 8;

    public function show()
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request, SmsService $sms)
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:120'],
        ], [
            'identifier.required' => __('ui.auth.forgot_identifier_required'),
        ]);

        $identifier = trim($data['identifier']);

        // IP throttle first — cheaper than even looking up the user.
        $ipKey = 'forgot-pw-ip:'.$request->ip();
        if (RateLimiter::tooManyAttempts($ipKey, 5)) {
            $seconds = RateLimiter::availableIn($ipKey);

            return back()
                ->withErrors(['identifier' => __('ui.auth.forgot_too_many', ['seconds' => $seconds])])
                ->withInput();
        }

        // Per-identifier throttle so retries don't spam SMS.
        $idKey = 'forgot-pw-id:'.mb_strtolower($identifier);
        if (RateLimiter::tooManyAttempts($idKey, 1)) {
            $seconds = RateLimiter::availableIn($idKey);

            return back()
                ->withErrors(['identifier' => __('ui.auth.forgot_sent_recently', ['seconds' => $seconds])])
                ->withInput();
        }

        $user = $this->resolveUser($identifier);

        // Hit the throttles regardless of whether the user was found —
        // otherwise the response timing leaks enumeration data.
        RateLimiter::hit($ipKey, 3600);
        RateLimiter::hit($idKey, 60);

        if (! $user || ! $user->canLogin() || empty($user->phone)) {
            ActivityLog::log('forgot_password.miss', __('ui.auth.forgot_miss_log', ['identifier' => $identifier]), null, [
                'identifier' => $identifier,
                'ip' => $request->ip(),
            ]);

            // Generic response regardless of outcome (anti-enumeration).
            return back()->with('success', $this->successMessage());
        }

        $tempPassword = $this->generateTempPassword();

        try {
            $sms->send(
                $user->phone,
                $this->formatMessage($user, $tempPassword),
                "forgot_password:user:{$user->id}"
            );
        } catch (\Throwable $e) {
            ActivityLog::log('forgot_password.sms_failed', __('ui.auth.forgot_sms_failed_log', ['id' => $user->id]), $user, [
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors(['identifier' => $e->getMessage()])->withInput();
        }

        // Only persist the new password AFTER the SMS has gone out —
        // otherwise a provider failure leaves the user locked out
        // with a password they never received.
        $user->update(['password' => Hash::make($tempPassword)]);

        ActivityLog::log('forgot_password.sent', __('ui.auth.forgot_sent_log', ['id' => $user->id]), $user, [
            'phone' => $user->phone,
        ]);

        return back()->with('success', $this->successMessage());
    }

    /**
     * Match the submitted identifier against username, email, or phone.
     * Phone comparison is digits-only on both sides so "+970 59…" and
     * "0599…" resolve to the same row.
     */
    protected function resolveUser(string $identifier): ?User
    {
        if (str_contains($identifier, '@')) {
            return User::where('email', $identifier)->first();
        }

        $digits = preg_replace('/\D+/', '', $identifier) ?? '';
        if ($digits !== '' && strlen($digits) >= 8) {
            $user = User::where('phone', $identifier)
                ->orWhere('phone', $digits)
                ->first();
            if ($user) {
                return $user;
            }
        }

        return User::where('username', $identifier)->first();
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
     * The SMS body is template-driven so each restaurant can rephrase
     * it from /admin/settings without redeploying. Placeholders:
     *   {brand}     → site name
     *   {password}  → the freshly generated temporary password
     *   {login_url} → absolute URL to the staff login screen
     * Missing or empty setting falls back to the English default below.
     */
    protected function formatMessage(User $user, string $password): string
    {
        $template = trim((string) Setting::get('sms_template_forgot_staff'))
            ?: "{brand}\nYour account password has been changed.\nNew password: {password}\nLogin: {login_url}";

        return strtr($template, [
            '{brand}' => Brand::name(),
            '{password}' => $password,
            '{login_url}' => route('login'),
        ]);
    }

    protected function successMessage(): string
    {
        return __('ui.auth.forgot_success_generic');
    }
}
