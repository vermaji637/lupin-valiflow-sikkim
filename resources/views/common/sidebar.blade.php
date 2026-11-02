<!-- Sidebar -->
<ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">

    <!-- Sidebar - Brand -->
    <a class="sidebar-brand d-flex align-items-center justify-content-center" href="{{ route('home') }}">
        <div class="brand-lockup">
            <div class="brand-mark">
                <img src="{{ asset('images/lupin-logo.png') }}"
                     alt="Lupin Logo">
            </div>
            <div class="brand-text">
                <span class="sidebar-brand-text">{{ config('app.name', 'Lupin') }}</span>
                <span class="sidebar-brand-subtitle">Protocol control suite</span>
            </div>
        </div>
    </a>

    <!-- Divider -->
    <hr class="sidebar-divider my-0">

    <!-- Nav Item - Dashboard -->
    <li class="nav-item {{ request()->routeIs('home') ? 'active' : '' }}">
        <a class="nav-link" href="{{ route('home') }}">
            <i class="fas fa-fw fa-tachometer-alt"></i>
            <span class="menu-label">Dashboard</span>
        </a>
    </li>



    <!-- Nav Item - User Management -->
    @if(\App\Services\SidebarPermissionService::userHasAnyPermissionInGroup('user_management'))
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
                @if(\App\Services\SidebarPermissionService::userCan('user-list'))
                <a class="collapse-item" href="{{ App\Helpers\SidebarHelper::getRouteUrl('user-list') }}">List</a>
                @endif
                @if(\App\Services\SidebarPermissionService::userCan('user-create'))
                <a class="collapse-item" href="{{ App\Helpers\SidebarHelper::getRouteUrl('user-create') }}">Add New</a>
                @endif
                {{-- @if(\App\Services\SidebarPermissionService::userCan('user-import'))
                <a class="collapse-item" href="{{ App\Helpers\SidebarHelper::getRouteUrl('user-import') }}">Import Data</a>
                @endif --}}
                @if(\App\Services\SidebarPermissionService::userCan('role-list'))
                <a class="collapse-item" href="{{ App\Helpers\SidebarHelper::getRouteUrl('role-list') }}">Roles</a>
                @endif
            </div>
        </div>
    </li>
    @endif

    <!-- Nav Item - Settings -->
    @if(\App\Services\SidebarPermissionService::userHasAnyPermissionInGroup('settings'))
    <hr class="sidebar-divider">

    <div class="sidebar-heading">
        Settings
    </div>
    <li class="nav-item {{ request()->routeIs('admin.security-policy') ? 'active' : '' }}">
        <a class="nav-link collapsed menu-link" href="#" data-toggle="collapse" data-target="#settingsMenu"
            aria-expanded="{{ request()->routeIs('admin.security-policy') ? 'true' : 'false' }}" aria-controls="settingsMenu">
            <i class="fas fa-cog"></i>
            <span class="menu-label">Settings</span>
            <span class="menu-chevron"><i class="fas fa-chevron-right"></i></span>
        </a>
        <div id="settingsMenu" class="collapse {{ request()->routeIs('admin.security-policy') ? 'show' : '' }}" aria-labelledby="headingSettings" data-parent="#accordionSidebar">
            <div class="collapse-inner rounded submenu-panel">
                <h6 class="collapse-header">Settings:</h6>
                @if(\App\Services\SidebarPermissionService::userCanAny(['security-policy-view', 'security-policy-edit']))
                <a class="collapse-item {{ request()->routeIs('admin.security-policy') ? 'active' : '' }}" href="{{ App\Helpers\SidebarHelper::getRouteUrl('security-policy-view') }}">Security Policy</a>
                @endif
            </div>
        </div>
    </li>
    @endif



    <!-- Nav Item - Masters -->
    <!-- @if(\App\Services\SidebarPermissionService::userHasAnyPermissionInGroup('masters')) -->
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
                {{-- Permissions menu removed --}}
                <!-- @if(\App\Services\SidebarPermissionService::userCan('equipment-list'))
                <a class="collapse-item" href="{{ App\Helpers\SidebarHelper::getRouteUrl('equipment-list') }}">Equipment Master</a>
                <a class="collapse-item" href="{{ route('equipment.my-approved') }}">My Approved Equipment</a>
                @endif
                @if(\App\Services\SidebarPermissionService::userCan('protocol-type-list'))
                <a class="collapse-item" href="{{ App\Helpers\SidebarHelper::getRouteUrl('protocol-type-list') }}">Protocol Type</a>
                @endif
                @if(\App\Services\SidebarPermissionService::userCan('facility-list'))
                <a class="collapse-item" href="{{ App\Helpers\SidebarHelper::getRouteUrl('facility-list') }}">Facility</a>
                @endif
                @if(\App\Services\SidebarPermissionService::userCan('document-view'))
                <a class="collapse-item" href="{{ App\Helpers\SidebarHelper::getRouteUrl('document-view') }}">Documents</a>
                @endif
            </div>
        </div>

    </li>
    @endif -->


<!-- Nav Item - QA Documents -->
<!-- @if(\App\Services\SidebarPermissionService::userHasAnyPermissionInGroup('qa_documents')) -->
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
            @if(\App\Services\SidebarPermissionService::userCan('qa-documents-view'))
            <a class="collapse-item" href="{{ route('qa.documents') }}">Pending Documents</a>
            @endif
        </div>
    </div>
</li> -->
<!-- @endif -->



    <!-- Nav Item - Protocol Template -->
    {{-- @if(\App\Services\SidebarPermissionService::userHasAnyPermissionInGroup('protocol_template')) --}}
    <!-- Divider -->
    {{-- <hr class="sidebar-divider"> --}}

    <!-- Heading -->
    {{-- <div class="sidebar-heading">
        Template
    </div>
    <li class="nav-item">
        <a class="nav-link collapsed menu-link" href="#" data-toggle="collapse" data-target="#template"
            aria-expanded="true" aria-controls="template">
            <i class="fas fa-file-alt"></i>
            <span class="menu-label">Protocol Template</span>
            <span class="menu-chevron"><i class="fas fa-chevron-right"></i></span>
        </a>
        <div id="template" class="collapse" aria-labelledby="headingTemplate" data-parent="#accordionSidebar">
            <div class="collapse-inner rounded submenu-panel">
                @if(Route::has('templates.index') && \App\Services\SidebarPermissionService::userCan('template-list'))
                <a class="collapse-item" href="{{ App\Helpers\SidebarHelper::getRouteUrl('template-list') }}">Template List</a>
                @endif
                Create Template menu removed
            </div>
        </div>
    </li>
    @endif --}}


    <!-- Nav Item - Protocol -->
    @if(\App\Services\SidebarPermissionService::userHasAnyPermissionInGroup('protocol'))
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

                <!-- @if(\App\Services\SidebarPermissionService::userCan('protocol-approve'))
                <a class="collapse-item" href="{{ App\Helpers\SidebarHelper::getRouteUrl('protocol-approve') }}">Approve Protocol</a>
                @endif -->
                {{-- @if(\App\Services\SidebarPermissionService::userCan('protocol-all'))
                <a class="collapse-item" href="{{ route('protocol') }}">All Protocol</a>
                @endif --}}
                {{-- @if(\App\Services\SidebarPermissionService::userCan('protocol-preapproved'))
                <a class="collapse-item" href="{{ route('preApproved') }}">Pre Approved Protocol</a>
                @endif --}}
                {{-- Hidden from sidebar; use All Protocol status filter instead --}}
                {{--
                @if(\App\Services\SidebarPermissionService::userCan('protocol-postprocessing'))
                <a class="collapse-item" href="{{ route('PostproccesingPotocol') }}">Post Processing Protocol</a>
                @endif
                @if(\App\Services\SidebarPermissionService::userCan('protocol-approved'))
                <a class="collapse-item" href="{{ route('ApprovedProtocol') }}">Approved Protocol</a>
                @endif
                @if(\App\Services\SidebarPermissionService::userCan('protocol-my-pending'))
                <a class="collapse-item" href="{{ route('reports.myPendingApprovals') }}">My Pending Approvals</a>
                @endif
                --}}
                @if(\App\Services\SidebarPermissionService::userCan('protocol-new-equipment'))
                <a class="collapse-item" href="{{ route('protocol.new-equipment-qualification.index') }}">New Equipment Qualification</a>
                @endif
                {{-- Fill / view / download roles without full NEQ menu permission --}}
                @if((\App\Services\SidebarPermissionService::userCan('protocol-fill-new-equipment')
                    || \App\Services\SidebarPermissionService::userCan('protocol-neq-view')
                    || \App\Services\SidebarPermissionService::userCan('protocol-neq-download'))
                    && !\App\Services\SidebarPermissionService::userCan('protocol-new-equipment'))
                <a class="collapse-item" href="{{ route('protocol.new-equipment-qualification.index') }}">New Equipment Qualification</a>
                @endif
                {{-- Former separate Fill entry (merged into NEQ) --}}
                {{--
                @if(\App\Services\SidebarPermissionService::userCan('protocol-fill-new-equipment'))
                <a class="collapse-item" href="{{ route('protocol.docx-import.create', ['source' => 'new_equipment_qualification']) }}">Fill New Equipment Qualification</a>
                @endif
                --}}
                @if(\App\Services\SidebarPermissionService::userCan('protocol-revalidation'))
                <a class="collapse-item" href="{{ route('protocol.revalidation') }}">Revalidation</a>
                @endif
                @if(\App\Services\SidebarPermissionService::userCan('protocol-requalification'))
                <a class="collapse-item" href="{{ route('protocol.requalification') }}">Requalification</a>
                @endif
                <!-- <a class="collapse-item" href="{{ route('protocol.docx-import.create') }}">Revalidation Qualification</a>

                <a class="collapse-item" href="{{ route('protocol.docx-import.create') }}">Requalification</a>
                 -->
            </div>
        </div>
    </li>
    @endif



    <!-- Nav Item - Audit -->
    @if(\App\Services\SidebarPermissionService::userHasAnyPermissionInGroup('audit'))

    <li class="nav-item">
        @if(\App\Services\SidebarPermissionService::userCan('audit-view'))
             <!-- Divider -->
    <hr class="sidebar-divider">

    <!-- Heading -->
    <div class="sidebar-heading">
        Audit
    </div>
        <a class="nav-link" href="{{ App\Helpers\SidebarHelper::getRouteUrl('audit-view') }}">
            <i class="fas fa-shield-alt"></i>
            <span class="menu-label">Audit</span>
        </a>
        @endif
    </li>
    @endif

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
