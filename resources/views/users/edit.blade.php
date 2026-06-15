@extends('layouts.app')

@section('title', 'Edit User')

@section('content')
<div class="container-fluid px-0">
    <section class="page-hero">
        <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between">
            <div>
                <span class="page-kicker">
                    <i class="fas fa-user-edit"></i>
                    User Administration
                </span>
                <h2 class="page-title">Edit User</h2>
                <p class="page-subtitle">
                    Update account information, role assignment, and activation status with the same premium form flow.
                </p>
            </div>

            <div class="toolbar-row mt-3 mt-lg-0">
                <a href="{{ route('users.index') }}" class="btn btn-light btn-compact toolbar-chip">
                    <i class="fas fa-arrow-left"></i>
                    Back to Users
                </a>
            </div>
        </div>
    </section>

    @include('common.alert')

    <div class="form-shell">
        <div class="card form-main-card">
            <div class="card-header">
                <div class="section-title">User Information</div>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('users.update', ['user' => $user->id]) }}" class="esign-required">
                    @csrf
                    @method('PUT')

                    <div class="form-section-grid">
                        <div class="form-field">
                            <label class="form-label" for="first_name"><span class="form-required">*</span>First Name</label>
                            <input type="text" id="first_name" name="first_name" class="form-control @error('first_name') is-invalid @enderror" placeholder="Enter first name" value="{{ old('first_name', $user->first_name) }}">
                            @error('first_name')<span class="form-error">{{ $message }}</span>@enderror
                        </div>

                        <div class="form-field">
                            <label class="form-label" for="last_name"><span class="form-required">*</span>Last Name</label>
                            <input type="text" id="last_name" name="last_name" class="form-control @error('last_name') is-invalid @enderror" placeholder="Enter last name" value="{{ old('last_name', $user->last_name) }}">
                            @error('last_name')<span class="form-error">{{ $message }}</span>@enderror
                        </div>

                        <div class="form-field">
                            <label class="form-label" for="email"><span class="form-required">*</span>Email Address</label>
                            <input type="email" id="email" name="email" class="form-control @error('email') is-invalid @enderror" placeholder="Enter email address" value="{{ old('email', $user->email) }}">
                            @error('email')<span class="form-error">{{ $message }}</span>@enderror
                        </div>

                        <div class="form-field">
                            <label class="form-label" for="mobile_number"><span class="form-required">*</span>Mobile Number</label>
                            <input type="text" id="mobile_number" name="mobile_number" class="form-control @error('mobile_number') is-invalid @enderror" placeholder="Enter mobile number" value="{{ old('mobile_number', $user->mobile_number) }}">
                            @error('mobile_number')<span class="form-error">{{ $message }}</span>@enderror
                        </div>

                        <div class="form-field">
                            <label class="form-label" for="role_id"><span class="form-required">*</span>Role</label>
                            <select id="role_id" name="role_id" class="form-control @error('role_id') is-invalid @enderror">
                                <option value="">Select role</option>
                                @foreach ($roles as $role)
                                    <option value="{{ $role->id }}" @selected(old('role_id', optional($user->roles->first())->id) == $role->id)>{{ $role->name }}</option>
                                @endforeach
                            </select>
                            @error('role_id')<span class="form-error">{{ $message }}</span>@enderror
                        </div>

                        <div class="form-field">
                            <label class="form-label" for="status"><span class="form-required">*</span>Status</label>
                            <select id="status" name="status" class="form-control @error('status') is-invalid @enderror">
                                <option value="1" @selected((string) old('status', $user->status) === '1')>Active</option>
                                <option value="0" @selected((string) old('status', $user->status) === '0')>Inactive</option>
                            </select>
                            @error('status')<span class="form-error">{{ $message }}</span>@enderror
                        </div>
                    </div>

                    <div class="form-actions">
                        <a class="btn btn-light btn-compact" href="{{ route('users.index') }}">Cancel</a>
                        <input type="hidden" name="password">
                        <button type="submit" class="btn btn-primary btn-compact">
                            <i class="fas fa-save"></i>
                            Update User
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card form-side-card">
            <div class="card-header">
                <div class="section-title">Current Access</div>
            </div>
            <div class="card-body">
                <div class="form-note-list">
                    <div class="form-note-item">
                        <span class="form-note-title">Current User</span>
                        <span class="form-note-copy">{{ $user->full_name ?: $user->email }}</span>
                    </div>
                    <div class="form-note-item">
                        <span class="form-note-title">Current Role</span>
                        <span class="form-note-copy">{{ optional($user->roles->first())->name ?? 'Unassigned' }}</span>
                    </div>
                    <div class="form-note-item">
                        <span class="form-note-title">Current Status</span>
                        <span class="form-note-copy">{{ $user->status ? 'Active' : 'Inactive' }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
