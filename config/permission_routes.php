<?php

return [
    // User Management Routes
    'user-list' => 'users.index',
    'user-create' => 'users.create',
    'user-edit' => 'users.edit',
    'user-delete' => 'users.delete',
    'user-import' => 'users.import',
    'user-change-password' => 'users.change.password',
    
    // Security Policy Routes
    'security-policy-view' => 'admin.security-policy',
    'security-policy-edit' => 'admin.security-policy',
    
    // Role & Permission Routes
    'role-list' => 'roles.index',
    'role-create' => 'roles.create',
    'role-edit' => 'roles.edit',
    'role-delete' => 'roles.delete',
    'permission-list' => 'permissions.index',
    'permission-create' => 'permissions.create',
    'permission-edit' => 'permissions.edit',
    'permission-delete' => 'permissions.delete',
    'permission-assign' => 'permissions.assign',
    
    // Equipment Routes
    'equipment-list' => 'equipment.index',
    'equipment-create' => 'equipment.create',
    'equipment-edit' => 'equipment.edit',
    'equipment-delete' => 'equipment.delete',
    
    // Protocol Type Routes
    'protocol-type-list' => 'protocol-type.index',
    'protocol-type-create' => 'protocol-type.create',
    'protocol-type-edit' => 'protocol-type.edit',
    'protocol-type-delete' => 'protocol-type.delete',
    
    // Facility Routes
    'facility-list' => 'facility.index',
    'facility-create' => 'facility.create',
    'facility-edit' => 'facility.edit',
    'facility-delete' => 'facility.delete',
    
    // Document Routes
    'document-view' => 'documents.index',
    'document-create' => 'documents.create',
    'document-edit' => 'documents.edit',
    'document-delete' => 'documents.delete',
    'document-list' => 'documents.list',
    
    // Protocol Routes
    'protocol-list' => 'listoffers',
    'protocol-create' => 'offer',
    'protocol-view' => 'listoffers',
    'protocol-edit' => 'offer',
    'protocol-delete' => 'offer',
    'protocol-approve' => 'approved.protocols',
    'protocol-send-back' => 'protocol',
    'protocol-reject' => 'protocol',
    'protocol-all' => 'protocol',
    'protocol-new-equipment' => 'protocol.new-equipment-qualification.index',
    'protocol-fill-new-equipment' => 'protocol.docx-import.create',
    'protocol-neq-view' => 'protocol.new-equipment-qualification.index',
    'protocol-neq-download' => 'protocol.new-equipment-qualification.index',
    'protocol-revalidation' => 'protocol.docx-import.create',
    'protocol-requalification' => 'protocol.docx-import.create',
    'protocol-view-approved' => 'protocol',
    'protocol-view-returned' => 'protocol',
    'protocol-view-rejected' => 'protocol',
    'protocol-preapproved' => 'preApproved',
    'protocol-postprocessing' => 'PostproccesingPotocol',
    'protocol-approved' => 'ApprovedProtocol',
    'protocol-my-pending' => 'reports.myPendingApprovals',
    
    // Template Routes
    'template-list' => 'templates.index',
    'template-create' => 'templates.create',
    'template-edit' => 'templates.edit',
    'template-delete' => 'templates.destroy',
    'template-view' => 'templates.show',
    
    // Audit Routes
    'audit-view' => 'admin.audit.logs',
    'activity-view' => 'activity-logs',
    
    // E-Sign Routes
    'esign-validate' => 'esign.validate',
    
    // Dashboard Route
    'dashboard' => 'home',
    'home' => 'home',
    
    // Offer Routes (Legacy - for backward compatibility)
    'offer' => 'offer',
    'listoffers' => 'listoffers',
    'approved.protocols' => 'approved.protocols',
];
