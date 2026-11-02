@extends('layouts.app')

@section('title', 'Security Policy Settings')

@section('content')
@php
    $passwordMinLength = data_get($policy, 'password_min_length', 8);
    $passwordMaxLength = data_get($policy, 'password_max_length', 50);
    $autoLogoutMinutes = data_get($policy, 'auto_logout_minutes', 30);
    $requireAlphanumeric = (bool) data_get($policy, 'require_alphanumeric', false);
    $requireSpecialChar = (bool) data_get($policy, 'require_special_char', false);
    $esignatureEnabled = (bool) data_get($policy, 'esignature_enabled', false);
    $passwordExpiryEnabled = (bool) data_get($policy, 'password_expiry_enabled', false);
    $passwordExpiryDays = (int) data_get($policy, 'password_expiry_days', 45);
    $loginLockoutEnabled = (bool) data_get($policy, 'login_lockout_enabled', true);
    $maxFailedLoginAttempts = (int) data_get($policy, 'max_failed_login_attempts', 5);
    $lockoutMinutes = (int) data_get($policy, 'lockout_minutes', 10);
    $lockoutNotifyEmail = (bool) data_get($policy, 'lockout_notify_email', true);
    $lockoutNotifySms = (bool) data_get($policy, 'lockout_notify_sms', true);
    $passwordHistoryEnabled = (bool) data_get($policy, 'password_history_enabled', true);
    $passwordHistoryCount = (int) data_get($policy, 'password_history_count', 5);
@endphp
<div class="container-fluid px-0">
    <section class="page-hero">
        <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between">
            <div>
                <span class="page-kicker">
                    <i class="fas fa-cog"></i>
                    Settings
                </span>
                <h2 class="page-title">Security Policy Settings</h2>
                <p class="page-subtitle">
                    Configure password rules, history, lockout, expiry reminders, inactivity logout, and e-sign enforcement from one control surface.
                </p>
            </div>
        </div>

        <div class="metric-grid">
            <div class="metric-card">
                <span class="metric-label">Password Range</span>
                <span class="metric-value">{{ $passwordMinLength }}-{{ $passwordMaxLength }}</span>
                <span class="metric-note">Current minimum and maximum password length</span>
            </div>
            <div class="metric-card">
                <span class="metric-label">Auto Logout</span>
                <span class="metric-value">{{ $autoLogoutMinutes }}</span>
                <span class="metric-note">Minutes of inactivity before session timeout</span>
            </div>
            <div class="metric-card">
                <span class="metric-label">Password Expiry</span>
                <span class="metric-value">{{ $passwordExpiryEnabled ? $passwordExpiryDays . 'd' : 'Off' }}</span>
                <span class="metric-note">{{ $passwordExpiryEnabled ? 'Users must reset password after this period' : 'Password expiry policy is disabled' }}</span>
            </div>
            <div class="metric-card">
                <span class="metric-label">E-Signature</span>
                <span class="metric-value">{{ $esignatureEnabled ? 'On' : 'Off' }}</span>
                <span class="metric-note">Controls signature confirmation on protected actions</span>
            </div>
            <div class="metric-card">
                <span class="metric-label">Login Lockout</span>
                <span class="metric-value">{{ $loginLockoutEnabled ? $maxFailedLoginAttempts . ' / ' . $lockoutMinutes . 'm' : 'Off' }}</span>
                <span class="metric-note">{{ $loginLockoutEnabled ? 'Wrong attempts before lock / unlock minutes' : 'Account lockout policy is disabled' }}</span>
            </div>
            <div class="metric-card">
                <span class="metric-label">Password History</span>
                <span class="metric-value">{{ $passwordHistoryEnabled ? 'Last ' . $passwordHistoryCount : 'Off' }}</span>
                <span class="metric-note">{{ $passwordHistoryEnabled ? 'New password cannot match recent passwords' : 'Password reuse check is disabled' }}</span>
            </div>
        </div>
    </section>

    @include('common.alert')

    <div class="form-shell">
        <div class="card form-main-card">
            <div class="card-header">
                <div class="section-title">Policy Configuration</div>
            </div>
            <div class="card-body">
                <form method="POST" class="{{ $esignatureEnabled ? 'esign-required' : '' }}" action="{{ route('admin.security-policy.update') }}">
                    @csrf

                    <div class="form-section-grid">
                        <div class="form-field form-field-full">
                            <div class="policy-panel">
                                <div class="policy-panel-head">
                                    <div>
                                        <div class="policy-panel-title">Password Policy</div>
                                        <div class="policy-panel-copy">Define the password strength and complexity requirements for all users.</div>
                                    </div>
                                </div>

                                <div class="form-section-grid">
                                    <div class="form-field">
                                        <label class="form-label" for="password_min_length"><span class="form-required">*</span>Min Password Length</label>
                                        <input type="number" id="password_min_length" name="password_min_length" value="{{ old('password_min_length', $passwordMinLength) }}" class="form-control @error('password_min_length') is-invalid @enderror">
                                        @error('password_min_length')<span class="form-error">{{ $message }}</span>@enderror
                                    </div>

                                    <div class="form-field">
                                        <label class="form-label" for="password_max_length"><span class="form-required">*</span>Max Password Length</label>
                                        <input type="number" id="password_max_length" name="password_max_length" value="{{ old('password_max_length', $passwordMaxLength) }}" class="form-control @error('password_max_length') is-invalid @enderror">
                                        @error('password_max_length')<span class="form-error">{{ $message }}</span>@enderror
                                    </div>
                                </div>

                                <div class="policy-toggle-grid mt-3">
                                    <label class="policy-toggle">
                                        <input type="checkbox" name="require_alphanumeric" {{ old('require_alphanumeric', $requireAlphanumeric) ? 'checked' : '' }}>
                                        <span class="policy-toggle-box">
                                            <span class="policy-toggle-title">Require Alphabets & Numbers</span>
                                            <span class="policy-toggle-copy">Passwords must include both letters and numeric characters.</span>
                                        </span>
                                    </label>

                                    <label class="policy-toggle">
                                        <input type="checkbox" name="require_special_char" {{ old('require_special_char', $requireSpecialChar) ? 'checked' : '' }}>
                                        <span class="policy-toggle-box">
                                            <span class="policy-toggle-title">Require Special Character</span>
                                            <span class="policy-toggle-copy">Passwords must include at least one special character.</span>
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="form-field form-field-full">
                            <div class="policy-panel">
                                <div class="policy-panel-head">
                                    <div>
                                        <div class="policy-panel-title">Password Reset / Expiry Policy</div>
                                        <div class="policy-panel-copy">
                                            Force password renewal after a fixed number of days. Reminder emails are sent at
                                            5 days, 4 days, 3 days, 2 days, 1 day, 5 hours, and 1 hour before expiry.
                                        </div>
                                    </div>
                                </div>

                                <div class="policy-toggle-grid mt-3">
                                    <label class="policy-toggle">
                                        <input type="checkbox" name="password_expiry_enabled" id="password_expiry_enabled"
                                               {{ old('password_expiry_enabled', $passwordExpiryEnabled) ? 'checked' : '' }}>
                                        <span class="policy-toggle-box">
                                            <span class="policy-toggle-title">Enable Password Expiry</span>
                                            <span class="policy-toggle-copy">When enabled, users must reset their password after the configured period.</span>
                                        </span>
                                    </label>
                                </div>

                                <div class="form-section-grid mt-3">
                                    <div class="form-field">
                                        <label class="form-label" for="password_expiry_days"><span class="form-required">*</span>Password Expiry (Days)</label>
                                        <input type="number"
                                               id="password_expiry_days"
                                               name="password_expiry_days"
                                               min="1"
                                               max="3650"
                                               value="{{ old('password_expiry_days', $passwordExpiryDays) }}"
                                               class="form-control @error('password_expiry_days') is-invalid @enderror">
                                        @error('password_expiry_days')<span class="form-error">{{ $message }}</span>@enderror
                                        <small class="text-muted">Example: 45 days. After expiry, login is blocked until Forgot Password reset.</small>
                                    </div>
                                </div>

                                <div class="alert alert-light border mt-3 mb-0 small">
                                    <strong>Reminder schedule:</strong>
                                    5 days → 4 days → 3 days → 2 days → 1 day → 5 hours → 1 hour before expiry.
                                </div>
                            </div>
                        </div>

                        <div class="form-field form-field-full">
                            <div class="policy-panel">
                                <div class="policy-panel-head">
                                    <div>
                                        <div class="policy-panel-title">Password History Policy</div>
                                        <div class="policy-panel-copy">
                                            Prevent users from reusing their recent passwords when they reset or change a password.
                                        </div>
                                    </div>
                                </div>

                                <div class="policy-toggle-grid mt-3">
                                    <label class="policy-toggle">
                                        <input type="checkbox" name="password_history_enabled" id="password_history_enabled"
                                               {{ old('password_history_enabled', $passwordHistoryEnabled) ? 'checked' : '' }}>
                                        <span class="policy-toggle-box">
                                            <span class="policy-toggle-title">Enable Password History</span>
                                            <span class="policy-toggle-copy">When enabled, new passwords cannot match the last N passwords.</span>
                                        </span>
                                    </label>
                                </div>

                                <div class="form-section-grid mt-3">
                                    <div class="form-field">
                                        <label class="form-label" for="password_history_count"><span class="form-required">*</span>Remember Last Passwords</label>
                                        <input type="number"
                                               id="password_history_count"
                                               name="password_history_count"
                                               min="1"
                                               max="20"
                                               value="{{ old('password_history_count', $passwordHistoryCount) }}"
                                               class="form-control @error('password_history_count') is-invalid @enderror">
                                        @error('password_history_count')<span class="form-error">{{ $message }}</span>@enderror
                                        <small class="text-muted">Example: 5 — new password cannot match current or any of the previous passwords within this limit.</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-field form-field-full">
                            <div class="policy-panel">
                                <div class="policy-panel-head">
                                    <div>
                                        <div class="policy-panel-title">Login Lockout Policy</div>
                                        <div class="policy-panel-copy">
                                            Lock a user account after repeated wrong password attempts, then auto-unlock after the configured time.
                                            Optionally notify the user by email and/or SMS.
                                        </div>
                                    </div>
                                </div>

                                <div class="policy-toggle-grid mt-3">
                                    <label class="policy-toggle">
                                        <input type="checkbox" name="login_lockout_enabled" id="login_lockout_enabled"
                                               {{ old('login_lockout_enabled', $loginLockoutEnabled) ? 'checked' : '' }}>
                                        <span class="policy-toggle-box">
                                            <span class="policy-toggle-title">Enable Login Lockout</span>
                                            <span class="policy-toggle-copy">When enabled, wrong password attempts can temporarily lock the account.</span>
                                        </span>
                                    </label>
                                </div>

                                <div class="form-section-grid mt-3">
                                    <div class="form-field">
                                        <label class="form-label" for="max_failed_login_attempts"><span class="form-required">*</span>Max Wrong Attempts</label>
                                        <input type="number"
                                               id="max_failed_login_attempts"
                                               name="max_failed_login_attempts"
                                               min="1"
                                               max="50"
                                               value="{{ old('max_failed_login_attempts', $maxFailedLoginAttempts) }}"
                                               class="form-control @error('max_failed_login_attempts') is-invalid @enderror">
                                        @error('max_failed_login_attempts')<span class="form-error">{{ $message }}</span>@enderror
                                        <small class="text-muted">Example: 5 wrong passwords then account locks.</small>
                                    </div>

                                    <div class="form-field">
                                        <label class="form-label" for="lockout_minutes"><span class="form-required">*</span>Auto Unlock After (Minutes)</label>
                                        <input type="number"
                                               id="lockout_minutes"
                                               name="lockout_minutes"
                                               min="1"
                                               max="1440"
                                               value="{{ old('lockout_minutes', $lockoutMinutes) }}"
                                               class="form-control @error('lockout_minutes') is-invalid @enderror">
                                        @error('lockout_minutes')<span class="form-error">{{ $message }}</span>@enderror
                                        <small class="text-muted">Example: 10 minutes. Account unlocks automatically after this time.</small>
                                    </div>
                                </div>

                                <div class="policy-toggle-grid mt-3">
                                    <label class="policy-toggle">
                                        <input type="checkbox" name="lockout_notify_email"
                                               {{ old('lockout_notify_email', $lockoutNotifyEmail) ? 'checked' : '' }}>
                                        <span class="policy-toggle-box">
                                            <span class="policy-toggle-title">Notify by Email</span>
                                            <span class="policy-toggle-copy">Send an email when the account is locked.</span>
                                        </span>
                                    </label>

                                    <!-- <label class="policy-toggle">
                                        <input type="checkbox" name="lockout_notify_sms"
                                               {{ old('lockout_notify_sms', $lockoutNotifySms) ? 'checked' : '' }}>
                                        <span class="policy-toggle-box">
                                            <span class="policy-toggle-title">Notify by SMS</span>
                                            <span class="policy-toggle-copy">Send an SMS when locked (requires SMS_ENABLED in .env).</span>
                                        </span>
                                    </label> -->
                                </div>
                            </div>
                        </div>

                        <div class="form-field">
                            <div class="policy-panel">
                                <div class="policy-panel-title">Session Policy</div>
                                <div class="policy-panel-copy">Control how long users stay signed in without activity.</div>
                                <div class="form-field mt-3">
                                    <label class="form-label" for="auto_logout_minutes"><span class="form-required">*</span>Auto Logout Time (Minutes)</label>
                                    <input type="number" id="auto_logout_minutes" name="auto_logout_minutes" value="{{ old('auto_logout_minutes', $autoLogoutMinutes) }}" class="form-control @error('auto_logout_minutes') is-invalid @enderror">
                                    @error('auto_logout_minutes')<span class="form-error">{{ $message }}</span>@enderror
                                </div>
                            </div>
                        </div>

                        <div class="form-field">
                            <div class="policy-panel">
                                <div class="policy-panel-title">E-Signature Policy</div>
                                <div class="policy-panel-copy">Enable or disable signature confirmation for protected operations.</div>
                                <div class="policy-toggle-grid mt-3">
                                    <label class="policy-toggle">
                                        <input type="checkbox" name="esignature_enabled" {{ old('esignature_enabled', $esignatureEnabled) ? 'checked' : '' }}>
                                        <span class="policy-toggle-box">
                                            <span class="policy-toggle-title">Enable E-Signature</span>
                                            <span class="policy-toggle-copy">Require e-sign confirmation on protected workflow actions.</span>
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button class="btn btn-primary btn-compact" type="submit">
                            <i class="fas fa-save"></i>
                            Save Settings
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card form-side-card">
            <div class="card-header">
                <div class="section-title">Policy Notes</div>
            </div>
            <div class="card-body">
                <div class="form-note-list">
                    <div class="form-note-item">
                        <span class="form-note-title">Password Length</span>
                        <span class="form-note-copy">Keep the minimum strict enough for security, but not so high that users struggle to comply.</span>
                    </div>
                    <div class="form-note-item">
                        <span class="form-note-title">Session Timeout</span>
                        <span class="form-note-copy">Lower values improve security on shared systems, while higher values reduce user interruption.</span>
                    </div>
                    <div class="form-note-item">
                        <span class="form-note-title">Password Expiry</span>
                        <span class="form-note-copy">Users receive reminder emails before expiry, then must use Forgot Password after the password expires.</span>
                    </div>
                    <div class="form-note-item">
                        <span class="form-note-title">E-Sign Impact</span>
                        <span class="form-note-copy">When enabled, protected forms and administrative changes require signature confirmation flows.</span>
                    </div>
                    <div class="form-note-item">
                        <span class="form-note-title">Login Lockout</span>
                        <span class="form-note-copy">Set how many wrong passwords lock an account and how many minutes until it auto-unlocks. Email/SMS alerts are optional.</span>
                    </div>
                    <div class="form-note-item">
                        <span class="form-note-title">Password History</span>
                        <span class="form-note-copy">Blocks reuse of recent passwords on reset and change-password flows. Only hashed values are stored.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
