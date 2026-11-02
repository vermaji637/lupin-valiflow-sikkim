@extends('auth.layouts.app')

@section('title', 'Login')

@section('content')
<div class="auth-stage">
    <section class="auth-showcase">
        <div>
            <div class="auth-brand">
                <div class="auth-brand-mark">
                    <img src="{{ asset('images/lupin-logo.png') }}" alt="Lupin">
                </div>
                <div>
                    <span class="auth-brand-name">{{ config('app.name', 'Lupin') }}</span>
                    <span class="auth-brand-subtitle">Protocol Control Suite</span>
                </div>
            </div>

            <div class="auth-story">
                <span class="auth-kicker">
                    <i class="fas fa-shield-alt"></i>
                    Controlled Workspace
                </span>
                <h1>Secure access for approvals, documents, and audit-ready reporting.</h1>
                <p>
                    Manage qualification workflows with a focused interface designed for regulated operations, multi-step approvals, and traceable records.
                </p>

                <div class="auth-metrics">
                    <div class="auth-metric">
                        <span class="auth-metric-value">Step by Step</span>
                        <span class="auth-metric-label">post approval workflow</span>
                    </div>
                    <div class="auth-metric">
                        <span class="auth-metric-value">PDF</span>
                        <span class="auth-metric-label">Report and offer generation</span>
                    </div>
                    <div class="auth-metric">
                        <span class="auth-metric-value">RBAC</span>
                        <span class="auth-metric-label">Role-based permission access</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="auth-footer-note">
            Designed for quality, engineering, validation, and approval operations.<br>
            <span style="display: inline-block; margin-top: 4px; font-size: 12px; color: rgba(255, 255, 255, 0.88);">
                Valiflow™ Platform by <strong style="color: #f7d79d; font-weight: 700;">Ecowiztech Solution Pvt. Ltd.™</strong>
            </span>
        </div>
    </section>

    <section class="auth-panel">
        <div class="auth-card">
            <div class="auth-card-header">
                <h2>Welcome back</h2>
                <p>Sign in to continue to the protocol and approval workspace.</p>
            </div>

            @if (session('error'))
                <div class="auth-alert">{{ session('error') }}</div>
            @endif

            @if (session('success'))
                <div class="auth-alert auth-alert-success">{{ session('success') }}</div>
            @endif

            <form method="POST" action="{{ route('login') }}">
                @csrf

                <div class="auth-field">
                    <label for="email" class="auth-label">Email Address</label>
                    <div class="auth-input-wrap">
                        <span class="auth-input-icon"><i class="fas fa-envelope"></i></span>
                        <input id="email"
                               type="email"
                               class="auth-input @error('email') is-invalid @enderror"
                               name="email"
                               value="{{ old('email') }}"
                               required
                               autocomplete="email"
                               autofocus
                               placeholder="name@company.com">
                    </div>
                    @error('email')
                        <div class="auth-invalid">{{ $message }}</div>
                    @enderror
                </div>

                <div class="auth-field">
                    <label for="password" class="auth-label">Password</label>
                    <div class="auth-input-wrap">
                        <span class="auth-input-icon"><i class="fas fa-lock"></i></span>
                        <input id="password"
                               type="password"
                               class="auth-input @error('password') is-invalid @enderror"
                               name="password"
                               required
                               autocomplete="current-password"
                               placeholder="Enter your password">
                    </div>
                    @error('password')
                        <div class="auth-invalid">{{ $message }}</div>
                    @enderror
                </div>

                <div class="auth-check-row">
                    <label class="auth-check" for="customCheck">
                        <input type="checkbox" name="remember" id="customCheck" {{ old('remember') ? 'checked' : '' }}>
                        <span>Remember me</span>
                    </label>

                    <a class="auth-link" href="{{ route('password.request') }}">Forgot password?</a>
                </div>

                <button class="auth-submit" type="submit">
                    Sign In
                </button>
            </form>

            <div class="auth-meta">
                Authorized users only. Activity may be monitored and logged.
                <div style="margin-top: 10px; font-size: 12px; color: #475569; font-weight: 500;">
                    © {{ date('Y') }} {{ config('app.name', 'Lupin') }}. All Rights Reserved. | Powered by <strong style="color: #0f4c81; font-weight: 800;">Ecowiztech Solution Pvt. Ltd.™</strong>
                </div>
            </div>
        </div>
    </section>
</div>
@endsection
