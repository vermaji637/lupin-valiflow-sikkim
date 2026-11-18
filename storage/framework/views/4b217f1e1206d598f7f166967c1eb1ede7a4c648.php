<?php $__env->startSection('title', 'Audit History'); ?>

<?php $__env->startPush('styles'); ?>
<style>
    /* Audit History only — sticky column headers inside the log scroll area */
    .audit-history-page .audit-history-table-scroll {
        max-height: min(72vh, calc(100vh - 14rem));
        overflow: auto;
        -webkit-overflow-scrolling: touch;
    }

    .audit-history-page .audit-history-table thead th {
        position: sticky;
        top: 0;
        z-index: 3;
        background-color: #f7f9fc;
        background-clip: padding-box;
        box-shadow: inset 0 -1px 0 rgba(15, 23, 42, 0.14);
    }

    .audit-history-page .audit-history-table.table-bordered thead th {
        border-color: rgba(15, 23, 42, 0.08);
    }
</style>
<?php $__env->stopPush(); ?>

<?php $__env->startSection('content'); ?>
<div class="container-fluid px-0 audit-history-page">
    <section class="page-hero">
        <span class="page-kicker">
            <i class="fas fa-shield-alt"></i>
            Audit Control
        </span>
        <h2 class="page-title">Audit & Activity History</h2>
        <p class="page-subtitle">
            Simple activity history for non-technical users with clear before/after values.
        </p>

        <div class="metric-grid">
            <div class="metric-card">
                <span class="metric-label">Total Events</span>
                <span class="metric-value"><?php echo e($summary['total']); ?></span>
                <span class="metric-note">Current filtered result set</span>
            </div>
            <div class="metric-card">
                <span class="metric-label">Form Submissions</span>
                <span class="metric-value"><?php echo e($summary['requests']); ?></span>
                <span class="metric-note">Disabled to avoid duplicate audit rows</span>
            </div>
            <div class="metric-card">
                <span class="metric-label">Changes / Auth</span>
                <span class="metric-value"><?php echo e($summary['changes']); ?> / <?php echo e($summary['auth']); ?></span>
                <span class="metric-note">Create, update, import, export, approve, and reject events</span>
            </div>
        </div>
    </section>

    <div class="card mb-4">
        <div class="card-header">
            <div class="section-title">Filter History</div>
        </div>
        <div class="card-body">
            <form method="GET">
                <div class="row">
                    <div class="col-lg-3 mb-3">
                        
                        <?php if(\App\Services\SidebarPermissionService::userCan('audit-view')): ?>
                            <label class="small text-muted">User</label>
                            <select name="user_id" class="form-control">
                                <option value="">All Users</option>
                                <?php $__currentLoopData = $users; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $user): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <?php $fullName = trim(($user->first_name ?? '').' '.($user->last_name ?? '')); ?>
                                    <option value="<?php echo e($user->id); ?>" <?php if(request('user_id')==$user->id): echo 'selected'; endif; ?>>
                                        <?php echo e($fullName !== '' ? $fullName : $user->email); ?>

                                    </option>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            </select>
                        <?php else: ?>
                            <label class="small text-muted">User</label>
                            <input class="form-control" value="<?php echo e(trim((auth()->user()->first_name ?? '').' '.(auth()->user()->last_name ?? ''))); ?>" disabled>
                        <?php endif; ?>
                    </div>

                    <div class="col-lg-2 mb-3">
                        <label class="small text-muted">Event</label>
                        <select name="event" class="form-control">
                            <option value="">All Events</option>
                            <?php $__currentLoopData = $filterEvents; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $value => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <option value="<?php echo e($value); ?>" <?php if(request('event') === $value): echo 'selected'; endif; ?>>
                                    <?php echo e($label); ?>

                                </option>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </select>
                    </div>

                    <div class="col-lg-2 mb-3">
                        <label class="small text-muted">From Date</label>
                        <input type="date" name="from_date" value="<?php echo e(request('from_date')); ?>" class="form-control">
                    </div>

                    <div class="col-lg-2 mb-3">
                        <label class="small text-muted">To Date</label>
                        <input type="date" name="to_date" value="<?php echo e(request('to_date')); ?>" class="form-control">
                    </div>

                    
                </div>

                <div class="toolbar-row">
                    <button type="submit" class="btn btn-primary btn-compact">Apply Filters</button>
                    <a href="<?php echo e(route('admin.audit.logs')); ?>" class="btn btn-light btn-compact">Reset</a>
                    <a href="<?php echo e(route('admin.audit.logs.pdf', request()->only(['user_id', 'event', 'from_date', 'to_date']))); ?>"
                       class="btn btn-outline-danger btn-compact">
                        <i class="fas fa-file-archive"></i> Download ZIP
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="section-title">History Log</div>
        </div>
        <div class="card-body p-0">
            <div class="audit-history-table-scroll">
                <table class="table table-bordered mb-0 audit-history-table">
                    <thead>
                        <tr>
                            <th>Date &amp; Time</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Activity Performed</th>
                            <th>Status</th>
                            <th>Field Changed</th>
                            <th>Previous Value</th>
                            <th>New Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $__empty_1 = true; $__currentLoopData = $logs; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $log): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                            <tr>
                                <td><?php echo e(optional($log->created_at)->format('d-m-Y h:i A')); ?></td>
                                <td>
                                    <div class="font-weight-bold"><?php echo e($log->actor_name ?? 'System'); ?></div>
                                    <div class="small text-muted"><?php echo e($log->actor_email ?? ''); ?></div>
                                </td>
                                <td><?php echo e($log->action_label ?? '—'); ?></td>
                                <td><?php echo e($log->activity_label ?? '—'); ?></td>
                                <td>
                                    <span class="badge <?php echo e(str_contains(strtolower($log->workflow_status_label ?? ''), 'reject') ? 'badge-danger' : (str_contains(strtolower($log->workflow_status_label ?? ''), 'approv') || str_contains(strtolower($log->workflow_status_label ?? ''), 'complet') ? 'badge-success' : 'badge-secondary')); ?>">
                                        <?php echo e($log->workflow_status_label ?? '—'); ?>

                                    </span>
                                </td>
                                <td style="white-space: pre-line;"><?php echo e($log->changed_fields_text ?? '—'); ?></td>
                                <td style="white-space: pre-line;"><?php echo e($log->previous_value_text ?? '—'); ?></td>
                                <td style="white-space: pre-line;"><?php echo e($log->new_value_text ?? '—'); ?></td>
                            </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">No audit history found for the current filters.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-4">
        <?php echo e($logs->links()); ?>

    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?><?php /**PATH D:\lupin\latest Lupin2\valiflow-main\resources\views/admin/audit-logs/index.blade.php ENDPATH**/ ?>