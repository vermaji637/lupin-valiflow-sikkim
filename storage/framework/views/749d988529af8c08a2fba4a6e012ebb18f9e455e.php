<?php $__env->startSection('title', 'Users List'); ?>

<?php $__env->startSection('content'); ?>
<div class="container-fluid px-0">
    <section class="page-hero">
        <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between">
            <div>
                <span class="page-kicker">
                    <i class="fas fa-users-cog"></i>
                    User Administration
                </span>
                <h2 class="page-title">Users Directory</h2>
                <p class="page-subtitle">
                    Manage access, roles, activation status, and password operations from a cleaner operational view.
                </p>
            </div>

            <div class="toolbar-row mt-3 mt-lg-0">
                <a href="<?php echo e(route('users.create')); ?>" class="btn btn-primary btn-compact toolbar-chip">
                    <i class="fas fa-plus"></i>
                    Add User
                </a>
                <!-- <a href="<?php echo e(route('users.export')); ?>" class="btn btn-success btn-compact toolbar-chip">
                    <i class="fas fa-file-excel"></i>
                    Export
                </a> -->
            </div>
        </div>

        <div class="metric-grid user-metric-grid">
            <div class="metric-card">
                <span class="metric-label">Total Users</span>
                <span class="metric-value"><?php echo e($stats['total']); ?></span>
                <span class="metric-note">Registered accounts in the system</span>
            </div>
            <div class="metric-card">
                <span class="metric-label">Active Users</span>
                <span class="metric-value"><?php echo e($stats['active']); ?></span>
                <span class="metric-note">Users currently enabled for access</span>
            </div>
            <div class="metric-card">
                <span class="metric-label">Inactive Users</span>
                <span class="metric-value"><?php echo e($stats['inactive']); ?></span>
                <span class="metric-note">Disabled accounts awaiting reactivation</span>
            </div>
            <div class="metric-card">
                <span class="metric-label">Available Roles</span>
                <span class="metric-value"><?php echo e($stats['roles']); ?></span>
                <span class="metric-note">Role profiles available for assignment</span>
            </div>
        </div>
    </section>

    <?php echo $__env->make('common.alert', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>

    <div class="card mb-4">
        <div class="card-header d-flex flex-column flex-lg-row align-items-lg-center justify-content-between">
            <div class="section-title">Filter Users</div>
            <div class="small text-muted mt-2 mt-lg-0">
                Showing <?php echo e($users->firstItem() ?? 0); ?>-<?php echo e($users->lastItem() ?? 0); ?> of <?php echo e($users->total()); ?> users
            </div>
        </div>
        <div class="card-body">
            <form method="GET" action="<?php echo e(route('users.index')); ?>">
                <div class="row">
                    <div class="col-lg-7 mb-3">
                        <label class="small text-muted">Search</label>
                        <input
                            type="text"
                            name="search"
                            value="<?php echo e(request('search')); ?>"
                            class="form-control"
                            placeholder="Name, designation & department, email, mobile, or role"
                        >
                    </div>
                    <div class="col-lg-3 mb-3">
                        <label class="small text-muted">Status</label>
                        <select name="status" class="form-control">
                            <option value="">All Statuses</option>
                            <option value="1" <?php if(request('status') === '1'): echo 'selected'; endif; ?>>Active</option>
                            <option value="0" <?php if(request('status') === '0'): echo 'selected'; endif; ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-lg-2 mb-3">
                        <label class="small text-muted d-block">&nbsp;</label>
                        <div class="toolbar-row justify-content-lg-end">
                            <button type="submit" class="btn btn-primary btn-compact">Apply</button>
                            <a href="<?php echo e(route('users.index')); ?>" class="btn btn-light btn-compact">Reset</a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex flex-column flex-lg-row align-items-lg-center justify-content-between">
            <div class="section-title">All Users</div>
            <div class="small text-muted mt-2 mt-lg-0">Compact controls and faster visibility for access management</div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered mb-0">
                    <thead>
                        <tr>
                            <th style="min-width: 220px;">User</th>
                            <th style="min-width: 160px;">Designation</th>
                            <th style="min-width: 160px;">Department</th>
                            <th style="min-width: 240px;">Contact</th>
                            <th style="min-width: 160px;">Role</th>
                            <th style="min-width: 120px;">Status</th>
                            <th style="min-width: 250px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $__empty_1 = true; $__currentLoopData = $users; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $user): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                            <?php
                                $fullName = trim($user->full_name);
                                $roleName = $user->roles->pluck('name')->first() ?? 'Unassigned';
                                $initials = strtoupper(substr($user->first_name ?? 'U', 0, 1) . substr($user->last_name ?? '', 0, 1));
                            ?>
                            <tr>
                                <td>
                                    <div class="user-listing">
                                        <div class="user-avatar"><?php echo e($initials !== '' ? $initials : 'U'); ?></div>
                                        <div>
                                            <div class="user-name"><?php echo e($fullName !== '' ? $fullName : 'Unnamed User'); ?></div>
                                            <div class="user-meta">User ID #<?php echo e($user->id); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo e($user->designation ?: '—'); ?></td>
                                <td><?php echo e($user->department_name ?: '—'); ?></td>
                                <td>
                                    <div class="user-contact-line"><?php echo e($user->email); ?></div>
                                    <div class="user-meta"><?php echo e($user->mobile_number ?: 'No mobile number'); ?></div>
                                </td>
                                <td>
                                    <span class="chip user-role-chip"><?php echo e($roleName); ?></span>
                                </td>
                                <td>
                                    <?php if($user->status == 1): ?>
                                        <span class="badge bg-success text-light user-status-badge">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger text-light user-status-badge">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-row">
                                        <?php if($user->status == 0): ?>
                                            <a
                                                href="<?php echo e(route('users.status', ['user_id' => $user->id, 'status' => 1])); ?>"
                                                class="btn btn-success btn-compact"
                                                title="Activate user"
                                            >
                                                <i class="fas fa-check"></i>
                                                Activate
                                            </a>
                                        <?php else: ?>
                                            <a
                                                href="<?php echo e(route('users.status', ['user_id' => $user->id, 'status' => 0])); ?>"
                                                class="btn btn-light btn-compact"
                                                title="Deactivate user"
                                            >
                                                <i class="fas fa-ban"></i>
                                                Deactivate
                                            </a>
                                        <?php endif; ?>

                                        <a
                                            href="<?php echo e(route('users.edit', ['user' => $user->id])); ?>"
                                            class="btn btn-primary btn-compact"
                                            title="Edit user"
                                        >
                                            <i class="fas fa-pen"></i>
                                            Edit
                                        </a>

                                        <!-- <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('change-any-user-password')): ?>
                                            <button
                                                type="button"
                                                class="btn btn-light btn-compact"
                                                title="Change password"
                                                onclick="openPasswordModal(<?php echo e($user->id); ?>, <?php echo json_encode($fullName !== '' ? $fullName : 'User', 15, 512) ?>)"
                                            >
                                                <i class="fas fa-key"></i>
                                                Password
                                            </button>
                                        <?php endif; ?> -->

                                        
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">No users found for the selected filters.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-4">
        <?php echo e($users->links()); ?>

    </div>
</div>

<div class="modal fade" id="passwordModal" tabindex="-1" role="dialog" aria-labelledby="passwordModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-1" id="passwordModalLabel">Change User Password</h5>
                    <div class="small text-muted">Update access password for <span id="modalUserName"></span></div>
                </div>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="passwordForm" method="POST" data-action-base="<?php echo e(url('users')); ?>">
                <div class="modal-body">
                    <?php echo csrf_field(); ?>
                    <div class="form-group">
                        <label class="small text-muted">New Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <div class="form-group mb-0">
                        <label class="small text-muted">Confirm Password</label>
                        <input type="password" name="password_confirmation" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light btn-compact" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-compact">Update Password</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('scripts'); ?>
<script>
function openPasswordModal(userId, userName) {
    var form = document.getElementById('passwordForm');
    var actionBase = form.getAttribute('data-action-base');

    document.getElementById('modalUserName').innerText = userName;
    form.action = actionBase.replace(/\/$/, '') + '/' + userId + '/change-password';
    $('#passwordModal').modal('show');
}
</script>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?><?php /**PATH D:\lupin\latest Lupin2\valiflow-main\resources\views/users/index.blade.php ENDPATH**/ ?>