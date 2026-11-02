@extends('layouts.app')

@section('title', 'Add Users')

@section('content')
<div class="container-fluid px-0">
    <section class="page-hero">
        <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between">
            <div>
                <span class="page-kicker">
                    <i class="fas fa-user-plus"></i>
                    User Administration
                </span>
                <h2 class="page-title">Create User</h2>
                <p class="page-subtitle">
                    Add a new system account with role assignment and access status in a cleaner controlled form.
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
                <form method="POST" action="{{ route('users.store') }}" class="esign-required">
                    @csrf
                    <div class="form-section-grid">
                        <div class="form-field">
                            <label class="form-label" for="first_name"><span class="form-required">*</span>First Name</label>
                            <input type="text" id="first_name" name="first_name" class="form-control @error('first_name') is-invalid @enderror" placeholder="Enter first name" value="{{ old('first_name') }}">
                            @error('first_name')<span class="form-error">{{ $message }}</span>@enderror
                        </div>

                        <div class="form-field">
                            <label class="form-label" for="last_name"><span class="form-required">*</span>Last Name</label>
                            <input type="text" id="last_name" name="last_name" class="form-control @error('last_name') is-invalid @enderror" placeholder="Enter last name" value="{{ old('last_name') }}">
                            @error('last_name')<span class="form-error">{{ $message }}</span>@enderror
                        </div>

                        <div class="form-field">
                            <label class="form-label" for="designation">Designation</label>
                            <input type="text" id="designation" name="designation" class="form-control @error('designation') is-invalid @enderror" placeholder="Enter designation" value="{{ old('designation') }}">
                            @error('designation')<span class="form-error">{{ $message }}</span>@enderror
                        </div>

                        <div class="form-field">
                            <label class="form-label" for="department_name">Department</label>
                            <input type="text" id="department_name" name="department_name" class="form-control @error('department_name') is-invalid @enderror" placeholder="Enter department" value="{{ old('department_name') }}">
                            @error('department_name')<span class="form-error">{{ $message }}</span>@enderror
                        </div>

                        <div class="form-field">
                            <label class="form-label" for="email"><span class="form-required">*</span>Email Address</label>
                            <input type="email" id="email" name="email" class="form-control @error('email') is-invalid @enderror" placeholder="Enter email address" value="{{ old('email') }}">
                            @error('email')<span class="form-error">{{ $message }}</span>@enderror
                        </div>

                        <div class="form-field">
                            <label class="form-label" for="mobile_number"><span class="form-required">*</span>Mobile Number</label>
                            <input type="text" id="mobile_number" name="mobile_number" class="form-control @error('mobile_number') is-invalid @enderror" placeholder="Enter mobile number" value="{{ old('mobile_number') }}">
                            @error('mobile_number')<span class="form-error">{{ $message }}</span>@enderror
                        </div>

                        <div class="form-field">
                            <label class="form-label" for="role_id"><span class="form-required">*</span>Role</label>
                            <select id="role_id" name="role_id" class="form-control @error('role_id') is-invalid @enderror">
                                <option value="">Select role</option>
                                @foreach ($roles as $role)
                                    <option value="{{ $role->id }}" @selected(old('role_id') == $role->id)>{{ $role->name }}</option>
                                @endforeach
                            </select>
                            @error('role_id')<span class="form-error">{{ $message }}</span>@enderror
                        </div>

                        <div class="form-field">
                            <label class="form-label" for="status"><span class="form-required">*</span>Status</label>
                            <select id="status" name="status" class="form-control @error('status') is-invalid @enderror">
                                <option value="1" @selected(old('status', '1') == '1')>Active</option>
                                <option value="0" @selected(old('status') === '0')>Inactive</option>
                            </select>
                            @error('status')<span class="form-error">{{ $message }}</span>@enderror
                        </div>
                    </div>

                    <div class="form-actions">
                        <a class="btn btn-light btn-compact" href="{{ route('users.index') }}">Cancel</a>
                        <input type="hidden" name="password">
                        <button type="submit" class="btn btn-primary btn-compact">
                            <i class="fas fa-save"></i>
                            Save User
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card form-side-card">
            <div class="card-header">
                <div class="section-title">Guidance</div>
            </div>
            <div class="card-body">
                <div class="form-note-list">
                    <div class="form-note-item">
                        <span class="form-note-title">Access Setup</span>
                        <span class="form-note-copy">A password setup email is sent after saving the user record.</span>
                    </div>
                    <div class="form-note-item">
                        <span class="form-note-title">Role Assignment</span>
                        <span class="form-note-copy">Select the correct role because it controls visibility, actions, and approval permissions.</span>
                    </div>
                    <div class="form-note-item">
                        <span class="form-note-title">E-Sign Check</span>
                        <span class="form-note-copy">This form uses the existing e-sign verification flow before submission is accepted.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
