<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OfferController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ProtocolTypeController;

use App\Http\Controllers\GoogleAuthController;
    use App\Http\Controllers\ActivityLogController;
/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\ProtocolController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Admin\SecurityPolicyController;
use App\Http\Controllers\EquipmentController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Models\company;
use App\Http\Controllers\Admin\RolePermissionController;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use App\Http\Controllers\FacilityController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EsignController;
use App\Http\Controllers\AhuFormController;
use App\Http\Controllers\Admin\FormTemplateController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\LicenseController;
use App\Http\Controllers\DocxImportController;
use App\Http\Controllers\NewEquipmentQualificationController;


Route::get('/', function () {
    return app(\App\Services\LicenseService::class)->hasValidLicense()
        ? redirect()->route('login')
        : redirect()->route('license.activate.show');
});


Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::get('/license/activate', [LicenseController::class, 'show'])->name('license.activate.show');
Route::post('/license/activate', [LicenseController::class, 'activate'])->name('license.activate.store');

Auth::routes(['register' => true]);
Route::get('auth/google', [GoogleAuthController::class, 'redirectToGoogle'])->name('google.login');
Route::get('auth/google/callback', [GoogleAuthController::class, 'handleGoogleCallback']);
Route::get('/setup-password/{token}', [App\Http\Controllers\Auth\PasswordSetupController::class, 'showForm']);
Route::post('/setup-password/{token}', [App\Http\Controllers\Auth\PasswordSetupController::class, 'setup']);

// All protected routes require authentication
Route::middleware(['auth'])->group(function () {

    Route::get('/home', [ReportController::class, 'myApprovalDashboard'])->name('home'); // approval dashboard home

    // Masters Routes
    Route::post('/equipment/import/preview', [EquipmentController::class, 'importPreview'])
        ->name('equipment.import.preview');
    Route::get('/equipment/import/preview', [EquipmentController::class, 'showImportPreview'])
        ->name('equipment.import.preview.show');
    Route::get('/equipment/import/cancel', [EquipmentController::class, 'importCancel'])
        ->name('equipment.import.cancel');
    Route::post('/equipment/import', [EquipmentController::class, 'import'])
        ->name('equipment.import');
    Route::post('/equipment/{equipment}/approve', [EquipmentController::class, 'approve'])
        ->name('equipment.approve');
    Route::get('/equipment/my-approved', [EquipmentController::class, 'myApproved'])
        ->name('equipment.my-approved');
    Route::resource('protocol-type', ProtocolTypeController::class);
    Route::resource('facility', FacilityController::class);
    Route::resource('equipment', EquipmentController::class);
    Route::resource('roles', App\Http\Controllers\RolesController::class);
    Route::resource('permissions', App\Http\Controllers\PermissionsController::class);
    Route::resource('companies',  App\Http\Controllers\CompanyController::class);
    Route::resource('customers', CustomerController::class);
    Route::resource('documents', DocumentController::class);
    Route::resource('templates', TemplateController::class);

    // Protocol Routes
    Route::get('/protocols/create', [ProtocolController::class, 'create'])->name('protocols.create');
    Route::post('/protocols', [ProtocolController::class, 'store'])->name('protocols.store');
    Route::post('/workflow/{workflow}/complete', [ProtocolController::class, 'completeStep'])->name('workflow.complete');
    Route::get('/my-tasks', [DashboardController::class, 'myTasks'])->name('dashboard.tasks');
    Route::get('/protocollist', [ReportController::class, 'approveList'])->name('reports.approveList');

    // Offer Routes
    Route::get('offer', [OfferController::class, 'showForm'])->name('offer');
    Route::post('offer', [OfferController::class, 'createForm'])->name('createoffer');
    Route::post('offer/generate', [OfferController::class, 'generateOffer']);
    Route::get('listoffers', [OfferController::class, 'index'])->name('listoffers');
    Route::get('/offers/{id}/edit', [OfferController::class,'edit'])->name('offers.edit');
    Route::put('/offers/{id}', [OfferController::class,'update'])->name('offers.update');

    // Activity Logs
    Route::get('/activity-logs', [ActivityLogController::class, 'index'])->name('activity-logs');

    // ================================
    // Added from Project  - Notifications
    // ================================
    Route::get('/notifications', [NotificationController::class, 'index'])
        ->name('notifications.index');

    Route::get('/notifications/{id}', [NotificationController::class, 'show'])
        ->name('notifications.show');

    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead'])
        ->name('notifications.read');

    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])
        ->name('notifications.readAll');

    Route::post('/notifications/clear-all', [NotificationController::class, 'destroyAll'])
        ->name('notifications.destroyAll');

    Route::post('/notifications/{id}/delete', [NotificationController::class, 'destroy'])
        ->name('notifications.destroy');

    Route::get('/notifications/{id}/delete', function () {
        return redirect()->route('notifications.index');
    });

    // Admin Documents
    Route::get('/admin/documents', [DocumentController::class, 'adminIndex'])->name('admin.documents');
    Route::get('/admin/documents/create', [DocumentController::class, 'create'])->name('admin.documents.create');
    Route::post('/admin/documents', [DocumentController::class, 'store'])->name('admin.documents.store');

    // QA Documents
    Route::get('/qa/documents', [DocumentController::class, 'qaIndex'])->name('qa.documents');
    Route::post('/qa/documents/update', [DocumentController::class, 'updateText'])->name('qa.documents.update');

    Route::get(
    'admin/documents/{id}/details',
    [DocumentController::class, 'getDocumentDetails']
);

    // Template Designer Routes
    Route::get('/templates/{form_key}/designer', [AhuFormController::class,'designer'])
        ->name('admin.templates.designer');

    Route::post('/templates/{form_key}/save', [AhuFormController::class,'save'])
        ->name('admin.templates.save');

    // Form Routes
    Route::get('/forms/{form_key}', [AhuFormController::class,'show'])
        ->name('forms.show');
    Route::post('/forms/{form_key}/submit', [AhuFormController::class,'submit'])
        ->name('forms.submit');

    // Template Management Routes
    Route::get('/templates', [TemplateController::class, 'index'])->name('templates.index');
    Route::get('/templates/create', [TemplateController::class, 'create'])->name('templates.create');
    Route::post('/templates', [TemplateController::class, 'store'])->name('templates.store');

    // DOCX template import — upload Word templates and convert to fillable fields
    Route::get('/templates/docx-import/create', [DocxImportController::class, 'createTemplate'])->name('templates.docx-import.create');
    Route::post('/templates/docx-import', [DocxImportController::class, 'uploadTemplate'])->name('templates.docx-import.upload');

    Route::get('/templates/{template}/edit', [TemplateController::class, 'edit'])->name('templates.edit');
    Route::put('/templates/{template}', [TemplateController::class, 'update'])->name('templates.update');
    Route::delete('/templates/{template}', [TemplateController::class, 'destroy'])->name('templates.destroy');
    Route::get('/templates/{template}', [TemplateController::class, 'show'])->name('templates.show');
    Route::get('/templates/{template}/source-doc', [TemplateController::class, 'downloadSourceDoc'])->name('templates.source-doc');
    Route::post('/templates/{template}/convert-docx-to-html', [TemplateController::class, 'convertDocxToHtml'])->name('templates.convert-docx-to-html');
    Route::get('/templates/{template}/submit',
        [TemplateController::class, 'submit']
    )->name('templates.submit');

    // Report Routes
    Route::post('/reports/{form_key}/submit', [ReportController::class, 'submit'])
        ->name('reports.submit');

    Route::get('/templates/{template}/fill', [ReportController::class, 'fill'])->name('reports.fill');
    Route::post('/templates/{template}/submit', [ReportController::class, 'store'])->name('reports.store');

    // Specific routes must come BEFORE generic routes with wildcards
    Route::get('/reports/approved-protocol', [ReportController::class, 'approvedProtocol'])->name('approved.protocols');

    // ================================
    // Added from Project B - Protocol dashboard routes
    // ================================
    Route::get('/preprocessingprotocol',
        [ReportController::class, 'proccesingPotocol'])
        ->name('proccesingPotocol');

    Route::get('/approvedprotocol',
        [ReportController::class, 'ApprovedProtocol'])
        ->name('ApprovedProtocol');

    Route::get('/postprocessingprotocol',
        [ReportController::class, 'PostproccesingPotocol'])
        ->name('PostproccesingPotocol');

    Route::get('/protocol',
        [ReportController::class, 'allProtocol'])
        ->middleware('permission:protocol-all')
        ->name('protocol');

    Route::get('/protocol/revalidation',
        [ReportController::class, 'revalidationProtocols'])
        ->middleware('permission:protocol-revalidation|protocol-all')
        ->name('protocol.revalidation');

    Route::get('/protocol/requalification',
        [ReportController::class, 'requalificationProtocols'])
        ->middleware('permission:protocol-requalification|protocol-all')
        ->name('protocol.requalification');

    // Protocol DOCX import — create a report from an uploaded Word document
    Route::get('/protocol/docx-import', [DocxImportController::class, 'createFromProtocol'])
        ->middleware('permission:protocol-fill-new-equipment|protocol-revalidation|protocol-requalification')
        ->name('protocol.docx-import.create');
    Route::post('/protocol/docx-import', [DocxImportController::class, 'storeFromProtocol'])
        ->middleware('permission:protocol-fill-new-equipment|protocol-revalidation|protocol-requalification')
        ->name('protocol.docx-import.store');

    // New Equipment Qualification — upload, preview, edit, and save DOCX files
    Route::get('/protocol/new-equipment-qualification', [NewEquipmentQualificationController::class, 'index'])
        ->middleware('permission:protocol-new-equipment|protocol-fill-new-equipment')
        ->name('protocol.new-equipment-qualification.index');
    Route::post('/protocol/new-equipment-qualification/upload', [NewEquipmentQualificationController::class, 'upload'])
        ->middleware('permission:protocol-new-equipment|protocol-fill-new-equipment')
        ->name('protocol.new-equipment-qualification.upload');
    Route::get('/protocol/new-equipment-qualification/{newEquipmentQualification}/view', [NewEquipmentQualificationController::class, 'view'])
        ->name('protocol.new-equipment-qualification.view');
    Route::get('/protocol/new-equipment-qualification/{newEquipmentQualification}/file', [NewEquipmentQualificationController::class, 'file'])
        ->name('protocol.new-equipment-qualification.file');
    Route::get('/protocol/new-equipment-qualification/{newEquipmentQualification}/preview-html', [NewEquipmentQualificationController::class, 'previewHtml'])
        ->name('protocol.new-equipment-qualification.preview-html');
    Route::get('/protocol/new-equipment-qualification/{newEquipmentQualification}/text-targets', [NewEquipmentQualificationController::class, 'textTargets'])
        ->name('protocol.new-equipment-qualification.text-targets');
    Route::get('/protocol/new-equipment-qualification/{newEquipmentQualification}/download', [NewEquipmentQualificationController::class, 'download'])
        ->name('protocol.new-equipment-qualification.download');
    Route::post('/protocol/new-equipment-qualification/{newEquipmentQualification}/save-as', [NewEquipmentQualificationController::class, 'saveAs'])
        ->name('protocol.new-equipment-qualification.save-as');
    Route::post('/protocol/new-equipment-qualification/{newEquipmentQualification}/send-for-approval', [NewEquipmentQualificationController::class, 'sendForApproval'])
        ->name('protocol.new-equipment-qualification.send-for-approval');
    Route::post('/protocol/new-equipment-qualification/{newEquipmentQualification}/approve', [NewEquipmentQualificationController::class, 'approve'])
        ->name('protocol.new-equipment-qualification.approve');
    Route::post('/protocol/new-equipment-qualification/{newEquipmentQualification}/send-back', [NewEquipmentQualificationController::class, 'sendBack'])
        ->name('protocol.new-equipment-qualification.send-back');
    Route::post('/protocol/new-equipment-qualification/{newEquipmentQualification}/reject', [NewEquipmentQualificationController::class, 'reject'])
        ->name('protocol.new-equipment-qualification.reject');

    Route::get('/reports/{report}', [ReportController::class, 'show'])->name('reports.show')
        ->where(['report' => '[0-9]+']);

    // Report DOCX import — upload, fill fields, preview, and download filled document
    Route::get('/reports/{report}/docx-import', [DocxImportController::class, 'showUploadForm'])->name('reports.docx-import.upload-form');
    Route::post('/reports/{report}/docx-import', [DocxImportController::class, 'upload'])->name('reports.docx-import.upload');
    Route::get('/reports/{report}/docx-import/fill', [DocxImportController::class, 'showFillForm'])->name('reports.docx-import.fill-form');
    Route::get('/reports/{report}/docx-import/fill-from-protocol/{sourceReport}', [DocxImportController::class, 'fillFromApprovedProtocol'])
        ->name('reports.docx-import.fill-from-protocol')
        ->where(['report' => '[0-9]+', 'sourceReport' => '[0-9]+']);
    Route::post('/reports/{report}/docx-import/draft', [DocxImportController::class, 'saveDraft'])
        ->name('reports.docx-import.draft')
        ->where(['report' => '[0-9]+']);
    Route::post('/reports/{report}/docx-import/fill', [DocxImportController::class, 'fill'])->name('reports.docx-import.fill');
    Route::get('/reports/{report}/preview-html', [DocxImportController::class, 'fetchPreviewHtml'])
        ->name('reports.preview-html');
    Route::get('/reports/{report}/docx-import/download-pdf', [DocxImportController::class, 'downloadFilledPdf'])
        ->name('reports.docx-import.download-pdf')
        ->where(['report' => '[0-9]+']);

    Route::get('/reports/{report}/after-preapproval', [ReportController::class, 'reportfillAfterPreaAprove'])->name('reports.preapprovalfill');

    // Report approval actions — e-sign approve, send back for correction, or permanently reject
    Route::post('/reports/{report}/approve', [ReportController::class, 'approve'])->name('reports.approve')
        ->middleware('permission:protocol-approve')
        ->where(['report' => '[0-9]+']);

    Route::post('/reports/{report}/send-back', [ReportController::class, 'sendBack'])->name('reports.sendBack')
        ->middleware('permission:protocol-send-back')
        ->where(['report' => '[0-9]+']);

    Route::post('/reports/{report}/reject', [ReportController::class, 'reject'])->name('reports.reject')
        ->middleware('permission:protocol-reject')
        ->where(['report' => '[0-9]+']);

    Route::get('/reports/{report}/edit-after-pre', [ReportController::class, 'editAfterPreApproved'])
        ->name('reports.editAfterPreApproved')
        ->where(['report' => '[0-9]+']);

    // ================================
    // Added from Project B - Pre Approved protocol routes
    // ================================
    Route::get('/preapproved',
        [ReportController::class, 'preApproved'])
        ->name('preApproved');

    Route::get('/reports/pre-approved',
        [ReportController::class, 'preApprovedList'])
        ->name('reports.pre-approved');

    Route::post('/reports/{report}/submit-to-post', [ReportController::class, 'submitToPost'])
        ->name('reports.submitToPost')
        ->where(['report' => '[0-9]+']);

    Route::get('/dashboard/pending', [ReportController::class, 'myPendingApprovals'])->name('reports.pending'); // current user's pending approval queue

    // ================================
    // Added from Project B - Returned workflow handling
    // ================================
    Route::get('/reports/{report}/edit-returned-pre',
        [ReportController::class,'editReturnedPre'])
        ->name('reports.editReturnedPre');

    Route::post('/reports/{report}/resubmit-pre',
        [ReportController::class,'resubmitPre'])
        ->name('reports.resubmitPre');

    Route::get('/reports/{report}/edit-returned-post',
        [ReportController::class,'editReturnedPost'])
        ->name('reports.editReturnedPost');

    Route::post('/reports/{report}/resubmit-post',
        [ReportController::class,'resubmitPost'])
        ->name('reports.resubmitPost');

    Route::post('/reports/{report}/resubmit-returned-post',
        [ReportController::class,'resubmitReturnedPost'])
        ->name('reports.resubmitReturnedPost');

    Route::post('/reports/{report}/resubmit-returned-pre',
        [ReportController::class,'resubmitReturnedPre'])
        ->name('reports.resubmitReturnedPre');

    // ================================
    // Added from Project B - Pending approvals
    // ================================
    Route::get('/my-pending-approvals',
        [ReportController::class, 'myPendingUserApprovals'])
        ->name('reports.myPendingApprovals');

    Route::get('/reports/{report}/download-zip', [ReportController::class, 'downloadAttachmentsFolderZip'])
        ->name('reports.downloadFolderZip')
        ->where(['report' => '[0-9]+']);
    Route::get('/reports/{report}/attachments/{index}/download', [ReportController::class, 'downloadAttachment'])
        ->name('reports.attachments.download')
        ->where(['report' => '[0-9]+', 'index' => '[0-9]+']);
    Route::get('/reports/{report}/download-approved-word', [ReportController::class, 'downloadApprovedWord'])
        ->name('reports.downloadApprovedWord')
        ->where(['report' => '[0-9]+']);

    Route::get('/reports/fill/{template}', [ReportController::class, 'fill'])->name('reports.fill');
    Route::post('/reports/store/{template}', [ReportController::class, 'store'])->name('reports.store');
    Route::get('/download-pdf/{id}', [ReportController::class, 'downloadPDF'])->name('download.pdf');

    // User Management Routes (with additional permission middleware)
    Route::middleware(['auth', 'permission:user-change-password'])->group(function () {
        // Show the modal/form (optional, can be on the same page)
        Route::get('/users/{user}/change-password', [UserController::class, 'changePasswordForm'])
            ->name('users.change.password');

        // Update password
        Route::post('/users/{user}/change-password', [UserController::class, 'updatePassword'])
            ->name('users.update.password');
    });

    Route::middleware(['auth', 'permission:user-create'])->group(function () {
        Route::get('/users/create', [UserController::class, 'create']);
    });

    Route::middleware(['auth', 'is_admin'])->prefix('admin')->group(function () {
        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('admin.audit.logs');
        Route::get('/audit-logs/pdf', [AuditLogController::class, 'downloadPdf'])->name('admin.audit.logs.pdf');
        Route::get('/security-policy', [SecurityPolicyController::class, 'index'])->name('admin.security-policy');
        Route::post('/security-policy', [SecurityPolicyController::class, 'update'])->name('admin.security-policy.update');
        Route::get('/roles/{roleId}/permissions',
            [RolePermissionController::class, 'edit']
        )->name('admin.roles.permissions');

        Route::post('admin/roles/{roleId}/permissions',
            [RolePermissionController::class, 'update']
        )->name('admin.roles.permissions.update');
    });

    // Profile Routes
    Route::prefix('profile')->name('profile.')->group(function(){
        Route::get('/', [HomeController::class, 'getProfile'])->name('detail');
        Route::post('/update', [HomeController::class, 'updateProfile'])->name('update');
        Route::post('/change-password', [HomeController::class, 'changePassword'])->name('change-password');
    });

    // User Resource Routes
    Route::middleware('auth')->prefix('users')->name('users.')->group(function(){

        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::get('/create', [UserController::class, 'create'])->name('create');
        Route::post('/store', [UserController::class, 'store'])->name('store');
        Route::get('/edit/{user}', [UserController::class, 'edit'])->name('edit');
        Route::put('/update/{user}', [UserController::class, 'update'])->name('update');
        Route::delete('/delete/{user}', [UserController::class, 'destroy'])->name('destroy');
        Route::get('/update/status/{user_id}/{status}', [UserController::class, 'updateStatus'])->name('status');


        Route::get('/import-users', [UserController::class, 'importUsers'])->name('import');
        Route::post('/upload-users', [UserController::class, 'uploadUsers'])->name('upload');

        Route::get('/export/', [UserController::class, 'export'])->name('export');
    });

    // E-Sign Validation Route
    Route::post('/esign/validate', [EsignController::class, 'validatePassword'])
        ->name('esign.validate');

    // ================================
    // Added from Project B - Password confirmation verification
    // ================================
    Route::post('/password-confirm-verify', function (Request $request) {

        if (!Hash::check($request->password, auth()->user()->password)) {
            return response()->json(['status' => 'error'], 401);
        }

        return response()->json(['status' => 'ok']);
    })->middleware('auth')->name('password.confirm.verify');

    // Resource route must come AFTER custom routes to prevent conflicts
    // Route::resource('users', UserController::class);

    // Form Routes
    Route::get('/forms/ahu', [AhuFormController::class, 'show'])->name('forms.ahu');
    Route::get('/ahu-form', [AhuFormController::class, 'showForm'])->name('ahu.form');
    Route::post('/forms/ahu', [AhuFormController::class, 'submit'])->name('forms.ahu.submit');
    Route::get('/form-templates', [FormTemplateController::class, 'index'])->name('form-templates.index');
    Route::get('/form-templates/{formTemplate}/edit', [FormTemplateController::class, 'edit'])->name('form-templates.edit');
    Route::put('/form-templates/{formTemplate}', [FormTemplateController::class, 'update'])->name('form-templates.update');

    // Admin prefix routes
    Route::prefix('admin')->name('admin.')->group(function () {

    });

});
