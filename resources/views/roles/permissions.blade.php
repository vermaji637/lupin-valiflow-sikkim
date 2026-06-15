@extends('layouts.app')

@section('title', 'Assign Permissions')

@section('content')
@php
    $totalPermissions = collect($groupedPermissions)->sum(fn ($group) => count($group['permissions']));
    $selectedPermissions = count($rolePermissions);
    $groupCount = count($groupedPermissions);
@endphp
<div class="container-fluid px-0">
    <section class="page-hero">
        <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between">
            <div>
                <span class="page-kicker">
                    <i class="fas fa-user-shield"></i>
                    Access Control
                </span>
                <h2 class="page-title">Assign Permissions</h2>
                <p class="page-subtitle">
                    Manage grouped permissions for <strong>{{ $role->name }}</strong> with clearer visibility, faster bulk actions, and safer updates.
                </p>
            </div>

            <div class="toolbar-row mt-3 mt-lg-0">
                <a href="{{ route('roles.index') }}" class="btn btn-light btn-compact toolbar-chip">
                    <i class="fas fa-arrow-left"></i>
                    Back to Roles
                </a>
            </div>
        </div>

        <div class="metric-grid user-metric-grid">
            <div class="metric-card">
                <span class="metric-label">Role</span>
                <span class="metric-value">{{ $role->name }}</span>
                <span class="metric-note">Current access profile being configured</span>
            </div>
            <div class="metric-card">
                <span class="metric-label">Selected</span>
                <span class="metric-value" id="selectedCount">{{ $selectedPermissions }}</span>
                <span class="metric-note">Permissions currently assigned</span>
            </div>
            <div class="metric-card">
                <span class="metric-label">Available</span>
                <span class="metric-value" id="totalCount">{{ $totalPermissions }}</span>
                <span class="metric-note">Permissions visible from the master permission set</span>
            </div>
            <div class="metric-card">
                <span class="metric-label">Groups</span>
                <span class="metric-value">{{ $groupCount }}</span>
                <span class="metric-note">Permission modules shown on this page</span>
            </div>
        </div>
    </section>

    @include('common.alert')

    <div class="card mb-4">
        <div class="card-header d-flex flex-column flex-lg-row align-items-lg-center justify-content-between">
            <div class="section-title">Permission Tools</div>
            <div class="toolbar-row mt-3 mt-lg-0">
                <button type="button" class="btn btn-light btn-compact" onclick="selectAllPermissions(true)">
                    <i class="fas fa-check-square"></i>
                    Select All
                </button>
                <button type="button" class="btn btn-light btn-compact" onclick="selectAllPermissions(false)">
                    <i class="fas fa-square"></i>
                    Deselect All
                </button>
                <input type="text" id="permissionSearch" class="form-control permission-search-input" placeholder="Search permissions or groups">
            </div>
        </div>
        <div class="card-body">
            <div class="small text-muted">
                Permissions are grouped by module. Use the group checkbox to toggle an entire section quickly.
            </div>
            <div class="permission-summary-strip">
                <div class="permission-summary-item">
                    <span class="permission-summary-label">Role</span>
                    <span class="permission-summary-value">{{ $role->name }}</span>
                </div>
                <div class="permission-summary-item">
                    <span class="permission-summary-label">Selected</span>
                    <span class="permission-summary-value" id="selectedCountStrip">{{ $selectedPermissions }}</span>
                </div>
                <div class="permission-summary-item">
                    <span class="permission-summary-label">Available</span>
                    <span class="permission-summary-value" id="totalCountStrip">{{ $totalPermissions }}</span>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.roles.permissions.update', $role->id) }}" id="permissionsForm">
        @csrf

        <div class="card">
            <div class="card-header d-flex flex-column flex-lg-row align-items-lg-center justify-content-between">
                <div>
                    <div class="section-title">Permission Groups</div>
                    <div class="small text-muted mt-1">Review access by module and update the selected role in one place.</div>
                </div>
                <div class="chip mt-3 mt-lg-0">
                    <i class="fas fa-layer-group"></i>
                    {{ $groupCount }} groups
                </div>
            </div>
            <div class="card-body permission-groups-shell">
                <div class="permission-groups-grid">
                    @foreach($groupedPermissions as $groupKey => $group)
                        <div class="card permission-group-card" data-group-card data-search="{{ strtolower($group['title']) }}">
                            <div class="card-header permission-group-header">
                                <div class="permission-group-title-block">
                                    <div class="permission-group-title">
                                        <i class="{{ $group['icon'] }}"></i>
                                        <span>{{ $group['title'] }}</span>
                                    </div>
                                    <div class="permission-group-meta">
                                        <span>{{ count($group['permissions']) }} permissions</span>
                                        <span><strong id="groupSelected_{{ $groupKey }}">{{ collect($group['permissions'])->whereIn('id', $rolePermissions)->count() }}</strong> selected</span>
                                    </div>
                                </div>
                                <label class="permission-group-toggle">
                                    <input
                                        class="group-select-all"
                                        type="checkbox"
                                        id="selectAll_{{ $groupKey }}"
                                        data-group="{{ $groupKey }}"
                                        onchange="toggleGroupPermissions('{{ $groupKey }}')"
                                    >
                                    <span>All</span>
                                </label>
                            </div>
                            <div class="card-body">
                                <div class="permission-pills">
                                    @foreach($group['permissions'] as $permission)
                                        <label
                                            class="permission-pill {{ in_array($permission['id'], $rolePermissions) ? 'is-selected' : '' }}"
                                            data-permission-pill
                                            data-search="{{ strtolower($permission['display_name'].' '.$permission['name']) }}"
                                            for="permission_{{ $permission['id'] }}"
                                        >
                                            <input
                                                class="permission-checkbox"
                                                type="checkbox"
                                                name="permissions[]"
                                                value="{{ $permission['id'] }}"
                                                id="permission_{{ $permission['id'] }}"
                                                data-group="{{ $groupKey }}"
                                                {{ in_array($permission['id'], $rolePermissions) ? 'checked' : '' }}
                                            >
                                            <span class="permission-pill-label">{{ $permission['display_name'] }}</span>
                                            <span class="permission-pill-name">{{ $permission['name'] }}</span>
                                        </label>
                                    @endforeach
                                </div>

                                @if(empty($group['permissions']))
                                    <div class="empty-state">
                                        <i class="fas fa-inbox"></i>
                                        <div>No permissions available in this group.</div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-body d-flex flex-column flex-lg-row align-items-lg-center justify-content-between">
                <div class="small text-muted mb-3 mb-lg-0">
                    Selected <strong id="selectedCountFooter">{{ $selectedPermissions }}</strong> of <strong id="totalCountFooter">{{ $totalPermissions }}</strong> permissions for <strong>{{ $role->name }}</strong>.
                </div>
                <div class="toolbar-row">
                    <a href="{{ route('roles.index') }}" class="btn btn-light btn-compact">
                        <i class="fas fa-times"></i>
                        Cancel
                    </a>
                    <button type="button" class="btn btn-primary btn-compact" onclick="showEsignModal()">
                        <i class="fas fa-save"></i>
                        Save Permissions
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- E-Sign Modal -->
<div class="modal fade" id="eSignModal" tabindex="-1" role="dialog" aria-labelledby="eSignModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="eSignModalLabel">
                    <i class="fas fa-signature"></i> E-Signature Required
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-3">
                    Please confirm your password to save the permission changes for role <strong>{{ $role->name }}</strong>.
                </p>
                
                <div class="form-group">
                    <label for="eSignPassword" class="font-weight-bold">
                        <i class="fas fa-lock"></i> Password
                    </label>
                    <input type="password" 
                           class="form-control" 
                           id="eSignPassword" 
                           placeholder="Enter your password"
                           autocomplete="current-password">
                    <div id="eSignError" class="alert alert-danger d-none mt-2" role="alert">
                        <i class="fas fa-exclamation-triangle"></i> <span id="eSignErrorText"></span>
                    </div>
                </div>

                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <strong>Changes Summary:</strong>
                    <ul class="mb-0 mt-2">
                        <li>Selected permissions will be assigned to the role</li>
                        <li>Unselected permissions will be removed from the role</li>
                        <li>This action will be logged in the audit trail</li>
                    </ul>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="btn btn-primary" onclick="submitPermissions()">
                    <i class="fas fa-save"></i> Confirm & Save
                </button>
            </div>
        </div>
    </div>
</div>

@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    updateSelectedCount();

    document.querySelectorAll('.permission-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', function () {
            syncPermissionPillState(this);
            updateSelectedCount();
        });
    });

    const searchInput = document.getElementById('permissionSearch');
    if (searchInput) {
        searchInput.addEventListener('input', filterPermissionCards);
    }
});

function updateSelectedCount() {
    const selectedCount = document.querySelectorAll('.permission-checkbox:checked').length;
    const totalCount = document.querySelectorAll('.permission-checkbox').length;

    document.getElementById('selectedCount').textContent = selectedCount;
    document.getElementById('totalCount').textContent = totalCount;
    document.getElementById('selectedCountFooter').textContent = selectedCount;
    document.getElementById('totalCountFooter').textContent = totalCount;
    document.getElementById('selectedCountStrip').textContent = selectedCount;
    document.getElementById('totalCountStrip').textContent = totalCount;

    updateGroupSelectAllCheckboxes();
    updateGroupSelectedCounts();
}

function syncPermissionPillState(checkbox) {
    const pill = checkbox.closest('[data-permission-pill]');
    if (!pill) return;
    pill.classList.toggle('is-selected', checkbox.checked);
}

function updateGroupSelectAllCheckboxes() {
    document.querySelectorAll('.group-select-all').forEach(groupCheckbox => {
        const groupKey = groupCheckbox.dataset.group;
        const groupCheckboxes = document.querySelectorAll(`.permission-checkbox[data-group="${groupKey}"]`);
        const checkedGroupCheckboxes = document.querySelectorAll(`.permission-checkbox[data-group="${groupKey}"]:checked`);

        groupCheckbox.checked = groupCheckboxes.length > 0 && checkedGroupCheckboxes.length === groupCheckboxes.length;
        groupCheckbox.indeterminate = checkedGroupCheckboxes.length > 0 && checkedGroupCheckboxes.length < groupCheckboxes.length;
    });
}

function updateGroupSelectedCounts() {
    document.querySelectorAll('.group-select-all').forEach(groupCheckbox => {
        const groupKey = groupCheckbox.dataset.group;
        const checkedGroupCheckboxes = document.querySelectorAll(`.permission-checkbox[data-group="${groupKey}"]:checked`).length;
        const selectedCounter = document.getElementById(`groupSelected_${groupKey}`);

        if (selectedCounter) {
            selectedCounter.textContent = checkedGroupCheckboxes;
        }
    });
}

function toggleGroupPermissions(groupKey) {
    const groupCheckbox = document.getElementById(`selectAll_${groupKey}`);
    const groupCheckboxes = document.querySelectorAll(`.permission-checkbox[data-group="${groupKey}"]`);

    groupCheckboxes.forEach(checkbox => {
        checkbox.checked = groupCheckbox.checked;
        syncPermissionPillState(checkbox);
    });

    updateSelectedCount();
}

function selectAllPermissions(select) {
    document.querySelectorAll('.permission-checkbox').forEach(checkbox => {
        checkbox.checked = select;
        syncPermissionPillState(checkbox);
    });

    updateSelectedCount();
}

function filterPermissionCards() {
    const query = (document.getElementById('permissionSearch').value || '').trim().toLowerCase();

    document.querySelectorAll('[data-group-card]').forEach(card => {
        const groupText = card.dataset.search || '';
        let hasVisiblePermission = false;

        card.querySelectorAll('[data-permission-pill]').forEach(pill => {
            const permissionText = pill.dataset.search || '';
            const visible = query === '' || permissionText.includes(query) || groupText.includes(query);
            pill.style.display = visible ? '' : 'none';
            if (visible) {
                hasVisiblePermission = true;
            }
        });

        card.style.display = hasVisiblePermission || query === '' || groupText.includes(query) ? '' : 'none';
    });
}

function showEsignModal() {
    $('#eSignModal').modal('show');
    document.getElementById('eSignPassword').value = '';
    document.getElementById('eSignError').classList.add('d-none');
}

function submitPermissions() {
    const password = document.getElementById('eSignPassword').value;

    if (!password) {
        showEsignError('Please enter your password');
        return;
    }

    submitForm();
}

function showEsignError(message) {
    const errorBox = document.getElementById('eSignError');
    const errorText = document.getElementById('eSignErrorText');

    errorText.textContent = message;
    errorBox.classList.remove('d-none');

    document.getElementById('eSignPassword').addEventListener('input', function() {
        errorBox.classList.add('d-none');
    });
}

function submitForm() {
    const submitButton = document.querySelector('button[onclick="submitPermissions()"]');
    submitButton.disabled = true;
    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

    document.getElementById('permissionsForm').submit();
}

document.addEventListener('keydown', function(e) {
    if (e.ctrlKey && e.key === 's') {
        e.preventDefault();
        showEsignModal();
    }

    if (e.key === 'Escape') {
        $('#eSignModal').modal('hide');
    }
});

$('#eSignModal').on('shown.bs.modal', function() {
    document.getElementById('eSignPassword').focus();
});
</script>
@endsection
