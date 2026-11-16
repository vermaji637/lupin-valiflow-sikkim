<?php $__env->startSection('title', 'Approval Dashboard'); ?>

<?php $__env->startSection('content'); ?>
<?php
    $neqPending = $neqPending ?? collect();
    $neqApproved = $neqApproved ?? collect();
    $neqReturned = $neqReturned ?? collect();
    $pendingCount = $pending->total() + $neqPending->count();
    $approvedCount = $approvedByMe->total() + $neqApproved->count();
    $rejectedCount = $rejectedByMe->total() + $neqReturned->count();
    $isAdminDashboard = $isAdminDashboard ?? !empty($adminProtocolActivity);
?>

<div class="container-fluid px-0">
    <section class="page-hero">
        <span class="page-kicker">
            <i class="fas fa-shield-alt"></i>
            Executive Workspace
        </span>
        <div class="row align-items-end">
            <div class="col-lg-7">
                <h2 class="page-title"><?php echo e($isAdminDashboard ? 'Admin Protocol Dashboard' : 'My Approval Dashboard'); ?></h2>
                <p class="page-subtitle">
                    <?php echo e($isAdminDashboard
                        ? 'Monitor all users protocol queues, approved records, and returned items from one admin control view.'
                        : 'Track the protocols waiting on your decision, review completed approvals, and monitor rejected records from a single operational view.'); ?>

                </p>
            </div>
            <div class="col-lg-5">
                <div class="metric-grid">
                    <div class="metric-card">
                        <span class="metric-label"><?php echo e($isAdminDashboard ? 'All Pending' : 'Pending Now'); ?></span>
                        <span class="metric-value"><?php echo e($pendingCount); ?></span>
                        <span class="metric-note"><?php echo e($isAdminDashboard ? 'Pending across all users' : 'Items currently routed to you'); ?></span>
                    </div>
                    <div class="metric-card">
                        <span class="metric-label">Approved</span>
                        <span class="metric-value"><?php echo e($approvedCount); ?></span>
                        <span class="metric-note"><?php echo e($isAdminDashboard ? 'Fully approved protocols' : 'Protocols you have approved'); ?></span>
                    </div>
                    <div class="metric-card">
                        <span class="metric-label"><?php echo e($isAdminDashboard ? 'Returned' : 'Rejected'); ?></span>
                        <span class="metric-value"><?php echo e($rejectedCount); ?></span>
                        <span class="metric-note"><?php echo e($isAdminDashboard ? 'Returned for correction' : 'Items returned for correction'); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="row">
        <div class="col-12 mb-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <div class="section-title"><?php echo e($isAdminDashboard ? 'All Pending Protocols' : 'Pending Approvals'); ?></div>
                        <div class="text-muted small mt-1">
                            <?php echo e($isAdminDashboard
                                ? 'Global pending protocols with the current responsible approver.'
                                : 'Records that require your action at the current workflow step.'); ?>

                        </div>
                    </div>
                    <span class="chip">
                        <span class="status-dot" style="background:#b7791f;"></span>
                        <?php echo e($pendingCount); ?> active
                    </span>
                </div>
                <div class="card-body">
                    <?php if($pending->count() === 0 && $neqPending->count() === 0): ?>
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <div class="font-weight-bold mb-1"><?php echo e($isAdminDashboard ? 'No pending protocols.' : 'No pending approvals.'); ?></div>
                            <div><?php echo e($isAdminDashboard ? 'Pending protocols from all users will appear here.' : 'New approvals assigned to you will appear here.'); ?></div>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Protocol Title</th>
                                        <?php if($isAdminDashboard): ?>
                                            <th>Created By</th>
                                            <th>Current Owner</th>
                                        <?php endif; ?>
                                        <th>Status</th>
                                        <th>Stage</th>
                                        <th class="text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $__currentLoopData = $pending; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                        <?php
                                            $currentStep = $isAdminDashboard
                                                ? $r->approvalSteps->first(function ($step) use ($r) {
                                                    return $step->stage === $r->stage && is_null($step->approved_at) && is_null($step->rejected_at);
                                                })
                                                : null;

                                            $pendingCreator = $isAdminDashboard && $r->creator
                                                ? trim(($r->creator->first_name ?? '').' '.($r->creator->last_name ?? ''))
                                                : '-';

                                            $pendingOwner = $isAdminDashboard && $currentStep?->user
                                                ? trim(($currentStep->user->first_name ?? '').' '.($currentStep->user->last_name ?? ''))
                                                : '-';
                                        ?>
                                        <tr>
                                            <td><?php echo e($r->displayCode()); ?></td>
                                            <td><?php echo e($r->protocol_title !== '' ? $r->protocol_title : '-'); ?></td>
                                            <?php if($isAdminDashboard): ?>
                                                <td><?php echo e($pendingCreator !== '' ? $pendingCreator : '-'); ?></td>
                                                <td><?php echo e($pendingOwner !== '' ? $pendingOwner : '-'); ?></td>
                                            <?php endif; ?>
                                            <td><?php echo e(strtoupper(str_replace('_', ' ', $r->status))); ?></td>
                                            <td><?php echo e(strtoupper($r->stage)); ?></td>
                                            <td class="text-right">
                                                <a href="<?php echo e(route('reports.show', $r)); ?>" class="btn btn-sm btn-primary">
                                                    Open Report
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                                    <?php $__currentLoopData = $neqPending; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $doc): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                        <?php
                                            $neqCreator = $doc->uploader
                                                ? trim(($doc->uploader->first_name ?? '').' '.($doc->uploader->last_name ?? ''))
                                                : '-';
                                            $neqStep = $doc->approvals->sortBy('step_order')->first(function ($step) {
                                                return ! empty($step->user_id) && is_null($step->approved_at) && is_null($step->rejected_at);
                                            });
                                            $neqOwner = $neqStep?->user
                                                ? trim(($neqStep->user->first_name ?? '').' '.($neqStep->user->last_name ?? ''))
                                                : '-';
                                            $neqTitle = pathinfo((string) $doc->original_filename, PATHINFO_FILENAME);
                                        ?>
                                        <tr>
                                            <td><?php echo e($doc->display_code ?: ('NEQ'.$doc->id)); ?></td>
                                            <td><?php echo e($neqTitle !== '' ? $neqTitle : 'New Equipment Qualification'); ?></td>
                                            <?php if($isAdminDashboard): ?>
                                                <td><?php echo e($neqCreator !== '' ? $neqCreator : '-'); ?></td>
                                                <td><?php echo e($neqOwner !== '' ? $neqOwner : '-'); ?></td>
                                            <?php endif; ?>
                                            <td>PENDING</td>
                                            <td>NEQ</td>
                                            <td class="text-right">
                                                <a href="<?php echo e(route('protocol.new-equipment-qualification.view', $doc)); ?>" class="btn btn-sm btn-primary">
                                                    Open Report
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-4">
                            <?php echo e($pending->links()); ?>

                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-xl-6 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <div class="section-title"><?php echo e($isAdminDashboard ? 'Approved Protocols' : 'Approved By Me'); ?></div>
                        <div class="text-muted small mt-1">
                            <?php echo e($isAdminDashboard
                                ? 'All protocols that have completed the full approval cycle.'
                                : 'Completed decisions recorded in the audit trail.'); ?>

                        </div>
                    </div>
                    <span class="chip">
                        <span class="status-dot" style="background:#157a5c;"></span>
                        <?php echo e($approvedCount); ?> approved
                    </span>
                </div>
                <div class="card-body">
                    <?php if($approvedByMe->count() === 0 && $neqApproved->count() === 0): ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <div class="font-weight-bold mb-1"><?php echo e($isAdminDashboard ? 'No approved protocols yet.' : 'No approved reports yet.'); ?></div>
                            <div><?php echo e($isAdminDashboard ? 'Approved protocols from all users will be listed here.' : 'Approved protocols will be listed here.'); ?></div>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Protocol Title</th>
                                        <?php if($isAdminDashboard): ?>
                                            <th>Created By</th>
                                            <th>Completed On</th>
                                        <?php endif; ?>
                                        <th>Status</th>
                                        <th class="text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $__currentLoopData = $approvedByMe; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                        <?php
                                            $approvedCreator = $isAdminDashboard && $r->creator
                                                ? trim(($r->creator->first_name ?? '').' '.($r->creator->last_name ?? ''))
                                                : '-';
                                        ?>
                                        <tr>
                                            <td><?php echo e($r->displayCode()); ?></td>
                                            <td><?php echo e($r->protocol_title !== '' ? $r->protocol_title : '-'); ?></td>
                                            <?php if($isAdminDashboard): ?>
                                                <td><?php echo e($approvedCreator !== '' ? $approvedCreator : '-'); ?></td>
                                                <td><?php echo e(optional($r->updated_at)->format('d M Y h:i A')); ?></td>
                                            <?php endif; ?>
                                            <td><?php echo e(strtoupper(str_replace('_', ' ', $r->status))); ?></td>
                                            <td class="text-right">
                                                <a href="<?php echo e(route('reports.show', $r)); ?>" class="btn btn-sm btn-light">
                                                    View
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                                    <?php $__currentLoopData = $neqApproved; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $doc): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                        <?php
                                            $neqCreator = $doc->uploader
                                                ? trim(($doc->uploader->first_name ?? '').' '.($doc->uploader->last_name ?? ''))
                                                : '-';
                                            $neqTitle = pathinfo((string) $doc->original_filename, PATHINFO_FILENAME);
                                        ?>
                                        <tr>
                                            <td><?php echo e($doc->display_code ?: ('NEQ'.$doc->id)); ?></td>
                                            <td><?php echo e($neqTitle !== '' ? $neqTitle : 'New Equipment Qualification'); ?></td>
                                            <?php if($isAdminDashboard): ?>
                                                <td><?php echo e($neqCreator !== '' ? $neqCreator : '-'); ?></td>
                                                <td><?php echo e(optional($doc->updated_at)->format('d M Y h:i A')); ?></td>
                                            <?php endif; ?>
                                            <td>APPROVED</td>
                                            <td class="text-right">
                                                <a href="<?php echo e(route('protocol.new-equipment-qualification.view', $doc)); ?>" class="btn btn-sm btn-light">
                                                    View
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-4">
                            <?php echo e($approvedByMe->links()); ?>

                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-xl-6 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <div class="section-title"><?php echo e($isAdminDashboard ? 'Returned Protocols' : 'Rejected By Me'); ?></div>
                        <div class="text-muted small mt-1">
                            <?php echo e($isAdminDashboard
                                ? 'All protocols sent back to the creator for revision.'
                                : 'Protocols returned to the creator for revision.'); ?>

                        </div>
                    </div>
                    <span class="chip">
                        <span class="status-dot" style="background:#b63d3d;"></span>
                        <?php echo e($rejectedCount); ?> <?php echo e($isAdminDashboard ? 'returned' : 'rejected'); ?>

                    </span>
                </div>
                <div class="card-body">
                    <?php if($rejectedByMe->count() === 0 && $neqReturned->count() === 0): ?>
                        <div class="empty-state">
                            <i class="fas fa-rotate-left"></i>
                            <div class="font-weight-bold mb-1"><?php echo e($isAdminDashboard ? 'No returned protocols.' : 'No rejected reports.'); ?></div>
                            <div><?php echo e($isAdminDashboard ? 'Returned protocols from all users will appear here when needed.' : 'Returned protocols will appear here when required.'); ?></div>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Protocol Title</th>
                                        <?php if($isAdminDashboard): ?>
                                            <th>Created By</th>
                                            <th>Returned On</th>
                                        <?php endif; ?>
                                        <th>Status</th>
                                        <th class="text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $__currentLoopData = $rejectedByMe; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                        <?php
                                            $returnedCreator = $isAdminDashboard && $r->creator
                                                ? trim(($r->creator->first_name ?? '').' '.($r->creator->last_name ?? ''))
                                                : '-';
                                            $isReturnedCreator = (int) $r->created_by === (int) auth()->id();
                                            $isReturnedDocx = ! empty($r->docx_import_path);
                                        ?>
                                        <tr>
                                            <td><?php echo e($r->displayCode()); ?></td>
                                            <td><?php echo e($r->protocol_title !== '' ? $r->protocol_title : '-'); ?></td>
                                            <?php if($isAdminDashboard): ?>
                                                <td><?php echo e($returnedCreator !== '' ? $returnedCreator : '-'); ?></td>
                                                <td><?php echo e(optional($r->updated_at)->format('d M Y h:i A')); ?></td>
                                            <?php endif; ?>
                                            <td><?php echo e(strtoupper(str_replace('_', ' ', $r->status))); ?></td>
                                            <td class="text-right">
                                                <?php if($isReturnedDocx && $isReturnedCreator): ?>
                                                    <a href="<?php echo e(route('reports.docx-import.fill-form', $r)); ?>" class="btn btn-sm btn-primary">
                                                        Edit &amp; Resubmit
                                                    </a>
                                                <?php endif; ?>
                                                <a href="<?php echo e(route('reports.show', ['report' => $r, 'view' => 1])); ?>" class="btn btn-sm btn-light">
                                                    View
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                                    <?php $__currentLoopData = $neqReturned; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $doc): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                        <?php
                                            $neqCreator = $doc->uploader
                                                ? trim(($doc->uploader->first_name ?? '').' '.($doc->uploader->last_name ?? ''))
                                                : '-';
                                            $neqTitle = pathinfo((string) $doc->original_filename, PATHINFO_FILENAME);
                                        ?>
                                        <tr>
                                            <td><?php echo e($doc->display_code ?: ('NEQ'.$doc->id)); ?></td>
                                            <td><?php echo e($neqTitle !== '' ? $neqTitle : 'New Equipment Qualification'); ?></td>
                                            <?php if($isAdminDashboard): ?>
                                                <td><?php echo e($neqCreator !== '' ? $neqCreator : '-'); ?></td>
                                                <td><?php echo e(optional($doc->updated_at)->format('d M Y h:i A')); ?></td>
                                            <?php endif; ?>
                                            <td>RETURNED</td>
                                            <td class="text-right">
                                                <a href="<?php echo e(route('protocol.new-equipment-qualification.view', $doc)); ?>" class="btn btn-sm btn-light">
                                                    View
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-4">
                            <?php echo e($rejectedByMe->links()); ?>

                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?><?php /**PATH D:\lupin\latest Lupin2\valiflow-main\resources\views/home.blade.php ENDPATH**/ ?>