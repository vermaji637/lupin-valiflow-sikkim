<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Lightweight SMS sender. Configure via .env:
 * SMS_ENABLED=true
 * SMS_DRIVER=http|log
 * SMS_URL=https://provider.example/send
 * SMS_API_KEY=...
 * SMS_SENDER=VALIFLOW
 *
 * HTTP driver POSTs JSON: { to, message, sender, api_key }
 */
class SmsService
{
    public function enabled(): bool
    {
        return (bool) config('services.sms.enabled', false);
    }

    public function send(string $mobile, string $message): bool
    {
        $to = preg_replace('/\D+/', '', $mobile);
        if ($to === '' || strlen($to) < 10) {
            Log::warning('SMS skipped: invalid mobile number.', ['mobile' => $mobile]);

            return false;
        }

        if (! $this->enabled()) {
            Log::info('SMS skipped: SMS_ENABLED is false.', [
                'mobile' => $to,
                'message' => $message,
            ]);

            return false;
        }

        $driver = (string) config('services.sms.driver', 'log');

        if ($driver === 'log') {
            Log::info('SMS (log driver)', ['mobile' => $to, 'message' => $message]);

            return true;
        }

        if ($driver === 'http') {
            $url = (string) config('services.sms.url', '');
            if ($url === '') {
                Log::warning('SMS skipped: SMS_URL is empty.');

                return false;
            }

            try {
                $response = Http::timeout(15)->asJson()->post($url, [
                    'to' => $to,
                    'message' => $message,
                    'sender' => config('services.sms.sender'),
                    'api_key' => config('services.sms.api_key'),
                ]);

                if (! $response->successful()) {
                    Log::error('SMS HTTP send failed.', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return false;
                }

                return true;
            } catch (\Throwable $e) {
                Log::error('SMS HTTP send exception.', ['error' => $e->getMessage()]);

                return false;
            }
        }

        Log::warning('SMS skipped: unknown SMS_DRIVER.', ['driver' => $driver]);

        return false;
    }
}
