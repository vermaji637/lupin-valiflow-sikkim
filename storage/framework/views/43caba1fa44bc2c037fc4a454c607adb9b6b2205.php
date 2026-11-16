<?php $__env->startSection('title', 'Login'); ?>

<?php $__env->startSection('content'); ?>
<div class="auth-stage">
    <section class="auth-showcase">
        <div>
            <div class="auth-brand">
                <div class="auth-brand-mark">
                    <img src="<?php echo e(asset('images/lupin-logo.png')); ?>" alt="Lupin">
                </div>
                <div>
                    <span class="auth-brand-name"><?php echo e(config('app.name', 'Lupin')); ?></span>
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

            <?php if(session('error')): ?>
                <div class="auth-alert"><?php echo e(session('error')); ?></div>
            <?php endif; ?>

            <?php if(session('success')): ?>
                <div class="auth-alert auth-alert-success"><?php echo e(session('success')); ?></div>
            <?php endif; ?>

            <form method="POST" action="<?php echo e(route('login')); ?>">
                <?php echo csrf_field(); ?>

                <div class="auth-field">
                    <label for="email" class="auth-label">Email Address</label>
                    <div class="auth-input-wrap">
                        <span class="auth-input-icon"><i class="fas fa-envelope"></i></span>
                        <input id="email"
                               type="email"
                               class="auth-input <?php $__errorArgs = ['email'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> is-invalid <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>"
                               name="email"
                               value="<?php echo e(old('email')); ?>"
                               required
                               autocomplete="email"
                               autofocus
                               placeholder="name@company.com">
                    </div>
                    <?php $__errorArgs = ['email'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                        <div class="auth-invalid"><?php echo e($message); ?></div>
                    <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                </div>

                <div class="auth-field">
                    <label for="password" class="auth-label">Password</label>
                    <div class="auth-input-wrap">
                        <span class="auth-input-icon"><i class="fas fa-lock"></i></span>
                        <input id="password"
                               type="password"
                               class="auth-input <?php $__errorArgs = ['password'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> is-invalid <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>"
                               name="password"
                               required
                               autocomplete="current-password"
                               placeholder="Enter your password">
                    </div>
                    <?php $__errorArgs = ['password'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                        <div class="auth-invalid"><?php echo e($message); ?></div>
                    <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                </div>

                <div class="auth-check-row">
                    <label class="auth-check" for="customCheck">
                        <input type="checkbox" name="remember" id="customCheck" <?php echo e(old('remember') ? 'checked' : ''); ?>>
                        <span>Remember me</span>
                    </label>

                    <a class="auth-link" href="<?php echo e(route('password.request')); ?>">Forgot password?</a>
                </div>

                <button class="auth-submit" type="submit">
                    Sign In
                </button>
            </form>

            <div class="auth-meta">
                Authorized users only. Activity may be monitored and logged.
                <div style="margin-top: 10px; font-size: 12px; color: #475569; font-weight: 500;">
                    © <?php echo e(date('Y')); ?> <?php echo e(config('app.name', 'Lupin')); ?>. All Rights Reserved. | Powered by <strong style="color: #0f4c81; font-weight: 800;">Ecowiztech Solution Pvt. Ltd.™</strong>
                </div>
            </div>
        </div>
    </section>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('auth.layouts.app', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?><?php /**PATH D:\lupin\latest Lupin2\valiflow-main\resources\views/auth/login.blade.php ENDPATH**/ ?>