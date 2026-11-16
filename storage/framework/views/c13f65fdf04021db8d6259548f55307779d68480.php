<!-- Sidebar -->
<ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">

    <!-- Sidebar - Brand -->
    <a class="sidebar-brand d-flex align-items-center justify-content-center" href="<?php echo e(route('home')); ?>">
        <div class="brand-lockup">
            <div class="brand-mark">
                <img src="<?php echo e(asset('images/lupin-logo.png')); ?>"
                     alt="Lupin Logo">
            </div>
            <div class="brand-text">
                <span class="sidebar-brand-text"><?php echo e(config('app.name', 'Lupin')); ?></span>
                <span class="sidebar-brand-subtitle">Protocol control suite</span>
            </div>
        </div>
    </a>

    <!-- Divider -->
    <hr class="sidebar-divider my-0">

    <!-- Nav Item - Dashboard -->
    <li class="nav-item <?php echo e(request()->routeIs('home') ? 'active' : ''); ?>">
        <a class="nav-link" href="<?php echo e(route('home')); ?>">
            <i class="fas fa-fw fa-tachometer-alt"></i>
            <span class="menu-label">Dashboard</span>
        </a>
    </li>



    <!-- Nav Item - User Management -->
    <?php if(\App\Services\SidebarPermissionService::userHasAnyPermissionInGroup('user_management')): ?>
     <!-- Divider -->
    <hr class="sidebar-divider">

    <!-- Heading -->
    <div class="sidebar-heading">
        User Management
    </div>
    <li class="nav-item">
        <a class="nav-link collapsed menu-link" href="#" data-toggle="collapse" data-target="#userManagement"
            aria-expanded="true" aria-controls="userManagement">
            <i class="fas fa-user-alt"></i>
            <span class="menu-label">User Management</span>
            <span class="menu-chevron"><i class="fas fa-chevron-right"></i></span>
        </a>
        <div id="userManagement" class="collapse" aria-labelledby="headingUserManagement" data-parent="#accordionSidebar">
            <div class="collapse-inner rounded submenu-panel">
                <h6 class="collapse-header">User Management:</h6>
                <?php if(\App\Services\SidebarPermissionService::userCan('user-list')): ?>
                <a class="collapse-item" href="<?php echo e(App\Helpers\SidebarHelper::getRouteUrl('user-list')); ?>">List</a>
                <?php endif; ?>
                <?php if(\App\Services\SidebarPermissionService::userCan('user-create')): ?>
                <a class="collapse-item" href="<?php echo e(App\Helpers\SidebarHelper::getRouteUrl('user-create')); ?>">Add New</a>
                <?php endif; ?>
                
                <?php if(\App\Services\SidebarPermissionService::userCan('role-list')): ?>
                <a class="collapse-item" href="<?php echo e(App\Helpers\SidebarHelper::getRouteUrl('role-list')); ?>">Roles</a>
                <?php endif; ?>
            </div>
        </div>
    </li>
    <?php endif; ?>

    <!-- Nav Item - Settings -->
    <?php if(\App\Services\SidebarPermissionService::userHasAnyPermissionInGroup('settings')): ?>
    <hr class="sidebar-divider">

    <div class="sidebar-heading">
        Settings
    </div>
    <li class="nav-item <?php echo e(request()->routeIs('admin.security-policy') ? 'active' : ''); ?>">
        <a class="nav-link collapsed menu-link" href="#" data-toggle="collapse" data-target="#settingsMenu"
            aria-expanded="<?php echo e(request()->routeIs('admin.security-policy') ? 'true' : 'false'); ?>" aria-controls="settingsMenu">
            <i class="fas fa-cog"></i>
            <span class="menu-label">Settings</span>
            <span class="menu-chevron"><i class="fas fa-chevron-right"></i></span>
        </a>
        <div id="settingsMenu" class="collapse <?php echo e(request()->routeIs('admin.security-policy') ? 'show' : ''); ?>" aria-labelledby="headingSettings" data-parent="#accordionSidebar">
            <div class="collapse-inner rounded submenu-panel">
                <h6 class="collapse-header">Settings:</h6>
                <?php if(\App\Services\SidebarPermissionService::userCanAny(['security-policy-view', 'security-policy-edit'])): ?>
                <a class="collapse-item <?php echo e(request()->routeIs('admin.security-policy') ? 'active' : ''); ?>" href="<?php echo e(App\Helpers\SidebarHelper::getRouteUrl('security-policy-view')); ?>">Security Policy</a>
                <?php endif; ?>
            </div>
        </div>
    </li>
    <?php endif; ?>



    <!-- Nav Item - Masters -->
    <!-- <?php if(\App\Services\SidebarPermissionService::userHasAnyPermissionInGroup('masters')): ?> -->
      <!-- Divider -->
    <!-- <hr class="sidebar-divider"> -->

    <!-- Heading -->
    <!-- <div class="sidebar-heading">
        Masters
    </div>
    <li class="nav-item">
        <a class="nav-link collapsed menu-link" href="#" data-toggle="collapse" data-target="#masters"
            aria-expanded="true" aria-controls="masters">
            <i class="fas fa-folder"></i>
            <span class="menu-label">Masters</span>
            <span class="menu-chevron"><i class="fas fa-chevron-right"></i></span>
        </a>
        <div id="masters" class="collapse" aria-labelledby="headingMasters" data-parent="#accordionSidebar">
            <div class="collapse-inner rounded submenu-panel"> -->
                
                <!-- <?php if(\App\Services\SidebarPermissionService::userCan('equipment-list')): ?>
                <a class="collapse-item" href="<?php echo e(App\Helpers\SidebarHelper::getRouteUrl('equipment-list')); ?>">Equipment Master</a>
                <a class="collapse-item" href="<?php echo e(route('equipment.my-approved')); ?>">My Approved Equipment</a>
                <?php endif; ?>
                <?php if(\App\Services\SidebarPermissionService::userCan('protocol-type-list')): ?>
                <a class="collapse-item" href="<?php echo e(App\Helpers\SidebarHelper::getRouteUrl('protocol-type-list')); ?>">Protocol Type</a>
                <?php endif; ?>
                <?php if(\App\Services\SidebarPermissionService::userCan('facility-list')): ?>
                <a class="collapse-item" href="<?php echo e(App\Helpers\SidebarHelper::getRouteUrl('facility-list')); ?>">Facility</a>
                <?php endif; ?>
                <?php if(\App\Services\SidebarPermissionService::userCan('document-view')): ?>
                <a class="collapse-item" href="<?php echo e(App\Helpers\SidebarHelper::getRouteUrl('document-view')); ?>">Documents</a>
                <?php endif; ?>
            </div>
        </div>

    </li>
    <?php endif; ?> -->


<!-- Nav Item - QA Documents -->
<!-- <?php if(\App\Services\SidebarPermissionService::userHasAnyPermissionInGroup('qa_documents')): ?> -->
            <!-- Divider -->
<!-- <hr class="sidebar-divider"> -->
<!-- Heading -->
<!-- <div class="sidebar-heading">
    QA Documents
</div>
<li class="nav-item">
    <a class="nav-link collapsed menu-link" href="#" data-toggle="collapse" data-target="#qaDocuments"
        aria-expanded="true" aria-controls="qaDocuments">
        <i class="fas fa-file-medical-alt"></i>
        <span class="menu-label">QA Documents</span>
        <span class="menu-chevron"><i class="fas fa-chevron-right"></i></span>
    </a>
    <div id="qaDocuments" class="collapse" aria-labelledby="headingQaDocuments" data-parent="#accordionSidebar">
        <div class="collapse-inner rounded submenu-panel">
            <h6 class="collapse-header">QA Documents:</h6>
            <?php if(\App\Services\SidebarPermissionService::userCan('qa-documents-view')): ?>
            <a class="collapse-item" href="<?php echo e(route('qa.documents')); ?>">Pending Documents</a>
            <?php endif; ?>
        </div>
    </div>
</li> -->
<!-- <?php endif; ?> -->



    <!-- Nav Item - Protocol Template -->
    
    <!-- Divider -->
    

    <!-- Heading -->
    


    <!-- Nav Item - Protocol -->
    <?php if(\App\Services\SidebarPermissionService::userHasAnyPermissionInGroup('protocol')): ?>
     <!-- Divider -->
    <hr class="sidebar-divider">

    <!-- Heading -->
    <div class="sidebar-heading">
        Protocol
    </div>

    <li class="nav-item">
        <a class="nav-link collapsed menu-link" href="#" data-toggle="collapse" data-target="#protocol"
            aria-expanded="true" aria-controls="protocol">
            <i class="fas fa-clipboard-list"></i>
            <span class="menu-label">Protocol</span>
            <span class="menu-chevron"><i class="fas fa-chevron-right"></i></span>
        </a>
        <div id="protocol" class="collapse" aria-labelledby="headingProtocol" data-parent="#accordionSidebar">
            <div class="collapse-inner rounded submenu-panel">

                <!-- <?php if(\App\Services\SidebarPermissionService::userCan('protocol-approve')): ?>
                <a class="collapse-item" href="<?php echo e(App\Helpers\SidebarHelper::getRouteUrl('protocol-approve')); ?>">Approve Protocol</a>
                <?php endif; ?> -->
                
                
                
                
                <?php if(\App\Services\SidebarPermissionService::userCanAny([
                    'protocol-fill-new-equipment',
                    'protocol-new-equipment',
                    'protocol-neq-view',
                    'protocol-neq-download',
                    'protocol-all',
                ])): ?>
                <a class="collapse-item" href="<?php echo e(route('protocol.new-equipment-qualification.reports')); ?>">New Equipment Qualification</a>
                <?php endif; ?>
                <?php if(\App\Services\SidebarPermissionService::userCan('protocol-revalidation')): ?>
                <a class="collapse-item" href="<?php echo e(route('protocol.revalidation')); ?>">Revalidation</a>
                <?php endif; ?>
                <?php if(\App\Services\SidebarPermissionService::userCan('protocol-requalification')): ?>
                <a class="collapse-item" href="<?php echo e(route('protocol.requalification')); ?>">Requalification</a>
                <?php endif; ?>
                <!-- <a class="collapse-item" href="<?php echo e(route('protocol.docx-import.create')); ?>">Revalidation Qualification</a>

                <a class="collapse-item" href="<?php echo e(route('protocol.docx-import.create')); ?>">Requalification</a>
                 -->
            </div>
        </div>
    </li>
    <?php endif; ?>



    <!-- Nav Item - Audit -->
    <?php if(\App\Services\SidebarPermissionService::userHasAnyPermissionInGroup('audit')): ?>

    <li class="nav-item">
        <?php if(\App\Services\SidebarPermissionService::userCan('audit-view')): ?>
             <!-- Divider -->
    <hr class="sidebar-divider">

    <!-- Heading -->
    <div class="sidebar-heading">
        Audit
    </div>
        <a class="nav-link" href="<?php echo e(App\Helpers\SidebarHelper::getRouteUrl('audit-view')); ?>">
            <i class="fas fa-shield-alt"></i>
            <span class="menu-label">Audit</span>
        </a>
        <?php endif; ?>
    </li>
    <?php endif; ?>

    <!-- Divider -->
    <hr class="sidebar-divider">

    <!-- Nav Item - Logout -->
    <li class="nav-item">
        <a class="nav-link" href="#" data-toggle="modal" data-target="#logoutModal">
            <i class="fas fa-sign-out-alt"></i>
            <span class="menu-label">Logout</span>
        </a>
    </li>

    <!-- Sidebar Toggler (Sidebar) -->
    <div class="text-center d-none d-md-inline">
        <button class="rounded-circle border-0" id="sidebarToggle"></button>
    </div>

</ul>
<?php /**PATH D:\lupin\latest Lupin2\valiflow-main\resources\views/common/sidebar.blade.php ENDPATH**/ ?>