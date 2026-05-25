<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Wrapper for the TweetSMS HTTP API (http://www.tweetsms.ps/api.php).
 *
 * The provider is single-tenant from our side — one set of credentials
 * for the whole restaurant — and is configured through the SMS tab in
 * the admin settings page (super_admin + admin). Credentials are read
 * from the settings table on every send (no boot-time cache) so an
 * admin can rotate them without redeploying.
 *
 * Response format from TweetSMS:
 *   Success:  `1:SMS_ID:9725XXXXXXXX`  (one line per recipient)
 *   Failure:  a numeric error code on the first colon-separated token
 *             (-100 missing params, -110 wrong creds, -113 no balance,
 *             -115 sender not allowed, -116 invalid sender, -999 provider
 *             error, u = unknown). See docs in tweetsmsApi.pdf.
 *
 * We don't surface raw error codes to end users — they map to friendly
 * Arabic messages and the raw code stays in the activity log so an
 * admin can debug.
 */
class SmsService
{
    /** Default endpoint — overridable from settings so a sandbox URL works. */
    protected const DEFAULT_URL = 'http://www.tweetsms.ps/api.php';

    /** HTTP timeout (seconds). Generous because the provider is on a
     *  shared connection and the password-reset flow blocks the user
     *  until we know whether the SMS went out. */
    protected const TIMEOUT = 15;

    /**
     * Send a single text message. Returns the provider's SMS_ID on
     * success. Throws on misconfiguration, network failure, or any
     * non-success result code so callers can show an error.
     */
    public function send(string $phone, string $message, ?string $context = null): string
    {
        if (! $this->isEnabled()) {
            throw new RuntimeException('SMS service is not enabled in settings.');
        }

        $creds = $this->credentials();
        $normalized = $this->normalizePhone($phone);

        if ($normalized === null) {
            throw new RuntimeException('رقم الجوال غير صالح.');
        }

        $params = [
            'comm'    => 'sendsms',
            'user'    => $creds['username'],
            'pass'    => $creds['password'],
            'to'      => $normalized,
            'message' => $message,
            'sender'  => $creds['sender'],
        ];

        try {
            $response = Http::timeout(self::TIMEOUT)->get($creds['url'], $params);
        } catch (\Throwable $e) {
            $this->log('sms.network_error', $context, [
                'to'    => $normalized,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('تعذّر الاتصال بمزود الرسائل النصية. حاول مرة أخرى.');
        }

        $body = trim($response->body());
        $parsed = $this->parseSendResponse($body, $normalized);

        if ($parsed['ok']) {
            $this->log('sms.sent', $context, [
                'to'     => $normalized,
                'sms_id' => $parsed['sms_id'],
            ]);
            return $parsed['sms_id'];
        }

        $this->log('sms.failed', $context, [
            'to'       => $normalized,
            'response' => $body,
            'code'     => $parsed['code'],
        ]);
        throw new RuntimeException($this->humanizeError($parsed['code']));
    }

    /**
     * Query the provider for the remaining credit balance. Used by the
     * "اختبر الاتصال" button on the settings page — a successful balance
     * fetch is the cheapest proof that the credentials are valid.
     */
    public function balance(): string
    {
        $creds = $this->credentials();

        try {
            $response = Http::timeout(self::TIMEOUT)->get($creds['url'], [
                'comm' => 'chk_balance',
                'user' => $creds['username'],
                'pass' => $creds['password'],
            ]);
        } catch (\Throwable $e) {
            throw new RuntimeException('تعذّر الاتصال بمزود الرسائل: '.$e->getMessage());
        }

        $body = trim($response->body());

        // Wrong credentials path — TweetSMS returns -110 from chk_balance too.
        if (str_starts_with($body, '-110')) {
            throw new RuntimeException('اسم المستخدم أو كلمة المرور لمزود الرسائل غير صحيحة.');
        }
        if (str_starts_with($body, '-100')) {
            throw new RuntimeException('بيانات الاتصال ناقصة. تأكد من تعبئة كل الحقول.');
        }
        if ($body === '' || ! is_numeric($body)) {
            throw new RuntimeException('رد غير متوقع من مزود الرسائل: '.($body ?: '[empty]'));
        }

        return $body; // numeric balance (credits or currency depending on provider config).
    }

    public function isEnabled(): bool
    {
        return (bool) Setting::get('sms_enabled', false);
    }

    /**
     * Resolve API credentials from settings with sensible defaults.
     * Throws if anything mandatory is missing — better to surface a
     * clear "missing credentials" message than blast a misconfigured
     * HTTP request and get a -110 / -100 back.
     */
    protected function credentials(): array
    {
        $url      = Setting::get('sms_api_url', self::DEFAULT_URL);
        $username = Setting::get('sms_username');
        $password = $this->decryptedPassword();
        $sender   = Setting::get('sms_sender');

        foreach (['url', 'username', 'password', 'sender'] as $key) {
            if (empty($$key)) {
                throw new RuntimeException("بيانات الاتصال بمزود الرسائل ناقصة ({$key}).");
            }
        }

        return compact('url', 'username', 'password', 'sender');
    }

    protected function decryptedPassword(): ?string
    {
        $stored = Setting::get('sms_password');
        if (empty($stored)) {
            return null;
        }
        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable $e) {
            // Stored as plain text (legacy / manual DB edit) — fall back
            // and let the caller try it as-is so the user isn't locked
            // out of a working setup by a bad migration.
            return $stored;
        }
    }

    /**
     * Normalise a Palestinian phone number to the format TweetSMS
     * accepts: country code 970/972 + 9 digits, all digits, no plus.
     *
     *   0599xxxxxxx → 97259xxxxxxx
     *   +972599xxxx → 97259xxxxxxx
     *   972599xxxx  → 97259xxxxxxx
     *
     * Returns null when nothing reasonable can be made of the input.
     */
    public function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') return null;

        // Strip a leading 00 (international dialing prefix).
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // Already includes a country code → use as-is.
        if (str_starts_with($digits, '972') || str_starts_with($digits, '970')) {
            return strlen($digits) >= 11 ? $digits : null;
        }

        // Local format "05xxxxxxxx" → prefix with 972 (drop the leading 0).
        if (str_starts_with($digits, '0') && strlen($digits) >= 9) {
            return '972'.substr($digits, 1);
        }

        // Last-resort: assume the user typed a bare national number ("5xxxxxxxx").
        if (strlen($digits) >= 8 && strlen($digits) <= 9) {
            return '972'.$digits;
        }

        return null;
    }

    /**
     * Parse the success/failure tokens from a `sendsms` response.
     * TweetSMS returns one line per recipient. We only support one
     * recipient per call, so we look at the first line.
     */
    protected function parseSendResponse(string $body, string $expectedNumber): array
    {
        $firstLine = trim(strtok($body, "\n") ?: '');
        if ($firstLine === '') {
            return ['ok' => false, 'code' => 'empty', 'sms_id' => null];
        }

        // Negative codes appear at the start of the response without a colon
        // (e.g. "-110", "-113"). Catch those before we split.
        if (str_starts_with($firstLine, '-')) {
            $code = strtok($firstLine, ':') ?: $firstLine;
            return ['ok' => false, 'code' => $code, 'sms_id' => null];
        }

        // Success / per-number status: "result:sms_id:phone"
        $parts = explode(':', $firstLine);
        $result = $parts[0] ?? null;
        $smsId  = $parts[1] ?? null;

        if ($result === '1') {
            return ['ok' => true, 'code' => '1', 'sms_id' => $smsId];
        }

        return ['ok' => false, 'code' => $result ?: 'unknown', 'sms_id' => $smsId];
    }

    /**
     * Map error codes (from tweetsmsApi.pdf) to user-readable Arabic
     * strings. Unknown codes fall through to a generic message so we
     * never expose the raw provider response to a normal user.
     */
    protected function humanizeError(string $code): string
    {
        return match ($code) {
            '-2'   => 'رقم الجوال غير صالح أو الدولة غير مدعومة.',
            '-100' => 'بيانات الاتصال ناقصة. راجع إعدادات الرسائل.',
            '-110' => 'اسم المستخدم أو كلمة المرور غير صحيحة.',
            '-113' => 'لا يوجد رصيد كافٍ في حساب الرسائل.',
            '-115' => 'اسم المرسل غير متاح في حسابك. تواصل مع المزود.',
            '-116' => 'اسم المرسل غير صالح.',
            '-999' => 'فشل إرسال الرسالة من جهة المزود.',
            'u'    => 'حالة الرسالة غير معروفة من المزود.',
            'empty'   => 'لم يصلنا رد من مزود الرسائل.',
            'unknown' => 'تعذّر تفسير رد المزود.',
            default => 'فشل إرسال الرسالة (رمز '.$code.').',
        };
    }

    /**
     * Audit-log SMS attempts so an admin can trace who triggered what.
     * Activity log is the existing app-wide audit surface — no special
     * channel needed.
     */
    protected function log(string $type, ?string $context, array $extra): void
    {
        try {
            ActivityLog::log($type, $context ?: 'SMS', null, $extra);
        } catch (\Throwable $e) {
            // Last-resort: drop to Laravel log if ActivityLog itself
            // is broken (db down etc.). We don't want a log failure to
            // mask the actual SMS outcome upstream.
            Log::warning('sms-log-fallback', ['type' => $type, 'extra' => $extra, 'err' => $e->getMessage()]);
        }
    }
}
