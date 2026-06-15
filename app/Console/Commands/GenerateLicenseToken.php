<?php

namespace App\Console\Commands;

use App\Services\LicenseService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateLicenseToken extends Command
{
    protected $signature = 'license:generate-token
        {license_key : License key to embed in the token}
        {machine_fingerprint : Machine fingerprint from the activation page}
        {--customer= : Customer or company name}
        {--days= : Number of days until expiry}
        {--expires_at= : Exact expiry date/time in ISO format}
        {--lifetime : Generate a lifetime token with no expiry}';

    protected $description = 'Generate a signed license activation token for a specific machine.';

    public function handle(LicenseService $licenseService): int
    {
        $licenseKey = (string) $this->argument('license_key');
        $machineFingerprint = (string) $this->argument('machine_fingerprint');

        $expiresAt = null;

        if ($this->option('lifetime')) {
            $expiresAt = null;
        } elseif ($this->option('expires_at')) {
            $expiresAt = Carbon::parse((string) $this->option('expires_at'))->toIso8601String();
        } elseif ($this->option('days') !== null && $this->option('days') !== '') {
            $expiresAt = now()->addDays((int) $this->option('days'))->toIso8601String();
        }

        try {
            $token = $licenseService->issueToken([
                'license_key' => $licenseKey,
                'machine_fingerprint' => $machineFingerprint,
                'customer_name' => $this->option('customer') ?: null,
                'issued_at' => now()->toIso8601String(),
                'expires_at' => $expiresAt,
            ]);
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $this->line('License key: ' . $licenseKey);
        $this->line('Machine fingerprint: ' . $machineFingerprint);
        $this->line('Expires at: ' . ($expiresAt ?: 'No expiry'));
        $this->newLine();
        $this->info($token);

        return self::SUCCESS;
    }
}
