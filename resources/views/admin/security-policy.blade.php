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
@endphp
<div class="container-fluid px-0">
    <section class="page-hero">
        <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between">
            <div>
                <span class="page-kicker">
                    <i class="fas fa-shield-alt"></i>
                    Security Control
                </span>
                <h2 class="page-title">Security Policy Settings</h2>
                <p class="page-subtitle">
                    Configure password rules, inactivity logout, and e-sign enforcement from one premium control surface.
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
                <span class="metric-label">E-Signature</span>
                <span class="metric-value">{{ $esignatureEnabled ? 'On' : 'Off' }}</span>
                <span class="metric-note">Controls signature confirmation on protected actions</span>
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
                        <span class="form-note-title">E-Sign Impact</span>
                        <span class="form-note-copy">When enabled, protected forms and administrative changes require signature confirmation flows.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
