<?php $__env->startPush('styles'); ?>
<style>
/*
 * DOCX fill form — overrides premium-ui.css global input rules:
 *   body.premium-app input[type="text"]  → white bg, min-height 46px, border-radius 14px, inset shadow
 *   body.premium-app input:focus         → blue focus ring box-shadow
 * Selectors below use #docxFillForm + input[type="text"] for higher specificity.
 */

/* Floating Action Dock (E-Sign & Table Row Controls) stays in top-right corner while scrolling */
#docxFloatingActionsContainer {
    position: fixed !important;
    top: 112px;
    right: 22px;
    z-index: 2000;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 10px;
    margin: 0;
    pointer-events: none;
}
#docxFloatingActionsContainer .btn,
#docxFloatingActionsContainer #docxEsignStatus,
#docxFloatingActionsContainer #docxRowSelectionBadge {
    pointer-events: auto;
}

#docxTableToolbarDock {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 6px;
    pointer-events: none;
}
#docxTableToolbarDock .btn-group {
    pointer-events: auto;
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.22) !important;
    border-radius: 8px;
    overflow: hidden;
}
#docxTableToolbarDock #btnDocxAddRow {
    background: #4e73df;
    border: 1px solid #3b60cc;
    color: #ffffff;
    font-weight: 700;
    font-size: 13px;
    padding: 8px 14px;
    cursor: pointer;
    transition: all 0.15s ease-in-out;
}
#docxTableToolbarDock #btnDocxAddRow:hover {
    background: #2e59d9;
    border-color: #2653d4;
    transform: translateY(-1px);
}
#docxTableToolbarDock #btnDocxDeleteRow {
    background: #ffffff;
    border: 1px solid #ea868f;
    color: #e74a3b;
    font-weight: 700;
    font-size: 13px;
    padding: 8px 14px;
    cursor: pointer;
    transition: all 0.15s ease-in-out;
}
#docxTableToolbarDock #btnDocxDeleteRow:hover {
    background: #e74a3b;
    border-color: #e74a3b;
    color: #ffffff;
    transform: translateY(-1px);
}
#docxTableToolbarDock #docxRowSelectionBadge {
    pointer-events: auto;
    background: #ffffff;
    color: #334155;
    border: 1px solid #c5d0e6;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.16);
    border-radius: 6px;
    max-width: 260px;
    white-space: normal;
    text-align: right;
    line-height: 1.35;
    font-size: 11px;
}

#docxEsignDock {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 8px;
    pointer-events: none;
}
#docxEsignDock #btnEsignStamp,
#docxEsignDock #docxEsignStatus {
    pointer-events: auto;
}
#docxEsignDock #btnEsignStamp {
    background: #ffffff;
    color: #1a252f;
    border: 1px solid #c5d0e6;
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.22);
    font-weight: 700;
}
#docxEsignDock #docxEsignStatus {
    max-width: 240px;
    white-space: normal;
    text-align: left;
    line-height: 1.35;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.16);
}

/* ── Preview container ── */
body.premium-app #docxFillForm .docx-preview-wrapper {
    overflow-x: auto;
    overflow-y: visible;
    background: #525659;
    border: 1px solid #334155;
    border-radius: 8px;
    padding: 28px 16px;
    min-height: 200px;
    position: relative;
    z-index: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
}

.docx-preview {
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
}

/* Realistic Strict A4 Page Sheet (210mm x 297mm) */
.docx-a4-page {
    width: 210mm;
    max-width: 100%;
    min-height: 297mm;
    max-height: 297mm;
    height: 297mm;
    background: #ffffff !important;
    box-shadow: 0 4px 18px rgba(0, 0, 0, 0.28), 0 1px 4px rgba(0, 0, 0, 0.16);
    border-radius: 2px;
    padding: 10mm 14mm 10mm 14mm;
    margin-bottom: 24px;
    position: relative;
    box-sizing: border-box;
    font-family: inherit;
    break-after: page;
    page-break-after: always;
    display: flex;
    flex-direction: column;
    /* Keep body growing into free space. space-between left a blank band
       between content and footer, so pull-back thought the page was full. */
    justify-content: flex-start;
    overflow: hidden; /* Page height NEVER expands */
}

.docx-page-body {
    flex: 1 1 0;
    min-height: 0;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.docx-page-body > * {
    flex-shrink: 0;
    max-width: 100%;
    min-width: 0;
    box-sizing: border-box;
}

.docx-keep-section {
    display: block;
    width: 100%;
    max-width: 100%;
    flex-shrink: 0;
}

.docx-para,
.docx-para-edit-wrapper,
.docx-para-content {
    max-width: 100%;
    white-space: normal;
    overflow-wrap: anywhere;
    word-break: break-word;
}

.docx-page-official-header {
    width: 100%;
    margin-bottom: 8px;
    flex-shrink: 0;
}

body.premium-app #docxFillForm .docx-preview-wrapper .docx-page-official-header table.header-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 0;
    table-layout: fixed;
    background: #ffffff;
}

body.premium-app #docxFillForm .docx-preview-wrapper .docx-page-official-header table.header-table td {
    border: 1px solid #334155 !important;
    padding: 3px 6px !important;
    font-size: 11px !important;
    vertical-align: middle !important;
    line-height: 1.25;
    background: #ffffff !important;
    color: #0f172a !important;
}

body.premium-app #docxFillForm .docx-preview-wrapper .docx-summary-header-card {
    width: 210mm;
    max-width: 100%;
    background: #ffffff !important;
    color: #0f172a !important;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 12px 14px;
    margin: 0 auto 20px auto;
    box-shadow: 0 3px 12px rgba(0, 0, 0, 0.22);
    box-sizing: border-box;
}
body.premium-app #docxFillForm .docx-preview-wrapper .docx-summary-header-card table.header-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 0;
    background: #ffffff !important;
    color: #0f172a !important;
}
body.premium-app #docxFillForm .docx-preview-wrapper .docx-summary-header-card table.header-table td {
    border: 1px solid #cbd5e1 !important;
    padding: 8px 10px !important;
    vertical-align: top !important;
    background: #ffffff !important;
    color: #0f172a !important;
    font-size: 13px !important;
    line-height: 1.4;
}
.docx-summary-header-card .docx-summary-field-label {
    display: block;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.35px;
    text-transform: uppercase;
    color: #475569 !important;
    margin-bottom: 4px;
}
.docx-summary-header-card .docx-summary-title-value {
    font-size: 13px;
    font-weight: 600;
    color: #0f172a !important;
}
body.premium-app #docxFillForm .docx-preview-wrapper .docx-summary-header-card table.header-table input[type="text"].docx-input {
    display: block !important;
    width: 100% !important;
    min-width: 0 !important;
    font-size: 13px !important;
    font-weight: 600;
    padding: 5px 8px !important;
}

.docx-page-official-footer {
    width: 100%;
    margin-top: auto;
    padding-top: 6px;
    border-top: 1.5px solid #64748b;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 10px;
    font-weight: 600;
    color: #475569;
    flex: 0 0 auto;
    flex-shrink: 0;
}

/* Page Break Divider between A4 Sheets */
.docx-page-break-indicator {
    display: flex;
    align-items: center;
    width: 210mm;
    max-width: 100%;
    margin: 4px 0 20px 0;
    user-select: none;
}

.docx-page-break-line {
    flex-grow: 1;
    border-top: 2px dashed #94a3b8;
    height: 0;
}

.docx-page-break-badge {
    background: #1e293b;
    color: #f8fafc;
    font-size: 11px;
    font-weight: 700;
    padding: 4px 16px;
    border-radius: 14px;
    margin: 0 14px;
    letter-spacing: 0.5px;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.35);
    white-space: nowrap;
}

/* Floating Page Navigator */
#docxPageNavigator {
    pointer-events: auto;
    background: #ffffff;
    border: 1px solid #c5d0e6;
    border-radius: 8px;
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.22);
    overflow: hidden;
}
#docxPageNavigator .btn-group {
    display: flex;
    align-items: center;
}
#docxPageNavigator .btn {
    border: none;
    background: #ffffff;
    color: #1e293b;
    padding: 7px 12px;
    font-size: 12px;
    transition: background 0.15s ease-in-out;
}
#docxPageNavigator .btn:hover {
    background: #f1f5f9;
}
#docxPageNavigator select {
    border: none;
    border-left: 1px solid #e2e8f0;
    border-right: 1px solid #e2e8f0;
    font-size: 12px;
    font-weight: 600;
    color: #334155;
    padding: 4px 8px;
    background: #ffffff;
    cursor: pointer;
    outline: none;
}

/* Table of Contents inputs */
.docx-toc-title-input {
    background: #fffde7 !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 4px !important;
    padding: 2px 8px !important;
    font-weight: 500 !important;
    font-size: 13px !important;
    color: #1e293b !important;
    height: 28px !important;
    transition: all 0.15s ease-in-out;
}
.docx-toc-title-input:focus {
    border-color: #3b82f6 !important;
    box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.25) !important;
    outline: none !important;
    background: #ffffff !important;
}
.docx-toc-page-input {
    background: #fffde7 !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 4px !important;
    font-weight: 700 !important;
    font-size: 12px !important;
    color: #1e293b !important;
    height: 28px !important;
    text-align: center !important;
    transition: all 0.15s ease-in-out;
}
.docx-toc-page-input:focus {
    border-color: #3b82f6 !important;
    box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.25) !important;
    outline: none !important;
    background: #ffffff !important;
}
body.premium-app #docxFillForm .docx-preview-wrapper .docx-toc-line {
    display: flex !important;
    align-items: center;
    gap: 8px;
    width: 100%;
}
body.premium-app #docxFillForm .docx-preview-wrapper .docx-toc-line input.docx-toc-title-input {
    display: inline-block !important;
    width: auto !important;
    flex: 1 1 auto !important;
    min-width: 0 !important;
    max-width: none !important;
    height: 28px !important;
}
body.premium-app #docxFillForm .docx-preview-wrapper .docx-toc-line input.docx-toc-page-input {
    display: inline-block !important;
    width: 52px !important;
    min-width: 52px !important;
    max-width: 52px !important;
    flex: 0 0 52px !important;
    height: 28px !important;
}

@media print {
    body.premium-app #docxFillForm .docx-preview-wrapper {
        background: transparent !important;
        padding: 0 !important;
        border: none !important;
    }
    .docx-a4-page {
        box-shadow: none !important;
        margin: 0 !important;
        padding: 12mm !important;
        width: 100% !important;
        min-height: auto !important;
        page-break-after: always !important;
        break-after: page !important;
    }
    .no-print,
    .docx-page-break-indicator,
    .docx-page-header-info,
    #docxFloatingActionsContainer,
    #docxPageNavigator {
        display: none !important;
    }
}

/* ── Table layout (must stay display:table for inputs to render in cells) ── */
body.premium-app #docxFillForm .docx-preview-wrapper table.docx-table {
    display: table !important;
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 16px;
    font-size: 13px;
    table-layout: auto;
}
body.premium-app #docxFillForm .docx-preview-wrapper table.docx-table tr {
    display: table-row !important;
}
body.premium-app #docxFillForm .docx-preview-wrapper table.docx-table td,
body.premium-app #docxFillForm .docx-preview-wrapper table.docx-table th {
    display: table-cell !important;
}
body.premium-app #docxFillForm .docx-preview-wrapper table.docx-table.header-table {
    margin-bottom: 0;
}
body.premium-app #docxFillForm .docx-preview-wrapper table.docx-table.header-table td {
    font-size: 12px;
    vertical-align: middle;
}
body.premium-app #docxFillForm .docx-preview-wrapper table.docx-table:not(.header-table) tr:first-child td,
body.premium-app #docxFillForm .docx-preview-wrapper table.docx-table:not(.header-table) tr:first-child th {
    font-weight: bold;
}

/* ── All DOCX cells ── */
body.premium-app #docxFillForm .docx-preview-wrapper td.docx-cell,
body.premium-app #docxFillForm .docx-preview-wrapper th.docx-cell {
    border: 1px solid #000;
    padding: 6px 8px;
    vertical-align: middle;
    min-width: 80px;
}

/* ── Editable cells: yellow background + blue bottom border ── */
body.premium-app #docxFillForm .docx-preview-wrapper td.docx-cell.editable {
    background-color: #fffde7 !important;
    background-image: none !important;
    padding: 2px 4px !important;
    border-bottom: 2px solid #4e73df !important;
    cursor: text;
    position: relative;
}

/* ── Locked cells: grey read-only ── */
body.premium-app #docxFillForm .docx-preview-wrapper td.docx-cell.locked {
    background-color: #f5f5f5 !important;
    background-image: none !important;
    color: #444 !important;
}

/* Data tables stay inside the A4 sheet. Long text wraps and the row grows. */
body.premium-app #docxFillForm .docx-preview-wrapper table.docx-table:not(.header-table) {
    table-layout: fixed;
    width: 100%;
    max-width: 100%;
}
body.premium-app #docxFillForm .docx-preview-wrapper table.docx-table:not(.header-table) td,
body.premium-app #docxFillForm .docx-preview-wrapper table.docx-table:not(.header-table) th {
    min-width: 0 !important;
    max-width: 100%;
    white-space: normal !important;
    overflow-wrap: anywhere;
    word-break: break-word;
    overflow: visible !important;
    height: auto;
    vertical-align: top;
}
body.premium-app #docxFillForm .docx-preview-wrapper table.docx-table:not(.header-table) input,
body.premium-app #docxFillForm .docx-preview-wrapper table.docx-table:not(.header-table) textarea,
body.premium-app #docxFillForm .docx-preview-wrapper table.docx-table:not(.header-table) select {
    min-width: 0 !important;
    max-width: 100% !important;
    width: 100% !important;
    box-sizing: border-box !important;
}
body.premium-app #docxFillForm .docx-preview-wrapper textarea.docx-input,
body.premium-app #docxFillForm .docx-preview-wrapper textarea.docx-para-content {
    field-sizing: content;
    overflow: hidden;
    resize: vertical;
    white-space: pre-wrap;
}

/*
 * DOCX table inputs — beat premium-ui input[type="text"] + input:focus
 * (white rounded 46px boxes that hide inside table cells)
 */
body.premium-app #docxFillForm .docx-preview-wrapper input[type="text"].docx-input,
body.premium-app #docxFillForm .docx-preview-wrapper input[type="text"].docx-input-inline,
body.premium-app #docxFillForm .docx-preview-wrapper textarea.docx-input {
    display: block !important;
    width: 100% !important;
    min-width: 60px !important;
    min-height: 28px !important;
    height: auto !important;
    margin: 0 !important;
    padding: 4px 6px !important;
    font-size: 13px !important;
    line-height: 1.4 !important;
    font-family: inherit !important;
    color: #212529 !important;
    background-color: #fffde7 !important;
    background-image: none !important;
    border: none !important;
    border-bottom: 2px solid #4e73df !important;
    border-radius: 0 !important;
    box-shadow: none !important;
    outline: none !important;
    box-sizing: border-box !important;
    -webkit-appearance: none !important;
    appearance: none !important;
    opacity: 1 !important;
    visibility: visible !important;
    pointer-events: auto !important;
    cursor: text !important;
    position: relative !important;
    z-index: 2 !important;
}

/* Header row inputs: inline width inside label cells */
body.premium-app #docxFillForm .docx-preview-wrapper table.header-table input[type="text"].docx-input {
    display: inline-block !important;
    width: auto !important;
    min-width: 120px !important;
    min-height: 24px !important;
    vertical-align: baseline;
}

/*
 * NEQ-only: yellow letterhead value inputs must not overflow into the next
 * label cell (e.g. "Equipment /system ID No." was hidden under equipment name).
 * Requalification / revalidation keep the rules above unchanged.
 */
body.premium-app #docxFillForm.docx-import-neq .docx-preview-wrapper .docx-page-official-header table.header-table td {
    overflow: hidden !important;
    word-break: break-word !important;
    overflow-wrap: anywhere !important;
    white-space: normal !important;
}
body.premium-app #docxFillForm.docx-import-neq .docx-preview-wrapper .docx-page-official-header table.header-table input[type="text"].docx-input,
body.premium-app #docxFillForm.docx-import-neq .docx-preview-wrapper table.header-table input[type="text"].docx-input {
    display: block !important;
    width: 100% !important;
    min-width: 0 !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
}

/* NEQ-only: tighten empty paras / letterhead→approval spacing to match Word. */
body.premium-app #docxFillForm.docx-import-neq .docx-preview-wrapper .docx-page-official-header {
    margin-bottom: 4px !important;
}
body.premium-app #docxFillForm.docx-import-neq .docx-preview-wrapper .docx-page-body > p.docx-para:empty {
    margin: 2px 0 !important;
    padding: 0 !important;
    min-height: 0 !important;
    line-height: 1.1 !important;
}
body.premium-app #docxFillForm.docx-import-neq .docx-preview-wrapper .docx-page-body > table.docx-table {
    margin-top: 4px;
    margin-bottom: 8px;
}
body.premium-app #docxFillForm.docx-import-neq .docx-preview-wrapper td.docx-cell.locked.readonly,
body.premium-app #docxFillForm.docx-import-neq .docx-preview-wrapper td.docx-cell.locked {
    background: transparent !important;
}

body.premium-app #docxFillForm .docx-preview-wrapper input[type="text"].docx-input:focus,
body.premium-app #docxFillForm .docx-preview-wrapper input[type="text"].docx-input-inline:focus,
body.premium-app #docxFillForm .docx-preview-wrapper textarea.docx-input:focus {
    background-color: #fff9c4 !important;
    border: none !important;
    border-bottom: 2px solid #224abe !important;
    box-shadow: none !important;
    outline: none !important;
}

body.premium-app #docxFillForm .docx-preview-wrapper input[type="text"].docx-input::placeholder,
body.premium-app #docxFillForm .docx-preview-wrapper input[type="text"].docx-input-inline::placeholder {
    color: #888 !important;
    opacity: 1 !important;
}

body.premium-app #docxFillForm .docx-preview-wrapper .docx-textbox {
    border: 1px solid #000;
    padding: 10px 12px;
    margin: 8px 0 12px 0;
    background: #fff;
}
body.premium-app #docxFillForm .docx-preview-wrapper .docx-textbox-line {
    margin: 4px 0;
}
body.premium-app #docxFillForm .docx-preview-wrapper .docx-textbox-blank .docx-input,
body.premium-app #docxFillForm .docx-preview-wrapper .docx-textbox-input {
    width: 100%;
    text-align: left;
}
body.premium-app #docxFillForm .docx-preview-wrapper .approval-stamp-cell {
    background: #f0f0f0 !important;
    color: #555;
}
body.premium-app #docxFillForm .docx-preview-wrapper .docx-approval-pending {
    color: #999;
    font-style: italic;
}
body.premium-app #docxFillForm .docx-preview-wrapper .docx-signature-font {
    font-family: "Segoe Script", "Brush Script MT", cursive;
    font-style: italic;
    font-size: 1.05em;
}
body.premium-app #docxFillForm .docx-preview-wrapper .docx-para {
    margin: 6px 0;
    font-size: 13px;
    line-height: 1.5;
}

/* Table cell textareas: compact min height, but grow with content so multi-line
 * Acceptance criteria (1..4) is never clipped like a single-line input. */
body.premium-app #docxFillForm .docx-preview-wrapper table.docx-table:not(.header-table) textarea.docx-input {
    min-height: 28px !important;
    height: auto !important;
    overflow: hidden !important;
    white-space: pre-wrap !important;
    resize: vertical;
}
body.premium-app #docxFillForm .docx-preview-wrapper table.docx-table:not(.header-table) td.docx-cell.locked {
    white-space: normal !important;
    overflow: visible !important;
    vertical-align: top !important;
    line-height: 1.45 !important;
}
body.premium-app #docxFillForm .docx-preview-wrapper textarea.docx-input {
    min-height: 40px !important;
    resize: vertical;
}

body.premium-app #docxFillForm .docx-preview-wrapper textarea.docx-para-content {
    min-height: 36px !important;
    width: 100% !important;
    background-color: #fffde7 !important;
    background-image: none !important;
    border: 1px solid #facc15 !important;
    border-bottom: 2px solid #4e73df !important;
    border-radius: 4px !important;
    padding: 8px 10px !important;
    font-size: 13px !important;
    line-height: 1.5 !important;
    color: #1e293b !important;
    font-family: inherit !important;
    box-sizing: border-box !important;
    transition: border-color 0.15s, box-shadow 0.15s !important;
}

body.premium-app #docxFillForm .docx-preview-wrapper textarea.docx-para-content:focus {
    background-color: #fff9c4 !important;
    border-color: #3b82f6 !important;
    box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.25) !important;
    outline: none !important;
}

body.premium-app #docxFillForm .docx-preview-wrapper .docx-toc-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 14px 18px;
    margin: 16px 0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}

body.premium-app #docxFillForm .docx-preview-wrapper th.docx-table-header {
    background-color: #f1f5f9 !important;
    color: #1e293b !important;
    font-weight: 700 !important;
    text-transform: none;
    letter-spacing: 0.3px;
    border: 1px solid #cbd5e1 !important;
    padding: 8px 10px !important;
}

/* Date inputs: keep native calendar picker (do NOT use appearance:none) */
body.premium-app #docxFillForm .docx-preview-wrapper input[type="date"].docx-input,
body.premium-app #docxFillForm .docx-preview-wrapper input[type="date"].docx-input-inline {
    display: inline-block !important;
    width: auto !important;
    min-width: 140px !important;
    min-height: 28px !important;
    margin: 0 !important;
    padding: 4px 6px !important;
    font-size: 13px !important;
    background-color: #fffde7 !important;
    border: none !important;
    border-bottom: 2px solid #4e73df !important;
    border-radius: 0 !important;
    box-shadow: none !important;
    -webkit-appearance: auto !important;
    appearance: auto !important;
    opacity: 1 !important;
    visibility: visible !important;
    pointer-events: auto !important;
    cursor: pointer !important;
    position: relative !important;
    z-index: 2 !important;
}

body.premium-app #docxFillForm .docx-preview-wrapper input[type="date"].docx-input:focus,
body.premium-app #docxFillForm .docx-preview-wrapper input[type="date"].docx-input-inline:focus {
    background-color: #e8f0fe !important;
    border-bottom: 2px solid #224abe !important;
    outline: none !important;
}

body.premium-app #docxFillForm .docx-preview-wrapper .docx-para-checkbox {
    margin: 4px 0 4px 12px;
}

body.premium-app #docxFillForm .docx-preview-wrapper .docx-checkbox-cell {
    white-space: nowrap;
}

body.premium-app #docxFillForm .docx-preview-wrapper .docx-check-label {
    margin-right: 12px;
    font-weight: normal;
    cursor: pointer;
}

body.premium-app #docxFillForm .docx-preview-wrapper .docx-check-label input {
    margin-right: 4px;
    pointer-events: auto !important;
    cursor: pointer !important;
}

/* Approver dropdowns inside signature tables */
body.premium-app #docxFillForm .docx-preview-wrapper select.docx-input.approver-field {
    display: block !important;
    width: 100% !important;
    min-height: 28px !important;
    padding: 4px 6px !important;
    font-size: 13px !important;
    background-color: #fffde7 !important;
    border: none !important;
    border-bottom: 2px solid #4e73df !important;
    border-radius: 0 !important;
    -webkit-appearance: menulist !important;
    appearance: menulist !important;
    pointer-events: auto !important;
    cursor: pointer !important;
}

body.premium-app #docxFillForm .docx-preview-wrapper input.docx-attachment-field {
    display: block !important;
    width: 100% !important;
    font-size: 12px !important;
    pointer-events: auto !important;
    cursor: pointer !important;
}

body.premium-app #docxFillForm .docx-preview-wrapper input.attachment-name-field {
    display: none !important;
}

body.premium-app #docxFillForm .docx-preview-wrapper a.docx-attachment-link {
    color: #0d6efd !important;
    text-decoration: underline !important;
    display: inline-block;
    margin-bottom: 4px;
    word-break: break-word;
}

.text-xs { font-size: 0.75rem; }

#docxFillForm.requal-fill-locked .docx-preview-wrapper,
#docxFillForm.requal-fill-locked .requal-locked-block {
    pointer-events: none;
}
.docx-preview-wrapper table.docx-table tr.docx-selected-row td {
    background-color: #e8f0fe !important;
    outline: 2px solid #4e73df;
}
</style>
<?php $__env->stopPush(); ?>

<?php $__env->startSection('content'); ?>
<div id="docxFloatingActionsContainer" aria-label="Floating Actions">
    <?php if(!empty($isPreApprovalMode) && (int)($report->created_by ?? 0) === (int)auth()->id()): ?>
        <div id="docxTableToolbarDock" aria-label="Table Row Controls">
            <div class="btn-group shadow" role="group">
                <button type="button" class="btn btn-primary" id="btnDocxAddRow" title="Add a new row below the selected row">
                    <i class="fas fa-plus mr-1"></i> Add Row
                </button>
                <button type="button" class="btn btn-outline-danger" id="btnDocxDeleteRow" title="Delete the selected row">
                    <i class="fas fa-trash-alt mr-1"></i> Delete Row
                </button>
            </div>
            <div id="docxRowSelectionBadge" class="badge badge-light px-2 py-1">
                <span id="selectedRowInfo"><i class="fas fa-mouse-pointer text-primary mr-1"></i> Click row to Add/Delete</span>
            </div>
        </div>
    <?php endif; ?>

    <?php if(empty($requalFillLocked) && in_array(($importSource ?? ''), ['requalification', 'new_equipment_qualification'], true)): ?>
        <div id="docxEsignDock" aria-label="E-Sign">
            <button type="button" class="btn btn-light" id="btnEsignStamp"
                    title="Click a cell, then stamp your name and today's date">
                <i class="fas fa-signature mr-1"></i> E-Sign
            </button>
            <span id="docxEsignStatus" class="badge badge-light text-secondary px-3 py-2" style="display:none;"></span>
        </div>
    <?php endif; ?>

    <div id="docxPageNavigator" class="docx-page-navigator no-print" aria-label="Page Navigation">
        <div class="btn-group shadow" role="group">
            <button type="button" class="btn btn-light" id="btnDocxPrevPage" title="Previous Page">
                <i class="fas fa-chevron-up"></i>
            </button>
            <select id="docxSelectPageJump" class="custom-select custom-select-sm border-0" style="width: 135px; font-weight: 600; font-size: 12px; cursor: pointer;" title="Jump to page">
                <option value="1">Page 1</option>
            </select>
            <button type="button" class="btn btn-light" id="btnDocxNextPage" title="Next Page">
                <i class="fas fa-chevron-down"></i>
            </button>
        </div>
    </div>
</div>
<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-11 col-xl-10">

            <div class="mb-4">
                <a href="<?php echo e(route('reports.show', $report)); ?>" class="text-decoration-none text-secondary">
                    <i class="fas fa-arrow-left mr-1"></i> Back to <?php echo e($report->displayCode()); ?>

                </a>
            </div>

            <div class="card shadow border-0">
                <div class="card-header py-3" style="background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <h5 class="mb-0 font-weight-bold text-white">
                                <i class="fas fa-file-signature mr-2"></i>
                                <?php echo e(($importSourceSectionTitle ?? '') !== '' ? $importSourceSectionTitle : 'Fill DOCX Fields'); ?>

                            </h5>
                            <p class="mb-0 text-white-50 small mt-1">
                                <?php if(!empty($isPreApprovalMode)): ?>
                                    Pre-Approval Drafting: All protocol descriptions, specifications, and acceptance criteria are editable. Use "+ Add Row" and "Delete Row" to customize tables before sending for Pre-Approval.
                                <?php elseif(!empty($requalFillLocked)): ?>
                                    Pre-approval in progress. The document stays locked until every pre-approver has approved.
                                <?php elseif(in_array(($importSource ?? ''), ['requalification', 'revalidation'], true) && ($report->status ?? '') === 'pre_approved'): ?>
                                    Pre-approval complete! Protocol structure is locked. Fill observed values, pass/fail results, and submit for Post-Approval.
                                <?php elseif(($importSource ?? '') === 'new_equipment_qualification'): ?>
                                    Fill New Equipment Qualification — draft fields stay editable until you submit for approval. Use E-Sign to stamp name and date.
                                <?php elseif(!empty($useTablePreview)): ?>
                                    Document table layout — empty cells are editable, filled cells are read-only.
                                <?php else: ?>
                                    Flat field list — empty cells are editable, filled cells are read-only.
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="d-flex align-items-center">
                            <span id="docxDraftStatus" class="badge badge-light text-secondary shadow-sm mr-2 px-3 py-2"
                                  <?php if(empty($draftSavedAt)): ?> style="display:none;" <?php endif; ?>>
                                <?php if(!empty($draftSavedAt)): ?>
                                    <i class="fas fa-cloud-upload-alt mr-1"></i> Draft saved <?php echo e($draftSavedAt); ?>

                                <?php endif; ?>
                            </span>
                            <span class="badge badge-light px-3 py-2 text-primary shadow-sm font-weight-bold">
                                <?php echo e(count($fields)); ?> Total Fields
                            </span>
                        </div>
                    </div>
                </div>

                <form action="<?php echo e(route('reports.docx-import.fill', $report)); ?>" method="POST" id="docxFillForm" class="esign-required <?php echo e((!empty($requalFillLocked) && empty($isPreApprovalMode)) ? 'requal-fill-locked' : ''); ?><?php echo e((($importSource ?? '') === 'new_equipment_qualification') ? ' docx-import-neq' : ''); ?>" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="password">
                    <input type="hidden" name="table_ops" id="docxTableOpsInput" value="<?php echo e(json_encode($tableOps ?? [])); ?>">
                    
                    <?php if(($importSource ?? '') !== 'new_equipment_qualification'): ?>
                    <input type="hidden" name="fields[header_0_row_2_col_2]" id="header_facility_hidden"
                           value="<?php echo e(old('fields.header_0_row_2_col_2', $facilitySection)); ?>">
                    <input type="hidden" name="fields[header_0_row_4_col_4]" id="header_version_hidden"
                           value="<?php echo e(old('fields.header_0_row_4_col_4', $report->version ?? '')); ?>">
                    <?php endif; ?>
                    <div class="card-body px-4 py-4">

                        <?php if(session('error')): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle mr-2"></i> <?php echo e(session('error')); ?>

                            </div>
                        <?php endif; ?>

                        <?php if($errors->any()): ?>
                            <div class="alert alert-danger">
                                <ul class="mb-0 pl-3">
                                    <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $error): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                        <li><?php echo e($error); ?></li>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php if(in_array(($importSource ?? ''), ['requalification', 'revalidation'], true) && ($report->status ?? '') === 'pre_approved'): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle mr-1"></i> Pre-approval is complete! All template specifications and acceptance criteria are now locked. Please fill the execution results below and submit for Post-Approval.
                            </div>
                        <?php endif; ?>

                        <?php if(!empty($requalFillLocked)): ?>
                            <div class="card mb-4 border" id="requalPreApprovalCard">
                                <div class="card-header font-weight-bold">Pre Approval</div>
                                <div class="card-body">
                                    <?php if(!empty($requalPreEditable) || !empty($isPreApprovalMode)): ?>
                                        <p class="mb-3">
                                            Select all seven pre-approval users in the order shown. Approval goes to each person one by one. Once approved, the document structure and acceptance criteria will be locked.
                                        </p>
                                        <input type="hidden" name="requal_pre_submit" value="1">
                                    <?php else: ?>
                                        <div class="alert alert-warning mb-3">
                                            Pre-approval is in progress. The document stays locked until every selected user has approved.
                                        </div>
                                    <?php endif; ?>
                                    <div class="row">
                                        <?php $__currentLoopData = \App\Http\Controllers\DocxImportController::preApproverFieldTitles(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $roleKey => $roleTitle): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label font-weight-bold" for="pre_<?php echo e($roleKey); ?>"><?php echo e($roleTitle); ?></label>
                                                <select class="form-control" id="pre_<?php echo e($roleKey); ?>" name="<?php echo e($roleKey); ?>"
                                                        <?php if(empty($requalPreEditable) && empty($isPreApprovalMode)): ?> disabled <?php else: ?> required <?php endif; ?>>
                                                    <option value="">-- Select User --</option>
                                                    <?php $__currentLoopData = $users; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $user): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                                        <option value="<?php echo e($user->id); ?>"
                                                            <?php echo e((string) old($roleKey, $draftPreApprovers[$roleKey] ?? '') === (string) $user->id ? 'selected' : ''); ?>>
                                                            <?php echo e($user->display_name); ?>

                                                        </option>
                                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                                                </select>
                                            </div>
                                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if(empty($requalFillLocked)): ?>
                        <div class="card mb-4 border" id="fillWithDocxCard">
                            
                            <div class="card-header d-flex align-items-center justify-content-between py-2">
                                <span class="font-weight-bold">Fill with DOCX</span>
                                <button type="button" class="btn btn-primary shadow-sm btn-sm" id="btnFillWithDocx">
                                    <i class="fas fa-file-import mr-1"></i> Fill with DOCX
                                </button>
                            </div>
                            <div class="card-body py-3" id="fillWithDocxPanel" style="display: none;">
                                <div class="row g-3">
                                    <div class="col-md-12">
                                        <label class="form-label font-weight-bold" for="fill_from_protocol_id">Approved Protocol</label>
                                        <select class="form-control" id="fill_from_protocol_id" data-fill-from-protocol-select="1">
                                            <option value="">-- Select Approved Protocol --</option>
                                            <?php $__empty_1 = true; $__currentLoopData = ($approvedDocxProtocols ?? []); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $protocol): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                                                <option value="<?php echo e($protocol->id); ?>">
                                                    <?php echo e($protocol->fill_option_label); ?>

                                                </option>
                                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                                                <option value="" disabled>No approved <?php echo e($importSourceLabel ?? 'DOCX'); ?> protocols available</option>
                                            <?php endif; ?>
                                        </select>
                                        <small class="text-muted d-block mt-1">
                                            <?php if(trim((string) ($importSourceLabel ?? '')) !== ''): ?>
                                                Select an approved <?php echo e($importSourceLabel); ?> protocol to copy matching field values into this form.
                                            <?php else: ?>
                                                Select an approved protocol to copy matching field values into this form.
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </div>
                                <div id="fillWithDocxStatus" class="alert mt-3 mb-0 py-2 small" style="display: none;" role="alert"></div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="card mb-4 border <?php echo e(!empty($requalFillLocked) ? 'requal-locked-block' : ''); ?>">
                            
                            <div class="card-header">Document Mapping</div>
                            <div class="card-body">
                                <div class="row g-3">
                                    
                                    <div class="col-md-12">
                                        <label class="form-label font-weight-bold">Protocol / Document Title </label>
                                        <input type="text" name="document_title" id="fill_document_title" class="form-control"
                                               value="<?php echo e(old('document_title', $documentTitle ?? '')); ?>"
                                               placeholder="Enter protocol or document title"
                                               >
                                        <small class="text-muted">This title appears in the protocol list after you submit.</small>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label font-weight-bold">Document Number</label>
                                        <input type="text" name="protocol_document_no" id="fill_protocol_document_no" class="form-control"
                                               value="<?php echo e(old('protocol_document_no', $protocolDocumentNo ?? '')); ?>"
                                               placeholder="From original protocol, or enter if blank">
                                        <small class="text-muted">Shown on the report header. Auto-filled when present in the uploaded DOCX.</small>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label font-weight-bold">Reference Protocol No</label>
                                        <input type="text" name="reference_protocol_no" id="fill_reference_protocol_no" class="form-control"
                                               value="<?php echo e(old('reference_protocol_no', $referenceProtocolNo ?? '')); ?>"
                                               placeholder="From original protocol, or enter if blank">
                                        <small class="text-muted">Shown on the report header. Auto-filled when present in the uploaded DOCX.</small>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label font-weight-bold">Facility / Section</label>
                                        <input type="text" name="facility_section" id="fill_facility_section" class="form-control"
                                               value="<?php echo e(old('facility_section', $facilitySection)); ?>"
                                               placeholder="From letterhead, or enter if blank">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label font-weight-bold">Equipment Name <span class="text-danger">*</span></label>
                                        <input type="text" name="equipment_name" id="fill_equipment_name" class="form-control"
                                               value="<?php echo e(old('equipment_name', $equipmentName)); ?>"
                                               required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label font-weight-bold">Equipment ID <span class="text-danger">*</span></label>
                                        <input type="text" name="equipment_id" id="fill_equipment_id" class="form-control"
                                               value="<?php echo e(old('equipment_id', $equipmentId)); ?>"
                                               required>
                                    </div>
                                    <?php if(!empty($hasEffectiveDateField)): ?>
                                        <div class="col-md-4">
                                            <label class="form-label font-weight-bold">Effective Date</label>
                                            <input type="date" name="effective_date" id="fill_effective_date" class="form-control"
                                                   value="<?php echo e(old('effective_date', $effectiveDate ?? '')); ?>">
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <?php
                            $presentApproverRoles = $presentApproverRoles ?? [];
                            $hasApproverFields = count($presentApproverRoles) > 0;
                            $isNeqFill = (($importSource ?? '') === 'new_equipment_qualification');
                        ?>

                        
                        <?php if($hasApproverFields): ?>
                            <?php
                                $draftApprovers = $draftApprovers ?? [];
                            ?>
                            <?php if(in_array('post_prepared_by_name', $presentApproverRoles, true)): ?>
                                <input type="hidden" name="post_prepared_by_name" id="post_prepared_by_name_value"
                                       value="<?php echo e(old('post_prepared_by_name', $draftApprovers['post_prepared_by_name'] ?? auth()->id())); ?>">
                            <?php endif; ?>
                            <?php $__currentLoopData = [
                                'post_checked_by_engineering_name' => 'post_checked_by_engineering_name_value',
                                'post_checked_by_production_name'  => 'post_checked_by_production_name_value',
                                'post_checked_by_quality_name'     => 'post_checked_by_quality_name_value',
                                'post_checked_by_validation_name'  => 'post_checked_by_validation_name_value',
                                'post_approved_by_user_name'       => 'post_approved_by_user_name_value',
                                'post_approved_by_quality_name'    => 'post_approved_by_quality_name_value',
                            ]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $roleKey => $inputId): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <?php if(in_array($roleKey, $presentApproverRoles, true)): ?>
                                    <input type="hidden" name="<?php echo e($roleKey); ?>" id="<?php echo e($inputId); ?>"
                                           value="<?php echo e(old($roleKey, $draftApprovers[$roleKey] ?? '')); ?>">
                                <?php endif; ?>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        <?php endif; ?>

                        
                        <?php if(empty($isNeqFill) && !empty($usesApprovalPage) && $hasApproverFields): ?>
                            <div class="alert alert-info mb-4">
                                <i class="fas fa-user-check mr-1"></i>
                                <strong>Approval Page:</strong> Select <strong>Performed By</strong>,
                                <strong>Checked By</strong>, and <strong>Approved By</strong> users in the signature table below.
                                Signature and Date columns are filled automatically when each approval step completes.
                                <strong>Performed By</strong> defaults to you
                                (<?php echo e(trim((auth()->user()->first_name ?? '') . ' ' . (auth()->user()->last_name ?? '')) ?: 'Current user'); ?>).
                            </div>
                        <?php elseif(empty($isNeqFill) && $hasApproverFields): ?>
                            <div class="alert alert-info mb-4">
                                <i class="fas fa-user-check mr-1"></i>
                                Select approvers in the <strong>Prepared By</strong>, <strong>Checked By</strong>, and
                                <strong>Approved By</strong> tables below. Those users will receive POST approval workflow steps.
                            </div>
                        <?php endif; ?>

                        <?php if(!empty($isPreApprovalMode)): ?>
                            <div class="alert alert-primary py-2 px-3 mb-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <div class="d-flex align-items-center">
                                    <span class="badge badge-primary mr-2"><i class="fas fa-edit mr-1"></i> Pre-Approval Drafting Mode</span>
                                    <span class="small font-weight-bold text-dark">
                                        All protocol descriptions, specifications, and acceptance criteria are editable. Use the floating <span class="badge badge-primary px-2 py-1"><i class="fas fa-plus"></i> Add Row</span> and <span class="badge badge-danger px-2 py-1"><i class="fas fa-trash-alt"></i> Delete Row</span> buttons on the top-right to customize tables.
                                    </span>
                                </div>
                            </div>
                        <?php endif; ?>

                        
                        <?php if(!empty($useTablePreview)): ?>
                            <?php
                                $templateName = trim((string) ($documentTitle ?? '')) !== ''
                                    ? (string) $documentTitle
                                    : (\App\Models\Report::importSourceLabel($importSource ?? '')
                                        ?: ($report->template->name ?? 'PERIODIC HVAC VALIDATION REPORT'));
                            ?>
                            <div class="docx-preview-wrapper mb-4">
                                <?php if(!empty($showLegacyBladeHeader)): ?>
                                
                                <div class="docx-summary-header-card">
                                    <table class="docx-table header-table" style="margin-bottom:0;">
                                        <tr>
                                            <td rowspan="3" style="width:96px; text-align:center; vertical-align:middle !important; background:#ffffff !important;">
                                                <img src="<?php echo e(asset('storage/lupin.png')); ?>" alt="Lupin" style="height:46px; width:auto;" onerror="this.style.display='none'">
                                                <div style="font-size:11px; font-weight:700; margin-top:4px; color:#047857;">LUPIN</div>
                                            </td>
                                            <td colspan="4" id="header_summary_banner" style="text-align:center; font-weight:700; font-size:15px; padding:10px !important; background:#f1f5f9 !important; color:#0f172a !important;">
                                                <?php echo e(strtoupper($templateName)); ?>

                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="width:22%; background:#f8fafc !important;">
                                                <span class="docx-summary-field-label">Title</span>
                                                <span class="docx-summary-title-value" id="header_summary_title"><?php echo e($templateName); ?></span>
                                            </td>
                                            <td style="width:26%;">
                                                <label class="docx-summary-field-label" for="header_doc_number">Document Number</label>
                                                <input type="text" name="fields[header_0_row_4_col_2]" id="header_doc_number" class="docx-input"
                                                       placeholder="Enter document number..."
                                                       value="<?php echo e(old('fields.header_0_row_4_col_2', $protocolDocumentNo ?? $documentNo)); ?>">
                                            </td>
                                            <td style="width:26%;">
                                                <label class="docx-summary-field-label" for="header_equipment_name">Equipment Name</label>
                                                <input type="text" name="fields[header_0_row_2_col_4]" id="header_equipment_name" class="docx-input"
                                                       value="<?php echo e(old('fields.header_0_row_2_col_4', $equipmentName)); ?>">
                                            </td>
                                            <td style="width:26%;">
                                                <label class="docx-summary-field-label" for="header_equipment_id">Equipment ID</label>
                                                <input type="text" name="fields[header_0_row_3_col_4]" id="header_equipment_id" class="docx-input"
                                                       value="<?php echo e(old('fields.header_0_row_3_col_4', $equipmentId)); ?>">
                                            </td>
                                        </tr>
                                        <tr>
                                            <td colspan="4">
                                                <label class="docx-summary-field-label" for="header_ref_protocol">Reference Protocol No</label>
                                                <input type="text" name="fields[header_ref_protocol]" id="header_ref_protocol" class="docx-input"
                                                       placeholder="Enter reference protocol number..."
                                                       value="<?php echo e(old('fields.header_ref_protocol', $referenceProtocolNo ?? '')); ?>">
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                                <?php endif; ?>

                                <?php echo $previewHtml; ?>

                            </div>

                            
                            <?php $__currentLoopData = $fields; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $field): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <?php
                                    $lockedRaw = $field['locked'] ?? false;
                                    if (is_bool($lockedRaw)) {
                                        $isLocked = $lockedRaw;
                                    } elseif (is_numeric($lockedRaw)) {
                                        $isLocked = (int) $lockedRaw === 1;
                                    } elseif (is_string($lockedRaw)) {
                                        $isLocked = in_array(strtolower(trim($lockedRaw)), ['1', 'true', 'yes'], true);
                                    } else {
                                        $isLocked = false;
                                    }
                                    $location = $field['location'] ?? '';
                                    $value    = $field['value'] ?? '';
                                ?>
                                <?php if($isLocked && $location !== ''): ?>
                                    <input type="hidden" name="fields[<?php echo e($location); ?>]" value="<?php echo e($value); ?>">
                                <?php endif; ?>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>

                        <?php else: ?>
                            
                            <div class="alert alert-warning mb-3">
                                <i class="fas fa-exclamation-triangle mr-1"></i>
                                <?php if(empty($docxTemplateAvailable)): ?>
                                    The original DOCX template file is missing on the server.
                                    Start the Python service on port 8001 and ensure a generated DOCX attachment exists,
                                    or re-import the document to restore the table layout.
                                <?php else: ?>
                                    Table preview unavailable — ensure the Python service is running on port 8001.
                                    Showing field list instead.
                                <?php endif; ?>
                            </div>
                            <div class="row">
                                <?php $__empty_1 = true; $__currentLoopData = ($flatListFields ?? $fields); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $field): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                                    <?php
                                        $lockedRaw = $field['locked'] ?? false;
                                        if (is_bool($lockedRaw)) {
                                            $isLocked = $lockedRaw;
                                        } elseif (is_numeric($lockedRaw)) {
                                            $isLocked = (int) $lockedRaw === 1;
                                        } elseif (is_string($lockedRaw)) {
                                            $isLocked = in_array(strtolower(trim($lockedRaw)), ['1', 'true', 'yes'], true);
                                        } else {
                                            $isLocked = false;
                                        }
                                        $label    = $field['label']    ?? '';
                                        $value    = $field['value']    ?? '';
                                        $location = $field['location'] ?? '';
                                        $type     = $field['type']     ?? '';
                                    ?>
                                    <div class="col-12 mb-3">
                                        <div class="d-flex align-items-center justify-content-between mb-1">
                                            <label class="form-label font-weight-bold mb-0 <?php echo e($isLocked ? 'text-muted' : 'text-dark'); ?>">
                                                <?php echo e($label); ?>

                                            </label>
                                            <?php if($isLocked): ?>
                                                <span class="badge badge-secondary text-xs">
                                                    <i class="fas fa-lock mr-1"></i> Locked
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-light text-primary text-xs">
                                                    <i class="fas fa-edit mr-1"></i> Editable
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <?php if($isLocked): ?>
                                            <div class="form-control-plaintext bg-light px-3 py-2 rounded border text-muted" style="min-height:38px;">
                                                <?php echo e($value !== '' ? $value : '—'); ?>

                                            </div>
                                            <input type="hidden" name="fields[<?php echo e($location); ?>]" value="<?php echo e($value); ?>">
                                        <?php else: ?>
                                            <?php if(strlen($value) > 100 || str_contains(strtolower($label), 'description') || str_contains(strtolower($label), 'remark')): ?>
                                                <textarea name="fields[<?php echo e($location); ?>]"
                                                          class="form-control"
                                                          rows="3"
                                                          placeholder="Enter <?php echo e(strtolower($label)); ?>..."><?php echo e(old('fields.'.$location, $value)); ?></textarea>
                                            <?php else: ?>
                                                <input type="text"
                                                       name="fields[<?php echo e($location); ?>]"
                                                       class="form-control"
                                                       value="<?php echo e(old('fields.'.$location, $value)); ?>"
                                                       placeholder="Enter <?php echo e(strtolower($label)); ?>...">
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <div class="d-flex justify-content-between mt-1 text-xs text-muted">
                                            <span>Type: <code><?php echo e($type); ?></code></span>
                                            <span>Location: <code><?php echo e($location); ?></code></span>
                                        </div>
                                    </div>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                                    <div class="col-12 text-center py-5">
                                        <h6 class="text-muted">No Fields Detected</h6>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php
                            $preApprovalSteps = $report->relationLoaded('approvalSteps')
                                ? $report->approvalSteps->where('stage', 'pre')->sortBy('step_order')
                                : $report->approvalSteps()->where('stage', 'pre')->orderBy('step_order')->get();
                        ?>
                        <?php if($preApprovalSteps->isNotEmpty()): ?>
                            <div class="card mt-4 mb-2 border shadow-sm">
                                <div class="card-header bg-light font-weight-bold d-flex align-items-center">
                                    <i class="fas fa-history text-primary mr-2"></i> Pre-Approval History
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-striped mb-0">
                                            <thead class="thead-light">
                                                <tr>
                                                    <th style="width: 70px;">Step</th>
                                                    <th>Role / Stage</th>
                                                    <th>Approver User</th>
                                                    <th>Status</th>
                                                    <th>Approved Date & Time</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php $__currentLoopData = $preApprovalSteps; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $step): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                                    <tr>
                                                        <td class="text-center font-weight-bold"><?php echo e($step->step_order); ?></td>
                                                        <td>
                                                            <span class="badge badge-info"><?php echo e($step->label ?? 'Approver'); ?></span>
                                                        </td>
                                                        <td>
                                                            <div class="font-weight-bold text-dark">
                                                                <?php echo e($step->user->first_name ?? ''); ?> <?php echo e($step->user->last_name ?? ''); ?>

                                                            </div>
                                                            <?php if(!empty($step->user->designation)): ?>
                                                                <div class="small text-muted"><?php echo e($step->user->designation); ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if($step->approved_at): ?>
                                                                <span class="badge badge-success text-light"><i class="fas fa-check mr-1"></i> Approved</span>
                                                            <?php elseif($step->rejected_at): ?>
                                                                <span class="badge badge-danger text-light"><i class="fas fa-times mr-1"></i> Rejected</span>
                                                            <?php else: ?>
                                                                <span class="badge badge-secondary text-light">Pending</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php echo e($step->approved_at ? \Carbon\Carbon::parse($step->approved_at)->timezone('Asia/Kolkata')->format('d-m-Y h:i A') : '-'); ?>

                                                        </td>
                                                    </tr>
                                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                    </div>

                    <div class="card-footer bg-light px-4 py-3 d-flex justify-content-end align-items-center">
                        
                        <?php if(!empty($requalFillLocked) && !empty($requalPreEditable) && (int)($report->created_by ?? 0) === (int)auth()->id()): ?>
                            <button type="submit" class="btn btn-primary shadow-sm" id="btnSubmit">
                                <i class="fas fa-user-check mr-1"></i>
                                <span id="btnText">Send for Pre Approval</span>
                            </button>
                        <?php elseif(empty($requalFillLocked) && count($fields) > 0 && (int)($report->created_by ?? 0) === (int)auth()->id()): ?>
                            <button type="submit" class="btn btn-primary shadow-sm" id="btnSubmit">
                                <i class="fas fa-file-export mr-1"></i>
                                <span id="btnText">Generate & Submit for Post Approval</span>
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form      = document.getElementById('docxFillForm');
    const btnSubmit = document.getElementById('btnSubmit');
    const btnText   = document.getElementById('btnText');
    const requalFillLocked = <?php echo json_encode(!empty($requalFillLocked), 15, 512) ?>;
    const isPreApprovalMode = <?php echo json_encode(!empty($isPreApprovalMode), 15, 512) ?>;

    if (requalFillLocked && !isPreApprovalMode && form) {
        form.querySelectorAll('.docx-preview-wrapper input, .docx-preview-wrapper select, .docx-preview-wrapper textarea, .requal-locked-block input, .requal-locked-block select, .requal-locked-block textarea').forEach(function (el) {
            el.disabled = true;
        });
        form.querySelectorAll('.docx-preview-wrapper [contenteditable="true"]').forEach(function (el) {
            el.setAttribute('contenteditable', 'false');
        });
    }

    let tableOps = <?php echo json_encode($tableOps ?? [], 15, 512) ?>;
    if (!Array.isArray(tableOps)) {
        // Recover from historically double-encoded draft payloads.
        try {
            let cur = tableOps;
            for (let i = 0; i < 8; i++) {
                if (Array.isArray(cur)) { tableOps = cur; break; }
                if (typeof cur !== 'string') { tableOps = []; break; }
                cur = JSON.parse(cur);
                if (i === 7) tableOps = Array.isArray(cur) ? cur : [];
            }
        } catch (e) {
            tableOps = [];
        }
    }
    let selectedRow = null;
    let selectedTable = null;

    // Draft can accumulate identical insert ops (1 Add → many). Keep one per content fingerprint.
    (function collapseDuplicateTableOps() {
        if (!Array.isArray(tableOps) || !tableOps.length) return;
        const out = [];
        const seenContent = new Set();
        tableOps.forEach(function (op) {
            if (!op || op.op !== 'insert') {
                out.push(op);
                return;
            }
            const vals = Array.isArray(op.values)
                ? op.values.map(function (v) { return String(v || '').replace(/\s+/g, ' ').trim().toLowerCase(); }).join('|')
                : '';
            const key = String(op.table) + '#' + vals;
            if (vals && seenContent.has(key)) return;
            if (vals) seenContent.add(key);
            out.push(op);
        });
        tableOps = out;
    })();

    function createNewA4Page(sourcePage, tIdx, sourceTable) {
        const newPage = document.createElement('div');
        newPage.className = 'docx-a4-page';

        const sourceHeader = sourcePage.querySelector('.docx-page-official-header');
        if (sourceHeader) {
            newPage.appendChild(sourceHeader.cloneNode(true));
        }

        const newBody = document.createElement('div');
        newBody.className = 'docx-page-body';

        const newTable = document.createElement('table');
        newTable.className = sourceTable.className;
        newTable.setAttribute('data-table-index', tIdx);

        appendClonedHeaderRows(newTable, sourceTable);
        newBody.appendChild(newTable);
        newPage.appendChild(newBody);

        const sourceFooter = sourcePage.querySelector('.docx-page-official-footer');
        if (sourceFooter) {
            newPage.appendChild(sourceFooter.cloneNode(true));
        }

        return newPage;
    }

    function createPageBreakIndicator() {
        const div = document.createElement('div');
        div.className = 'docx-page-break-indicator no-print';
        div.innerHTML = '<div class="docx-page-break-line"></div><div class="docx-page-break-badge"><i class="fas fa-file-alt mr-1"></i> Page Break</div><div class="docx-page-break-line"></div>';
        return div;
    }

    function previewA4Pages() {
        const preview = document.querySelector('.docx-preview-wrapper .docx-preview')
            || document.querySelector('.docx-preview')
            || document.querySelector('.docx-preview-wrapper');
        if (!preview) {
            return [];
        }
        return Array.from(preview.querySelectorAll('.docx-a4-page'));
    }

    function updateAllPageNumbers() {
        // Only number sheets inside the live preview — never reuse a cloned "17 of 17" header.
        const allPages = previewA4Pages();
        const total = allPages.length;

        allPages.forEach(function (page, idx) {
            const pNum = idx + 1;
            page.setAttribute('data-page-number', String(pNum));
            page.id = 'docxPage' + pNum;

            // Update every marker on this sheet (header + footer).
            page.querySelectorAll('.current-page-num').forEach(function (el) {
                el.textContent = String(pNum);
            });
            page.querySelectorAll('.total-pages-count').forEach(function (el) {
                el.textContent = String(total);
            });

            // Plain-text / broken markup fallback (continuation clones often keep "Page 17 of 17").
            page.querySelectorAll('.docx-page-official-header td').forEach(function (td) {
                const text = (td.textContent || '').replace(/\s+/g, ' ').trim();
                if (!/page\s*\d+\s*of\s*\d+/i.test(text)) {
                    return;
                }
                if (td.querySelector('.current-page-num') && td.querySelector('.total-pages-count')) {
                    return;
                }
                const keepLabel = /page\s*no\.?/i.test(text);
                td.innerHTML = (keepLabel ? 'PAGE NO.<br>' : '')
                    + 'Page <span class="current-page-num">' + pNum
                    + '</span> of <span class="total-pages-count">' + total + '</span>';
            });

            const footerEl = page.querySelector('.docx-page-official-footer');
            if (footerEl) {
                let pageSpan = footerEl.querySelector('.docx-footer-page');
                if (!pageSpan) {
                    pageSpan = footerEl;
                }
                if (!pageSpan.querySelector('.current-page-num')) {
                    pageSpan.innerHTML = 'Page <span class="current-page-num">' + pNum
                        + '</span> of <span class="total-pages-count">' + total + '</span>';
                }
            }
        });

        // Dividers sit between sheets: first divider = start of page 2.
        const previewRoot = document.querySelector('.docx-preview-wrapper .docx-preview')
            || document.querySelector('.docx-preview')
            || document.querySelector('.docx-preview-wrapper')
            || document;
        Array.from(previewRoot.querySelectorAll('.docx-page-break-indicator')).forEach(function (div, idx) {
            const badge = div.querySelector('.docx-page-break-badge');
            if (badge) {
                badge.innerHTML = '<i class="fas fa-file-alt mr-1"></i> Page ' + (idx + 2);
            }
        });

        const selectJump = document.getElementById('docxSelectPageJump');
        if (selectJump) {
            // Prefer the page currently in view — never snap the dropdown (and scroll) to page 1.
            let currNum = parseInt(selectJump.value, 10);
            if (!currNum || currNum < 1) {
                const mid = window.scrollY + Math.min(240, window.innerHeight * 0.35);
                currNum = 1;
                for (let i = 0; i < allPages.length; i++) {
                    const top = allPages[i].getBoundingClientRect().top + window.scrollY;
                    if (top <= mid) currNum = i + 1;
                    else break;
                }
            }
            if (currNum > total) currNum = total;
            const prevTotal = selectJump.options.length;
            if (prevTotal !== total) {
                selectJump.innerHTML = '';
                for (let i = 1; i <= total; i++) {
                    const opt = document.createElement('option');
                    opt.value = String(i);
                    opt.textContent = 'Page ' + i + ' of ' + total;
                    selectJump.appendChild(opt);
                }
            } else {
                Array.from(selectJump.options).forEach(function (opt, i) {
                    opt.textContent = 'Page ' + (i + 1) + ' of ' + total;
                });
            }
            selectJump.value = String(Math.max(1, currNum || 1));
        }
    }

    // <tr> nodes parsed from /preview HTML live inside an implicit <tbody>;
    // table.insertBefore(row, refRow) throws NotFoundError there and aborts
    // paginateDocxPreview() half-way (9.0/9.1/T12 left clipped under 8.6).
    function insertRowBefore(table, row, ref) {
        const parent = (ref && ref.parentNode) || (table.tBodies && table.tBodies[0]) || table;
        parent.insertBefore(row, ref || null);
    }

    function placeRowAtStart(table, row) {
        const first = table.rows[0];
        if (first && first.querySelector('th')) first.after(row);
        else if (first) insertRowBefore(table, row, first);
        else table.appendChild(row);
    }

    function reflowPageOverflow(page) {
        if (!page) return;
        const pageBody = page.querySelector('.docx-page-body');
        if (!pageBody) return;

        let safety = 0;
        while ((pageIsOverfull(pageBody) || pageBody.scrollHeight > pageBody.clientHeight + 8) && safety < 50) {
            safety++;
            const last = pageBody.lastElementChild;
            if (!last) break;

            if (last.matches('table.docx-table') && !last.classList.contains('header-table') && last.rows.length > 1) {
                const lastRow = last.rows[last.rows.length - 1];
                if (lastRow.offsetHeight + 12 >= pageBody.clientHeight && last.rows.length <= 2 && pageBody.childElementCount === 1) {
                    break;
                }
                // Keep Sr.No 1 visible: if only header+one data row remain and still
                // overfull, move the whole leftover table instead of clipping row 1.
                if (last.rows.length <= 2) {
                    const nextPage = followingA4Page(page) || createContinuationPage(page);
                    const nextBody = nextPage.querySelector('.docx-page-body');
                    const tIdx = last.getAttribute('data-table-index') || '';
                    const existing = tIdx
                        ? nextBody.querySelector('table.docx-table[data-table-index="' + tIdx + '"]')
                        : null;
                    if (existing && existing !== last) {
                        const dataRows = Array.from(last.rows).slice(1);
                        const hostHeader = existing.rows[0];
                        dataRows.reverse().forEach(function (r) {
                            if (hostHeader) hostHeader.after(r);
                            else insertRowBefore(existing, r, existing.rows[0] || null);
                        });
                        last.remove();
                    } else {
                        const glued = [];
                        let prevH = last.previousElementSibling;
                        while (prevH && isRequalChecksHeading(prevH)) {
                            glued.unshift(prevH);
                            prevH = prevH.previousElementSibling;
                        }
                        const keep = last.closest && last.closest('.docx-keep-section[data-keep-section="requal-91"]');
                        if (keep && keep.parentNode === pageBody) {
                            nextBody.insertBefore(keep, nextBody.firstChild);
                        } else {
                            nextBody.insertBefore(last, nextBody.firstChild);
                            for (let gi = glued.length - 1; gi >= 0; gi--) {
                                nextBody.insertBefore(glued[gi], last);
                            }
                        }
                    }
                    continue;
                }
                const before = lastRow.previousElementSibling;
                const cont = continuationTable(page, last);
                const header = lastHeaderRow(cont);
                if (header && header !== lastRow) header.after(lastRow);
                else placeRowAtStart(cont, lastRow);
                // If the row did not move, push the whole leftover table — never
                // leave General Checks / major-component rows clipped under overflow:hidden.
                if (last.contains(lastRow) || (before && before.nextElementSibling === lastRow)) {
                    const nextPage = followingA4Page(page) || createContinuationPage(page);
                    const nextBody = nextPage.querySelector('.docx-page-body');
                    if (nextBody && last.parentNode === pageBody) {
                        nextBody.insertBefore(last, nextBody.firstChild);
                    }
                    continue;
                }
                if (last.rows.length === 2) {
                    const maybeFirst = last.rows[1];
                    if (maybeFirst && maybeFirst.getAttribute('data-row-index') === '1') {
                        const hostRow2 = Array.from(cont.rows).find(function (r) {
                            return r.getAttribute('data-row-index') === '2';
                        });
                        if (hostRow2) insertRowBefore(cont, maybeFirst, hostRow2);
                        else if (header) header.after(maybeFirst);
                        else placeRowAtStart(cont, maybeFirst);
                    }
                }
                continue;
            }

            if (pageBody.childElementCount === 1) break;
            const nextPage = followingA4Page(page) || createContinuationPage(page);
            const nextBody = nextPage.querySelector('.docx-page-body');
            nextBody.insertBefore(last, nextBody.firstChild);
        }

        updateAllPageNumbers();

        // Recursively check next page
        let nextP = page.nextElementSibling;
        while (nextP && !nextP.classList.contains('docx-a4-page')) {
            nextP = nextP.nextElementSibling;
        }
        if (nextP && nextP.querySelector('.docx-page-body') && nextP.querySelector('.docx-page-body').scrollHeight > nextP.querySelector('.docx-page-body').clientHeight + 4) {
            reflowPageOverflow(nextP);
        }
    }

    function reflowPageUnderflow(page) {
        if (!page) return;
        const pageBody = page.querySelector('.docx-page-body');
        if (!pageBody) return;

        let nextPage = page.nextElementSibling;
        while (nextPage && !nextPage.classList.contains('docx-a4-page')) {
            nextPage = nextPage.nextElementSibling;
        }
        if (!nextPage) return;

        const currentTables = pageBody.querySelectorAll('table.docx-table:not(.header-table)');
        if (!currentTables.length) return;
        const currentTable = currentTables[currentTables.length - 1];
        const tIdx = currentTable.getAttribute('data-table-index') || '';

        const nextTable = nextPage.querySelector('table.docx-table[data-table-index="' + tIdx + '"]');
        if (!nextTable) return;

        while (true) {
            const nextRows = Array.from(nextTable.querySelectorAll('tr'));
            if (nextRows.length <= 1) break;
            const firstDataRow = nextRows[1];

            currentTable.appendChild(firstDataRow);
            if (pageBody.scrollHeight > pageBody.clientHeight + 4) {
                if (nextTable.querySelectorAll('tr').length > 1) {
                    nextTable.insertBefore(firstDataRow, nextTable.querySelectorAll('tr')[1]);
                } else {
                    nextTable.appendChild(firstDataRow);
                }
                break;
            }
        }

        const remainingRows = Array.from(nextTable.querySelectorAll('tr'));
        const nextBody = nextPage.querySelector('.docx-page-body');
        if (remainingRows.length <= 1 && nextBody && nextBody.querySelectorAll('table, p').length <= 1) {
            let prev = nextPage.previousElementSibling;
            if (prev && prev.classList.contains('docx-page-break-indicator')) {
                prev.remove();
            }
            nextPage.remove();
        }

        updateAllPageNumbers();
    }

    window.updateAllPageNumbers = updateAllPageNumbers;
    window.reflowPageOverflow = reflowPageOverflow;
    window.reflowPageUnderflow = reflowPageUnderflow;

    function followingA4Page(page) {
        let next = page ? page.nextElementSibling : null;
        while (next && !next.classList.contains('docx-a4-page')) {
            next = next.nextElementSibling;
        }
        return next;
    }

    function precedingA4Page(page) {
        let prev = page ? page.previousElementSibling : null;
        while (prev && !prev.classList.contains('docx-a4-page')) {
            prev = prev.previousElementSibling;
        }
        return prev;
    }

    function createContinuationPage(sourcePage) {
        const newPage = document.createElement('div');
        newPage.className = 'docx-a4-page';

        const sourceHeader = sourcePage.querySelector('.docx-page-official-header');
        if (sourceHeader) {
            const header = sourceHeader.cloneNode(true);
            header.querySelectorAll('[id]').forEach(function (el) { el.removeAttribute('id'); });
            newPage.appendChild(header);
        }

        const newBody = document.createElement('div');
        newBody.className = 'docx-page-body';
        newPage.appendChild(newBody);

        const sourceFooter = sourcePage.querySelector('.docx-page-official-footer');
        if (sourceFooter) {
            const footer = sourceFooter.cloneNode(true);
            footer.querySelectorAll('[id]').forEach(function (el) { el.removeAttribute('id'); });
            newPage.appendChild(footer);
        }

        const divider = createPageBreakIndicator();
        sourcePage.after(divider);
        divider.after(newPage);
        // Cloned header still says e.g. "Page 17 of 17" — renumber immediately.
        updateAllPageNumbers();
        return newPage;
    }

    function rowPlainLabel(row) {
        return ((row && (row.innerText || row.textContent)) || '')
            .replace(/\s+/g, ' ')
            .trim()
            .toLowerCase();
    }

    function rowLooksLikeColumnHeader(row) {
        const text = rowPlainLabel(row);
        if (!text) return false;
        // 9.1 / 9.2 / 9.3 style column titles — not section banners.
        return (
            /\bchecks\b/.test(text)
            && (
                /\bmethodology\b/.test(text)
                || /\bacceptance\s*criteria\b/.test(text)
                || /\bobservation\b/.test(text)
                || /\bdone\s*by\b/.test(text)
            )
        ) || (
            /\bsr\.?\s*no\b/.test(text)
            && /\bacceptance\s*criteria\b/.test(text)
        );
    }

    function rowLooksLikeSectionBanner(row) {
        if (!row || rowLooksLikeColumnHeader(row)) return false;
        const text = rowPlainLabel(row);
        if (!text || text.length > 80) return false;
        return /^(?:\d+\.\s*)?(major\s*components|machine\s*frame|mechanical\s*component|electrical\s*component|mechanical\s*adjustment|machine\s*performance)\b/.test(text);
    }

    // Att08 Instrument Calibration: 2-row header (Calibration details + Done On/Due On).
    // Clone the full header block — not only rows[0] — or page 2 loses column headings.
    // For 9.2 Major components: row0 is a banner; the real column header is Checks/Methodology.
    // Cloning only the banner made Checks spill as a "data" row and reappear twice.
    function leadingHeaderRowCount(sourceTable) {
        if (!sourceTable || !sourceTable.rows.length) return 0;

        let colHdrIdx = -1;
        const scan = Math.min(6, sourceTable.rows.length);
        for (let i = 0; i < scan; i++) {
            if (rowLooksLikeColumnHeader(sourceTable.rows[i])) {
                colHdrIdx = i;
                break;
            }
        }
        if (colHdrIdx >= 0) {
            // Include banner rows above the column header (e.g. "Major components :").
            return Math.max(1, Math.min(colHdrIdx + 1, 3, sourceTable.rows.length - 1 || 1));
        }

        const row0 = sourceTable.rows[0];
        const looksHeader = !!(
            row0.querySelector('th')
            || row0.getAttribute('data-row-index') === '0'
            || row0.getAttribute('data-cont-header') === '1'
            || row0.classList.contains('docx-table-header')
            || row0.querySelector('.docx-table-header')
        );
        if (!looksHeader) return 0;

        let span = 1;
        Array.from(row0.cells).forEach(function (cell) {
            const rs = parseInt(cell.getAttribute('rowspan') || '1', 10);
            if (!isNaN(rs) && rs > span) span = rs;
        });

        if (span < 2 && sourceTable.rows.length > 1) {
            const row1 = sourceTable.rows[1];
            const hasInput = !!row1.querySelector('input, textarea, select');
            const text = rowPlainLabel(row1);
            const subHints = ['done on', 'due on'];
            if (!hasInput && subHints.some(function (h) { return text.indexOf(h) !== -1; })) {
                span = 2;
            }
        }

        const marked = parseInt(sourceTable.getAttribute('data-header-row-count') || '0', 10);
        if (!isNaN(marked) && marked > span) span = marked;

        return Math.max(1, Math.min(span, 3, sourceTable.rows.length - 1 || 1));
    }

    function appendClonedHeaderRows(targetTable, sourceTable) {
        const n = leadingHeaderRowCount(sourceTable);
        if (n <= 0) return 0;
        // Avoid stacking a second Checks/Methodology block when the host already
        // has the real column header (merge/pull after soft page-break).
        if (Array.from(targetTable.rows || []).some(rowLooksLikeColumnHeader)) {
            targetTable.setAttribute('data-header-row-count', String(Math.max(1, leadingHeaderRowCount(targetTable))));
            return 0;
        }
        for (let i = 0; i < n; i++) {
            const src = sourceTable.rows[i];
            if (!src) break;
            // Prefer cloning from the real header block (banner + Checks), not a
            // prior continuation that only carried a banner.
            const clone = src.cloneNode(true);
            clone.classList.remove('docx-selected-row');
            clone.setAttribute('data-cont-header', '1');
            clone.querySelectorAll('input, textarea, select').forEach(function (el) {
                el.removeAttribute('name');
            });
            targetTable.appendChild(clone);
        }
        targetTable.setAttribute('data-header-row-count', String(n));
        return n;
    }

    function dedupeDuplicateColumnHeaderRows() {
        // Keep one Checks/Methodology (or Sr.No/Acceptance) header per table fragment.
        document.querySelectorAll('.docx-preview table.docx-table:not(.header-table)').forEach(function (table) {
            let seenColHdr = false;
            Array.from(table.rows).forEach(function (row) {
                if (!rowLooksLikeColumnHeader(row)) return;
                if (!seenColHdr) {
                    seenColHdr = true;
                    return;
                }
                // Extra repeated header from Word soft-break / bad continuation clone.
                row.remove();
            });
        });
    }

    function lastHeaderRow(table) {
        const n = parseInt(table.getAttribute('data-header-row-count') || '0', 10);
        if (n > 0 && table.rows[n - 1]) return table.rows[n - 1];
        const computed = leadingHeaderRowCount(table);
        if (computed > 0 && table.rows[computed - 1]) return table.rows[computed - 1];
        return table.rows[0] || null;
    }

    function continuationTable(page, sourceTable) {
        const nextPage = followingA4Page(page) || createContinuationPage(page);
        const body = nextPage.querySelector('.docx-page-body');
        const tIdx = sourceTable.getAttribute('data-table-index') || '';
        const first = body.firstElementChild;
        if (
            first
            && first.matches('table.docx-table')
            && (first.getAttribute('data-table-index') || '') === tIdx
        ) {
            return first;
        }

        const table = document.createElement('table');
        table.className = sourceTable.className;
        if (tIdx !== '') table.setAttribute('data-table-index', tIdx);
        appendClonedHeaderRows(table, sourceTable);
        body.insertBefore(table, body.firstChild);
        return table;
    }

    function continuationTocCard(page) {
        const nextPage = followingA4Page(page) || createContinuationPage(page);
        const body = nextPage.querySelector('.docx-page-body');
        const first = body.firstElementChild;
        if (first && first.classList.contains('docx-toc-card') && first.getAttribute('data-toc-cont') === '1') {
            return first;
        }
        const card = document.createElement('div');
        card.className = 'docx-toc-card';
        card.setAttribute('data-toc-cont', '1');
        body.insertBefore(card, body.firstChild);
        return card;
    }

    function spillOneBlock(page) {
        const body = page.querySelector('.docx-page-body');
        if (!body) return false;
        const last = body.lastElementChild;
        if (!last) return false;

        // 9.0/9.1 keep-section: spill rows from the inner Installation table, or
        // move the whole keep block — never orphan headings on this sheet.
        if (last.classList && last.classList.contains('docx-keep-section')
            && last.getAttribute('data-keep-section') === 'requal-91') {
            const innerTable = last.querySelector('table.docx-table');
            // Keep block shares the sheet with earlier content (8.6 spill-over):
            // move the WHOLE unit forward. Never peel T12 rows off it — that left a
            // header-only keep hopping page-to-page with 9.0/9.1 stripped out.
            if (body.childElementCount > 1) {
                const nextPage = followingA4Page(page) || createContinuationPage(page);
                const nextBody = nextPage.querySelector('.docx-page-body');
                nextBody.insertBefore(last, nextBody.firstChild);
                return true;
            }
            // Alone on its sheet: split rows only for a genuinely tall table, and
            // never push a header-only keep to the next sheet.
            if (!innerTable || innerTable.rows.length <= 1) return false;
            const row = innerTable.rows[innerTable.rows.length - 1];
            const table = continuationTable(page, innerTable);
            const header = lastHeaderRow(table);
            if (header && header !== row) header.after(row);
            else table.appendChild(row);
            return true;
        }

        if (last.classList.contains('docx-toc-card')) {
            const lines = last.querySelectorAll(':scope > .docx-toc-line');
            if (lines.length > 1) {
                // Avoid creating a near-empty TOC sheet for a single leftover line
                // when overflow is only slight — keep the line on this page.
                const overflow = Math.max(0, body.scrollHeight - body.clientHeight);
                if (lines.length === 2 && overflow > 0 && overflow <= 40) {
                    return false;
                }
                const line = lines[lines.length - 1];
                const card = continuationTocCard(page);
                const firstLine = card.querySelector('.docx-toc-line');
                if (firstLine) card.insertBefore(line, firstLine);
                else card.appendChild(line);
                return true;
            }
        }

        if (last.matches('table.docx-table') && !last.classList.contains('header-table') && last.rows.length > 1) {
            const row = last.rows[last.rows.length - 1];
            // Unbreakable: one data row taller than the whole sheet.
            if (row.offsetHeight + 12 >= body.clientHeight && last.rows.length <= 2 && body.childElementCount === 1) {
                return false;
            }
            // Header + last data row still overfull (tall mitigation header): move the
            // whole leftover table so Sr.No 1 is not clipped under overflow:hidden.
            if (last.rows.length <= 2 && (pageIsOverfull(body) || contentOverflows(body))) {
                const nextPage = followingA4Page(page) || createContinuationPage(page);
                const nextBody = nextPage.querySelector('.docx-page-body');
                const tIdx = last.getAttribute('data-table-index') || '';
                const existing = tIdx
                    ? nextBody.querySelector('table.docx-table[data-table-index="' + tIdx + '"]')
                    : null;
                if (existing && existing !== last) {
                    // Merge remaining data rows after the continuation's full header block.
                    const hdrN = leadingHeaderRowCount(last);
                    const dataRows = Array.from(last.rows).slice(hdrN);
                    const hostHeader = lastHeaderRow(existing);
                    dataRows.reverse().forEach(function (r) {
                        if (hostHeader) hostHeader.after(r);
                        else insertRowBefore(existing, r, existing.rows[0] || null);
                    });
                    last.remove();
                } else {
                    // Keep 9.0/9.1 (or 9.2) glued to their table when the whole
                    // table moves — never leave headings clipped on the prior sheet.
                    const glued = [];
                    let prev = last.previousElementSibling;
                    while (prev && isRequalChecksHeading(prev)) {
                        glued.unshift(prev);
                        prev = prev.previousElementSibling;
                    }
                    nextBody.insertBefore(last, nextBody.firstChild);
                    for (let gi = glued.length - 1; gi >= 0; gi--) {
                        nextBody.insertBefore(glued[gi], last);
                    }
                }
                return true;
            }
            const table = continuationTable(page, last);
            const header = lastHeaderRow(table);
            if (header && header !== row) header.after(row);
            else table.appendChild(row);
            // If only header + Sr.No 1 remain on this sheet, move "1" with the
            // spilled block so it is not clipped under overflow:hidden.
            const hdrLeft = leadingHeaderRowCount(last);
            if (last.rows.length === hdrLeft + 1) {
                const maybeFirst = last.rows[hdrLeft];
                if (maybeFirst && maybeFirst.getAttribute('data-row-index') === '1') {
                    const hostRow2 = Array.from(table.rows).find(function (r) {
                        return r.getAttribute('data-row-index') === '2';
                    });
                    if (hostRow2) insertRowBefore(table, maybeFirst, hostRow2);
                    else if (header) header.after(maybeFirst);
                    else table.appendChild(maybeFirst);
                }
            }
            return true;
        }

        if (last.matches('table.docx-table') && last.rows.length <= 1) {
            const tIdx = last.getAttribute('data-table-index') || '';
            const next = followingA4Page(page);
            const moved = next
                ? next.querySelector('table.docx-table[data-table-index="' + tIdx + '"]')
                : null;
            const named = last.querySelector('[name]');
            if (!named && moved && moved.rows.length > 1) {
                last.remove();
                return true;
            }
            if (body.children.length === 1) return false;
        }

        // History / recommendation textboxes: spill one line at a time so
        // 8.5 content is never clipped under the A4 footer.
        if (last.classList && last.classList.contains('docx-textbox')) {
            const lines = last.querySelectorAll(':scope > .docx-textbox-line, :scope > p');
            if (lines.length > 1) {
                const line = lines[lines.length - 1];
                const nextPage = followingA4Page(page) || createContinuationPage(page);
                const nextBody = nextPage.querySelector('.docx-page-body');
                let cont = nextBody.firstElementChild;
                if (!(cont && cont.classList && cont.classList.contains('docx-textbox')
                    && (cont.getAttribute('data-txbx') || '') === (last.getAttribute('data-txbx') || ''))) {
                    cont = document.createElement('div');
                    cont.className = last.className || 'docx-textbox';
                    const tx = last.getAttribute('data-txbx');
                    if (tx !== null) cont.setAttribute('data-txbx', tx);
                    nextBody.insertBefore(cont, nextBody.firstChild);
                }
                cont.insertBefore(line, cont.firstChild);
                if (!last.querySelector('.docx-textbox-line, p')) last.remove();
                return true;
            }
        }

        // Never refuse to spill an overfull last block — with overflow:hidden a
        // tall History textbox / notes box used to stay clipped forever.
        // Orphan 9.x headings (table already on a later sheet): park them in
        // front of their table instead of dumping onto a random next sheet.
        if (isRequalChecksHeading(last)) {
            const t12 = document.querySelector('.docx-preview table.docx-table[data-table-index="12"]');
            const t13 = document.querySelector('.docx-preview table.docx-table[data-table-index="13"]');
            const text = headingTextOf(last);
            let target = null;
            if (/\b9\.0\b|\b9\.1\b/.test(text) && t12) target = t12;
            else if (/\b9\.2\b|GENERAL\s+CHECKS/.test(text) && t13) target = t13;
            if (target && target.closest('.docx-page-body')) {
                const tb = target.closest('.docx-page-body');
                tb.insertBefore(last, target);
                return true;
            }
        }
        const nextPage = followingA4Page(page) || createContinuationPage(page);
        const nextBody = nextPage.querySelector('.docx-page-body');
        if (last.matches && last.matches('table.docx-table')) {
            const glued = [];
            let prev = last.previousElementSibling;
            while (prev && isRequalChecksHeading(prev)) {
                glued.unshift(prev);
                prev = prev.previousElementSibling;
            }
            nextBody.insertBefore(last, nextBody.firstChild);
            for (let gi = glued.length - 1; gi >= 0; gi--) {
                nextBody.insertBefore(glued[gi], last);
            }
            return true;
        }
        nextBody.insertBefore(last, nextBody.firstChild);
        return true;
    }

    function contentOverflows(pageBody) {
        if (!pageBody) return false;
        const box = pageBody.getBoundingClientRect();
        const limit = box.top + Math.max(pageBody.clientHeight || 0, box.height || 0) + 6;
        return pageEdge(pageBody) > limit;
    }

    function pageEdge(pageBody) {
        const last = pageBody.lastElementChild;
        if (!last) return pageBody.getBoundingClientRect().top;
        if (last.matches && last.matches('table.docx-table') && last.rows && last.rows.length) {
            return last.rows[last.rows.length - 1].getBoundingClientRect().bottom;
        }
        if (last.classList && last.classList.contains('docx-toc-card')) {
            const lines = last.querySelectorAll('.docx-toc-line');
            if (lines.length) return lines[lines.length - 1].getBoundingClientRect().bottom;
        }
        if (last.classList && last.classList.contains('docx-textbox')) {
            const lines = last.querySelectorAll('.docx-textbox-line, p');
            if (lines.length) return lines[lines.length - 1].getBoundingClientRect().bottom;
        }
        return last.getBoundingClientRect().bottom;
    }

    function pageContentUsed(pageBody) {
        if (!pageBody) return 0;
        const last = pageBody.lastElementChild;
        if (!last) return 0;
        const top = pageBody.getBoundingClientRect().top;
        return Math.max(0, pageEdge(pageBody) - top);
    }

    function pageFreeSpace(pageBody) {
        if (!pageBody) return 0;
        // Use the real body box on the A4 sheet, not content-sized height.
        const box = pageBody.getBoundingClientRect();
        const height = Math.max(pageBody.clientHeight || 0, box.height || 0);
        if (height < 40) {
            return 0;
        }
        return height - pageContentUsed(pageBody) - 8;
    }

    function pageIsOverfull(pageBody) {
        if (!pageBody) return false;
        const box = pageBody.getBoundingClientRect();
        const limit = box.top + Math.max(pageBody.clientHeight || 0, box.height || 0) - 2;
        return pageEdge(pageBody) > limit;
    }

    function rowLabel(row) {
        return Array.from(row.cells || []).map(function (cell) {
            return (cell.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
        }).join('|');
    }

    function dropEmptySheet(page) {
        const body = page.querySelector('.docx-page-body');
        if (!body) return false;
        Array.from(body.children).forEach(function (el) {
            if (el.matches && el.matches('table.docx-table') && el.rows.length <= 1 && !el.querySelector('[name]')) {
                el.remove();
            }
            if (el.classList && el.classList.contains('docx-toc-card') && !el.querySelector('.docx-toc-line')) {
                el.remove();
            }
        });
        if (body.children.length) return false;
        const prev = page.previousElementSibling;
        if (prev && prev.classList.contains('docx-page-break-indicator')) prev.remove();
        page.remove();
        return true;
    }

    function pullBlockBack(body, nextBody, el) {
        const marker = el.nextSibling;
        body.appendChild(el);
        if (pageIsOverfull(body)) {
            if (marker) nextBody.insertBefore(el, marker);
            else nextBody.appendChild(el);
            return false;
        }
        return true;
    }

    function isSparseBannerTable(table) {
        if (!table || !table.rows || table.rows.length > 3) {
            return false;
        }
        const text = (table.innerText || table.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
        return /major\s*components|mechanical\s*adjustment|machine\s*performance|general\s*checks/i.test(text);
    }

    function rowFullyInsideBody(body, row) {
        if (!body || !row) return false;
        const box = body.getBoundingClientRect();
        const limit = box.top + Math.max(body.clientHeight || 0, box.height || 0) - 4;
        return row.getBoundingClientRect().bottom <= limit;
    }

    function isHvacChecklistTable(table) {
        if (!table || !table.querySelector) return false;
        // 9.1 Installation (12), 9.2 Major components (13), 9.3 Functional (14):
        // never pull these rows onto a prior sheet (clips 9.0/9.1 headings).
        const tIdx = table.getAttribute && table.getAttribute('data-table-index');
        if (tIdx === '12' || tIdx === '13' || tIdx === '14') return true;
        const text = (table.innerText || table.textContent || '').replace(/\s+/g, ' ').toLowerCase();
        if (!/acceptance\s*criteria/.test(text)) return false;
        return /sr\.?\s*no|identification|methodology|observation\s*\/\s*result|mechanical\s*adjustment|machine\s*performance|major\s*components|installation|done\s*by\s*\(?\s*engineering/.test(text);
    }

    function pullTableRowBack(body, sourceTable) {
        // Att08: skip full 2-row header; first pullable data row is after Done On/Due On.
        const hdrN = leadingHeaderRowCount(sourceTable);
        const row = sourceTable.rows[hdrN] || null;
        if (!row) {
            if (!sourceTable.querySelector('[name]')) sourceTable.remove();
            return true;
        }
        // Never pull 9.1 / 9.2 / 9.3 checklist rows back onto a prior sheet —
        // that clips Mechanical/Electrical rows and causes Sr.No 7→13 jumps.
        const lastEl = body && body.lastElementChild;
        if (
            isHvacChecklistTable(sourceTable)
            || (lastEl && lastEl.matches && lastEl.matches('table.docx-table') && isHvacChecklistTable(lastEl))
        ) {
            return false;
        }
        // Never pull a row back if it clearly won't fit — that recreates the
        // Sr.No 7→10 jump (rows 8–9 get clipped under overflow:hidden).
        const free = pageFreeSpace(body);
        const need = Math.max(row.offsetHeight || 0, 28) + 40;
        if (free < need + 28) {
            return false;
        }
        // Never pull Sr.No 1 back onto the previous sheet when 2+ already
        // live on this continuation — that clips "1" under overflow:hidden.
        const pullIdx = row.getAttribute('data-row-index') || '';
        if (pullIdx === '1') {
            const hasLater = Array.from(sourceTable.rows || []).some(function (r) {
                const n = parseInt(r.getAttribute('data-row-index') || '-1', 10);
                return n >= 2;
            });
            if (hasLater) {
                return false;
            }
        }
        const last = body.lastElementChild;
        const same = last && last.matches && last.matches('table.docx-table')
            && (last.getAttribute('data-table-index') || '') === (sourceTable.getAttribute('data-table-index') || '');
        if (same && rowLabel(row) && last.rows[0] && rowLabel(row) === rowLabel(last.rows[0])) {
            row.remove();
            return true;
        }
        // Prefer moving whole table when it fits the free band on this sheet.
        if (!same && sourceTable.offsetHeight + 6 <= pageFreeSpace(body)) {
            return pullBlockBack(body, sourceTable.parentNode, sourceTable);
        }
        let target = same ? last : null;
        let created = false;
        if (!target) {
            target = document.createElement('table');
            target.className = sourceTable.className;
            const tIdx = sourceTable.getAttribute('data-table-index') || '';
            if (tIdx) target.setAttribute('data-table-index', tIdx);
            appendClonedHeaderRows(target, sourceTable);
            body.appendChild(target);
            created = true;
        }
        // Keep Mechanical Adjustment sequence contiguous (…7,8,9,10…).
        // Do not pull a non-consecutive row onto this sheet.
        const hostData = Math.max(0, target.rows.length - hdrN);
        // Already-dense host: do not squeeze another tall HVAC row into a
        // remaining sliver — that is exactly the clipped 8/9 failure mode.
        if (hostData >= 5 && free < 160) {
            if (created) target.remove();
            return false;
        }
        if (target.rows.length > hdrN) {
            const lastHost = target.rows[target.rows.length - 1];
            const lastIdx = parseInt(lastHost.getAttribute('data-row-index') || '-1', 10);
            const pullN = parseInt(pullIdx || '-1', 10);
            if (lastIdx >= 0 && pullN >= 0 && pullN !== lastIdx + 1) {
                if (created) target.remove();
                return false;
            }
        }
        const marker = row.nextSibling;
        // Always append at the end of the host table. Prepending after the header
        // (hostHeader.after) reverses Sl.No order across spill→pull cycles (11,10,9…).
        target.appendChild(row);
        if (
            pageIsOverfull(body)
            || contentOverflows(body)
            || pageFreeSpace(body) < 12
            || body.scrollHeight > body.clientHeight + 2
            || !rowFullyInsideBody(body, row)
        ) {
            if (marker && marker.parentNode) marker.parentNode.insertBefore(row, marker);
            else sourceTable.appendChild(row);
            if (created) target.remove();
            return false;
        }
        const leftoverHead = sourceTable.rows.length <= hdrN && !sourceTable.querySelector('[name]');
        if (!sourceTable.rows.length || leftoverHead) sourceTable.remove();
        return true;
    }

    function isTocTitleHeading(el) {
        if (!el || (el.classList && el.classList.contains('docx-toc-card'))) {
            return false;
        }
        // Title inside the TOC card must stay with the card.
        if (el.classList && el.classList.contains('docx-toc-heading')) {
            return true;
        }
        if (el.closest && el.closest('.docx-toc-card')) {
            return false;
        }
        const text = ((el.dataset && el.dataset.headingText) || el.innerText || el.textContent || '')
            .replace(/\s+/g, ' ')
            .replace(/^[📋\s]+/, '')
            .trim()
            .toUpperCase();
        return /^(TABLE OF CONTENTS|TABLE OF CONTENT|CONTENTS)$/.test(text);
    }

    function pageHasTocCard(page) {
        if (!page || !page.querySelector) {
            return false;
        }
        if (page.querySelector('.docx-page-body .docx-toc-card, .docx-page-body .docx-toc-line')) {
            return true;
        }
        // Orphan "TABLE OF CONTENTS" heading still counts as TOC page content
        // so 1.0 OBJECTIVE is never pulled under it.
        const body = page.querySelector('.docx-page-body');
        if (!body) return false;
        return Array.from(body.children).some(isTocTitleHeading);
    }

    function removeOrphanTocTitleAboveBody() {
        // Word: TOC title is only on the TOC sheet. Strip any free-floating
        // "TABLE OF CONTENTS" heading that landed above 1.0 OBJECTIVE.
        document.querySelectorAll('.docx-preview .docx-a4-page').forEach(function (page) {
            const body = page.querySelector('.docx-page-body');
            if (!body) return;
            const kids = Array.from(body.children);
            const hasBodySection = kids.some(function (el) {
                if (el.classList && el.classList.contains('docx-toc-card')) return false;
                const t = ((el.dataset && el.dataset.headingText) || el.innerText || el.textContent || '')
                    .replace(/\s+/g, ' ')
                    .trim();
                return /^\d+(?:\.\d+)+\s+\S/.test(t) || /^OBJECTIVE\b/i.test(t);
            });
            if (!hasBodySection) return;
            kids.forEach(function (el) {
                if (el.classList && el.classList.contains('docx-toc-card')) return;
                if (el.closest && el.closest('.docx-toc-card')) return;
                if (isTocTitleHeading(el)) el.remove();
            });
        });
    }

    function isBodySectionBlock(el) {
        if (!el) {
            return false;
        }
        if (el.classList && el.classList.contains('docx-toc-card')) {
            return false;
        }
        if (isTocTitleHeading(el)) {
            return false;
        }
        const text = (el.dataset && el.dataset.headingText)
            || (el.innerText || el.textContent || '');
        const flat = String(text).replace(/\s+/g, ' ').trim();
        // 1.0 OBJECTIVE / 6.0 PRE-REQUISITES must stay off the TOC sheet.
        return /^\d+(?:\.\d+)+\s+\S/.test(flat);
    }

    function isMajorTailHeading(el) {
        if (!el) return false;
        const text = ((el.dataset && el.dataset.headingText) || el.innerText || el.textContent || '').toUpperCase();
        return /\b(DEVIATIONS?\s*\(IF ANY\)|LIST OF ATTACHMENT|SUMMARY\s*&\s*CONCLUSION|REFERENCES|ABBREVIATIONS|REVISION\s+HISTORY|POST-APPROVAL)\b/.test(text);
    }

    function headingTextOf(el) {
        if (!el) return '';
        return ((el.dataset && el.dataset.headingText) || el.innerText || el.textContent || '')
            .replace(/\s+/g, ' ')
            .trim()
            .toUpperCase();
    }

    function isRequalChecksHeading(el) {
        const text = headingTextOf(el);
        return /\b(9\.0\s+REQUALIFICATION\s+CHECKS|9\.1\s+INSTALLATION\s+RE-?QUALIFICATION|9\.2\s+GENERAL\s+CHECKS|GENERAL\s+CHECKS\s*:\s*VERIFICATION\s+OF\s+MAJOR\s+COMPONENTS|9\.3\s+OPERATIONAL\s+QUALIFICATION|OPERATIONAL\s+QUALIFICATION\s+CHECKS\s*:\s*FUNCTIONAL)\b/.test(text);
    }

    function nextBodyContentHeight(nextBody) {
        if (!nextBody) return 0;
        return Array.from(nextBody.children).reduce(function (sum, el) {
            return sum + (el.offsetHeight || 0);
        }, 0);
    }

    function collectRequalHeadingBlock(first) {
        // 9.0 (+ 9.1) + Installation table, or 9.2 + Major-components table, or 9.3 + intro + FT table.
        const blocks = [first];
        let walk = first.nextElementSibling;
        while (walk && isRequalChecksHeading(walk)) {
            blocks.push(walk);
            walk = walk.nextElementSibling;
        }
        // Optional intro paragraph under 9.3 before the FT table.
        while (
            walk
            && !(walk.matches && walk.matches('table.docx-table'))
            && !isRequalChecksHeading(walk)
            && !isMajorTailHeading(walk)
            && !isBodySectionBlock(walk)
        ) {
            blocks.push(walk);
            walk = walk.nextElementSibling;
        }
        if (walk && walk.matches && walk.matches('table.docx-table') && !walk.classList.contains('header-table')) {
            blocks.push(walk);
        }
        return blocks;
    }

    function findRequalBodyHeading(re) {
        // Prefer real section titles near protocol body — never TOC card lines.
        const nodes = Array.from(document.querySelectorAll(
            '.docx-preview p.docx-heading-target, .docx-preview p.docx-para.docx-heading-target, .docx-preview p.docx-para'
        ));
        let best = null;
        for (let i = 0; i < nodes.length; i++) {
            const el = nodes[i];
            if (el.closest && el.closest('.docx-toc-card')) continue;
            if (el.closest && el.closest('table.docx-table')) continue;
            if (!re.test(headingTextOf(el))) continue;
            best = el;
            // Keep scanning so a later body heading wins over an earlier stray.
        }
        return best;
    }

    function forceRequalInstallationOwnPage() {
        // HARD guarantee: 9.0 + 9.1 + Installation table (T12) sit alone at the
        // top of a sheet BEFORE 9.2 / Machine-frame (T13). Pages may grow;
        // content must never stay clipped under 8.6 or skipped before T13.
        const t12 = document.querySelector('.docx-preview table.docx-table[data-table-index="12"]');
        if (!t12 || !document.body.contains(t12)) return;
        const h90 = findRequalBodyHeading(/\b9\.0\s+REQUALIFICATION\s+CHECKS\b/);
        const h91 = findRequalBodyHeading(/\b9\.1\s+INSTALLATION\s+RE-?QUALIFICATION\b/);
        const h92 = findRequalBodyHeading(/\b9\.2\s+GENERAL\s+CHECKS\b|\bGENERAL\s+CHECKS\s*:\s*VERIFICATION\s+OF\s+MAJOR\s+COMPONENTS\b/);
        const t13 = document.querySelector('.docx-preview table.docx-table[data-table-index="13"]');

        function glueBefore(node, target) {
            if (!node || !target || !document.body.contains(target)) return;
            if (node.nextElementSibling === target) return;
            target.parentNode.insertBefore(node, target);
        }

        // Keep wrapper moves as one unit when present.
        const keep = t12.closest('.docx-keep-section[data-keep-section="requal-91"]');
        if (!keep) {
            glueBefore(h91, t12);
            glueBefore(h90, (h91 && document.body.contains(h91)) ? h91 : t12);
        }

        const blockRoot = keep || ((h90 && document.body.contains(h90)) ? h90 : ((h91 && document.body.contains(h91)) ? h91 : t12));
        const page = t12.closest('.docx-a4-page');
        const body = page && page.querySelector('.docx-page-body');
        if (!page || !body || !blockRoot) return;

        const atTop = body.firstElementChild === (keep || blockRoot);
        const orderOk = !t13 || !!(t12.compareDocumentPosition(t13) & Node.DOCUMENT_POSITION_FOLLOWING);
        const hasBothHeadings = !!(h90 && document.body.contains(h90) && h91 && document.body.contains(h91));
        const glued = keep
            ? (keep.contains(t12) && (!h90 || keep.contains(h90)) && (!h91 || keep.contains(h91)))
            : (hasBothHeadings && h90.nextElementSibling === h91 && h91.nextElementSibling === t12);

        if (atTop && orderOk && glued) {
            page.setAttribute('data-requal-91-lock', '1');
            // 9.2 heading directly in front of T13 (may share later sheets).
            if (h92 && t13) glueBefore(h92, t13);
            return;
        }

        const t13Page = t13 ? t13.closest('.docx-a4-page') : null;
        const anchor = t13Page || page;
        const prev = precedingA4Page(anchor);
        const host = prev ? createContinuationPage(prev) : createContinuationPage(anchor);
        const nb = host.querySelector('.docx-page-body');
        if (!nb) return;

        if (keep) {
            nb.appendChild(keep);
        } else {
            if (h90 && document.body.contains(h90)) nb.appendChild(h90);
            if (h91 && document.body.contains(h91)) nb.appendChild(h91);
            nb.appendChild(t12);
        }
        host.setAttribute('data-requal-91-lock', '1');
        if (h92 && t13 && document.body.contains(t13)) {
            glueBefore(h92, t13);
        }
    }

    function reattachRequalCheckHeadings() {
        forceRequalInstallationOwnPage();
    }

    function pullOneBack(page) {
        const body = page.querySelector('.docx-page-body');
        const next = followingA4Page(page);
        if (!body || !next || pageFreeSpace(body) < 24) return false;
        const nextBody = next.querySelector('.docx-page-body');
        const first = nextBody && nextBody.firstElementChild;
        if (!first) return false;
        // Never pull 9.0/9.1/9.2/9.3 (or their glued table) onto the previous
        // sheet — free space after 8.6 used to yank headings alone, then
        // overflow:hidden clipped REQUALIFICATION CHECKS entirely. Pages may grow.
        if (next.hasAttribute('data-requal-91-lock') || page.hasAttribute('data-requal-91-lock')) {
            return false;
        }
        if (first.getAttribute && first.getAttribute('data-keep-section') === 'requal-91') {
            return false;
        }
        if (isRequalChecksHeading(first)) {
            return false;
        }
        if (
            first.matches
            && first.matches('table.docx-table')
            && (first.getAttribute('data-table-index') === '12' || first.getAttribute('data-table-index') === '13')
        ) {
            return false;
        }
        // Tail sections (10.0–16.0): pull one section at a time (heading + body
        // until the next major heading). Requiring the *entire next page* meant
        // SUMMARY would not absorb REFERENCES when ABBREVIATIONS shared that
        // sheet — leaving large blank bands.
        if (isMajorTailHeading(first)) {
            const free = pageFreeSpace(body);
            const section = [first];
            let walk = first.nextElementSibling;
            while (walk && !isMajorTailHeading(walk)) {
                section.push(walk);
                walk = walk.nextElementSibling;
            }
            const sectionNeed = section.reduce(function (sum, el) {
                return sum + (el.offsetHeight || 0);
            }, 0) + 12;
            if (sectionNeed > 0 && sectionNeed <= free) {
                let moved = false;
                for (let si = 0; si < section.length; si++) {
                    if (!pullBlockBack(body, nextBody, section[si])) break;
                    moved = true;
                }
                if (moved) dropEmptySheet(next);
                return moved;
            }
            // Whole next page still OK when it is only this one short section.
            const need = nextBodyContentHeight(nextBody) + 12;
            if (need > 0 && need <= free) {
                let moved = false;
                while (nextBody.firstElementChild) {
                    if (!pullBlockBack(body, nextBody, nextBody.firstElementChild)) break;
                    moved = true;
                }
                if (moved) dropEmptySheet(next);
                return moved;
            }
            return false;
        }
        // Never pull protocol body onto the Table of Contents page.
        if (pageHasTocCard(page) && !first.classList.contains('docx-toc-card')) {
            return false;
        }
        if (first.matches('table.docx-table') && !first.classList.contains('header-table')) {
            // Installation / major-components / FT tables stay on their sheets.
            if (isHvacChecklistTable(first)) return false;
            return pullTableRowBack(body, first);
        }
        if (first.classList.contains('docx-toc-card')) {
            const last = body.lastElementChild;
            if (last && last.classList.contains('docx-toc-card')) {
                // Whole continuation card fits — pull it back.
                if (first.offsetHeight + 6 <= pageFreeSpace(body)) {
                    return pullBlockBack(body, nextBody, first);
                }
                // Otherwise pull one TOC line at a time into the previous card.
                const line = first.querySelector(':scope > .docx-toc-line');
                if (!line) {
                    first.remove();
                    return true;
                }
                if (line.offsetHeight + 6 > pageFreeSpace(body)) {
                    return false;
                }
                const marker = line.nextSibling;
                last.appendChild(line);
                if (pageIsOverfull(body)) {
                    if (marker) first.insertBefore(line, marker);
                    else first.appendChild(line);
                    return false;
                }
                if (!first.querySelector('.docx-toc-line')) first.remove();
                return true;
            }
        }
        if (isBodySectionBlock(first) && pageHasTocCard(page)) {
            return false;
        }
        // Other numbered section titles: only pull when the following table also
        // fits — never leave a lone 9.x heading clipped under the footer.
        if (isBodySectionBlock(first)) {
            const blocks = collectRequalHeadingBlock(first);
            if (blocks.length > 1) {
                const need = blocks.reduce(function (sum, el) {
                    return sum + (el.offsetHeight || 0);
                }, 0) + 16;
                if (need > pageFreeSpace(body)) return false;
                let moved = false;
                for (let bi = 0; bi < blocks.length; bi++) {
                    if (!pullBlockBack(body, nextBody, blocks[bi])) break;
                    moved = true;
                }
                if (moved) dropEmptySheet(next);
                return moved;
            }
            return false;
        }
        const freeNow = pageFreeSpace(body);
        // Do not leave a short title alone when the next block still fits.
        // With large free bands, always try — pullBlockBack reverts if overfull.
        if (first.offsetHeight + 6 <= freeNow || freeNow >= 100) {
            return pullBlockBack(body, nextBody, first);
        }
        return false;
    }

    function isPureTocPage(page) {
        const body = page && page.querySelector('.docx-page-body');
        if (!body || !pageHasTocCard(page)) {
            return false;
        }
        const kids = Array.from(body.children);
        if (!kids.length) {
            return false;
        }
        return kids.every(function (el) {
            return el.classList && el.classList.contains('docx-toc-card');
        });
    }

    function tocPageIsSparse(page) {
        if (!isPureTocPage(page)) {
            return false;
        }
        const body = page.querySelector('.docx-page-body');
        const lines = body.querySelectorAll('.docx-toc-line');
        // One leftover TOC row (e.g. "10.0 post approval") must not own a whole A4 sheet.
        if (!(lines.length > 0 && lines.length <= 4)) {
            return false;
        }
        // Only fold continuation orphans — never crush the first TOC page.
        const prev = precedingA4Page(page);
        return !!(prev && pageHasTocCard(prev));
    }

    function lastTocCardOn(page) {
        const body = page && page.querySelector('.docx-page-body');
        if (!body) {
            return null;
        }
        const cards = body.querySelectorAll('.docx-toc-card');
        return cards.length ? cards[cards.length - 1] : null;
    }

    function pageOverflowPx(pageBody) {
        if (!pageBody) {
            return 0;
        }
        const box = pageBody.getBoundingClientRect();
        const limit = box.top + Math.max(pageBody.clientHeight || 0, box.height || 0);
        return Math.max(0, pageEdge(pageBody) - limit);
    }

    function compactOrphanTocPages() {
        // Fold near-empty TOC continuation sheets back onto the previous TOC page.
        // Body content must never fill a TOC sheet, so orphans otherwise stay blank.
        let safety = 0;
        let changed = true;
        while (changed && safety < 24) {
            changed = false;
            safety++;
            const pages = Array.from(document.querySelectorAll('.docx-preview .docx-a4-page'));
            for (let i = 1; i < pages.length; i++) {
                const page = pages[i];
                if (!tocPageIsSparse(page)) {
                    continue;
                }
                const prev = precedingA4Page(page);
                if (!prev || !pageHasTocCard(prev)) {
                    continue;
                }
                const prevBody = prev.querySelector('.docx-page-body');
                const body = page.querySelector('.docx-page-body');
                const target = lastTocCardOn(prev);
                if (!target || !prevBody || !body) {
                    continue;
                }

                const lines = Array.from(body.querySelectorAll('.docx-toc-line'));
                if (!lines.length) {
                    dropEmptySheet(page);
                    changed = true;
                    break;
                }

                lines.forEach(function (line) {
                    target.appendChild(line);
                });
                Array.from(body.querySelectorAll('.docx-toc-card')).forEach(function (card) {
                    if (!card.querySelector('.docx-toc-line')) {
                        card.remove();
                    }
                });

                // Prefer a slightly tight previous TOC page over a blank continuation sheet.
                const softLimit = 52;
                let guard = 0;
                while (pageOverflowPx(prevBody) > softLimit && guard < 40) {
                    guard++;
                    const kept = target.querySelectorAll(':scope > .docx-toc-line');
                    if (kept.length <= 1) {
                        break;
                    }
                    const lastLine = kept[kept.length - 1];
                    let card = body.querySelector('.docx-toc-card');
                    if (!card) {
                        card = document.createElement('div');
                        card.className = 'docx-toc-card';
                        card.setAttribute('data-toc-cont', '1');
                        body.appendChild(card);
                    }
                    card.insertBefore(lastLine, card.firstChild);
                }

                if (!body.querySelector('.docx-toc-line')) {
                    dropEmptySheet(page);
                    changed = true;
                    break;
                }

                // Widow/orphan: 1–2 leftover TOC lines must not own a blank A4 sheet.
                const leftover = body.querySelectorAll('.docx-toc-line').length;
                if (leftover > 0 && leftover <= 2) {
                    Array.from(body.querySelectorAll('.docx-toc-line')).forEach(function (line) {
                        target.appendChild(line);
                    });
                    Array.from(body.querySelectorAll('.docx-toc-card')).forEach(function (card) {
                        card.remove();
                    });
                    dropEmptySheet(page);
                    changed = true;
                    break;
                }
            }
        }
    }

    function fillPageGaps() {
        let page = document.querySelector('.docx-preview .docx-a4-page');
        let pulls = 0;
        while (page && pulls < 1200) {
            const next = followingA4Page(page);
            if (!next) break;
            const body = page.querySelector('.docx-page-body');
            const free = pageFreeSpace(body);
            if (free < 24 || !pullOneBack(page)) {
                // Sparse title pages (Major components :) must keep pulling rows.
                const last = body && body.lastElementChild;
                if (
                    free >= 60
                    && last
                    && last.matches
                    && last.matches('table.docx-table')
                    && isSparseBannerTable(last)
                ) {
                    const cont = next.querySelector(
                        '.docx-page-body table.docx-table[data-table-index="'
                        + (last.getAttribute('data-table-index') || '') + '"]'
                    ) || next.querySelector('.docx-page-body > table.docx-table');
                    if (cont && pullTableRowBack(body, cont)) {
                        pulls++;
                        dropEmptySheet(next);
                        continue;
                    }
                }
                // Half-empty sheets (Deviations/References/Revision alone): keep
                // pulling following section blocks while free space remains.
                if (free >= 120) {
                    let local = 0;
                    let progressed = false;
                    while (local++ < 30 && pageFreeSpace(body) >= 64 && pullOneBack(page)) {
                        pulls++;
                        progressed = true;
                        dropEmptySheet(followingA4Page(page));
                    }
                    if (progressed) continue;
                }
                page = next;
                continue;
            }
            pulls++;
            dropEmptySheet(next);
        }
    }

    function packSparsePages() {
        // Extra passes so short tail sections (10–15) share sheets instead of
        // leaving large unprofessional white bands above the footer.
        let guard = 0;
        while (guard++ < 6) {
            let moved = 0;
            const pages = Array.from(document.querySelectorAll('.docx-preview .docx-a4-page'));
            for (let i = 0; i < pages.length; i++) {
                const page = pages[i];
                if (!document.body.contains(page)) continue;
                const body = page.querySelector('.docx-page-body');
                if (!body || pageFreeSpace(body) < 100) continue;
                let local = 0;
                while (local++ < 40 && pageFreeSpace(body) >= 56 && pullOneBack(page)) {
                    moved++;
                    const nxt = followingA4Page(page);
                    if (nxt) dropEmptySheet(nxt);
                }
            }
            if (!moved) break;
        }
    }

    function moveBodyOffTocPages() {
        // 1.0 OBJECTIVE (and later sections) must start after the TOC sheet.
        Array.from(document.querySelectorAll('.docx-preview .docx-a4-page')).forEach(function (page) {
            if (!pageHasTocCard(page)) {
                return;
            }
            const body = page.querySelector('.docx-page-body');
            if (!body) {
                return;
            }
            const movers = Array.from(body.children).filter(function (el) {
                if (el.classList && el.classList.contains('docx-toc-card')) {
                    return false;
                }
                if (el.querySelector && el.querySelector('.docx-toc-line, .docx-toc-card')) {
                    return false;
                }
                return true;
            });
            if (!movers.length) {
                return;
            }
            const next = followingA4Page(page) || createContinuationPage(page);
            const nextBody = next.querySelector('.docx-page-body');
            const frag = document.createDocumentFragment();
            movers.forEach(function (el) {
                frag.appendChild(el);
            });
            nextBody.insertBefore(frag, nextBody.firstChild);
        });
    }

    function tableRowIndexes(table) {
        return Array.from(table.rows || []).map(function (r) {
            return parseInt(r.getAttribute('data-row-index') || '-1', 10);
        });
    }

    function spillClippedTableRows() {
        // After pack/pull, any data row / textbox line / block whose bottom sits
        // past the A4 body clip must move forward — History (8.5), 9.1 rows, and
        // REFERENCES bullets must never stay hidden under overflow:hidden.
        let guard = 0;
        let moved = true;
        while (moved && guard++ < 120) {
            moved = false;
            const pages = Array.from(document.querySelectorAll('.docx-preview .docx-a4-page'));
            for (let pi = 0; pi < pages.length; pi++) {
                const page = pages[pi];
                if (!document.body.contains(page)) continue;
                const body = page.querySelector('.docx-page-body');
                if (!body) continue;
                if (!(pageIsOverfull(body) || contentOverflows(body))) continue;
                if (spillOneBlock(page)) {
                    moved = true;
                    break;
                }
            }
        }
    }

    function repairSplitTableFirstDataRows() {
        const tablesByIndex = {};
        document.querySelectorAll('.docx-preview table.docx-table[data-table-index]').forEach(function (table) {
            if (table.classList.contains('header-table')) return;
            const tIdx = table.getAttribute('data-table-index') || '';
            if (!tIdx) return;
            if (!tablesByIndex[tIdx]) tablesByIndex[tIdx] = [];
            tablesByIndex[tIdx].push(table);
        });

        Object.keys(tablesByIndex).forEach(function (tIdx) {
            const parts = tablesByIndex[tIdx];
            if (!parts.length) return;

            const rowMap = {};
            parts.forEach(function (table) {
                Array.from(table.rows || []).forEach(function (row) {
                    const ri = row.getAttribute('data-row-index');
                    if (ri === null || ri === '') return;
                    if (!rowMap[ri]) rowMap[ri] = [];
                    rowMap[ri].push({ table: table, row: row });
                });
            });

            // Host = fragment that shows 2+ but is missing 1 (classic clipped split).
            let host = null;
            parts.forEach(function (table) {
                const indexes = tableRowIndexes(table);
                if (indexes.indexOf(2) !== -1 && indexes.indexOf(1) === -1) {
                    host = table;
                }
            });
            if (!host) {
                parts.forEach(function (table) {
                    if (tableRowIndexes(table).indexOf(2) !== -1) host = table;
                });
            }

            // Case A: header+only-row1 stuck on previous sheet while 2+ are on next.
            parts.forEach(function (table) {
                const indexes = tableRowIndexes(table);
                const onlyFirstData = indexes.filter(function (n) { return n > 0; });
                if (!(indexes.indexOf(0) !== -1 && onlyFirstData.length === 1 && onlyFirstData[0] === 1)) {
                    return;
                }
                const page = table.closest('.docx-a4-page');
                const next = page ? followingA4Page(page) : null;
                const cont = next
                    ? next.querySelector('.docx-page-body table.docx-table[data-table-index="' + tIdx + '"]')
                    : null;
                if (!cont || cont === table) return;
                const contIndexes = tableRowIndexes(cont);
                if (contIndexes.indexOf(2) === -1) return;
                host = cont;
            });

            if (!host) return;

            const firstData = rowMap['1'] && rowMap['1'][0];
            if (!firstData) return;
            if (firstData.table === host) {
                const row2 = Array.from(host.rows).find(function (r) {
                    return r.getAttribute('data-row-index') === '2';
                });
                if (row2 && firstData.row.nextElementSibling !== row2) {
                    insertRowBefore(host, firstData.row, row2);
                }
                return;
            }

            const row2 = Array.from(host.rows).find(function (r) {
                return r.getAttribute('data-row-index') === '2';
            });
            if (row2) insertRowBefore(host, firstData.row, row2);
            else {
                const hostHeader = host.rows[0];
                if (hostHeader) hostHeader.after(firstData.row);
                else insertRowBefore(host, firstData.row, host.rows[0] || null);
            }

            if (firstData.table.rows.length <= 1 && !firstData.table.querySelector('[name]')) {
                firstData.table.remove();
            }
        });
    }

    function paginateDocxPreview() {
        document.querySelectorAll('.docx-preview textarea').forEach(function (el) {
            el.style.height = 'auto';
            el.style.height = el.scrollHeight + 'px';
        });
        const first = document.querySelector('.docx-preview .docx-a4-page');
        if (!first) return;
        const firstBody = first.querySelector('.docx-page-body');
        if (!firstBody || firstBody.clientHeight < 80) return;

        // Keep protocol body off the TOC page before measuring free space.
        moveBodyOffTocPages();
        removeOrphanTocTitleAboveBody();

        // Pull content into empty bands first.
        fillPageGaps();

        let page = document.querySelector('.docx-preview .docx-a4-page');
        let moves = 0;
        while (page && moves < 250) {
            const pageBody = page.querySelector('.docx-page-body');
            if (!contentOverflows(pageBody) && !pageIsOverfull(pageBody)) {
                page = followingA4Page(page);
                continue;
            }
            const before = pageBody.scrollHeight;
            const beforeLast = pageBody.lastElementChild;
            const beforeRow = beforeLast && beforeLast.rows ? beforeLast.rows[beforeLast.rows.length - 1] : null;
            if (!spillOneBlock(page)) {
                page = followingA4Page(page);
                continue;
            }
            moves++;
            const rowStillHere = beforeRow && pageBody.contains(beforeRow);
            if ((contentOverflows(pageBody) || pageIsOverfull(pageBody)) && rowStillHere && pageBody.scrollHeight >= before) {
                page = followingA4Page(page);
            }
        }

        // Spill can leave blank lower halves. Fill those before numbering.
        fillPageGaps();
        // Reunite Sr.No 1 before more pull-back can clip it again.
        repairSplitTableFirstDataRows();
        fillPageGaps();
        // Pack only whole short sections that fit — never yank table rows back
        // onto an almost-full sheet (that caused Sr.No 7→10 with 8–9 clipped).
        packSparsePages();
        // If packing overfilled a sheet, spill again so rows stay visible.
        (function respillOverfull() {
            let p = document.querySelector('.docx-preview .docx-a4-page');
            let n = 0;
            while (p && n < 160) {
                const pb = p.querySelector('.docx-page-body');
                if (!pb || (!contentOverflows(pb) && !pageIsOverfull(pb))) {
                    p = followingA4Page(p);
                    continue;
                }
                if (!spillOneBlock(p)) {
                    p = followingA4Page(p);
                    continue;
                }
                n++;
            }
        })();
        // Explicit geometry check: clipped HVAC rows (8/9) must leave the sheet.
        spillClippedTableRows();
        // Fold orphan TOC rows (e.g. lone "10.0 post approval") off empty sheets.
        compactOrphanTocPages();
        repairSplitTableFirstDataRows();
        fillPageGaps();
        spillClippedTableRows();
        moveBodyOffTocPages();
        removeOrphanTocTitleAboveBody();
        // Final pass: Sr.No 1 must sit with 2/3/4 after all pull/spill settles.
        repairSplitTableFirstDataRows();
        reattachRequalCheckHeadings();
        spillClippedTableRows();
        reattachRequalCheckHeadings();
        spillClippedTableRows();
        removeOrphanTocTitleAboveBody();

        document.querySelectorAll('.docx-preview .docx-a4-page').forEach(function (sheet) {
            const sheetBody = sheet.querySelector('.docx-page-body');
            const tail = sheetBody && sheetBody.lastElementChild;
            if (!tail || !tail.matches('table.docx-table') || tail.rows.length > 1) return;
            const tIdx = tail.getAttribute('data-table-index') || '';
            const next = followingA4Page(sheet);
            const moved = next
                ? next.querySelector('.docx-page-body table.docx-table[data-table-index="' + tIdx + '"]')
                : null;
            if (moved && moved.rows.length > 1 && !tail.querySelector('[name]')) tail.remove();
        });

        // Drop pages left with only a break / empty body after pull-back.
        Array.from(document.querySelectorAll('.docx-preview .docx-a4-page')).forEach(function (sheet) {
            dropEmptySheet(sheet);
        });
        repairSplitTableFirstDataRows();
        // After empty-sheet cleanup, force 9.0/9.1/T12 onto their own sheet again
        // (pack/drop can re-hide them under 8.6 or skip them before Machine frame).
        reattachRequalCheckHeadings();
        dedupeDuplicateColumnHeaderRows();
        updateAllPageNumbers();
        if (typeof window.dedupeIdenticalAddedRows === 'function') {
            window.dedupeIdenticalAddedRows();
        }
        if (typeof window.syncTocPageNumbers === 'function') {
            window.syncTocPageNumbers(true);
        }
    }

    window.paginateDocxPreview = paginateDocxPreview;

    function unlockDeviationColumns() {
        document.querySelectorAll('.docx-preview table.docx-table').forEach(function (table) {
            const header = table.rows[0];
            if (!header) return;
            const headers = Array.from(header.cells).map(function (cell) {
                return (cell.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
            });
            const devIdx = headers.findIndex(function (text) { return text.indexOf('deviation') !== -1; });
            if (devIdx < 0 || headers.every(function (text) { return text.indexOf('corrective') === -1; })) return;
            Array.from(table.rows).forEach(function (row, rowIndex) {
                if (rowIndex === 0) return;
                const cell = row.cells[devIdx];
                if (!cell || cell.querySelector('textarea, input.docx-input')) return;
                const loc = cell.getAttribute('data-field')
                    || ('table_' + (table.getAttribute('data-table-index') || '0') + '_row_' + rowIndex + '_col_' + devIdx);
                const current = (cell.textContent || '').replace(/\s+/g, ' ').trim();
                const value = (current === '—' || current === '-' || current === '–') ? '' : current;
                cell.classList.add('editable');
                cell.classList.remove('readonly', 'locked');
                cell.innerHTML = '<textarea name="fields[' + loc + ']" class="docx-input" rows="2" placeholder="Enter deviation...">'
                    + value.replace(/[&<>]/g, function (ch) {
                        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' })[ch];
                    })
                    + '</textarea>';
            });
        });
    }

    let paginateFrame = null;
    let tocAfterPaginateTimer = null;
    // Keep the focused field / current page stable across layout passes.
    // Absolute scrollY restore fails when A4 reflow changes page heights.
    window.docxWithPreservedScroll = function (fn) {
        const active = document.activeElement;
        const anchor = (active && active.closest)
            ? (active.closest('.docx-a4-page, tr, .docx-para-edit-wrapper, textarea, input') || active)
            : null;
        const beforeTop = anchor && anchor.getBoundingClientRect
            ? anchor.getBoundingClientRect().top
            : null;
        const x = window.scrollX || window.pageXOffset || 0;
        const y = window.scrollY || window.pageYOffset || 0;
        try {
            fn();
        } finally {
            if (anchor && document.contains(anchor) && beforeTop !== null) {
                const afterTop = anchor.getBoundingClientRect().top;
                window.scrollBy(0, afterTop - beforeTop);
            } else {
                window.scrollTo(x, y);
            }
            if (active && typeof active.focus === 'function' && document.contains(active)) {
                try {
                    active.focus({ preventScroll: true });
                } catch (err) {
                    try { active.focus(); } catch (err2) {}
                    if (anchor && document.contains(anchor) && beforeTop !== null) {
                        window.scrollBy(0, anchor.getBoundingClientRect().top - beforeTop);
                    } else {
                        window.scrollTo(x, y);
                    }
                }
            }
        }
    };
    function scheduleDocxPaginate() {
        if (paginateFrame) cancelAnimationFrame(paginateFrame);
        paginateFrame = requestAnimationFrame(function () {
            window.docxWithPreservedScroll(function () {
                unlockDeviationColumns();
                paginateDocxPreview();
            });
            // Light TOC number sync only — never full prepareDocxTocForSave here
            // (that double-paginates and jumps the user back to page 1).
            if (tocAfterPaginateTimer) clearTimeout(tocAfterPaginateTimer);
            tocAfterPaginateTimer = setTimeout(function () {
                window.docxWithPreservedScroll(function () {
                    if (typeof window.syncTocPageNumbers === 'function') {
                        window.syncTocPageNumbers(true);
                    }
                });
            }, 80);
            // After letterhead/images settle, pack short sections that fully fit,
            // then respill so table rows are never left clipped (7→10 jump).
            setTimeout(function () {
                window.docxWithPreservedScroll(function () {
                    if (typeof packSparsePages === 'function') packSparsePages();
                    if (typeof reattachRequalCheckHeadings === 'function') reattachRequalCheckHeadings();
                    let p = document.querySelector('.docx-preview .docx-a4-page');
                    let n = 0;
                    while (p && n < 120) {
                        const pb = p.querySelector('.docx-page-body');
                        if (pb && (contentOverflows(pb) || pageIsOverfull(pb))) {
                            if (spillOneBlock(p)) { n++; continue; }
                        }
                        p = followingA4Page(p);
                    }
                    if (typeof spillClippedTableRows === 'function') spillClippedTableRows();
                    if (typeof reattachRequalCheckHeadings === 'function') reattachRequalCheckHeadings();
                    if (typeof spillClippedTableRows === 'function') spillClippedTableRows();
                    // Final lock: 9.0/9.1/Installation table must precede Machine frame.
                    if (typeof reattachRequalCheckHeadings === 'function') reattachRequalCheckHeadings();
                    if (typeof dedupeDuplicateColumnHeaderRows === 'function') dedupeDuplicateColumnHeaderRows();
                    if (typeof updateAllPageNumbers === 'function') updateAllPageNumbers();
                });
            }, 700);
        });
    }
    window.scheduleDocxPaginate = scheduleDocxPaginate;

    // Typing: only grow the textarea. Never reflow/paginate on each keystroke —
    // that was yanking requal fill back to the top page while editing.
    document.addEventListener('input', function (event) {
        const el = event.target;
        if (!el || !el.matches || !el.matches('.docx-preview textarea, .docx-preview-wrapper textarea')) return;
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 480) + 'px';
    });

    if (isPreApprovalMode) {
        const rowInfo = document.getElementById('selectedRowInfo');
        const btnAddRow = document.getElementById('btnDocxAddRow');
        const btnDeleteRow = document.getElementById('btnDocxDeleteRow');
        const tableOpsInput = document.getElementById('docxTableOpsInput');

        // Row selection on cell click / focus inside preview tables (any table on any page).
        function selectPreviewTableRow(row) {
            if (!row) return;
            const table = row.closest('table.docx-table:not(.header-table)');
            if (!table || !table.closest('.docx-preview-wrapper')) return;
            document.querySelectorAll('.docx-preview-wrapper table.docx-table tr.docx-selected-row').forEach(function (r) {
                r.classList.remove('docx-selected-row');
            });
            row.classList.add('docx-selected-row');
            selectedRow = row;
            selectedTable = table;
            ensureTableIndex(selectedTable);
            if (rowInfo) {
                const tIdx = selectedTable.getAttribute('data-table-index') || '?';
                const rIdx = selectedRow.getAttribute('data-row-index')
                    || Array.from(selectedTable.querySelectorAll('tr')).indexOf(selectedRow);
                rowInfo.innerHTML = '<i class="fas fa-check text-success mr-1"></i> Table ' + tIdx + ', row ' + rIdx + ' selected';
            }
        }
        document.addEventListener('click', function (e) {
            const cell = e.target.closest('.docx-preview-wrapper table.docx-table:not(.header-table) td, .docx-preview-wrapper table.docx-table:not(.header-table) th');
            if (!cell) return;
            selectPreviewTableRow(cell.closest('tr'));
        });
        document.addEventListener('focusin', function (e) {
            const cell = e.target && e.target.closest
                ? e.target.closest('.docx-preview-wrapper table.docx-table:not(.header-table) td')
                : null;
            if (!cell) return;
            selectPreviewTableRow(cell.closest('tr'));
        });

        function rowContentFingerprint(row) {
            if (!row) return '';
            return Array.from(row.cells || []).map(function (cell) {
                const inp = cell.querySelector('input:not([type="file"]):not([type="hidden"]), textarea, select');
                if (inp) {
                    return String(inp.value || '').replace(/\s+/g, ' ').trim().toLowerCase();
                }
                return String(cell.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
            }).filter(Boolean).join('|');
        }

        // One Add click must never leave duplicate identical rows (seen: 7.1/test × 3).
        function dedupeIdenticalAddedRows() {
            const tables = Array.from(document.querySelectorAll('.docx-preview-wrapper table.docx-table:not(.header-table)'));
            const byIdx = {};
            tables.forEach(function (table) {
                const idx = table.getAttribute('data-table-index');
                const key = idx === null ? ('auto:' + tables.indexOf(table)) : String(idx);
                (byIdx[key] = byIdx[key] || []).push(table);
            });
            Object.keys(byIdx).forEach(function (key) {
                const seen = new Map();
                byIdx[key].forEach(function (table) {
                    Array.from(table.rows).forEach(function (row) {
                        if (row.querySelector('th')) return;
                        if (row.getAttribute('data-is-added-row') !== '1') return;
                        // Fresh Add Row inserts stay — never auto-delete mid-click.
                        if (row.getAttribute('data-add-token')) return;
                        const fp = rowContentFingerprint(row);
                        if (!fp || fp.length < 2) return;
                        if (seen.has(fp)) {
                            tableOps = tableOps.filter(function (op) { return op._rowEl !== row; });
                            row.remove();
                            return;
                        }
                        seen.set(fp, row);
                    });
                });
            });
            // Collapse same-index table fragments that landed on one sheet.
            document.querySelectorAll('.docx-preview .docx-a4-page').forEach(function (page) {
                const body = page.querySelector('.docx-page-body');
                if (!body) return;
                const firstByIdx = {};
                Array.from(body.children).forEach(function (el) {
                    if (!el.matches || !el.matches('table.docx-table')) return;
                    const idx = el.getAttribute('data-table-index');
                    if (idx === null || idx === '') return;
                    if (!firstByIdx[idx]) {
                        firstByIdx[idx] = el;
                        return;
                    }
                    const keep = firstByIdx[idx];
                    Array.from(el.rows).forEach(function (row) {
                        if (row.querySelector('th')) return;
                        keep.appendChild(row);
                    });
                    el.remove();
                });
            });
            if (typeof syncTableOpsInput === 'function') {
                syncTableOpsInput();
            }
        }
        window.dedupeIdenticalAddedRows = dedupeIdenticalAddedRows;

        function tableRowsByIndex(tableIndex) {
            const out = [];
            const sel = '.docx-preview-wrapper table.docx-table[data-table-index="' + tableIndex + '"]';
            document.querySelectorAll(sel).forEach(function (table) {
                Array.from(table.rows || []).forEach(function (tr) {
                    const raw = tr.getAttribute('data-row-index');
                    if (raw === null || raw === '') return;
                    const n = parseInt(raw, 10);
                    if (isNaN(n)) return;
                    out.push({ index: n, row: tr, table: table });
                });
            });
            out.sort(function (a, b) { return a.index - b.index; });
            return out;
        }

        function rowHasTypedValue(row) {
            if (!row) return false;
            const inputs = row.querySelectorAll('input:not([type="file"]):not([type="hidden"]), textarea, select');
            for (let i = 0; i < inputs.length; i++) {
                const el = inputs[i];
                if (el.type === 'checkbox' || el.type === 'radio') {
                    if (el.checked) return true;
                    continue;
                }
                if (String(el.value || '').replace(/\s+/g, ' ').trim() !== '') return true;
            }
            return false;
        }

        function rewriteLocationToken(token, tableIndex, fromRow, toRow) {
            if (!token) return token;
            const re = new RegExp(
                '(table_|header_)' + tableIndex + '_row_' + fromRow + '(?=_col_|$)'
            );
            return String(token).replace(re, '$1' + tableIndex + '_row_' + toRow);
        }

        function remapRowLocations(row, tableIndex, fromRow, toRow) {
            if (!row || fromRow === toRow) return;
            row.setAttribute('data-row-index', String(toRow));
            row.querySelectorAll('[name], [data-field], [data-field-location], [data-attachment-location], [data-department-location], [data-signature-location]').forEach(function (el) {
                ['name', 'data-field', 'data-field-location', 'data-attachment-location', 'data-department-location', 'data-signature-location'].forEach(function (attr) {
                    const cur = el.getAttribute(attr);
                    if (!cur) return;
                    const next = rewriteLocationToken(cur, tableIndex, fromRow, toRow);
                    if (next !== cur) el.setAttribute(attr, next);
                });
                if (el.dataset) {
                    if (el.dataset.fieldLocation) {
                        el.dataset.fieldLocation = rewriteLocationToken(el.dataset.fieldLocation, tableIndex, fromRow, toRow);
                    }
                    if (el.dataset.attachmentLocation) {
                        el.dataset.attachmentLocation = rewriteLocationToken(el.dataset.attachmentLocation, tableIndex, fromRow, toRow);
                    }
                }
            });
        }

        function clearRowInputs(row) {
            if (!row) return;
            row.querySelectorAll('input, textarea').forEach(function (inp) {
                if (inp.type === 'radio' || inp.type === 'checkbox') {
                    inp.checked = false;
                } else {
                    inp.value = '';
                }
                inp.removeAttribute('readonly');
                inp.disabled = false;
            });
            row.querySelectorAll('select').forEach(function (sel) {
                sel.selectedIndex = 0;
                sel.disabled = false;
            });
            row.querySelectorAll('.docx-attachment-link, .docx-saved-file').forEach(function (el) {
                el.remove();
            });
            row.querySelectorAll('.locked').forEach(function (el) {
                el.classList.remove('locked');
                el.classList.add('editable');
            });
        }

        function assignRowLocations(row, tableIndex, rowIndex) {
            if (!row) return;
            row.setAttribute('data-row-index', String(rowIndex));
            Array.from(row.cells || []).forEach(function (td, colIdx) {
                const loc = 'table_' + tableIndex + '_row_' + rowIndex + '_col_' + colIdx;
                td.setAttribute('data-field', loc);
                td.querySelectorAll('input, textarea, select').forEach(function (inp) {
                    if (inp.type === 'file') {
                        inp.name = 'attachments[' + loc + ']';
                        if (td.setAttribute) td.setAttribute('data-attachment-location', loc);
                        const hiddenName = td.querySelector('.attachment-name-field');
                        if (hiddenName) {
                            hiddenName.value = '';
                            hiddenName.name = 'fields[' + loc + ']';
                            hiddenName.setAttribute('data-attachment-location', loc);
                        }
                        return;
                    }
                    if (inp.type === 'hidden' && inp.classList.contains('attachment-name-field')) {
                        inp.name = 'fields[' + loc + ']';
                        inp.setAttribute('data-attachment-location', loc);
                        return;
                    }
                    inp.name = 'fields[' + loc + ']';
                    if (inp.dataset) inp.dataset.fieldLocation = loc;
                    inp.setAttribute('data-field-location', loc);
                });
            });
        }

        function shiftRowsAfter(tableIndex, afterRow) {
            const rows = tableRowsByIndex(tableIndex).filter(function (item) {
                return item.index > afterRow;
            });
            // Highest index first so renames do not collide.
            rows.sort(function (a, b) { return b.index - a.index; });
            rows.forEach(function (item) {
                remapRowLocations(item.row, tableIndex, item.index, item.index + 1);
            });
        }

        function tryReuseTrailingEmptyRow(tableIndex, afterRow, selectedRowEl) {
            const rows = tableRowsByIndex(tableIndex).filter(function (item) {
                return item.index > afterRow;
            });
            if (!rows.length) return null;
            let empty = null;
            for (let i = rows.length - 1; i >= 0; i--) {
                if (rowHasTypedValue(rows[i].row)) break;
                empty = rows[i];
            }
            if (!empty) return null;
            const insertAt = afterRow + 1;
            // Only reuse a blank that is already at the insert slot.
            // Remapping distant blanks is session-only and is lost on refresh — use structural insert instead.
            if (empty.index === insertAt) {
                clearRowInputs(empty.row);
                return empty.row;
            }
            return null;
        }

        function persistTableOpsDraftSoon() {
            syncTableOpsInput();
            // Immediate save so refresh keeps Add/Delete (debounce alone can miss a quick reload).
            if (typeof window.docxSaveDraftNow === 'function') {
                window.docxSaveDraftNow({ silent: true });
            }
        }

        function pickVisibleEditableTable() {
            const tables = Array.from(document.querySelectorAll(
                '.docx-preview-wrapper table.docx-table:not(.header-table)'
            ));
            if (!tables.length) return null;
            const viewMid = window.scrollY + (window.innerHeight * 0.45);
            let best = null;
            let bestDist = Infinity;
            tables.forEach(function (table) {
                const rect = table.getBoundingClientRect();
                const top = rect.top + window.scrollY;
                const bottom = top + Math.max(rect.height, 1);
                const center = (top + bottom) / 2;
                const dist = (viewMid >= top && viewMid <= bottom)
                    ? 0
                    : Math.min(Math.abs(center - viewMid), Math.abs(top - viewMid));
                if (dist < bestDist) {
                    bestDist = dist;
                    best = table;
                }
            });
            return best || tables[0];
        }

        function ensureTableIndex(table) {
            if (!table) return 0;
            let raw = table.getAttribute('data-table-index');
            if (raw !== null && raw !== '' && !isNaN(parseInt(raw, 10))) {
                return parseInt(raw, 10);
            }
            const all = Array.from(document.querySelectorAll('.docx-preview-wrapper table.docx-table:not(.header-table)'));
            const idx = Math.max(0, all.indexOf(table));
            table.setAttribute('data-table-index', String(idx));
            return idx;
        }

        // Add Row — always insert a real new row (never "reuse" a trailing blank:
        // that looked like Add Row failed when the next template row was already empty).
        let addRowBusy = false;
        let addRowBusyTimer = null;
        window.docxAddRowBusy = false;
        function releaseAddRowBusy() {
            addRowBusy = false;
            window.docxAddRowBusy = false;
            if (addRowBusyTimer) {
                clearTimeout(addRowBusyTimer);
                addRowBusyTimer = null;
            }
            if (btnAddRow) btnAddRow.disabled = false;
        }
        if (btnAddRow) {
            // Keep the selected table row — do not let the button steal focus first.
            btnAddRow.addEventListener('mousedown', function (e) {
                e.preventDefault();
            });
            btnAddRow.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (addRowBusy) return;

                // Prefer the highlighted row; recover if DOM was reflowed away.
                if (!selectedTable || !selectedRow || !document.contains(selectedRow)) {
                    const highlighted = document.querySelector(
                        '.docx-preview-wrapper table.docx-table:not(.header-table) tr.docx-selected-row'
                    );
                    if (highlighted && document.contains(highlighted)) {
                        selectPreviewTableRow(highlighted);
                    } else {
                        const visibleTable = pickVisibleEditableTable();
                        if (!visibleTable) {
                            alert('No editable table found in document.');
                            return;
                        }
                        const rows = Array.from(visibleTable.querySelectorAll('tr')).filter(function (r) {
                            return !r.querySelector('th') && !(r.querySelector('td[colspan]') && r.querySelectorAll('td').length === 1);
                        });
                        selectedTable = visibleTable;
                        selectedRow = rows.length ? rows[rows.length - 1] : visibleTable.querySelector('tr');
                        if (!selectedRow) {
                            alert('No editable row found in this table.');
                            return;
                        }
                        selectPreviewTableRow(selectedRow);
                    }
                }

                addRowBusy = true;
                window.docxAddRowBusy = true;
                btnAddRow.disabled = true;
                // Safety: never leave the button permanently disabled.
                if (addRowBusyTimer) clearTimeout(addRowBusyTimer);
                addRowBusyTimer = setTimeout(releaseAddRowBusy, 2500);

                try {
                    let templateRow = selectedRow;
                    if (templateRow.querySelector('th')) {
                        const nextData = templateRow.nextElementSibling;
                        if (nextData) templateRow = nextData;
                    }

                    const tIdx = ensureTableIndex(selectedTable);
                    let afterRow = parseInt(selectedRow.getAttribute('data-row-index') || '', 10);
                    if (isNaN(afterRow)) {
                        afterRow = Array.from(selectedTable.querySelectorAll('tr')).indexOf(selectedRow);
                    }
                    if (isNaN(afterRow) || afterRow < 0) afterRow = Math.max(0, selectedTable.rows.length - 1);
                    const headers = Array.from(selectedTable.querySelectorAll('tr:first-child td, tr:first-child th')).map(function (th) {
                        return th.textContent.trim();
                    });

                    // Always structural insert so every click visibly adds a row.
                    shiftRowsAfter(tIdx, afterRow);
                    const newRow = templateRow.cloneNode(true);
                    newRow.classList.remove('docx-selected-row');
                    newRow.setAttribute('data-is-added-row', '1');
                    // Unique token so blank-row dedupe cannot delete this insert.
                    newRow.setAttribute('data-add-token', String(Date.now()) + '-' + Math.random().toString(36).slice(2, 8));
                    clearRowInputs(newRow);
                    newRow.querySelectorAll('td').forEach(function (td) {
                        if (td.querySelector('input, textarea, select')) return;
                        td.textContent = '';
                        td.innerHTML = '<input type="text" class="docx-input" value="">';
                        td.classList.add('editable');
                        td.classList.remove('locked', 'readonly');
                    });
                    Array.from(newRow.cells || []).forEach(function (td) {
                        let inp = td.querySelector('input:not([type="file"]):not([type="hidden"]), textarea, select');
                        if (!inp) {
                            td.textContent = '';
                            td.innerHTML = '<input type="text" class="docx-input" value="" placeholder="Enter details...">';
                            td.classList.add('editable');
                            td.classList.remove('locked', 'readonly');
                        } else {
                            inp.removeAttribute('readonly');
                            inp.disabled = false;
                            td.classList.add('editable');
                            td.classList.remove('locked', 'readonly');
                        }
                    });
                    assignRowLocations(newRow, tIdx, afterRow + 1);
                    selectedRow.after(newRow);
                    tableOps.push({
                        op: 'insert',
                        table: tIdx,
                        after_row: afterRow,
                        headers: headers,
                        _rowEl: newRow
                    });

                    // Default next Sr. No. (max existing integer + 1) — user may edit it.
                    (function assignDefaultSrNo() {
                        const h0 = String(headers[0] || '').toLowerCase();
                        if (!/sl\.?\s*no|sr\.?\s*no|s\.?\s*no|#/.test(h0)) return;
                        let maxN = 0;
                        tableRowsByIndex(tIdx).forEach(function (item) {
                            if (item.row === newRow) return;
                            const cell = item.row.cells && item.row.cells[0];
                            if (!cell) return;
                            const inp = cell.querySelector('input, textarea');
                            const raw = inp ? String(inp.value || '') : String(cell.textContent || '');
                            const m = raw.trim().match(/^(\d+)/);
                            if (m) maxN = Math.max(maxN, parseInt(m[1], 10));
                        });
                        const next = String(maxN + 1);
                        const firstTd = newRow.cells && newRow.cells[0];
                        if (!firstTd) return;
                        let inp = firstTd.querySelector('input:not([type="file"]):not([type="hidden"]), textarea');
                        if (!inp) {
                            firstTd.textContent = '';
                            firstTd.innerHTML = '<input type="text" class="docx-input" value="">';
                            firstTd.classList.add('editable');
                            firstTd.classList.remove('locked', 'readonly');
                            inp = firstTd.querySelector('input');
                            assignRowLocations(newRow, tIdx, afterRow + 1);
                        }
                        if (inp) {
                            inp.value = next;
                            inp.removeAttribute('readonly');
                            inp.disabled = false;
                        }
                    })();

                    document.querySelectorAll('.docx-preview-wrapper table.docx-table tr.docx-selected-row').forEach(function (r) {
                        r.classList.remove('docx-selected-row');
                    });
                    newRow.classList.add('docx-selected-row');
                    selectedRow = newRow;
                    selectedTable = newRow.closest('table') || selectedTable;
                    if (selectedTable) ensureTableIndex(selectedTable);

                    // Soft local reflow only; keep user on the same sheet.
                    if (typeof window.docxWithPreservedScroll === 'function') {
                        window.docxWithPreservedScroll(function () {
                            const page = newRow.closest('.docx-a4-page');
                            if (page && typeof reflowPageOverflow === 'function') {
                                reflowPageOverflow(page);
                            }
                        });
                    }

                    if (rowInfo) {
                        rowInfo.innerHTML = '<span class="text-success font-weight-bold">Row Added!</span> Type text into the new cells.';
                    }

                    setTimeout(function () {
                        try {
                            if (typeof window.docxWithPreservedScroll === 'function') {
                                window.docxWithPreservedScroll(function () {
                                    if (typeof window.syncTocPageNumbers === 'function') {
                                        window.syncTocPageNumbers(true);
                                    }
                                });
                            }
                            // Persist after DOM settles — one draft, not during the click race.
                            persistTableOpsDraftSoon();
                            try {
                                newRow.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                            } catch (err) {}
                            const firstInput = newRow.querySelector('input:not([type="file"]), textarea');
                            if (firstInput) {
                                try { firstInput.focus({ preventScroll: true }); } catch (err2) { firstInput.focus(); }
                            }
                        } finally {
                            releaseAddRowBusy();
                        }
                    }, 80);
                } catch (addErr) {
                    console.warn('Add Row failed', addErr);
                    releaseAddRowBusy();
                    if (rowInfo) {
                        rowInfo.innerHTML = '<span class="text-danger">Add Row failed — select a row and try again.</span>';
                    }
                }
            });
        }

        // Delete Row
        if (btnDeleteRow) {
            btnDeleteRow.addEventListener('click', function () {
                if (!selectedTable || !selectedRow) {
                    alert('Please click on a row inside a table first to select it.');
                    return;
                }
                if (selectedRow.querySelector('th')) {
                    alert('Cannot delete the table header row.');
                    return;
                }
                if (selectedRow.querySelector('td[colspan]') && selectedRow.querySelectorAll('td').length === 1) {
                    alert('Cannot delete a section banner row.');
                    return;
                }
                const sameIndex = selectedTable.getAttribute('data-table-index');
                const siblingTables = sameIndex === null
                    ? [selectedTable]
                    : Array.from(document.querySelectorAll('table.docx-table[data-table-index="' + sameIndex + '"]'));
                let dataRowCount = 0;
                siblingTables.forEach(function (table) {
                    Array.from(table.rows).forEach(function (row) {
                        if (!row.querySelector('th')) dataRowCount++;
                    });
                });
                if (dataRowCount <= 1) {
                    alert('Cannot delete the last remaining data row of this table.');
                    return;
                }

                if (!confirm('Are you sure you want to delete this row?')) {
                    return;
                }

                const parentPage = selectedRow.closest('.docx-a4-page');

                if (selectedRow.getAttribute('data-is-added-row') === '1') {
                    tableOps = tableOps.filter(function (op) {
                        return op._rowEl !== selectedRow;
                    });
                    selectedRow.remove();
                } else {
                    const tIdx = parseInt(selectedTable.getAttribute('data-table-index') || Array.from(document.querySelectorAll('.docx-preview-wrapper table.docx-table')).indexOf(selectedTable));
                    const rIdx = parseInt(selectedRow.getAttribute('data-row-index') || Array.from(selectedTable.querySelectorAll('tr')).indexOf(selectedRow));
                    const headers = Array.from(selectedTable.querySelectorAll('tr:first-child td, tr:first-child th')).map(function (th) {
                        return th.textContent.trim();
                    });

                    tableOps.push({
                        op: 'delete',
                        table: tIdx,
                        row: rIdx,
                        headers: headers
                    });
                    selectedRow.remove();
                }

                selectedRow = null;
                selectedTable = null;
                persistTableOpsDraftSoon();

                if (parentPage) {
                    let guard = 0;
                    while (pageFreeSpace(parentPage.querySelector('.docx-page-body')) >= 28 && pullOneBack(parentPage) && guard < 40) {
                        guard++;
                    }
                    const nextSheet = followingA4Page(parentPage);
                    if (nextSheet) dropEmptySheet(nextSheet);
                    updateAllPageNumbers();
                }

                setTimeout(function () {
                    if (typeof window.docxWithPreservedScroll === 'function') {
                        window.docxWithPreservedScroll(function () {
                            if (typeof window.syncTocPageNumbers === 'function') {
                                window.syncTocPageNumbers(true);
                            } else if (typeof updateAllPageNumbers === 'function') {
                                updateAllPageNumbers();
                            }
                        });
                    }
                    persistTableOpsDraftSoon();
                }, 60);

                if (rowInfo) {
                    rowInfo.textContent = 'Row deleted.';
                }
            });
        }

        function syncTableOpsInput(opts) {
            opts = opts || {};
            if (!tableOpsInput) return;
            const serialized = tableOps.map(function (op) {
                if (op.op === 'insert') {
                    // Keep Sr. No. (default next serial or user-typed) in values for bake.
                    const values = op._rowEl
                        ? Array.from(op._rowEl.querySelectorAll('td')).map(function (td) {
                            const inp = td.querySelector('input, textarea, select');
                            return inp ? (inp.value || '').trim() : td.textContent.trim();
                        })
                        : (op.values || []).slice();
                    return {
                        op: 'insert',
                        table: op.table,
                        after_row: op.after_row,
                        headers: op.headers,
                        values: values
                    };
                }
                return {
                    op: op.op,
                    table: op.table,
                    row: op.row,
                    headers: op.headers
                };
            });
            tableOpsInput.value = JSON.stringify(serialized);
            // quiet=true when collecting FormData mid-save (avoid re-entrancy / extra timers).
            if (!opts.quiet && typeof window.docxScheduleDraftSave === 'function') {
                window.docxScheduleDraftSave();
            }
        }

        function findPreviewTablesByIndex(tableIndex) {
            return Array.from(document.querySelectorAll(
                '.docx-preview-wrapper table.docx-table[data-table-index="' + tableIndex + '"]:not(.header-table)'
            ));
        }

        function findRowByDataIndex(tables, rowIndex) {
            for (let t = 0; t < tables.length; t++) {
                const rows = Array.from(tables[t].rows || []);
                for (let i = 0; i < rows.length; i++) {
                    const raw = rows[i].getAttribute('data-row-index');
                    if (raw === null || raw === '') continue;
                    if (parseInt(raw, 10) === rowIndex) return rows[i];
                }
            }
            return null;
        }

        function applyValuesToRow(row, values) {
            if (!row || !Array.isArray(values)) return;
            Array.from(row.cells || []).forEach(function (td, colIdx) {
                if (colIdx >= values.length) return;
                const v = values[colIdx];
                if (v === undefined || v === null || String(v).trim() === '') return;
                const inp = td.querySelector('input:not([type="file"]):not([type="hidden"]), textarea, select');
                if (!inp) {
                    td.textContent = String(v);
                    return;
                }
                if (inp.tagName === 'SELECT') {
                    inp.value = String(v);
                    return;
                }
                if (inp.type === 'checkbox' || inp.type === 'radio') {
                    inp.checked = ['1', 'yes', 'true', 'on'].indexOf(String(v).toLowerCase()) >= 0;
                    return;
                }
                inp.value = String(v);
            });
        }

        // Replay draft table_ops only when rows are not already in the Word preview
        // (after pre-approval submit, inserts are baked into the template — replaying
        // them again caused duplicate locked rows on send-back).
        function replayDraftTableOps() {
            if (!Array.isArray(tableOps) || !tableOps.length) return;

            const wantFingerprint = function (values) {
                if (!Array.isArray(values) || !values.length) return '';
                return values.map(function (v) {
                    return String(v || '').replace(/\s+/g, ' ').trim().toLowerCase();
                }).filter(Boolean).join('|');
            };

            const rowMatchesValues = function (tr, values) {
                const want = wantFingerprint(values);
                if (!want) return false;
                const fp = rowContentFingerprint(tr);
                if (fp === want) return true;
                // Partial match: any non-empty typed cell from the op appears in the row.
                const parts = want.split('|').filter(Boolean);
                if (!parts.length) return false;
                return parts.every(function (p) { return fp.indexOf(p) !== -1; });
            };

            const deletes = tableOps.filter(function (op) { return op && op.op === 'delete'; })
                .slice()
                .sort(function (a, b) {
                    if (a.table !== b.table) return a.table - b.table;
                    return b.row - a.row;
                });
            deletes.forEach(function (op) {
                const tables = findPreviewTablesByIndex(op.table);
                if (!tables.length) return;
                const row = findRowByDataIndex(tables, op.row);
                if (row && !row.querySelector('th')) {
                    row.remove();
                }
            });

            const keptOps = [];
            tableOps.forEach(function (op) {
                if (!op || op.op !== 'insert') {
                    if (op && op.op === 'delete') keptOps.push(op);
                    return;
                }
                if (op._rowEl && document.contains(op._rowEl)) {
                    keptOps.push(op);
                    return;
                }

                const tables = findPreviewTablesByIndex(op.table);
                if (!tables.length) {
                    keptOps.push(op);
                    return;
                }

                // Skip when Word preview already has this row (baked after pre-approval).
                let already = false;
                tables.forEach(function (table) {
                    Array.from(table.rows || []).forEach(function (tr) {
                        if (already || tr.querySelector('th')) return;
                        if (rowMatchesValues(tr, op.values || [])) {
                            already = true;
                        }
                    });
                });
                if (already) {
                    // Drop stale insert — content is already in the template.
                    return;
                }

                let afterRowEl = findRowByDataIndex(tables, op.after_row);
                let hostTable = afterRowEl
                    ? (afterRowEl.closest('table') || tables[0])
                    : tables[tables.length - 1];
                if (!afterRowEl) {
                    const dataRows = Array.from(hostTable.rows || []).filter(function (r) {
                        return !r.querySelector('th');
                    });
                    afterRowEl = dataRows[dataRows.length - 1] || hostTable.rows[hostTable.rows.length - 1];
                }
                if (!afterRowEl) {
                    keptOps.push(op);
                    return;
                }

                shiftRowsAfter(op.table, op.after_row);

                let templateRow = afterRowEl;
                if (templateRow.querySelector('th') && templateRow.nextElementSibling) {
                    templateRow = templateRow.nextElementSibling;
                }
                const newRow = templateRow.cloneNode(true);
                newRow.classList.remove('docx-selected-row');
                newRow.setAttribute('data-is-added-row', '1');
                clearRowInputs(newRow);
                newRow.querySelectorAll('td').forEach(function (td) {
                    if (td.querySelector('input, textarea, select')) return;
                    td.textContent = '';
                    td.innerHTML = '<input type="text" class="docx-input" value="">';
                    td.classList.add('editable');
                });
                assignRowLocations(newRow, op.table, op.after_row + 1);
                applyValuesToRow(newRow, op.values || []);
                afterRowEl.after(newRow);
                op._rowEl = newRow;
                keptOps.push(op);
            });

            tableOps = keptOps;
            syncTableOpsInput();
            dedupeIdenticalAddedRows();
            if (typeof window.scheduleDocxPaginate === 'function') {
                window.scheduleDocxPaginate();
            }
        }

        window.syncDocxTableOps = syncTableOpsInput;
        window.replayDocxTableOps = replayDraftTableOps;

        // Restore Add/Delete rows from last draft before the user edits further.
        replayDraftTableOps();

        // Keep tableOps continuously synced as user edits cells
        document.addEventListener('input', function (e) {
            if (e.target && e.target.closest('.docx-preview-wrapper')) {
                syncTableOpsInput();
            }
        });

        // Collect tableOps values before form submit
        if (form) {
            form.addEventListener('submit', function () {
                syncTableOpsInput();
            });
        }
        if (btnSubmit) {
            btnSubmit.addEventListener('click', function () {
                syncTableOpsInput();
            });
        }
    }

    /** Active users for signature-table / Att01 training approver dropdowns. */
    <?php
        $docxUsersPayload = $users->map(static function ($u) {
            return [
                'id' => $u->id,
                'label' => $u->display_name,
                'name' => $u->full_name ?? $u->display_name,
                'designation' => $u->designation ?? '',
                'department' => $u->department_label ?? '',
            ];
        })->values();
    ?>
    const docxUsers = <?php echo json_encode($docxUsersPayload, 15, 512) ?>;

    const approverHiddenByRole = {
        post_prepared_by_name: document.getElementById('post_prepared_by_name_value'),
        post_checked_by_engineering_name: document.getElementById('post_checked_by_engineering_name_value'),
        post_checked_by_production_name: document.getElementById('post_checked_by_production_name_value'),
        post_checked_by_quality_name: document.getElementById('post_checked_by_quality_name_value'),
        post_checked_by_validation_name: document.getElementById('post_checked_by_validation_name_value'),
        post_approved_by_user_name: document.getElementById('post_approved_by_user_name_value'),
        post_approved_by_quality_name: document.getElementById('post_approved_by_quality_name_value'),
    };

    /** Copy Name dropdown selections from the document into hidden POST approver inputs. */
    const syncDocxApproverHiddens = function () {
        document.querySelectorAll('.docx-preview-wrapper select.approver-field').forEach(function (previewSel) {
            if (previewSel.dataset.isPreApprover === '1') return;
            const role = previewSel.dataset.approverRole
                || inferApproverRoleFromApprovalPageTable(previewSel)
                || inferApproverRoleFromReportApprovalTable(previewSel)
                || inferApproverRoleFromRow(previewSel);
            if (!role || !approverHiddenByRole[role]) return;
            if (previewSel.value) {
                approverHiddenByRole[role].value = previewSel.value;
            }
        });
    };

    /**
     * Infer POST approver role from Veeva approval-page tables (Role | Name | Signature | Date).
     */
    const inferApproverRoleFromApprovalPageTable = function (sel) {
        const row = sel.closest('tr');
        if (!row) return '';
        const roleCell = row.querySelector('td:first-child');
        const roleText = (roleCell ? roleCell.textContent : '').toLowerCase();
        if (
            roleText.includes('performed')
            || roleText.includes('recorded')
            || roleText.includes('complied')
            || roleText.includes('identified')
        ) {
            return 'post_prepared_by_name';
        }
        if (roleText.includes('checked') || roleText.includes('reviewed')) {
            return 'post_checked_by_engineering_name';
        }
        if (roleText.includes('approved')) {
            return 'post_approved_by_quality_name';
        }
        return '';
    };

    /**
     * Infer POST approver role from 8.0 Report Approval tables (Prepared/Checked/Approved By blocks).
     */
    const inferApproverRoleFromReportApprovalTable = function (sel) {
        const table = sel.closest('table');
        if (!table) return '';
        const titleCell = table.querySelector('tr:first-child td');
        const title = (titleCell ? titleCell.textContent : '').toLowerCase();
        const approverSelects = Array.from(table.querySelectorAll('select.approver-field'));
        const idx = approverSelects.indexOf(sel);
        if (idx < 0) return '';
        if (title.includes('checked by')) {
            const roles = [
                'post_checked_by_validation_name',
                'post_checked_by_production_name',
                'post_checked_by_engineering_name',
                'post_checked_by_quality_name',
            ];
            return roles[idx] || '';
        }
        if (title.includes('prepared by')) {
            return 'post_prepared_by_name';
        }
        if (title.includes('approved by')) {
            const roles = [
                'post_approved_by_user_name',
                'post_approved_by_quality_name',
            ];
            return roles[idx] || 'post_approved_by_user_name';
        }
        return '';
    };

    /**
     * Infer POST approver role from signature row text when parser did not set data-approver-role.
     * Covers Quality Assurance rows embedded in Checked By tables.
     */
    const inferApproverRoleFromRow = function (sel) {
        const row = sel.closest('tr');
        if (!row) return '';
        const loc = sel.dataset.fieldLocation || '';
        if (/^table_[123]_row_\d+_col_\d+$/.test(loc) || sel.dataset.isPreApprover === '1') {
            return '';
        }
        const table = sel.closest('table');
        if (table) {
            const tableText = ((table.innerText || '') + ' ' + (table.previousElementSibling ? table.previousElementSibling.innerText : '')).toLowerCase();
            if (tableText.includes('pre-approval') || tableText.includes('prior to starting')) {
                return '';
            }
        }
        const text = (row.textContent || '').toLowerCase();
        const tableTitle = ((sel.closest('table') || {}).innerText || '').slice(0, 120).toLowerCase();
        const isApprovedBy = tableTitle.includes('approved by');
        const isCheckedBy = tableTitle.includes('checked by') && !isApprovedBy;
        if (text.includes('quality') || text.includes('assurance') || /\bqa\b/.test(text)) {
            if (isCheckedBy) {
                return 'post_checked_by_quality_name';
            }
            return 'post_approved_by_quality_name';
        }
        if (text.includes('reviewed')) {
            return 'post_checked_by_engineering_name';
        }
        if (text.includes('engineering') || text.includes('checked')) {
            return 'post_checked_by_engineering_name';
        }
        if (text.includes('production')) {
            return 'post_checked_by_production_name';
        }
        if (text.includes('validation') || text.includes('user/validation') || text.includes('user / validation')) {
            if (isApprovedBy) {
                return 'post_approved_by_user_name';
            }
            return 'post_checked_by_validation_name';
        }
        if (text.includes('performed') || text.includes('recorded') || text.includes('complied')) {
            return 'post_prepared_by_name';
        }
        if (text.includes('prepared')) {
            return 'post_prepared_by_name';
        }
        return '';
    };

    const preApproverRoles = [
        'prepared_by_engineering_name',
        'checked_by_engineering_name',
        'checked_by_production_name',
        'checked_by_validation_name',
        'checked_by_quality_name',
        'approved_by_user_name',
        'approved_by_quality_name'
    ];

    const preApprovedUserNames = <?php echo json_encode($preApprovedUserNames ?? [], 15, 512) ?>;

    /**
     * Lock pre-approved cells into static readonly text when pre-approval is complete or in post-fill stage.
     * Prevents any pre-approval signature cells from showing editable dropdowns.
     */
    const lockPreApprovedCells = function () {
        if (isPreApprovalMode) return;
        if ('<?php echo e($importSource ?? ''); ?>' !== 'requalification') return;
        const allApprovers = Array.from(document.querySelectorAll('.docx-preview-wrapper select.approver-field'));

        preApproverRoles.forEach(function (roleKey, idx) {
            let previewSelect = document.querySelector(
                '.docx-preview-wrapper select.approver-field[data-approver-role="' + roleKey + '"]'
            );
            if (!previewSelect && idx < allApprovers.length) {
                const candidate = allApprovers[idx];
                const loc = candidate.dataset.fieldLocation || '';
                if (/^table_[123]_row_\d+_col_\d+$/.test(loc) || candidate.dataset.isPreApprover === '1') {
                    previewSelect = candidate;
                }
            }
            if (!previewSelect) return;

            const loc = previewSelect.dataset.fieldLocation || '';
            const td = previewSelect.closest('td');
            const savedName = (preApprovedUserNames && preApprovedUserNames[roleKey])
                ? preApprovedUserNames[roleKey]
                : (previewSelect.dataset.userName || previewSelect.value || '');

            if (td) {
                td.className = 'docx-cell readonly docx-approver-readonly font-weight-bold text-dark';
                td.innerHTML = '<span class="docx-readonly-value font-weight-bold text-dark" data-field="' + loc + '">'
                    + (savedName || '—')
                    + '</span>'
                    + (loc ? '<input type="hidden" name="fields[' + loc + ']" value="' + String(savedName).replace(/"/g, '&quot;') + '">' : '');
            } else {
                previewSelect.disabled = true;
                previewSelect.style.display = 'none';
            }
        });

        document.querySelectorAll('.docx-preview-wrapper select.approver-field').forEach(function (sel) {
            const loc = sel.dataset.fieldLocation || '';
            const role = sel.dataset.approverRole || '';
            if (/^table_[123]_row_\d+_col_\d+$/.test(loc) || sel.dataset.isPreApprover === '1' || preApproverRoles.includes(role)) {
                const td = sel.closest('td');
                const savedName = (role && preApprovedUserNames && preApprovedUserNames[role])
                    ? preApprovedUserNames[role]
                    : (sel.dataset.userName || sel.value || '');
                if (td) {
                    td.className = 'docx-cell readonly docx-approver-readonly font-weight-bold text-dark';
                    td.innerHTML = '<span class="docx-readonly-value font-weight-bold text-dark" data-field="' + loc + '">'
                        + (savedName || '—')
                        + '</span>'
                        + (loc ? '<input type="hidden" name="fields[' + loc + ']" value="' + String(savedName).replace(/"/g, '&quot;') + '">' : '');
                } else {
                    sel.disabled = true;
                    sel.style.display = 'none';
                }
            }
        });
    };

    /**
     * Synchronize the 7 Pre-Approval dropdowns in the top card (#pre_{roleKey})
     * with the 7 signature dropdowns in the document preview table (PRE APPROVAL SIGNATURE).
     * Any selection in either dropdown automatically updates the other and sets the hidden name field.
     */
    const setupPreApproverSync = function () {
        if ('<?php echo e($importSource ?? ''); ?>' !== 'requalification') return;
        const firstCardSelect = document.getElementById('pre_prepared_by_engineering_name');
        const preCard = document.getElementById('requalPreApprovalCard');
        if (!firstCardSelect && !preCard) {
            return;
        }

        const allPreviewApprovers = Array.from(
            document.querySelectorAll('.docx-preview-wrapper select.approver-field')
        );

        preApproverRoles.forEach(function (roleKey, idx) {
            const cardSelect = document.getElementById('pre_' + roleKey);

            // Find matching preview select: by data-approver-role first, else by index among preview approvers
            let previewSelect = document.querySelector(
                '.docx-preview-wrapper select.approver-field[data-approver-role="' + roleKey + '"]'
            );
            if (!previewSelect && idx < allPreviewApprovers.length) {
                previewSelect = allPreviewApprovers[idx];
            }

            if (!previewSelect) return;

            previewSelect.dataset.approverRole = roleKey;
            previewSelect.dataset.isPreApprover = '1';
            previewSelect.dataset.hydrated = '1';
            previewSelect.removeAttribute('required');
            previewSelect.required = false;

            // Populate options from docxUsers if not populated
            if (previewSelect.options.length <= 1) {
                previewSelect.innerHTML = '<option value="">-- Select User --</option>';
                docxUsers.forEach(function (user) {
                    const opt = document.createElement('option');
                    opt.value = user.id;
                    opt.textContent = user.label;
                    previewSelect.appendChild(opt);
                });
            }

            const fieldLoc = previewSelect.dataset.fieldLocation || '';
            const nameHidden = fieldLoc
                ? document.querySelector('input.approver-name-field[data-field-location="' + fieldLoc + '"]')
                : null;
            if (nameHidden) {
                nameHidden.dataset.isPreApprover = '1';
                nameHidden.dataset.approverRole = roleKey;
            }

            const updateHiddenName = function (userId) {
                if (!nameHidden) return;
                const selUser = docxUsers.find(function (u) { return String(u.id) === String(userId); });
                nameHidden.value = selUser ? selUser.label : '';
            };

            // Initial sync: cardSelect value > previewSelect data-user-id > previewSelect value
            let initialUserId = '';
            if (cardSelect && cardSelect.value) {
                initialUserId = cardSelect.value;
            } else if (previewSelect.dataset.userId) {
                initialUserId = previewSelect.dataset.userId;
            } else if (previewSelect.value) {
                initialUserId = previewSelect.value;
            }

            if (initialUserId) {
                previewSelect.value = String(initialUserId);
                if (cardSelect && String(cardSelect.value) !== String(initialUserId)) {
                    cardSelect.value = String(initialUserId);
                }
                updateHiddenName(initialUserId);
            }

            // Sync Card -> Preview Table
            if (cardSelect) {
                cardSelect.addEventListener('change', function () {
                    const val = cardSelect.value;
                    if (previewSelect.value !== val) {
                        previewSelect.value = val;
                        updateHiddenName(val);
                        previewSelect.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });
            }

            // Sync Preview Table -> Card
            previewSelect.addEventListener('change', function () {
                const val = previewSelect.value;
                if (cardSelect && cardSelect.value !== val) {
                    cardSelect.value = val;
                    cardSelect.dispatchEvent(new Event('change', { bubbles: true }));
                }
                updateHiddenName(val);
            });
        });
    };

    /**
     * NEQ APPROVAL PAGE (Role | Name | Signature | Date): only Name is a user dropdown.
     * Signature / Date must stay locked stamps until the selected user approves.
     */
    const lockNeqApprovalStampColumns = function () {
        const form = document.getElementById('docxFillForm');
        if (!form || !form.classList.contains('docx-import-neq')) return;
        document.querySelectorAll('.docx-preview-wrapper table.docx-table').forEach(function (table) {
            const header = table.querySelector('tr');
            if (!header) return;
            const headers = Array.from(header.children).map(function (cell) {
                return (cell.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
            });
            if (headers.length < 4) return;
            if (headers[0] !== 'role' || headers[1].indexOf('name') < 0) return;
            if (headers[2].indexOf('signature') < 0 || headers[3].indexOf('date') < 0) return;

            Array.from(table.querySelectorAll('tr')).forEach(function (row, rowIndex) {
                if (rowIndex === 0) return;
                const cells = Array.from(row.children);
                [2, 3].forEach(function (colIdx) {
                    const cell = cells[colIdx];
                    if (!cell) return;
                    const rogue = cell.querySelector('select.approver-field, input.docx-input:not([type="hidden"]), textarea.docx-input');
                    if (!rogue && cell.classList.contains('approval-stamp-cell')) return;
                    const locMatch = (rogue && (rogue.getAttribute('data-field-location') || rogue.getAttribute('name') || ''))
                        .match(/table_\d+_row_\d+_col_\d+/);
                    const loc = locMatch
                        ? locMatch[0]
                        : ((cell.getAttribute('data-field') || '') || '');
                    cell.className = 'docx-cell locked approval-stamp-cell';
                    cell.style.background = '#f0f0f0';
                    cell.style.color = '#555';
                    cell.innerHTML = '<span class="docx-approval-pending">—</span>'
                        + (loc
                            ? '<input type="hidden" name="fields[' + loc + ']" value="">'
                            : '');
                });
            });
        });
    };

    /** Att01: Name select → auto-fill Department + Signature on the same row. */
    const syncTrainingPersonnelFromName = function (sel) {
        const role = sel.dataset.approverRole || '';
        if (role !== 'training_personnel_name') return;
        const userId = sel.value;
        const selected = docxUsers.find(function (u) { return String(u.id) === String(userId); });
        const deptLoc = sel.getAttribute('data-department-location') || '';
        const sigLoc = sel.getAttribute('data-signature-location') || '';
        const deptInput = deptLoc
            ? document.querySelector('.docx-preview-wrapper [name="fields[' + deptLoc + ']"]')
            : null;
        const sigInput = sigLoc
            ? document.querySelector('.docx-preview-wrapper [name="fields[' + sigLoc + ']"]')
            : null;
        if (deptInput) {
            deptInput.value = selected ? (selected.department || selected.designation || '') : '';
            deptInput.dispatchEvent(new Event('input', { bubbles: true }));
            deptInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (sigInput) {
            sigInput.value = selected ? (selected.name || selected.label || '') : '';
            sigInput.dispatchEvent(new Event('input', { bubbles: true }));
            sigInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
    };

    /** Hydrate signature-table / Att01 approver placeholders with user options from DB. */
    const hydrateApproverFields = function () {
        const isNeqImport = !!(document.getElementById('docxFillForm')
            && document.getElementById('docxFillForm').classList.contains('docx-import-neq'));
        document.querySelectorAll('select.approver-field').forEach(function (sel) {
            if (sel.dataset.isPreApprover === '1') return;
            // Requal HVAC pre-sig tables are often table_1/2/3 — skip those only.
            // NEQ Att11 APPROVAL PAGE is also table_3 and must receive user options.
            if (
                !isNeqImport
                && /^table_[123]_row_\d+_col_\d+$/.test(sel.dataset.fieldLocation || '')
            ) {
                return;
            }
            let role = sel.dataset.approverRole || '';
            const isTrainingSelect = role === 'training_personnel_name'
                || role === 'training_imparted_by_name';
            // Never hydrate Signature/Date columns on Role|Name|Signature|Date approval pages.
            // Att01 "Training Imparted by" is also col_3 — allow that through.
            if (!isTrainingSelect && /_col_[23]$/.test(sel.dataset.fieldLocation || '')) {
                const table = sel.closest('table');
                const header = table ? table.querySelector('tr') : null;
                const headers = header
                    ? Array.from(header.children).map(function (c) {
                        return (c.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
                    })
                    : [];
                const isApprovalPage = headers[0] === 'role'
                    && (headers[1] || '').indexOf('name') >= 0
                    && (headers[2] || '').indexOf('signature') >= 0;
                if (isApprovalPage || !role) return;
            }
            if (preApproverRoles.includes(sel.dataset.approverRole || '')) return;
            if (sel.dataset.hydrated === '1') return;
            sel.dataset.hydrated = '1';

            // Att01 table Name / Training Imparted by are never mandatory.
            // Post-approval selects must never be required during pre-approval mode.
            if (
                isTrainingSelect
                || isPreApprovalMode
                || document.querySelector('input[name="requal_pre_submit"]')
            ) {
                sel.removeAttribute('required');
                sel.required = false;
            }

            if (!role) {
                role = inferApproverRoleFromApprovalPageTable(sel);
            }
            if (!role) {
                role = inferApproverRoleFromReportApprovalTable(sel);
            }
            if (!role) {
                role = inferApproverRoleFromRow(sel);
                if (role) {
                    sel.dataset.approverRole = role;
                }
            }
            // Training / Validation selects already carry approver_role from Python.
            if (!role && !isTrainingSelect) return;
            if (!role) role = sel.dataset.approverRole || '';

            const hiddenId = approverHiddenByRole[role] ? approverHiddenByRole[role].value : '';
            const savedName = (sel.parentElement
                && sel.parentElement.querySelector('input.approver-name-field')
                    ? sel.parentElement.querySelector('input.approver-name-field').value
                    : '') || '';
            docxUsers.forEach(function (user) {
                const opt = document.createElement('option');
                opt.value = user.id;
                opt.textContent = user.label;
                opt.setAttribute('data-name', user.name || user.label || '');
                opt.setAttribute('data-department', user.department || '');
                opt.setAttribute('data-designation', user.designation || '');
                if (String(hiddenId) === String(user.id)) {
                    opt.selected = true;
                } else if (!hiddenId && savedName && (
                    savedName === user.label || savedName === user.name
                )) {
                    opt.selected = true;
                }
                sel.appendChild(opt);
            });

            sel.addEventListener('change', function () {
                const userId = sel.value;
                const fieldLoc = sel.dataset.fieldLocation || '';

                // Only real POST workflow roles write hidden IDs; never clear from empty table rows.
                if (role && approverHiddenByRole[role] && userId) {
                    approverHiddenByRole[role].value = userId;
                }

                const nameHidden = fieldLoc
                    ? document.querySelector(
                        'input.approver-name-field[data-field-location="' + fieldLoc + '"]'
                    )
                    : null;
                const selected = docxUsers.find(function (u) { return String(u.id) === String(userId); });
                if (nameHidden) {
                    nameHidden.value = selected
                        ? (selected.name || selected.label || '')
                        : '';
                }

                syncTrainingPersonnelFromName(sel);
            });

            if (sel.value) {
                sel.dispatchEvent(new Event('change'));
            }
        });
    };

    /**
     * Attachment rows whose title was treated as a heading (Dust Extractor, Area Recovery)
     * can arrive without a file input. Put Choose File on every Attachment No. data cell.
     */
    const ensureAttachmentFileInputs = function () {
        document.querySelectorAll('table.docx-table').forEach(function (table) {
            const headerRow = table.querySelector('tr');
            if (!headerRow) return;
            const headers = Array.from(headerRow.children);
            let attachCol = -1;
            headers.forEach(function (cell, idx) {
                const text = (cell.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
                if (text === 'attachment no.' || text === 'attachment no') attachCol = idx;
            });
            if (attachCol < 0) return;

            Array.from(table.querySelectorAll('tr')).forEach(function (row, rowIndex) {
                if (rowIndex === 0) return;
                const cells = Array.from(row.children);
                const cell = cells[attachCol];
                if (!cell || cell.querySelector('input[type="file"]')) return;

                let location = '';
                const marked = row.querySelector('[data-field], [name^="fields["], [name^="attachments["]');
                const raw = marked
                    ? (marked.getAttribute('data-field') || marked.getAttribute('name') || '')
                    : '';
                const match = raw.match(/table_\d+_row_\d+/);
                if (match) {
                    location = match[0] + '_col_' + attachCol;
                }
                if (!location) return;

                cell.classList.add('editable', 'docx-attachment-cell');
                cell.setAttribute('data-attachment-location', location);
                cell.innerHTML = ''
                    + '<input type="file" class="docx-attachment-field" name="attachments[' + location + ']" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">'
                    + '<small class="text-muted d-block mt-1">Upload PDF/DOCX</small>'
                    + '<input type="hidden" name="fields[' + location + ']" class="attachment-name-field" data-attachment-location="' + location + '">';
            });
        });
    };

    const isDateTimeEntryInput = function (input) {
        if (!input || input.type === 'hidden' || input.type === 'file' || input.type === 'radio' || input.type === 'checkbox') {
            return false;
        }
        const hint = ((input.getAttribute('placeholder') || '') + ' ' + (input.getAttribute('name') || '')).toLowerCase();
        if (hint.indexOf('signature') !== -1 || hint.indexOf('effective') !== -1) return false;
        // "Checked by (Engineering) (Sign. /Date)" is a written sign-off, not a calendar.
        if (/\bsign\b/.test(hint) && /\bdate\b/.test(hint)) return false;
        return /\bdate\b/.test(hint) || /\btime\b/.test(hint);
    };

    /** Date and time are picked on this protocol, including a time, not copied from the old file. */
    const prepareDateTimeInputs = function (clearValues) {
        document.querySelectorAll('input.docx-input, input.form-control').forEach(function (input) {
            if (!isDateTimeEntryInput(input)) return;
            if (input.type === 'date' || input.type === 'text' || input.type === 'datetime-local') {
                const current = input.value || input.getAttribute('value') || '';
                input.type = 'datetime-local';
                if (clearValues) {
                    input.value = '';
                } else if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(current)) {
                    input.value = current.slice(0, 16);
                } else if (/^\d{4}-\d{2}-\d{2}$/.test(current)) {
                    input.value = current + 'T00:00';
                }
            }
        });
    };

    /** Copy selected filename into visible + hidden fields[] when user picks a file. */
    const bindAttachmentFields = function () {
        document.querySelectorAll('input.docx-attachment-field').forEach(function (fileInput) {
            fileInput.addEventListener('change', function () {
                const loc = (fileInput.name.match(/attachments\[(.+)\]/) || [])[1];
                if (!loc || !fileInput.files || !fileInput.files[0]) return;
                const name = fileInput.files[0].name;
                const hidden = document.querySelector(
                    'input.attachment-name-field[data-attachment-location="' + loc + '"]'
                );
                const labelEdit = fileInput.closest('td')?.querySelector('input.docx-attachment-label-edit');
                if (hidden) hidden.value = name;
                if (labelEdit) labelEdit.value = name;
                const cell = fileInput.closest('td');
                if (cell && !cell.querySelector('a.docx-attachment-link')) {
                    const link = document.createElement('a');
                    link.className = 'docx-attachment-link';
                    link.href = '#';
                    link.textContent = name;
                    cell.insertBefore(link, cell.firstChild);
                } else if (cell) {
                    const link = cell.querySelector('a.docx-attachment-link');
                    if (link) link.textContent = name;
                }
                if (typeof window.scheduleDocxPaginate === 'function') window.scheduleDocxPaginate();
            });
        });
    };

    lockPreApprovedCells();
    setupPreApproverSync();
    lockNeqApprovalStampColumns();
    hydrateApproverFields();
    lockNeqApprovalStampColumns();
    syncDocxApproverHiddens();
    lockPreApprovedCells();
    ensureAttachmentFileInputs();
    prepareDateTimeInputs(false);
    bindAttachmentFields();

    if (form && btnSubmit) {
        const preparePreSubmitFields = function () {
            if (window.syncDocxTableOps) {
                window.syncDocxTableOps();
            }
            document.querySelectorAll('select.approver-field').forEach(function (sel) {
                sel.dispatchEvent(new Event('change'));
            });
            syncDocxApproverHiddens();

            // Never block submit on optional Att01 table Name / Training Imparted by.
            document.querySelectorAll('.docx-preview-wrapper select.approver-field').forEach(function (sel) {
                const r = sel.dataset.approverRole || '';
                if (
                    r === 'training_personnel_name'
                    || r === 'training_imparted_by_name'
                    || form.querySelector('input[name="requal_pre_submit"]')
                ) {
                    sel.removeAttribute('required');
                    sel.required = false;
                }
            });
        };

        const applySubmittingUi = function () {
            if (form.querySelector('input[name="requal_pre_submit"]')) {
                if (btnText) btnText.innerText = 'Sending for pre-approval...';
                const icon = btnSubmit.querySelector('i');
                if (icon) icon.className = 'spinner-border spinner-border-sm mr-1';
                setTimeout(function () {
                    btnSubmit.disabled = true;
                }, 10);
                return;
            }
            btnSubmit.disabled = true;
            if (btnText) btnText.innerText = 'Filling Document, please wait...';
            const icon = btnSubmit.querySelector('i');
            if (icon) icon.className = 'spinner-border spinner-border-sm mr-1';
        };

        form.addEventListener('submit', function () {
            if (typeof window.prepareDocxTocForSave === 'function') {
                window.prepareDocxTocForSave();
            }
            preparePreSubmitFields();
            // Do not lock #btnSubmit before #esignModal confirms; esign:confirmed locks it right before POST.
            if (!form.classList.contains('esign-required')) {
                applySubmittingUi();
            }
        });

        form.addEventListener('esign:confirmed', function () {
            if (typeof window.prepareDocxTocForSave === 'function') {
                window.prepareDocxTocForSave();
            }
            preparePreSubmitFields();
            applySubmittingUi();
        });
    }

    // Sync blade header inputs with Word header table field locations
    const syncHeaderPair = function (headerId, fieldName) {
        const header = document.getElementById(headerId);
        if (!header) return;
        const match = document.querySelector('[name="fields[' + fieldName + ']"]');
        if (!match) return;
        header.addEventListener('input', function () { match.value = header.value; });
        match.addEventListener('input', function () { header.value = match.value; });
        if (header.value && !match.value) match.value = header.value;
        if (match.value && !header.value) header.value = match.value;
    };

    const coverLocations = <?php echo json_encode($coverFieldLocations ?? [], 15, 512) ?>;
    const fillFromProtocolUrlTemplate = <?php echo json_encode(
        route('reports.docx-import.fill-from-protocol', ['report' => $report->id, 'sourceReport' => '__SOURCE__'])) ?>;

    const btnFillWithDocx = document.getElementById('btnFillWithDocx');
    const fillWithDocxPanel = document.getElementById('fillWithDocxPanel');
    const fillFromProtocolSelect = document.getElementById('fill_from_protocol_id');
    const fillWithDocxStatus = document.getElementById('fillWithDocxStatus');

    const showFillWithDocxStatus = function (message, type) {
        if (!fillWithDocxStatus) return;
        fillWithDocxStatus.style.display = 'block';
        fillWithDocxStatus.className = 'alert mt-3 mb-0 py-2 small alert-' + (type || 'info');
        fillWithDocxStatus.textContent = message;
    };

    const applyDocxAutofillPayload = function (payload) {
        const fields = payload.fields || {};
        let fieldCount = 0;

        Object.keys(fields).forEach(function (location) {
            const value = fields[location];
            const inputs = document.querySelectorAll('[name="fields[' + location + ']"]');
            inputs.forEach(function (input) {
                if (!input || input.type === 'file') return;
                if (input.readOnly || input.disabled) return;
                if (input.classList.contains('approver-field') || input.classList.contains('approver-name-field')) return;
                if (input.classList.contains('attachment-name-field') || input.classList.contains('docx-attachment-field')) return;
                if (isDateTimeEntryInput(input)) return;
                input.value = value;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
                fieldCount++;
            });
        });

        // Approval selects stay on "-- Select User --". Date and time stay empty
        // so this protocol can pick its own.
        prepareDateTimeInputs(true);

        
        if (coverLocations.equipment_name && fields[coverLocations.equipment_name]) {
            const el = document.getElementById('fill_equipment_name');
            if (el) {
                el.value = fields[coverLocations.equipment_name];
                el.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }
        if (coverLocations.equipment_id && fields[coverLocations.equipment_id]) {
            const el = document.getElementById('fill_equipment_id');
            if (el) {
                el.value = fields[coverLocations.equipment_id];
                el.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }

        return fieldCount;
    };

    if (btnFillWithDocx && fillWithDocxPanel) {
        btnFillWithDocx.addEventListener('click', function () {
            const isHidden = fillWithDocxPanel.style.display === 'none';
            fillWithDocxPanel.style.display = isHidden ? 'block' : 'none';
            if (isHidden && fillFromProtocolSelect) {
                fillFromProtocolSelect.focus();
            }
        });
    }

    if (fillFromProtocolSelect) {
        fillFromProtocolSelect.addEventListener('change', function () {
            const sourceId = fillFromProtocolSelect.value;
            if (!sourceId) {
                if (fillWithDocxStatus) fillWithDocxStatus.style.display = 'none';
                return;
            }

            const url = fillFromProtocolUrlTemplate.replace('__SOURCE__', encodeURIComponent(sourceId));
            showFillWithDocxStatus('Loading field data from selected protocol...', 'info');

            fetch(url, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        return { ok: response.ok, status: response.status, data: data };
                    });
                })
                .then(function (result) {
                    if (!result.ok) {
                        const message = (result.data && result.data.message)
                            ? result.data.message
                            : 'Failed to load DOCX field data.';
                        console.error('Fill with DOCX failed', {
                            status: result.status,
                            sourceId: sourceId,
                            response: result.data,
                        });
                        showFillWithDocxStatus(message, 'danger');
                        return;
                    }

                    const filled = applyDocxAutofillPayload(result.data);
                    const count = result.data.filled_count != null ? result.data.filled_count : filled;
                    const sourceCode = (result.data.source_code || '').trim() || sourceId;
                    showFillWithDocxStatus(
                        'Filled ' + count + ' matching field(s) from ' + sourceCode + '.',
                        'success'
                    );
                    if (typeof window.docxScheduleDraftSave === 'function') {
                        window.docxScheduleDraftSave();
                    }
                })
                .catch(function (error) {
                    console.error('Fill with DOCX request error', {
                        sourceId: sourceId,
                        url: url,
                        error: error,
                    });
                    showFillWithDocxStatus('Failed to load DOCX field data. Check the console for details.', 'danger');
                });
        });
    }

    const syncLetterheadCells = function (letterheadKey, value) {
        if (!letterheadKey) return;
        const text = value == null ? '' : String(value);
        document
            .querySelectorAll('.docx-page-official-header td[data-letterhead-field="' + letterheadKey + '"]')
            .forEach(function (td) {
                // Keep yellow letterhead inputs fillable — never wipe them with textContent.
                const input = td.querySelector('input.docx-input, textarea.docx-input, select.docx-input');
                if (input) {
                    if (input.value !== text) {
                        input.value = text;
                    }
                    return;
                }
                td.textContent = text;
            });
    };

    const syncMappingToHeader = function (mappingId, headerId, fieldNames, letterheadKey) {
        const mapping = mappingId ? document.getElementById(mappingId) : null;
        const header  = headerId ? document.getElementById(headerId) : null;
        const targets = [];
        (Array.isArray(fieldNames) ? fieldNames : [fieldNames]).forEach(function (fname) {
            if (!fname) return;
            document.querySelectorAll('[name="fields[' + fname + ']"]').forEach(function (el) {
                if (el !== header && !targets.includes(el)) {
                    targets.push(el);
                }
            });
        });

        // Recover initial value from header or cover field if mapping input loaded blank
        const firstFilledTarget = targets.find(function (t) { return (t.value || '').trim() !== ''; });
        const initial = (mapping && mapping.value.trim() !== '')
            ? mapping.value
            : ((header && header.value.trim() !== '') ? header.value : (firstFilledTarget ? firstFilledTarget.value : ''));

        if (initial !== '') {
            if (mapping && mapping.value !== initial) mapping.value = initial;
            if (header && header.value !== initial) header.value = initial;
        }

        const propagate = function (val, sourceEl) {
            if (mapping && sourceEl !== mapping) mapping.value = val;
            if (header && sourceEl !== header) header.value = val;
            targets.forEach(function (el) {
                if (el !== sourceEl) el.value = val;
            });
            syncLetterheadCells(letterheadKey, val);
        };

        if (mapping) {
            const onMapping = function () { propagate(mapping.value, mapping); };
            mapping.addEventListener('input', onMapping);
            mapping.addEventListener('change', onMapping);
        }
        if (header) {
            const onHeader = function () { propagate(header.value, header); };
            header.addEventListener('input', onHeader);
            header.addEventListener('change', onHeader);
        }
        targets.forEach(function (el) {
            const onTarget = function () { propagate(el.value, el); };
            el.addEventListener('input', onTarget);
            el.addEventListener('change', onTarget);
        });

        propagate(mapping ? mapping.value : (header ? header.value : initial), null);
    };

    const isNeqLetterhead = !!(document.getElementById('docxFillForm')
        && document.getElementById('docxFillForm').classList.contains('docx-import-neq'));
    const uniqLocs = function (locs) {
        return (Array.isArray(locs) ? locs : [locs]).filter(function (loc, idx, arr) {
            return !!loc && arr.indexOf(loc) === idx;
        });
    };

    if (isNeqLetterhead) {
        // NEQ Att6/Att11/Att14: ONLY sync via PHP-detected coverLocations.
        // Hardcoded HVAC/Att11 fallbacks cross-wire Att6
        // (facility=row3col2, equip name=row3col4, ref=row4col2, equip id=row4col4,
        //  document no=row5col2, version=row5col4).
        syncMappingToHeader(
            'fill_equipment_name',
            'header_equipment_name',
            uniqLocs([coverLocations.equipment_name]),
            'equipment_name'
        );
        syncMappingToHeader(
            'fill_equipment_id',
            'header_equipment_id',
            uniqLocs([coverLocations.equipment_id]),
            'equipment_id'
        );
        syncMappingToHeader(
            'fill_facility_section',
            null,
            uniqLocs([coverLocations.facility_section]),
            'facility_section'
        );
        syncMappingToHeader(
            'fill_reference_protocol_no',
            'header_ref_protocol',
            uniqLocs([
                coverLocations.reference_protocol_no,
                coverLocations.reference_document_no,
                'header_ref_protocol',
            ]),
            'reference_protocol_no'
        );
        syncMappingToHeader(
            'fill_protocol_document_no',
            'header_doc_number',
            uniqLocs([coverLocations.document_no]),
            'document_no'
        );
        // VERSION stays as Python rendered from Word (e.g. "0.2"). Do not
        // syncMappingToHeader(version) — empty stored values would wipe it.
        if (coverLocations.document_title) {
            syncMappingToHeader(
                'fill_document_title',
                null,
                uniqLocs([coverLocations.document_title]),
                'document_title'
            );
        }
    } else {
        syncMappingToHeader(
            'fill_equipment_name',
            'header_equipment_name',
            ['header_0_row_4_col_2', 'header_0_row_2_col_4', coverLocations.equipment_name],
            'equipment_name'
        );
        syncMappingToHeader(
            'fill_equipment_id',
            'header_equipment_id',
            ['header_0_row_4_col_4', 'header_0_row_3_col_4', coverLocations.equipment_id],
            'equipment_id'
        );
        syncMappingToHeader(
            'fill_protocol_document_no',
            'header_doc_number',
            [coverLocations.document_no, 'header_0_row_4_col_2'].filter(function (loc, idx, arr) {
                return !!loc && arr.indexOf(loc) === idx;
            }),
            'document_no'
        );
        syncMappingToHeader(
            'fill_reference_protocol_no',
            'header_ref_protocol',
            ['header_0_row_2_col_2', 'header_ref_protocol', coverLocations.reference_protocol_no],
            'reference_protocol_no'
        );
        syncMappingToHeader(
            'fill_document_title',
            null,
            ['header_0_row_3_col_2', coverLocations.document_title],
            'document_title'
        );
        syncMappingToHeader(
            'fill_facility_section',
            null,
            ['header_0_row_5_col_2', coverLocations.facility_section],
            'facility_section'
        );
    }

    const headerFacilityHidden = document.getElementById('header_facility_hidden');
    // Requal only: hidden facility uses header_0_row_2_col_2. NEQ Att11 uses that cell for Reference No.
    if (!isNeqLetterhead && headerFacilityHidden && headerFacilityHidden.value.trim() !== '') {
        syncLetterheadCells('facility_section', headerFacilityHidden.value);
    }
    // Requal only: header_version_hidden is bound to HVAC header_0_row_4_col_4.
    // On NEQ Att6 that location is Equipment ID — never push it into VERSION.
    const headerVersionHidden = document.getElementById('header_version_hidden');
    if (!isNeqLetterhead && headerVersionHidden && headerVersionHidden.value.trim() !== '') {
        const verText = headerVersionHidden.value.trim();
        // Ignore broken python-docx "." reads and page chrome.
        if (verText !== '.' && verText !== '0.' && verText !== '.2'
            && !/\bpage\s+\d+\s+of\s+\d+\b/i.test(verText)) {
            syncLetterheadCells('version', verText);
        }
    }

    const documentTitleLocation = <?php echo json_encode($documentTitleLocation ?? null, 15, 512) ?>;
    const fillDocumentTitle = document.getElementById('fill_document_title');
    if (fillDocumentTitle) {
        const previewTitle = documentTitleLocation
            ? document.querySelector('[name="fields[' + documentTitleLocation + ']"]')
            : null;
        const summaryTitleEl = document.getElementById('header_summary_title');
        const summaryBannerEl = document.getElementById('header_summary_banner');
        const defaultSummaryTitle = summaryTitleEl ? summaryTitleEl.textContent : '';
        const applyDocumentTitle = function () {
            const titleVal = fillDocumentTitle.value;
            if (previewTitle) previewTitle.value = titleVal;
            const effectiveTitle = titleVal.trim() !== '' ? titleVal.trim() : defaultSummaryTitle;
            if (summaryTitleEl && effectiveTitle) summaryTitleEl.textContent = effectiveTitle;
            if (summaryBannerEl && effectiveTitle) summaryBannerEl.textContent = effectiveTitle.toUpperCase();
        };
        fillDocumentTitle.addEventListener('input', applyDocumentTitle);
        fillDocumentTitle.addEventListener('change', applyDocumentTitle);
        if (!fillDocumentTitle.value && previewTitle && previewTitle.value) {
            fillDocumentTitle.value = previewTitle.value;
        }
        applyDocumentTitle();
    }
    

    const effectiveDateLocation = <?php echo json_encode($effectiveDateLocation ?? null, 15, 512) ?>;
    const fillEffectiveDate = document.getElementById('fill_effective_date');
    const toIsoDate = function (value) {
        if (!value) return '';
        const v = String(value).trim();
        const dmy = v.match(/^(\d{2})-(\d{2})-(\d{4})$/);
        if (dmy) return dmy[3] + '-' + dmy[2] + '-' + dmy[1];
        return v;
    };
    const injectEffectiveDateParagraphInputs = function () {
        const defaultDate = fillEffectiveDate ? toIsoDate(fillEffectiveDate.value) : '';
        document.querySelectorAll('.docx-preview p.docx-para').forEach(function (p) {
            const raw = (p.textContent || '').trim();
            if (!/effective\s*date/i.test(raw)) return;
            if (p.querySelector('input[type="date"]')) return;

            const loc = effectiveDateLocation || ('para_effective_' + Array.from(document.querySelectorAll('input[name^="fields[para_"]')).length);
            const label = 'Effective Date: ';
            const input = document.createElement('input');
            input.type = 'date';
            input.name = 'fields[' + loc + ']';
            input.className = 'docx-input-inline';
            input.value = defaultDate;

            p.textContent = '';
            p.classList.add('docx-para-editable');
            p.appendChild(document.createTextNode(label));
            p.appendChild(input);

            if (fillEffectiveDate) {
                input.addEventListener('input', function () { fillEffectiveDate.value = input.value; });
                input.addEventListener('change', function () { fillEffectiveDate.value = input.value; });
            }
        });
    };
    if (effectiveDateLocation && fillEffectiveDate) {
        const previewEffective = document.querySelector('[name="fields[' + effectiveDateLocation + ']"]');
        const applyEffectiveDate = function () {
            if (previewEffective) {
                previewEffective.value = toIsoDate(fillEffectiveDate.value);
            }
        };
        fillEffectiveDate.addEventListener('input', applyEffectiveDate);
        fillEffectiveDate.addEventListener('change', applyEffectiveDate);
        if (!fillEffectiveDate.value && previewEffective && previewEffective.value) {
            fillEffectiveDate.value = toIsoDate(previewEffective.value);
        }
        applyEffectiveDate();
    }
    injectEffectiveDateParagraphInputs();

    
    Object.keys(approverHiddenByRole).forEach(function (role) {
        const hidden = approverHiddenByRole[role];
        if (!hidden || !hidden.value) return;
        document.querySelectorAll('select.approver-field').forEach(function (sel) {
            if (sel.dataset.isPreApprover === '1') return;
            const selRole = sel.dataset.approverRole
                || inferApproverRoleFromApprovalPageTable(sel)
                || inferApproverRoleFromReportApprovalTable(sel)
                || inferApproverRoleFromRow(sel);
            if (selRole === role) {
                sel.value = String(hidden.value);
            }
        });
        const named = document.querySelector('select[name="' + role + '"]');
        if (named) {
            named.value = String(hidden.value);
        }
    });
    syncDocxApproverHiddens();

    // ── Autosave / resume draft (no e-sign, no approval start) ──
    const draftSaveUrl = <?php echo json_encode(route('reports.docx-import.draft', $report), 512) ?>;
    const draftStatusEl = document.getElementById('docxDraftStatus');
    const btnSaveDraft = document.getElementById('btnSaveDraft');
    let draftTimer = null;
    let draftSaving = false;
    let draftPending = false;
    let draftDirty = false;
    let finalSubmitting = false;

    const setDraftStatus = function (message, tone) {
        if (!draftStatusEl) return;
        draftStatusEl.style.display = 'inline-block';
        draftStatusEl.className = 'badge shadow-sm mr-2 px-3 py-2 '
            + (tone === 'danger' ? 'badge-danger'
                : (tone === 'saving' ? 'badge-warning text-dark' : 'badge-light text-secondary'));
        draftStatusEl.innerHTML = message;
    };

    const collectDraftFormData = function () {
        if (typeof window.syncDocxTableOps === 'function') {
            window.syncDocxTableOps({ quiet: true });
        }
        const fd = new FormData(form);
        fd.delete('password');
        // Empty file inputs must not be posted. A chosen file is part of the draft.
        form.querySelectorAll('input[type="file"]').forEach(function (input) {
            if (!input.name) return;
            if (!input.files || !input.files.length) {
                fd.delete(input.name);
            }
        });
        // Pagination may clone header inputs; keep last value per fields[...] key.
        const seen = {};
        const cleaned = new FormData();
        fd.forEach(function (value, key) {
            if (key === 'password') return;
            if (typeof key === 'string' && key.indexOf('fields[') === 0) {
                seen[key] = value;
                return;
            }
            cleaned.append(key, value);
        });
        Object.keys(seen).forEach(function (key) {
            cleaned.append(key, seen[key]);
        });
        return cleaned;
    };

    const paintSavedAttachment = function (loc, info) {
        if (!loc || !info || !info.url) return;
        const cell = document.querySelector('td[data-attachment-location="' + loc + '"]');
        if (!cell) return;
        let link = cell.querySelector('a.docx-attachment-link');
        if (!link) {
            link = document.createElement('a');
            link.className = 'docx-attachment-link';
            link.target = '_blank';
            cell.insertBefore(link, cell.firstChild);
        }
        link.href = info.url;
        link.textContent = info.name || 'Open attachment';
        const hidden = cell.querySelector('input.attachment-name-field');
        if (hidden) hidden.value = info.name || hidden.value;
        const fileInput = cell.querySelector('input[type="file"]');
        if (fileInput) fileInput.value = '';
        if (typeof window.scheduleDocxPaginate === 'function') window.scheduleDocxPaginate();
    };

    const saveDraftNow = function (opts) {
        opts = opts || {};
        const silent = opts.silent !== false; // autosave default: silent (no TOC reflow)
        if ((requalFillLocked && !isPreApprovalMode) || !form || finalSubmitting) {
            return Promise.resolve(false);
        }
        if (draftSaving) {
            draftPending = true;
            draftDirty = true;
            return Promise.resolve(false);
        }
        // Heavy TOC/paginate only on explicit save/submit — never on every autosave.
        // Running it here jumped the viewport to page 1 and re-fired draft in a loop.
        if (!silent) {
            try {
                if (typeof window.prepareDocxTocForSave === 'function') {
                    window.docxWithPreservedScroll
                        ? window.docxWithPreservedScroll(window.prepareDocxTocForSave)
                        : window.prepareDocxTocForSave();
                }
            } catch (tocErr) {
                console.warn('DOCX TOC prepare skipped during draft save', tocErr);
            }
        }
        draftSaving = true;
        draftDirty = false;
        setDraftStatus('<i class="fas fa-spinner fa-spin mr-1"></i> Saving draft…', 'saving');
        if (btnSaveDraft) {
            btnSaveDraft.disabled = true;
        }

        let body;
        try {
            body = collectDraftFormData();
        } catch (collectErr) {
            console.warn('DOCX draft FormData collect failed', collectErr);
            draftSaving = false;
            draftDirty = true;
            setDraftStatus('<i class="fas fa-exclamation-triangle mr-1"></i> Draft could not be collected.', 'danger');
            if (btnSaveDraft) btnSaveDraft.disabled = false;
            return Promise.resolve(false);
        }

        return fetch(draftSaveUrl, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': (form.querySelector('input[name="_token"]') || {}).value || '',
            },
            credentials: 'same-origin',
            body: body,
            keepalive: !!opts.keepalive,
        })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { ok: response.ok, data: data };
                }).catch(function () {
                    return { ok: response.ok, data: {} };
                });
            })
            .then(function (result) {
                if (!result.ok || !result.data.success) {
                    const msg = (result.data && result.data.message)
                        ? result.data.message
                        : 'Draft could not be saved.';
                    setDraftStatus('<i class="fas fa-exclamation-triangle mr-1"></i> ' + msg, 'danger');
                    draftDirty = true;
                    return false;
                }
                const when = result.data.saved_at || '';
                const savedFiles = result.data.attachments || {};
                Object.keys(savedFiles).forEach(function (loc) {
                    paintSavedAttachment(loc, savedFiles[loc]);
                });
                setDraftStatus(
                    '<i class="fas fa-cloud-upload-alt mr-1"></i> Draft saved'
                        + (when ? (' ' + when) : ''),
                    'ok'
                );
                return true;
            })
            .catch(function () {
                setDraftStatus('<i class="fas fa-exclamation-triangle mr-1"></i> Draft save failed (network).', 'danger');
                draftDirty = true;
                return false;
            })
            .finally(function () {
                draftSaving = false;
                if (btnSaveDraft) {
                    btnSaveDraft.disabled = false;
                }
                if (draftPending && !finalSubmitting) {
                    draftPending = false;
                    // Coalesce rapid keystroke queue into one follow-up save.
                    setTimeout(function () {
                        if (!finalSubmitting) saveDraftNow({ silent: true });
                    }, 400);
                }
            });
    };

    const scheduleDraftSave = function () {
        draftDirty = true;
        // Don't stampede /draft while Add Row is mutating the DOM.
        if (window.docxAddRowBusy) {
            return;
        }
        if (draftTimer) {
            clearTimeout(draftTimer);
        }
        // Longer debounce — date pickers / typing were flooding /draft and
        // making Add Row clicks feel ignored during saves.
        draftTimer = setTimeout(function () {
            if (window.docxAddRowBusy) {
                scheduleDraftSave();
                return;
            }
            saveDraftNow({ silent: true });
        }, 2200);
    };
    window.docxScheduleDraftSave = scheduleDraftSave;
    window.docxSaveDraftNow = saveDraftNow;

    // Capture edits on preview fields even if pagination moves nodes; always autosave.
    const draftInputTarget = function (t) {
        if (!t) return false;
        if (t.name === 'password') return false;
        // Programmatic TOC page sync must not start another draft+paginate loop.
        if (t.classList && (t.classList.contains('docx-toc-page-input') || t.classList.contains('docx-toc-title-input'))) {
            return false;
        }
        if (window.docxTocSyncing) return false;
        if (t.closest && !t.closest('#docxFillForm, .docx-preview-wrapper')) return false;
        return true;
    };
    document.addEventListener('input', function (e) {
        const t = e.target;
        if (!draftInputTarget(t) || (t.type === 'file')) return;
        scheduleDraftSave();
    });
    document.addEventListener('change', function (e) {
        const t = e.target;
        if (!draftInputTarget(t)) return;
        if (t.type === 'file') {
            if (!t.files || !t.files.length) return;
            if (draftTimer) clearTimeout(draftTimer);
            saveDraftNow({ silent: true });
            return;
        }
        scheduleDraftSave();
    });

    if (form) {
        form.addEventListener('submit', function () {
            if (draftTimer) {
                clearTimeout(draftTimer);
            }
            // Freeze TOC to the same pages the 2nd approver will see.
            try {
                if (typeof window.prepareDocxTocForSave === 'function') {
                    window.prepareDocxTocForSave();
                }
            } catch (tocErr) {}
            if (!form.classList.contains('esign-required')) {
                finalSubmitting = true;
            }
        });
        form.addEventListener('esign:confirmed', function () {
            try {
                if (typeof window.prepareDocxTocForSave === 'function') {
                    window.prepareDocxTocForSave();
                }
            } catch (tocErr) {}
            finalSubmitting = true;
            if (draftTimer) {
                clearTimeout(draftTimer);
            }
        });
    }

    if (btnSaveDraft) {
        btnSaveDraft.addEventListener('click', function () {
            if (draftTimer) {
                clearTimeout(draftTimer);
            }
            saveDraftNow({ silent: false });
        });
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden' && draftDirty && !finalSubmitting) {
            if (draftTimer) {
                clearTimeout(draftTimer);
            }
            saveDraftNow({ keepalive: true, silent: true });
        }
    });
    window.addEventListener('pagehide', function () {
        if (draftDirty && !finalSubmitting) {
            saveDraftNow({ keepalive: true, silent: true });
        }
    });

    /* ── Table of Contents (TOC) Auto-Sync Page Numbers ── */
    (function () {
        function headingTextOf(el) {
            if (!el) return '';
            const prefixEl = el.querySelector && el.querySelector(':scope > .docx-num-prefix, .docx-num-prefix');
            const prefix = prefixEl ? (prefixEl.textContent || '').replace(/\s+/g, ' ').trim() : '';
            const fromData = (el.dataset && el.dataset.headingText || '').replace(/\s+/g, ' ').trim();
            let fromDom = (el.textContent || '').replace(/\s+/g, ' ').trim();
            // Prefer short heading title; avoid swallowing an entire table cell.
            if (fromDom.length > 180 && fromData) {
                fromDom = fromData;
            }
            if (prefix && !new RegExp('^' + prefix.replace(/\./g, '\\.') + '\\b').test(fromDom)) {
                return (prefix + ' ' + (fromData || fromDom)).replace(/\s+/g, ' ').trim();
            }
            if (/\d+(?:\.\d+)/.test(fromDom)) {
                return fromDom;
            }
            if (fromData && fromDom && fromData.toUpperCase() !== fromDom.toUpperCase()) {
                return (fromData + ' ' + fromDom).replace(/\s+/g, ' ').trim();
            }
            return fromDom || fromData;
        }

        function pageNumberFromSheet(page, pages) {
            if (!page) {
                return 0;
            }
            // Same digit the sheet header/footer shows ("Page 18 of 19").
            const stamped = page.querySelector(
                '.docx-page-official-header .current-page-num, .docx-page-official-footer .current-page-num, .current-page-num'
            );
            if (stamped) {
                const n = parseInt((stamped.textContent || '').replace(/\D+/g, ''), 10);
                if (!isNaN(n) && n > 0) {
                    return n;
                }
            }
            const attr = parseInt(page.getAttribute('data-page-number') || '', 10);
            if (!isNaN(attr) && attr > 0) {
                return attr;
            }
            const list = pages || (typeof previewA4Pages === 'function'
                ? previewA4Pages()
                : Array.from(document.querySelectorAll('.docx-preview .docx-a4-page')));
            const index = list.indexOf(page);
            return index >= 0 ? (index + 1) : 0;
        }

        function realPageNumberOf(el) {
            if (!el || !el.closest) {
                return 0;
            }
            const pages = typeof previewA4Pages === 'function'
                ? previewA4Pages()
                : Array.from(document.querySelectorAll('.docx-preview .docx-a4-page'));

            // After pagination, the owning A4 sheet is ground truth (fixes 15.0 → 17 while
            // the visible Revision History heading lives on stamped page 18).
            const domPage = el.closest('.docx-a4-page');
            if (domPage && el.closest('.docx-page-body')) {
                const domNum = pageNumberFromSheet(domPage, pages);
                if (domNum > 0) {
                    return domNum;
                }
            }

            // Geometry fallback: best-fit sheet (not first hit) for mid-reflow orphans.
            if (pages.length) {
                const rect = el.getBoundingClientRect();
                const y = rect.top + Math.min(8, Math.max(1, rect.height / 2));
                let best = null;
                let bestEdge = -1;
                for (let i = 0; i < pages.length; i++) {
                    const box = pages[i].getBoundingClientRect();
                    if (y < box.top - 2 || y > box.bottom + 2) {
                        continue;
                    }
                    const edge = Math.min(y - box.top, box.bottom - y);
                    if (edge > bestEdge) {
                        bestEdge = edge;
                        best = pages[i];
                    }
                }
                if (best) {
                    return pageNumberFromSheet(best, pages);
                }
            }
            return pageNumberFromSheet(domPage, pages);
        }

        function extractSecNum(text) {
            const m = String(text || '').trim().match(/^(\d+(?:\.\d+)+)\b/);
            return m ? m[1] : '';
        }

        function syncTocPageNumbers(silent) {
            const preview = document.querySelector('.docx-preview-wrapper') || document.querySelector('.docx-preview');
            if (!preview) return;

            const tocInputs = Array.from(preview.querySelectorAll('.docx-toc-page-input'));
            const tocSpans = Array.from(preview.querySelectorAll('.docx-toc-line .toc-page'));
            if (tocInputs.length === 0 && tocSpans.length === 0) return;

            const syncBtn = document.getElementById('btnAutoSyncToc');
            if (syncBtn && !silent) {
                const icon = syncBtn.querySelector('i');
                if (icon) icon.classList.add('fa-spin');
                syncBtn.disabled = true;
            }

            try {
                // Keep A4 sheet numbers current before reading them.
                if (typeof updateAllPageNumbers === 'function') {
                    updateAllPageNumbers();
                }

                const a4Pages = Array.from(preview.querySelectorAll('.docx-a4-page'));
                const totalPages = a4Pages.length;

                function isTocContext(el) {
                    return !!(el && el.closest && (
                        el.closest('.docx-toc-card')
                        || el.closest('.docx-toc-line')
                        || el.closest('.docx-page-official-header, .docx-page-official-footer, header, footer')
                    ));
                }

                function hostForPrefix(prefixEl) {
                    return prefixEl.closest(
                        'p.docx-para, p.docx-heading-target, .docx-heading-target, .docx-para-edit-wrapper, h1, h2, h3, h4, h5, td.docx-cell, th.docx-cell'
                    ) || prefixEl.parentElement;
                }

                // Collect ALL section candidates; pick best by title when resolving TOC rows.
                // First-wins caused 3.0 RESPONSIBILITY to stick on page 3 after Add Row moved it to 5.
                const anchors = [];
                const seen = new Set();
                // secNum → latest stamped sheet (walk pages top→bottom so 13.0 REFERENCES on 17 wins over stale 15).
                const sectionPageMap = {};

                function pushAnchor(secNum, el, text) {
                    if (!secNum || !el || isTocContext(el)) return;
                    const pageNo = realPageNumberOf(el);
                    const pageEl = el.closest('.docx-a4-page');
                    if (pageEl && pageEl.querySelector('.docx-page-body .docx-toc-card, .docx-page-body .docx-toc-line')) {
                        return;
                    }
                    if (pageNo <= 1 && /^\d+\.0$/.test(secNum)) {
                        return;
                    }
                    const flat = String(text || '').replace(/\s+/g, ' ').trim();
                    const key = secNum + '::' + pageNo + '::' + flat.slice(0, 60);
                    if (seen.has(key)) return;
                    seen.add(key);
                    const anchor = {
                        secNum: secNum,
                        el: el,
                        text: flat,
                        page: pageNo
                    };
                    anchors.push(anchor);
                    if (/^\d+\.0$/.test(secNum) && pageNo > 0) {
                        const prev = sectionPageMap[secNum];
                        const titled = /^\d+\.0\s+\S/i.test(flat);
                        if (!prev || pageNo > prev.page || (pageNo === prev.page && titled)) {
                            sectionPageMap[secNum] = anchor;
                        }
                    }
                }

                Array.from(preview.querySelectorAll('.docx-heading-target, p.docx-para.docx-heading-target, h1, h2, h3, h4, h5')).forEach(function (el) {
                    const text = headingTextOf(el);
                    if (text.length > 160) return;
                    pushAnchor(extractSecNum(text), el, text);
                });

                Array.from(preview.querySelectorAll('.docx-num-prefix')).forEach(function (prefixEl) {
                    const sec = (prefixEl.textContent || '').replace(/\s+/g, ' ').trim();
                    if (!/^\d+(?:\.\d+)+$/.test(sec)) return;
                    // Major TOC sections only from bare list prefixes (avoid 3.1 checklist noise).
                    if (!/^\d+\.0$/.test(sec)) return;
                    const host = hostForPrefix(prefixEl);
                    if (!host || isTocContext(host)) return;
                    // Attachment grid "7.0 / 7.1" cells must not become TOC section anchors.
                    if (host.closest && host.closest('table.docx-table') && !host.classList.contains('docx-heading-target')) {
                        return;
                    }
                    const text = headingTextOf(host);
                    if (text.length > 160 && !host.classList.contains('docx-heading-target')) return;
                    pushAnchor(sec, host, text || sec);
                });

                Array.from(preview.querySelectorAll('.docx-page-body p.docx-para, .docx-page-body .docx-para-edit-wrapper')).forEach(function (el) {
                    if (isTocContext(el)) return;
                    // Never treat attachment/table grid rows (7.0 Calibration…) as TOC section anchors.
                    if (el.closest('table.docx-table')) return;
                    const text = headingTextOf(el);
                    if (text.length > 140) return;
                    const sec = extractSecNum(text);
                    if (!sec || !/^\d+\.0$/.test(sec)) return;
                    if (!/^\d+\.0\s+\S/.test(text)) return;
                    pushAnchor(sec, el, text);
                });

                function normalizeTitle(s) {
                    return String(s || '').toUpperCase().replace(/[\.\:\-_]+/g, ' ').replace(/\s+/g, ' ').trim();
                }

                function scoreAnchorForToc(anchor, words) {
                    const raw = anchor.text || '';
                    const txt = normalizeTitle(raw);
                    let score = 0;
                    // Real section title: "3.0 RESPONSIBILITY"
                    if (/^\d+\.0\s+\S/i.test(raw)) score += 50;
                    if (anchor.el && anchor.el.classList && anchor.el.classList.contains('docx-heading-target')) {
                        score += 35;
                    }
                    if (words && words.length) {
                        const hit = words.filter(function (kw) { return txt.includes(kw); }).length;
                        score += hit * 30;
                        // Bare "3.0" without the TOC title loses to the real heading.
                        if (hit === 0) score -= 40;
                    }
                    if (raw.length <= 90) score += 12;
                    if (raw.length <= 50) score += 8;
                    if (raw.length < 8) score -= 20;
                    return score;
                }

                function findAnchor(secNum, words) {
                    const major = secNum && /^\d+$/.test(secNum) ? (secNum + '.0') : secNum;
                    // Prefer the latest stamped sheet for this section (REFERENCES on 17, not stale 15).
                    if (major && sectionPageMap[major]) {
                        const mapped = sectionPageMap[major];
                        if (words && words.length) {
                            const txt = normalizeTitle(mapped.text);
                            const hit = words.some(function (kw) { return txt.includes(kw); });
                            if (hit || /^\d+\.0\s+\S/i.test(mapped.text)) {
                                return mapped;
                            }
                        } else {
                            return mapped;
                        }
                    }

                    const want = [];
                    if (secNum) {
                        anchors.forEach(function (a) {
                            if (a.secNum === secNum) want.push(a);
                        });
                        if (!want.length && major) {
                            anchors.forEach(function (a) {
                                if (a.secNum === major) want.push(a);
                            });
                        }
                    }
                    if (want.length) {
                        // Prefer titled matches (e.g. "13.0 REFERENCES") over bare "13.0".
                        let pool = want;
                        if (words && words.length) {
                            const titled = want.filter(function (a) {
                                const txt = normalizeTitle(a.text);
                                return words.some(function (kw) { return txt.includes(kw); });
                            });
                            if (titled.length) {
                                pool = titled;
                            }
                        }
                        let best = pool[0];
                        let bestScore = scoreAnchorForToc(best, words);
                        let bestPage = realPageNumberOf(best.el) || best.page || 0;
                        for (let i = 1; i < pool.length; i++) {
                            const score = scoreAnchorForToc(pool[i], words);
                            const page = realPageNumberOf(pool[i].el) || pool[i].page || 0;
                            // Content only grows downward: later sheet wins when scores are close.
                            if (page > bestPage && score >= bestScore - 40) {
                                best = pool[i];
                                bestScore = score;
                                bestPage = page;
                            } else if (score > bestScore && page >= bestPage) {
                                best = pool[i];
                                bestScore = score;
                                bestPage = page;
                            } else if (score > bestScore + 40) {
                                best = pool[i];
                                bestScore = score;
                                bestPage = page;
                            }
                        }
                        return best;
                    }
                    if (!words || !words.length) return null;
                    let best = null;
                    let bestScore = -1;
                    let bestPage = 0;
                    anchors.forEach(function (a) {
                        if (a.text.length > 120) return;
                        const score = scoreAnchorForToc(a, words);
                        const need = Math.min(1, words.length);
                        const hit = words.filter(function (kw) {
                            return normalizeTitle(a.text).includes(kw);
                        }).length;
                        if (hit < need) return;
                        const page = realPageNumberOf(a.el) || a.page || 0;
                        if (score > bestScore || (score === bestScore && page > bestPage)) {
                            bestScore = score;
                            bestPage = page;
                            best = a;
                        }
                    });
                    return best;
                }

                function tocMetaFrom(el) {
                    const line = el.closest ? el.closest('.docx-toc-line') : null;
                    let secNum = ((el.dataset && el.dataset.tocSec) || (line && line.dataset.tocSec) || '').trim();
                    let secTitle = ((el.dataset && el.dataset.tocTitle) || (line && line.dataset.tocTitle) || '').trim().toUpperCase();
                    // Live title input may have been edited; prefer it for word matching.
                    const titleInput = line && line.querySelector('.docx-toc-title-input');
                    if (titleInput && titleInput.value) {
                        const live = String(titleInput.value || '').replace(/\s+/g, ' ').trim();
                        const m = live.match(/^(\d+(?:\.\d+)*)\s*(.*)$/);
                        if (m) {
                            if (!secNum) secNum = m[1];
                            if (m[2]) secTitle = m[2].toUpperCase();
                        } else if (live) {
                            secTitle = live.toUpperCase();
                        }
                    }
                    const cleanTitle = normalizeTitle(secTitle.replace(/^\d+(?:\.\d+)*\s*/, ''));
                    const words = cleanTitle.split(/\s+/).filter(function (w) {
                        return w.length > 2 && !['AND', 'THE', 'FOR', 'ANY', 'OF'].includes(w);
                    });
                    return { secNum: secNum, words: words };
                }

                function applyPage(node, pageNo, isInput) {
                    if (!pageNo || pageNo < 1) return;
                    if (totalPages > 0 && pageNo > totalPages) pageNo = totalPages;
                    if (isInput) {
                        const curVal = parseInt(node.value, 10);
                        if (isNaN(curVal) || curVal !== pageNo) {
                            node.value = String(pageNo);
                            if (!silent) {
                                node.dispatchEvent(new Event('input', { bubbles: true }));
                                node.dispatchEvent(new Event('change', { bubbles: true }));
                            }
                        }
                    } else {
                        node.textContent = String(pageNo);
                    }
                }

                // Walk TOC in order so page numbers stay monotonic like the original Word TOC.
                const tocNodes = tocInputs.length
                    ? tocInputs.map(function (input) { return { node: input, input: true }; })
                    : tocSpans.map(function (span) { return { node: span, input: false }; });

                let lastPage = 0;
                tocNodes.forEach(function (item) {
                    const meta = tocMetaFrom(item.node);
                    const anchor = findAnchor(meta.secNum, meta.words);
                    // Always re-read the stamped sheet number for the heading's owning page.
                    let pageNo = anchor ? realPageNumberOf(anchor.el) : 0;
                    if (pageNo < 1 && anchor) {
                        pageNo = anchor.page || 0;
                    }
                    if (pageNo < 1) {
                        return;
                    }
                    if (lastPage > 0 && pageNo < lastPage) {
                        pageNo = lastPage;
                    }
                    lastPage = pageNo;
                    applyPage(item.node, pageNo, item.input);
                });

                if (syncBtn && !silent) {
                    setTimeout(function () {
                        const icon = syncBtn.querySelector('i');
                        if (icon) icon.classList.remove('fa-spin');
                        syncBtn.disabled = false;
                    }, 400);
                }
            } catch (err) {
                console.warn('TOC auto-sync error:', err);
                if (syncBtn) {
                    const icon = syncBtn.querySelector('i');
                    if (icon) icon.classList.remove('fa-spin');
                    syncBtn.disabled = false;
                }
            }
        }

        window.syncTocPageNumbers = syncTocPageNumbers;

        // Paginate twice so signature/select height is included, then write TOC.
        window.prepareDocxTocForSave = function () {
            const runPass = function () {
                if (typeof window.paginateDocxPreview === 'function') {
                    window.paginateDocxPreview();
                } else if (typeof window.updateAllPageNumbers === 'function') {
                    window.updateAllPageNumbers();
                }
                if (typeof updateAllPageNumbers === 'function') {
                    updateAllPageNumbers();
                }
                syncTocPageNumbers(true);
            };
            const exec = function () {
                window.docxTocSyncing = true;
                try {
                    runPass();
                    // Force layout, then recompute — fixes 15.0 showing 16 while content is on 17.
                    const preview = document.querySelector('.docx-preview-wrapper, .docx-preview');
                    if (preview) {
                        void preview.offsetHeight;
                    }
                    runPass();
                } finally {
                    window.docxTocSyncing = false;
                }
                return true;
            };
            if (typeof window.docxWithPreservedScroll === 'function') {
                window.docxWithPreservedScroll(exec);
                return true;
            }
            return exec();
        };

        // Attach to sync button clicks
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('#btnAutoSyncToc, .btn-sync-toc');
            if (btn) {
                e.preventDefault();
                if (typeof window.prepareDocxTocForSave === 'function') {
                    window.prepareDocxTocForSave();
                }
                // Late pass after selects/fonts finish affecting page breaks.
                setTimeout(function () {
                    if (typeof window.prepareDocxTocForSave === 'function') {
                        window.prepareDocxTocForSave();
                    } else {
                        syncTocPageNumbers(false);
                    }
                }, 320);
            }
        });

        let initialTocSynced = false;
        function scheduleTocPrepare(delay) {
            setTimeout(function () {
                if (initialTocSynced) return;
                initialTocSynced = true;
                if (typeof window.docxWithPreservedScroll === 'function') {
                    window.docxWithPreservedScroll(function () {
                        // Initial layout once, then stay put while the user edits.
                        if (typeof window.scheduleDocxPaginate === 'function') {
                            window.scheduleDocxPaginate();
                        } else {
                            syncTocPageNumbers(true);
                        }
                    });
                } else if (typeof window.scheduleDocxPaginate === 'function') {
                    window.scheduleDocxPaginate();
                } else {
                    syncTocPageNumbers(true);
                }
            }, delay);
        }

        // One quiet layout pass after first paint (not a double prepareDocxTocForSave).
        window.addEventListener('DOMContentLoaded', function () {
            if (typeof window.dedupeIdenticalAddedRows === 'function') {
                window.dedupeIdenticalAddedRows();
            }
            scheduleTocPrepare(1600);
        });

        if (document.readyState === 'complete' || document.readyState === 'interactive') {
            if (typeof window.dedupeIdenticalAddedRows === 'function') {
                window.dedupeIdenticalAddedRows();
            }
            scheduleTocPrepare(1600);
        }

        // While typing: only refresh TOC page numbers (silent). Never paginate —
        // full layout during edit was redirecting requal users to the top page.
        let tocDebounce = null;
        document.addEventListener('input', function (e) {
            if (e.target && (e.target.tagName === 'TEXTAREA' || e.target.classList.contains('docx-para-content'))) {
                clearTimeout(tocDebounce);
                tocDebounce = setTimeout(function () {
                    if (typeof window.docxWithPreservedScroll === 'function') {
                        window.docxWithPreservedScroll(function () {
                            syncTocPageNumbers(true);
                        });
                    } else {
                        syncTocPageNumbers(true);
                    }
                }, 2500);
            }
        });
    })();

    <?php if(empty($requalFillLocked) && in_array(($importSource ?? ''), ['requalification', 'new_equipment_qualification'], true)): ?>
    (function () {
        const btnEsignStamp = document.getElementById('btnEsignStamp');
        const esignStatusEl = document.getElementById('docxEsignStatus');
        const preview = document.querySelector('.docx-preview-wrapper');
        const signerName = <?php echo json_encode(trim((auth()->user()->first_name ?? '').' '.(auth()->user()->last_name ?? '')) ?: (auth()->user()->email ?? 'User'), 15, 512) ?>;
        let lastSignField = null;
        const esignUndo = new WeakMap();

        const pushEsignUndo = function (field, previous, stamped) {
            if (!field || previous === stamped) return;
            const stack = esignUndo.get(field) || [];
            stack.push({ previous: previous, stamped: stamped });
            esignUndo.set(field, stack);
        };

        const looksLikeEsign = function (value) {
            const text = String(value || '').replace(/\s+/g, ' ').trim();
            if (!/\d{2}-\d{2}-\d{4}\s+\d{1,2}:\d{2}\s+(AM|PM)$/i.test(text)) return false;
            return text.indexOf(signerName) === 0
                || text.indexOf('Verified by sign and date : ' + signerName) === 0
                || /^safety\s*(?:checks?\s*)?s?\s*by\b.*:\s*/i.test(text)
                    && text.indexOf(signerName) !== -1;
        };

        const undoEsignValue = function (field) {
            const stack = esignUndo.get(field);
            if (stack && stack.length) {
                const top = stack[stack.length - 1];
                if (field.value === top.stamped) {
                    stack.pop();
                    field.value = top.previous;
                    return true;
                }
            }
            if (!looksLikeEsign(field.value)) return false;
            field.value = '';
            return true;
        };

        const showEsignStatus = function (message, ok) {
            if (!esignStatusEl) return;
            esignStatusEl.style.display = 'inline-block';
            esignStatusEl.className = 'badge shadow-sm mr-2 px-3 py-2 '
                + (ok ? 'badge-success' : 'badge-warning text-dark');
            esignStatusEl.textContent = message;
        };

        const isStampableField = function (el) {
            if (!el || !preview || !preview.contains(el)) return false;
            if (el.disabled || el.readOnly) return false;
            const tag = (el.tagName || '').toLowerCase();
            if (tag !== 'textarea' && tag !== 'input') return false;
            const type = (el.getAttribute('type') || 'text').toLowerCase();
            if (tag === 'input' && type !== 'text' && type !== 'search') return false;
            const name = el.getAttribute('name') || '';
            return name.indexOf('fields[') === 0;
        };

        const rememberSignField = function (fromNode) {
            if (!fromNode || !fromNode.closest) return;
            const direct = fromNode.closest('input, textarea');
            if (isStampableField(direct)) {
                lastSignField = direct;
                return;
            }
            const cell = fromNode.closest('td, th, p, .docx-textbox');
            if (!cell) return;
            const field = cell.querySelector('input, textarea');
            if (isStampableField(field)) {
                lastSignField = field;
            }
        };

        if (preview) {
            preview.addEventListener('focusin', function (event) {
                rememberSignField(event.target);
            });
            preview.addEventListener('click', function (event) {
                rememberSignField(event.target);
            });
            preview.addEventListener('keydown', function (event) {
                if ((event.key !== 'z' && event.key !== 'Z') || event.altKey || event.shiftKey) return;
                if (!event.ctrlKey && !event.metaKey) return;
                const field = event.target;
                if (!isStampableField(field) || !undoEsignValue(field)) return;
                event.preventDefault();
                event.stopPropagation();
                field.dispatchEvent(new Event('input', { bubbles: true }));
                field.dispatchEvent(new Event('change', { bubbles: true }));
            }, true);
        }

        const stampWhen = function () {
            const now = new Date();
            const pad = function (n) { return String(n).padStart(2, '0'); };
            let hours = now.getHours();
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12 || 12;
            return pad(now.getDate()) + '-' + pad(now.getMonth() + 1) + '-' + now.getFullYear()
                + ' ' + pad(hours) + ':' + pad(now.getMinutes()) + ' ' + ampm;
        };

        if (btnEsignStamp) {
            btnEsignStamp.addEventListener('mousedown', function (event) {
                event.preventDefault();
            });
            btnEsignStamp.addEventListener('click', function () {
                const field = isStampableField(lastSignField) ? lastSignField : null;
                if (!field) {
                    showEsignStatus('Click a sign cell first, then click E-Sign.', false);
                    return;
                }
                const when = stampWhen();
                const previous = field.value;
                const hint = ((field.getAttribute('placeholder') || '') + ' ' + (field.value || ''))
                    .replace(/\s+/g, ' ')
                    .trim();
                const verifiedLabel = /^verified\s*by\s*sign\s*and\s*date\b/i.test(hint);
                const safetyByMatch = hint.match(/^(safety\s*(?:checks?\s*)?s?\s*by\b.*?(?:sign\.?\s*\/?\s*date)?)/i);
                if (verifiedLabel) {
                    field.value = 'Verified by sign and date : ' + signerName + ' ' + when;
                } else if (safetyByMatch) {
                    const base = (safetyByMatch[1] || 'Safety Checks By (Sign./Date)')
                        .replace(/\s*:\s*$/, '')
                        .replace(/\s+/g, ' ')
                        .trim();
                    field.value = base + ' : ' + signerName + ' ' + when;
                } else if ((field.tagName || '').toLowerCase() === 'textarea') {
                    field.value = signerName + '\n' + when;
                } else {
                    field.value = signerName + '  ' + when;
                }
                pushEsignUndo(field, previous, field.value);
                field.focus();
                field.dispatchEvent(new Event('input', { bubbles: true }));
                field.dispatchEvent(new Event('change', { bubbles: true }));
                if (esignStatusEl) {
                    esignStatusEl.style.display = 'none';
                    esignStatusEl.textContent = '';
                }
            });
        }
    })();
    <?php endif; ?>

    /* ── DOCX A4 Page Navigator ── */
    (function () {
        const navEl = document.getElementById('docxPageNavigator');
        if (!navEl) return;

        const selectJump = document.getElementById('docxSelectPageJump');
        const btnPrev = document.getElementById('btnDocxPrevPage');
        const btnNext = document.getElementById('btnDocxNextPage');
        // Do not paginate from the navigator boot path — that double layout was
        // jumping the viewport to page 1 while the user typed on later pages.
        const pageList = function () {
            if (typeof previewA4Pages === 'function') {
                return previewA4Pages();
            }
            return Array.from(document.querySelectorAll('.docx-preview .docx-a4-page, .docx-a4-page'));
        };

        if (!pageList().length) {
            navEl.style.display = 'none';
            return;
        }

        if (selectJump) {
            selectJump.addEventListener('change', function () {
                const pagesNow = pageList();
                const targetNum = parseInt(selectJump.value, 10);
                const targetPage = document.getElementById('docxPage' + targetNum) || pagesNow[targetNum - 1];
                if (targetPage) {
                    targetPage.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        }
        if (typeof window.updateAllPageNumbers === 'function') {
            window.updateAllPageNumbers();
        }

        const getCurrentPageIndex = function () {
            const pagesNow = pageList();
            const scrollPos = window.scrollY + 180;
            let currentIdx = 0;
            for (let i = 0; i < pagesNow.length; i++) {
                const top = pagesNow[i].getBoundingClientRect().top + window.scrollY;
                if (top <= scrollPos) {
                    currentIdx = i;
                } else {
                    break;
                }
            }
            return currentIdx;
        };

        if (btnPrev) {
            btnPrev.addEventListener('click', function () {
                const pagesNow = pageList();
                const curr = getCurrentPageIndex();
                if (curr > 0) {
                    pagesNow[curr - 1].scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        }

        if (btnNext) {
            btnNext.addEventListener('click', function () {
                const pagesNow = pageList();
                const curr = getCurrentPageIndex();
                if (curr < pagesNow.length - 1) {
                    pagesNow[curr + 1].scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        }

        let scrollTimer = null;
        window.addEventListener('scroll', function () {
            if (scrollTimer) clearTimeout(scrollTimer);
            scrollTimer = setTimeout(function () {
                const curr = getCurrentPageIndex();
                if (selectJump && selectJump.value !== String(curr + 1)) {
                    selectJump.value = String(curr + 1);
                }
            }, 100);
        }, { passive: true });

        setTimeout(function () {
            if (typeof window.updateAllPageNumbers === 'function') {
                window.updateAllPageNumbers();
            }
        }, 300);
    })();
});
</script>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?><?php /**PATH D:\lupin\latest Lupin2\valiflow-main\resources\views/reports/docx_import/fill.blade.php ENDPATH**/ ?>