<?php

namespace App\Services;

use App\Models\LicenseActivation;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

class LicenseService
{
    public function currentActivation(): ?LicenseActivation
    {
        if (!$this->hasActivationTable()) {
            return null;
        }

        return LicenseActivation::query()->latest('id')->first();
    }

    public function isEnforced(): bool
    {
        return (bool) config('license.enforced', true);
    }

    public function hasValidLicense(): bool
    {
        if (!$this->isEnforced()) {
            return true;
        }

        $activation = $this->currentActivation();
        if (!$activation || $activation->status !== 'active') {
            return false;
        }

        $validation = $this->validateStoredActivation($activation);
        return (bool) $validation['valid'];
    }

    public function validateStoredActivation(LicenseActivation $activation): array
    {
        return $this->validateActivationToken(
            (string) $activation->license_key,
            (string) $activation->activation_token
        );
    }

    public function activate(string $licenseKey, string $token, ?int $activatedBy = null): LicenseActivation
    {
        if (!$this->hasActivationTable()) {
            throw new \RuntimeException('License table is missing. Run php artisan migrate before activating the portal.');
        }

        $validation = $this->validateActivationToken($licenseKey, $token);
        if (!$validation['valid']) {
            throw new \RuntimeException($validation['message']);
        }

        $payload = $validation['payload'];
        $activation = $this->currentActivation() ?: new LicenseActivation();

        $activation->fill([
            'license_key' => $licenseKey,
            'activation_token' => $token,
            'machine_fingerprint' => $this->getMachineFingerprint(),
            'machine_profile' => $this->getMachineProfile(),
            'license_payload' => $payload,
            'customer_name' => $payload['customer_name'] ?? null,
            'status' => 'active',
            'activated_by' => $activatedBy,
            'activated_at' => now(),
            'last_validated_at' => now(),
            'expires_at' => !empty($payload['expires_at']) ? Carbon::parse($payload['expires_at']) : null,
        ]);

        $activation->save();

        return $activation;
    }

    public function invalidateCurrent(?string $status = 'invalid'): void
    {
        $activation = $this->currentActivation();
        if (!$activation) {
            return;
        }

        $activation->status = $status ?: 'invalid';
        $activation->save();
    }

    public function activationStatus(): array
    {
        $activation = $this->currentActivation();
        $machineFingerprint = $this->getMachineFingerprint();
        $profile = $this->getMachineProfile();

        if (!$this->hasActivationTable()) {
            return [
                'licensed' => false,
                'message' => 'License table is not available yet. Run php artisan migrate, then apply the activation token.',
                'activation' => null,
                'machine_fingerprint' => $machineFingerprint,
                'machine_profile' => $profile,
            ];
        }

        if (!$activation) {
            return [
                'licensed' => !$this->isEnforced(),
                'message' => $this->isEnforced()
                    ? 'No active license is installed on this machine.'
                    : 'License enforcement is disabled in configuration.',
                'activation' => null,
                'machine_fingerprint' => $machineFingerprint,
                'machine_profile' => $profile,
            ];
        }

        $validation = $this->validateStoredActivation($activation);

        return [
            'licensed' => (bool) $validation['valid'],
            'message' => $validation['message'],
            'activation' => $activation,
            'machine_fingerprint' => $machineFingerprint,
            'machine_profile' => $profile,
            'payload' => $validation['payload'] ?? $activation->license_payload,
        ];
    }

    public function validateActivationToken(string $licenseKey, string $token): array
    {
        $licenseKey = trim($licenseKey);
        $token = trim($token);

        if ($licenseKey === '' || $token === '') {
            return $this->invalid('License key and activation token are required.');
        }

        $secret = (string) config('license.signing_secret');
        if ($secret === '') {
            return $this->invalid('License signing secret is not configured.');
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] !== 'LIC1') {
            return $this->invalid('Activation token format is invalid.');
        }

        $encodedPayload = $parts[1];
        $encodedSignature = $parts[2];

        $payloadJson = $this->base64UrlDecode($encodedPayload);
        if ($payloadJson === false) {
            return $this->invalid('Activation payload could not be decoded.');
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return $this->invalid('Activation payload is not valid JSON.');
        }

        $expectedSignature = $this->base64UrlEncode(hash_hmac('sha256', $encodedPayload, $secret, true));
        if (!hash_equals($expectedSignature, $encodedSignature)) {
            return $this->invalid('Activation token signature is invalid.');
        }

        if (($payload['license_key'] ?? null) !== $licenseKey) {
            return $this->invalid('License key does not match the activation token.');
        }

        $expectedFingerprint = $this->getMachineFingerprint();
        if (($payload['machine_fingerprint'] ?? null) !== $expectedFingerprint) {
            return $this->invalid('This activation token was issued for a different machine.');
        }

        if (!empty($payload['issued_at']) && Carbon::parse($payload['issued_at'])->isFuture()) {
            return $this->invalid('This activation token is not active yet.');
        }

        if (!empty($payload['expires_at']) && Carbon::parse($payload['expires_at'])->isPast()) {
            return $this->invalid('This license has expired.');
        }

        return [
            'valid' => true,
            'message' => 'License is valid for this machine.',
            'payload' => $payload,
        ];
    }

    public function issueToken(array $payload): string
    {
        $secret = (string) config('license.signing_secret');
        if ($secret === '') {
            throw new \RuntimeException('LICENSE_SIGNING_SECRET is not configured.');
        }

        $normalizedPayload = [
            'issuer' => $payload['issuer'] ?? config('license.issuer'),
            'license_key' => $payload['license_key'] ?? null,
            'machine_fingerprint' => $payload['machine_fingerprint'] ?? null,
            'customer_name' => $payload['customer_name'] ?? null,
            'issued_at' => $payload['issued_at'] ?? now()->toIso8601String(),
            'expires_at' => $payload['expires_at'] ?? null,
            'meta' => Arr::except($payload, ['issuer', 'license_key', 'machine_fingerprint', 'customer_name', 'issued_at', 'expires_at']),
        ];

        $encodedPayload = $this->base64UrlEncode(json_encode($normalizedPayload, JSON_UNESCAPED_SLASHES));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $encodedPayload, $secret, true));

        return 'LIC1.' . $encodedPayload . '.' . $signature;
    }

    public function getMachineFingerprint(): string
    {
        $profile = $this->getMachineProfile();

        return hash('sha256', implode('|', [
            $profile['hostname'] ?? '',
            $profile['machine_guid'] ?? '',
            $profile['machine_id'] ?? '',
            $profile['os_family'] ?? '',
            $profile['architecture'] ?? '',
            $profile['app_path'] ?? '',
        ]));
    }

    public function getMachineProfile(): array
    {
        return [
            'hostname' => gethostname() ?: php_uname('n'),
            'computer_name' => getenv('COMPUTERNAME') ?: null,
            'os_family' => PHP_OS_FAMILY,
            'os_name' => php_uname('s'),
            'architecture' => php_uname('m'),
            'app_path' => base_path(),
            'machine_guid' => $this->readWindowsMachineGuid(),
            'machine_id' => $this->readLinuxMachineId(),
        ];
    }

    public function maskLicenseKey(?string $licenseKey): string
    {
        $licenseKey = trim((string) $licenseKey);
        if ($licenseKey === '') {
            return '-';
        }

        $suffix = substr($licenseKey, -4);
        return str_repeat('*', max(strlen($licenseKey) - 4, 4)) . $suffix;
    }

    private function readWindowsMachineGuid(): ?string
    {
        if (PHP_OS_FAMILY !== 'Windows' || !function_exists('shell_exec')) {
            return null;
        }

        $output = @shell_exec('reg query "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Cryptography" /v MachineGuid 2>NUL');
        if (!is_string($output) || trim($output) === '') {
            return null;
        }

        if (preg_match('/MachineGuid\s+REG_SZ\s+([A-Za-z0-9\-]+)/i', $output, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function readLinuxMachineId(): ?string
    {
        foreach (['/etc/machine-id', '/var/lib/dbus/machine-id'] as $path) {
            if (is_readable($path)) {
                $value = trim((string) @file_get_contents($path));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value)
    {
        $padding = 4 - (strlen($value) % 4);
        if ($padding < 4) {
            $value .= str_repeat('=', $padding);
        }

        return base64_decode(strtr($value, '-_', '+/'), true);
    }

    private function invalid(string $message): array
    {
        return [
            'valid' => false,
            'message' => $message,
            'payload' => null,
        ];
    }

    private function hasActivationTable(): bool
    {
        try {
            return Schema::hasTable('license_activations');
        } catch (\Throwable $exception) {
            return false;
        }
    }
}
