@extends('layouts.app')

@push('styles')
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
    justify-content: space-between;
    overflow: hidden; /* Page height NEVER expands */
}

.docx-page-body {
    flex: 1 1 auto;
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
    height: auto;
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

body.premium-app #docxFillForm .docx-preview-wrapper textarea.docx-input {
    min-height: 72px !important;
    resize: vertical;
}

body.premium-app #docxFillForm .docx-preview-wrapper textarea.docx-para-content {
    min-height: 48px !important;
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
@endpush

@section('content')
<div id="docxFloatingActionsContainer" aria-label="Floating Actions">
    @if(!empty($isPreApprovalMode) && (int)($report->created_by ?? 0) === (int)auth()->id())
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
    @endif

    @if(($importSource ?? '') === 'requalification' && empty($requalFillLocked))
        <div id="docxEsignDock" aria-label="E-Sign">
            <button type="button" class="btn btn-light" id="btnEsignStamp"
                    title="Click a cell, then stamp your name and today's date">
                <i class="fas fa-signature mr-1"></i> E-Sign
            </button>
            <span id="docxEsignStatus" class="badge badge-light text-secondary px-3 py-2" style="display:none;"></span>
        </div>
    @endif

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
                <a href="{{ route('reports.show', $report) }}" class="text-decoration-none text-secondary">
                    <i class="fas fa-arrow-left mr-1"></i> Back to {{ $report->displayCode() }}
                </a>
            </div>

            <div class="card shadow border-0">
                <div class="card-header py-3" style="background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <h5 class="mb-0 font-weight-bold text-white">
                                <i class="fas fa-file-signature mr-2"></i>
                                {{ ($importSourceSectionTitle ?? '') !== '' ? $importSourceSectionTitle : 'Fill DOCX Fields' }}
                            </h5>
                            <p class="mb-0 text-white-50 small mt-1">
                                @if(!empty($isPreApprovalMode))
                                    Pre-Approval Drafting: All protocol descriptions, specifications, and acceptance criteria are editable. Use "+ Add Row" and "Delete Row" to customize tables before sending for Pre-Approval.
                                @elseif(!empty($requalFillLocked))
                                    Pre-approval in progress. The document stays locked until every pre-approver has approved.
                                @elseif(in_array(($importSource ?? ''), ['requalification', 'revalidation'], true) && ($report->status ?? '') === 'pre_approved')
                                    Pre-approval complete! Protocol structure is locked. Fill observed values, pass/fail results, and submit for Post-Approval.
                                @elseif(($importSource ?? '') === 'new_equipment_qualification')
                                    Fill New Equipment Qualification — empty cells are editable; pre-approved content stays locked.
                                @elseif(!empty($useTablePreview))
                                    Document table layout — empty cells are editable, filled cells are read-only.
                                @else
                                    Flat field list — empty cells are editable, filled cells are read-only.
                                @endif
                            </p>
                        </div>
                        <div class="d-flex align-items-center">
                            <span id="docxDraftStatus" class="badge badge-light text-secondary shadow-sm mr-2 px-3 py-2"
                                  @if(empty($draftSavedAt)) style="display:none;" @endif>
                                @if(!empty($draftSavedAt))
                                    <i class="fas fa-cloud-upload-alt mr-1"></i> Draft saved {{ $draftSavedAt }}
                                @endif
                            </span>
                            <span class="badge badge-light px-3 py-2 text-primary shadow-sm font-weight-bold">
                                {{ count($fields) }} Total Fields
                            </span>
                        </div>
                    </div>
                </div>

                <form action="{{ route('reports.docx-import.fill', $report) }}" method="POST" id="docxFillForm" class="esign-required {{ (!empty($requalFillLocked) && empty($isPreApprovalMode)) ? 'requal-fill-locked' : '' }}" enctype="multipart/form-data">
                    @csrf
                    <input type="hidden" name="password">
                    <input type="hidden" name="table_ops" id="docxTableOpsInput" value="{{ json_encode($report->data['table_ops'] ?? []) }}">
                    {{-- Hidden header cell values synced from  and table inputs --}}
                    <input type="hidden" name="fields[header_0_row_2_col_2]" id="header_facility_hidden"
                           value="{{ old('fields.header_0_row_2_col_2', $facilitySection) }}">
                    <input type="hidden" name="fields[header_0_row_4_col_4]" id="header_version_hidden"
                           value="{{ old('fields.header_0_row_4_col_4', $report->version ?? '') }}">
                    <div class="card-body px-4 py-4">

                        @if (session('error'))
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle mr-2"></i> {{ session('error') }}
                            </div>
                        @endif

                        @if ($errors->any())
                            <div class="alert alert-danger">
                                <ul class="mb-0 pl-3">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if(in_array(($importSource ?? ''), ['requalification', 'revalidation'], true) && ($report->status ?? '') === 'pre_approved')
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle mr-1"></i> Pre-approval is complete! All template specifications and acceptance criteria are now locked. Please fill the execution results below and submit for Post-Approval.
                            </div>
                        @endif

                        @if(!empty($requalFillLocked))
                            <div class="card mb-4 border" id="requalPreApprovalCard">
                                <div class="card-header font-weight-bold">Pre Approval</div>
                                <div class="card-body">
                                    @if(!empty($requalPreEditable) || !empty($isPreApprovalMode))
                                        <p class="mb-3">
                                            Select all seven pre-approval users in the order shown. Approval goes to each person one by one. Once approved, the document structure and acceptance criteria will be locked.
                                        </p>
                                        <input type="hidden" name="requal_pre_submit" value="1">
                                    @else
                                        <div class="alert alert-warning mb-3">
                                            Pre-approval is in progress. The document stays locked until every selected user has approved.
                                        </div>
                                    @endif
                                    <div class="row">
                                        @foreach(\App\Http\Controllers\DocxImportController::preApproverFieldTitles() as $roleKey => $roleTitle)
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label font-weight-bold" for="pre_{{ $roleKey }}">{{ $roleTitle }}</label>
                                                <select class="form-control" id="pre_{{ $roleKey }}" name="{{ $roleKey }}"
                                                        @if(empty($requalPreEditable) && empty($isPreApprovalMode)) disabled @else required @endif>
                                                    <option value="">-- Select User --</option>
                                                    @foreach($users as $user)
                                                        <option value="{{ $user->id }}"
                                                            {{ (string) old($roleKey, $draftPreApprovers[$roleKey] ?? '') === (string) $user->id ? 'selected' : '' }}>
                                                            {{ $user->display_name }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @endif

                        @if(empty($requalFillLocked))
                        <div class="card mb-4 border" id="fillWithDocxCard">
                            {{-- Copy field values from an already-approved DOCX protocol --}}
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
                                            @forelse(($approvedDocxProtocols ?? []) as $protocol)
                                                <option value="{{ $protocol->id }}">
                                                    {{ $protocol->fill_option_label }}
                                                </option>
                                            @empty
                                                <option value="" disabled>No approved {{ $importSourceLabel ?? 'DOCX' }} protocols available</option>
                                            @endforelse
                                        </select>
                                        <small class="text-muted d-block mt-1">
                                            @if(trim((string) ($importSourceLabel ?? '')) !== '')
                                                Select an approved {{ $importSourceLabel }} protocol to copy matching field values into this form.
                                            @else
                                                Select an approved protocol to copy matching field values into this form.
                                            @endif
                                        </small>
                                    </div>
                                </div>
                                <div id="fillWithDocxStatus" class="alert mt-3 mb-0 py-2 small" style="display: none;" role="alert"></div>
                            </div>
                        </div>
                        @endif

                        <div class="card mb-4 border {{ !empty($requalFillLocked) ? 'requal-locked-block' : '' }}">
                            {{-- Link report to approved document master and equipment metadata --}}
                            <div class="card-header">Document Mapping</div>
                            <div class="card-body">
                                <div class="row g-3">
                                    {{-- <div class="col-md-6">
                                        <label class="form-label font-weight-bold">Approved Document</label>
                                        <input type="hidden" name="document_id" id="document_id_hidden"
                                               value="{{ old('document_id', $report->document_no) }}">
                                        <select class="form-control" id="fill_document_id" data-docx-fill-select="1">
                                            <option value="">-- Select Approved Document --</option>
                                            @foreach(($documents ?? []) as $document)
                                                <option value="{{ $document->id }}"
                                                        data-facility="{{ $document->facility->facility_name ?? '' }}"
                                                        data-equipment-name="{{ $document->equipment->equipment_name ?? '' }}"
                                                        data-equipment-id="{{ $document->equipment->equipment_id ?? '' }}"
                                                        {{ (string) old('document_id', $report->document_no) === (string) $document->id ? 'selected' : '' }}>
                                                    {{ $document->id }} - {{ $document->title }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label font-weight-bold">Version</label>
                                        <input type="text" name="version" class="form-control"
                                               value="{{ old('version', $report->version) }}">
                                    </div> --}}
                                    <div class="col-md-12">
                                        <label class="form-label font-weight-bold">Protocol / Document Title </label>
                                        <input type="text" name="document_title" id="fill_document_title" class="form-control"
                                               value="{{ old('document_title', $documentTitle ?? '') }}"
                                               placeholder="Enter protocol or document title"
                                               >
                                        <small class="text-muted">This title appears in the protocol list after you submit.</small>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label font-weight-bold">Document Number</label>
                                        <input type="text" name="protocol_document_no" id="fill_protocol_document_no" class="form-control"
                                               value="{{ old('protocol_document_no', $protocolDocumentNo ?? '') }}"
                                               placeholder="From original protocol, or enter if blank">
                                        <small class="text-muted">Shown on the report header. Auto-filled when present in the uploaded DOCX.</small>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label font-weight-bold">Reference Protocol No</label>
                                        <input type="text" name="reference_protocol_no" id="fill_reference_protocol_no" class="form-control"
                                               value="{{ old('reference_protocol_no', $referenceProtocolNo ?? '') }}"
                                               placeholder="From original protocol, or enter if blank">
                                        <small class="text-muted">Shown on the report header. Auto-filled when present in the uploaded DOCX.</small>
                                    </div>
                                    {{-- <div class="col-md-4">
                                        <label class="form-label font-weight-bold">Facility / Section</label>
                                        <input type="text" name="facility_section" id="fill_facility_section" class="form-control"
                                               value="{{ old('facility_section', $facilitySection) }}">
                                    </div> --}}
                                    <div class="col-md-6">
                                        <label class="form-label font-weight-bold">Equipment Name <span class="text-danger">*</span></label>
                                        <input type="text" name="equipment_name" id="fill_equipment_name" class="form-control"
                                               value="{{ old('equipment_name', $equipmentName) }}"
                                               required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label font-weight-bold">Equipment ID <span class="text-danger">*</span></label>
                                        <input type="text" name="equipment_id" id="fill_equipment_id" class="form-control"
                                               value="{{ old('equipment_id', $equipmentId) }}"
                                               required>
                                    </div>
                                    @if(!empty($hasEffectiveDateField))
                                        <div class="col-md-4">
                                            <label class="form-label font-weight-bold">Effective Date</label>
                                            <input type="date" name="effective_date" id="fill_effective_date" class="form-control"
                                                   value="{{ old('effective_date', $effectiveDate ?? '') }}">
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>

                        @php
                            $presentApproverRoles = $presentApproverRoles ?? [];
                            $hasApproverFields = count($presentApproverRoles) > 0;
                        @endphp

                        {{-- POST approver IDs: only for roles that exist in this report's parsed DOCX fields. --}}
                        @if($hasApproverFields)
                            @php $draftApprovers = $draftApprovers ?? []; @endphp
                            @if(in_array('post_prepared_by_name', $presentApproverRoles, true))
                                <input type="hidden" name="post_prepared_by_name" id="post_prepared_by_name_value"
                                       value="{{ old('post_prepared_by_name', $draftApprovers['post_prepared_by_name'] ?? auth()->id()) }}">
                            @endif
                            @foreach ([
                                'post_checked_by_engineering_name' => 'post_checked_by_engineering_name_value',
                                'post_checked_by_production_name'  => 'post_checked_by_production_name_value',
                                'post_checked_by_quality_name'     => 'post_checked_by_quality_name_value',
                                'post_checked_by_validation_name'  => 'post_checked_by_validation_name_value',
                                'post_approved_by_quality_name'    => 'post_approved_by_quality_name_value',
                            ] as $roleKey => $inputId)
                                @if(in_array($roleKey, $presentApproverRoles, true))
                                    <input type="hidden" name="{{ $roleKey }}" id="{{ $inputId }}"
                                           value="{{ old($roleKey, $draftApprovers[$roleKey] ?? '') }}">
                                @endif
                            @endforeach
                        @endif

                        @if(!empty($usesApprovalPage) && $hasApproverFields)
                            <div class="alert alert-info mb-4">
                                <i class="fas fa-user-check mr-1"></i>
                                <strong>Approval Page:</strong> Select <strong>Performed By</strong>,
                                <strong>Checked By</strong>, and <strong>Approved By</strong> users in the signature table below.
                                Signature and Date columns are filled automatically when each approval step completes.
                                <strong>Performed By</strong> defaults to you
                                ({{ trim((auth()->user()->first_name ?? '') . ' ' . (auth()->user()->last_name ?? '')) ?: 'Current user' }}).
                            </div>
                        @elseif($hasApproverFields)
                            <div class="alert alert-info mb-4">
                                <i class="fas fa-user-check mr-1"></i>
                                Select approvers in the <strong>Prepared By</strong>, <strong>Checked By</strong>, and
                                <strong>Approved By</strong> tables below. Those users will receive POST approval workflow steps.
                            </div>
                        @endif

                        @if(!empty($isPreApprovalMode))
                            <div class="alert alert-primary py-2 px-3 mb-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <div class="d-flex align-items-center">
                                    <span class="badge badge-primary mr-2"><i class="fas fa-edit mr-1"></i> Pre-Approval Drafting Mode</span>
                                    <span class="small font-weight-bold text-dark">
                                        All protocol descriptions, specifications, and acceptance criteria are editable. Use the floating <span class="badge badge-primary px-2 py-1"><i class="fas fa-plus"></i> Add Row</span> and <span class="badge badge-danger px-2 py-1"><i class="fas fa-trash-alt"></i> Delete Row</span> buttons on the top-right to customize tables.
                                    </span>
                                </div>
                            </div>
                        @endif

                        {{-- Primary UI: Python-rendered DOCX table with inline editable cells --}}
                        @if(!empty($useTablePreview))
                            @php
                                $templateName = trim((string) ($documentTitle ?? '')) !== ''
                                    ? (string) $documentTitle
                                    : (\App\Models\Report::importSourceLabel($importSource ?? '')
                                        ?: ($report->template->name ?? 'PERIODIC HVAC VALIDATION REPORT'));
                            @endphp
                            <div class="docx-preview-wrapper mb-4">
                                @if(!empty($showLegacyBladeHeader))
                                {{-- Protocol Identity Summary Bar (crisp readable header synced to A4 letterheads) --}}
                                <div class="docx-summary-header-card">
                                    <table class="docx-table header-table" style="margin-bottom:0;">
                                        <tr>
                                            <td rowspan="3" style="width:96px; text-align:center; vertical-align:middle !important; background:#ffffff !important;">
                                                <img src="{{ asset('storage/lupin.png') }}" alt="Lupin" style="height:46px; width:auto;" onerror="this.style.display='none'">
                                                <div style="font-size:11px; font-weight:700; margin-top:4px; color:#047857;">LUPIN</div>
                                            </td>
                                            <td colspan="4" id="header_summary_banner" style="text-align:center; font-weight:700; font-size:15px; padding:10px !important; background:#f1f5f9 !important; color:#0f172a !important;">
                                                {{ strtoupper($templateName) }}
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="width:22%; background:#f8fafc !important;">
                                                <span class="docx-summary-field-label">Title</span>
                                                <span class="docx-summary-title-value" id="header_summary_title">{{ $templateName }}</span>
                                            </td>
                                            <td style="width:26%;">
                                                <label class="docx-summary-field-label" for="header_doc_number">Document Number</label>
                                                <input type="text" name="fields[header_0_row_4_col_2]" id="header_doc_number" class="docx-input"
                                                       placeholder="Enter document number..."
                                                       value="{{ old('fields.header_0_row_4_col_2', $protocolDocumentNo ?? $documentNo) }}">
                                            </td>
                                            <td style="width:26%;">
                                                <label class="docx-summary-field-label" for="header_equipment_name">Equipment Name</label>
                                                <input type="text" name="fields[header_0_row_2_col_4]" id="header_equipment_name" class="docx-input"
                                                       value="{{ old('fields.header_0_row_2_col_4', $equipmentName) }}">
                                            </td>
                                            <td style="width:26%;">
                                                <label class="docx-summary-field-label" for="header_equipment_id">Equipment ID</label>
                                                <input type="text" name="fields[header_0_row_3_col_4]" id="header_equipment_id" class="docx-input"
                                                       value="{{ old('fields.header_0_row_3_col_4', $equipmentId) }}">
                                            </td>
                                        </tr>
                                        <tr>
                                            <td colspan="4">
                                                <label class="docx-summary-field-label" for="header_ref_protocol">Reference Protocol No</label>
                                                <input type="text" name="fields[header_ref_protocol]" id="header_ref_protocol" class="docx-input"
                                                       placeholder="Enter reference protocol number..."
                                                       value="{{ old('fields.header_ref_protocol', $referenceProtocolNo ?? '') }}">
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                                @endif

                                {!! $previewHtml !!}
                            </div>

                            {{-- Hidden inputs for locked fields (not editable in table preview) --}}
                            @foreach($fields as $field)
                                @php
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
                                @endphp
                                @if($isLocked && $location !== '')
                                    <input type="hidden" name="fields[{{ $location }}]" value="{{ $value }}">
                                @endif
                            @endforeach

                        @else
                            {{-- Fallback flat field list when Python table preview is unavailable --}}
                            <div class="alert alert-warning mb-3">
                                <i class="fas fa-exclamation-triangle mr-1"></i>
                                @if(empty($docxTemplateAvailable))
                                    The original DOCX template file is missing on the server.
                                    Start the Python service on port 8001 and ensure a generated DOCX attachment exists,
                                    or re-import the document to restore the table layout.
                                @else
                                    Table preview unavailable — ensure the Python service is running on port 8001.
                                    Showing field list instead.
                                @endif
                            </div>
                            <div class="row">
                                @forelse(($flatListFields ?? $fields) as $field)
                                    @php
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
                                    @endphp
                                    <div class="col-12 mb-3">
                                        <div class="d-flex align-items-center justify-content-between mb-1">
                                            <label class="form-label font-weight-bold mb-0 {{ $isLocked ? 'text-muted' : 'text-dark' }}">
                                                {{ $label }}
                                            </label>
                                            @if($isLocked)
                                                <span class="badge badge-secondary text-xs">
                                                    <i class="fas fa-lock mr-1"></i> Locked
                                                </span>
                                            @else
                                                <span class="badge badge-light text-primary text-xs">
                                                    <i class="fas fa-edit mr-1"></i> Editable
                                                </span>
                                            @endif
                                        </div>

                                        @if($isLocked)
                                            <div class="form-control-plaintext bg-light px-3 py-2 rounded border text-muted" style="min-height:38px;">
                                                {{ $value !== '' ? $value : '—' }}
                                            </div>
                                            <input type="hidden" name="fields[{{ $location }}]" value="{{ $value }}">
                                        @else
                                            @if(strlen($value) > 100 || str_contains(strtolower($label), 'description') || str_contains(strtolower($label), 'remark'))
                                                <textarea name="fields[{{ $location }}]"
                                                          class="form-control"
                                                          rows="3"
                                                          placeholder="Enter {{ strtolower($label) }}...">{{ old('fields.'.$location, $value) }}</textarea>
                                            @else
                                                <input type="text"
                                                       name="fields[{{ $location }}]"
                                                       class="form-control"
                                                       value="{{ old('fields.'.$location, $value) }}"
                                                       placeholder="Enter {{ strtolower($label) }}...">
                                            @endif
                                        @endif

                                        <div class="d-flex justify-content-between mt-1 text-xs text-muted">
                                            <span>Type: <code>{{ $type }}</code></span>
                                            <span>Location: <code>{{ $location }}</code></span>
                                        </div>
                                    </div>
                                @empty
                                    <div class="col-12 text-center py-5">
                                        <h6 class="text-muted">No Fields Detected</h6>
                                    </div>
                                @endforelse
                            </div>
                        @endif

                        @php
                            $preApprovalSteps = $report->relationLoaded('approvalSteps')
                                ? $report->approvalSteps->where('stage', 'pre')->sortBy('step_order')
                                : $report->approvalSteps()->where('stage', 'pre')->orderBy('step_order')->get();
                        @endphp
                        @if($preApprovalSteps->isNotEmpty())
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
                                                @foreach($preApprovalSteps as $step)
                                                    <tr>
                                                        <td class="text-center font-weight-bold">{{ $step->step_order }}</td>
                                                        <td>
                                                            <span class="badge badge-info">{{ $step->label ?? 'Approver' }}</span>
                                                        </td>
                                                        <td>
                                                            <div class="font-weight-bold text-dark">
                                                                {{ $step->user->first_name ?? '' }} {{ $step->user->last_name ?? '' }}
                                                            </div>
                                                            @if(!empty($step->user->designation))
                                                                <div class="small text-muted">{{ $step->user->designation }}</div>
                                                            @endif
                                                        </td>
                                                        <td>
                                                            @if($step->approved_at)
                                                                <span class="badge badge-success text-light"><i class="fas fa-check mr-1"></i> Approved</span>
                                                            @elseif($step->rejected_at)
                                                                <span class="badge badge-danger text-light"><i class="fas fa-times mr-1"></i> Rejected</span>
                                                            @else
                                                                <span class="badge badge-secondary text-light">Pending</span>
                                                            @endif
                                                        </td>
                                                        <td>
                                                            {{ $step->approved_at ? \Carbon\Carbon::parse($step->approved_at)->timezone('Asia/Kolkata')->format('d-m-Y h:i A') : '-' }}
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        @endif

                    </div>

                    <div class="card-footer bg-light px-4 py-3 d-flex justify-content-end align-items-center">
                        {{-- Cancel does not leave this form; left out.
                        <a href="{{ route('reports.show', $report) }}" class="btn btn-light shadow-sm">
                            <i class="fas fa-times mr-1"></i> Cancel
                        </a>
                        --}}
                        @if(!empty($requalFillLocked) && !empty($requalPreEditable) && (int)($report->created_by ?? 0) === (int)auth()->id())
                            <button type="submit" class="btn btn-primary shadow-sm" id="btnSubmit">
                                <i class="fas fa-user-check mr-1"></i>
                                <span id="btnText">Send for Pre Approval</span>
                            </button>
                        @elseif(empty($requalFillLocked) && count($fields) > 0 && (int)($report->created_by ?? 0) === (int)auth()->id())
                            <button type="submit" class="btn btn-primary shadow-sm" id="btnSubmit">
                                <i class="fas fa-file-export mr-1"></i>
                                <span id="btnText">Generate & Submit for Post Approval</span>
                            </button>
                        @endif
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
    const requalFillLocked = @json(!empty($requalFillLocked));
    const isPreApprovalMode = @json(!empty($isPreApprovalMode));

    if (requalFillLocked && !isPreApprovalMode && form) {
        form.querySelectorAll('.docx-preview-wrapper input, .docx-preview-wrapper select, .docx-preview-wrapper textarea, .requal-locked-block input, .requal-locked-block select, .requal-locked-block textarea').forEach(function (el) {
            el.disabled = true;
        });
        form.querySelectorAll('.docx-preview-wrapper [contenteditable="true"]').forEach(function (el) {
            el.setAttribute('contenteditable', 'false');
        });
    }

    let tableOps = @json($report->data['table_ops'] ?? []);
    let selectedRow = null;
    let selectedTable = null;

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

        const thRow = sourceTable.querySelector('tr:first-child');
        if (thRow && thRow.querySelector('th')) {
            const clonedTh = thRow.cloneNode(true);
            clonedTh.classList.remove('docx-selected-row');
            clonedTh.querySelectorAll('[name]').forEach(function (el) { el.removeAttribute('name'); });
            newTable.appendChild(clonedTh);
        }
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

    function updateAllPageNumbers() {
        const allPages = Array.from(document.querySelectorAll('.docx-a4-page'));
        const total = allPages.length;

        allPages.forEach(function (page, idx) {
            const pNum = idx + 1;
            page.setAttribute('data-page-number', String(pNum));
            page.id = 'docxPage' + pNum;

            // Update Header page numbers
            const headerTbl = page.querySelector('.docx-page-official-header');
            if (headerTbl) {
                headerTbl.querySelectorAll('.current-page-num').forEach(function (el) {
                    el.textContent = String(pNum);
                });
                headerTbl.querySelectorAll('.total-pages-count').forEach(function (el) {
                    el.textContent = String(total);
                });
                headerTbl.querySelectorAll('td').forEach(function (td) {
                    if (/page\s*\d+\s*of\s*\d+/i.test(td.textContent)) {
                        td.innerHTML = 'Page <span class="current-page-num">' + pNum + '</span> of <span class="total-pages-count">' + total + '</span>';
                    }
                });
            }

            // Update Footer page numbers
            const footerEl = page.querySelector('.docx-page-official-footer');
            if (footerEl) {
                const pageSpan = footerEl.querySelector('.docx-footer-page');
                if (pageSpan) {
                    pageSpan.innerHTML = 'Page <span class="current-page-num">' + pNum + '</span> of <span class="total-pages-count">' + total + '</span>';
                }
            }
        });

        // Update Dividers
        const dividers = Array.from(document.querySelectorAll('.docx-page-break-indicator'));
        dividers.forEach(function (div, idx) {
            const badge = div.querySelector('.docx-page-break-badge');
            if (badge) {
                badge.innerHTML = '<i class="fas fa-file-alt mr-1"></i> Page ' + (idx + 2);
            }
        });

        // Update Page Jump selector
        const selectJump = document.getElementById('docxSelectPageJump');
        if (selectJump) {
            const curr = selectJump.value || '1';
            selectJump.innerHTML = '';
            for (let i = 1; i <= total; i++) {
                const opt = document.createElement('option');
                opt.value = String(i);
                opt.textContent = 'Page ' + i + ' of ' + total;
                selectJump.appendChild(opt);
            }
            selectJump.value = parseInt(curr, 10) <= total ? curr : '1';
        }
    }

    function placeRowAtStart(table, row) {
        const first = table.rows[0];
        if (first && first.querySelector('th')) first.after(row);
        else if (first) table.insertBefore(row, first);
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
                const before = lastRow.previousElementSibling;
                placeRowAtStart(continuationTable(page, last), lastRow);
                if (last.contains(lastRow) || (before && before.nextElementSibling === lastRow)) break;
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
        return newPage;
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
        const header = sourceTable.rows[0];
        if (header && header.querySelector('th')) {
            const clonedHeader = header.cloneNode(true);
            clonedHeader.classList.remove('docx-selected-row');
            clonedHeader.querySelectorAll('input, textarea, select').forEach(function (el) {
                el.removeAttribute('name');
            });
            table.appendChild(clonedHeader);
        }
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

        if (last.classList.contains('docx-toc-card')) {
            const lines = last.querySelectorAll(':scope > .docx-toc-line');
            if (lines.length > 1) {
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
            if (row.offsetHeight + 12 >= body.clientHeight && last.rows.length <= 2 && body.childElementCount === 1) {
                return false;
            }
            const table = continuationTable(page, last);
            const header = table.rows[0];
            if (header && header !== row) header.after(row);
            else table.appendChild(row);
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

        const overflowPx = body.scrollHeight - body.clientHeight;
        if (last.offsetHeight > Math.max(overflowPx, 0) + 48 && body.childElementCount > 1) {
            return false;
        }

        const nextPage = followingA4Page(page) || createContinuationPage(page);
        const nextBody = nextPage.querySelector('.docx-page-body');
        nextBody.insertBefore(last, nextBody.firstChild);
        return true;
    }

    function contentOverflows(pageBody) {
        if (!pageBody) return false;
        return pageEdge(pageBody) > pageBody.getBoundingClientRect().bottom + 6;
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
        return last.getBoundingClientRect().bottom;
    }

    function pageFreeSpace(pageBody) {
        if (!pageBody) return 0;
        return pageBody.getBoundingClientRect().bottom - 8 - pageEdge(pageBody);
    }

    function pageIsOverfull(pageBody) {
        if (!pageBody) return false;
        return pageEdge(pageBody) > pageBody.getBoundingClientRect().bottom - 2;
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

    function pullTableRowBack(body, sourceTable) {
        const head = sourceTable.rows[0] && sourceTable.rows[0].querySelector('th') ? sourceTable.rows[0] : null;
        const row = head ? sourceTable.rows[1] : sourceTable.rows[0];
        if (!row) {
            if (!sourceTable.querySelector('[name]')) sourceTable.remove();
            return true;
        }
        const last = body.lastElementChild;
        const same = last && last.matches && last.matches('table.docx-table')
            && (last.getAttribute('data-table-index') || '') === (sourceTable.getAttribute('data-table-index') || '');
        if (same && rowLabel(row) && last.rows[0] && rowLabel(row) === rowLabel(last.rows[0])) {
            row.remove();
            return true;
        }
        if (!same && sourceTable.offsetHeight + 6 < pageFreeSpace(body)) {
            return pullBlockBack(body, sourceTable.parentNode, sourceTable);
        }
        let target = same ? last : null;
        let created = false;
        if (!target) {
            target = document.createElement('table');
            target.className = sourceTable.className;
            const tIdx = sourceTable.getAttribute('data-table-index') || '';
            if (tIdx) target.setAttribute('data-table-index', tIdx);
            if (head) {
                const clone = head.cloneNode(true);
                clone.querySelectorAll('[id]').forEach(function (el) { el.removeAttribute('id'); });
                clone.querySelectorAll('[name]').forEach(function (el) { el.removeAttribute('name'); });
                target.appendChild(clone);
            }
            body.appendChild(target);
            created = true;
        }
        const marker = row.nextSibling;
        target.appendChild(row);
        if (pageIsOverfull(body)) {
            if (marker) sourceTable.insertBefore(row, marker);
            else sourceTable.appendChild(row);
            if (created) target.remove();
            return false;
        }
        const leftoverHead = sourceTable.rows.length === 1 && sourceTable.rows[0].querySelector('th') && !sourceTable.querySelector('[name]');
        if (!sourceTable.rows.length || leftoverHead) sourceTable.remove();
        return true;
    }

    function pullOneBack(page) {
        const body = page.querySelector('.docx-page-body');
        const next = followingA4Page(page);
        if (!body || !next || pageFreeSpace(body) < 28) return false;
        const nextBody = next.querySelector('.docx-page-body');
        const first = nextBody && nextBody.firstElementChild;
        if (!first) return false;
        if (first.matches('table.docx-table') && !first.classList.contains('header-table')) {
            return pullTableRowBack(body, first);
        }
        if (first.classList.contains('docx-toc-card')) {
            const line = first.querySelector('.docx-toc-line');
            const last = body.lastElementChild;
            if (line && last && last.classList.contains('docx-toc-card') && first.offsetHeight + 6 >= pageFreeSpace(body)) {
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
        return pullBlockBack(body, nextBody, first);
    }

    function fillPageGaps() {
        let page = document.querySelector('.docx-preview .docx-a4-page');
        let pulls = 0;
        while (page && pulls < 700) {
            const next = followingA4Page(page);
            if (!next) break;
            if (pageFreeSpace(page.querySelector('.docx-page-body')) < 28 || !pullOneBack(page)) {
                page = next;
                continue;
            }
            pulls++;
            dropEmptySheet(next);
        }
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

        fillPageGaps();

        let page = document.querySelector('.docx-preview .docx-a4-page');
        let moves = 0;
        while (page && moves < 250) {
            const pageBody = page.querySelector('.docx-page-body');
            if (!contentOverflows(pageBody)) {
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
            if (contentOverflows(pageBody) && rowStillHere && pageBody.scrollHeight >= before) {
                page = followingA4Page(page);
            }
        }

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
        updateAllPageNumbers();
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
    function scheduleDocxPaginate() {
        if (paginateFrame) cancelAnimationFrame(paginateFrame);
        paginateFrame = requestAnimationFrame(function () {
            unlockDeviationColumns();
            paginateDocxPreview();
        });
    }
    window.scheduleDocxPaginate = scheduleDocxPaginate;

    document.addEventListener('input', function (event) {
        const el = event.target;
        if (!el || !el.matches || !el.matches('.docx-preview textarea')) return;
        el.style.height = 'auto';
        el.style.height = el.scrollHeight + 'px';
        const page = el.closest('.docx-a4-page');
        if (page) reflowPageOverflow(page);
    });

    if (isPreApprovalMode) {
        const rowInfo = document.getElementById('selectedRowInfo');
        const btnAddRow = document.getElementById('btnDocxAddRow');
        const btnDeleteRow = document.getElementById('btnDocxDeleteRow');
        const tableOpsInput = document.getElementById('docxTableOpsInput');

        // Row selection on cell click inside preview tables
        document.addEventListener('click', function (e) {
            const cell = e.target.closest('.docx-preview-wrapper table.docx-table:not(.header-table) td, .docx-preview-wrapper table.docx-table:not(.header-table) th');
            if (!cell) return;
            const row = cell.closest('tr');
            if (!row) return;

            document.querySelectorAll('.docx-preview-wrapper table.docx-table tr.docx-selected-row').forEach(function (r) {
                r.classList.remove('docx-selected-row');
            });

            row.classList.add('docx-selected-row');
            selectedRow = row;
            selectedTable = row.closest('table');

            if (rowInfo) {
                const tIdx = selectedTable.getAttribute('data-table-index') || Array.from(document.querySelectorAll('.docx-preview-wrapper table.docx-table')).indexOf(selectedTable);
                const rIdx = selectedRow.getAttribute('data-row-index') || Array.from(selectedTable.querySelectorAll('tr')).indexOf(selectedRow);
            }
        });

        // Add Row
        if (btnAddRow) {
            btnAddRow.addEventListener('click', function () {
                if (!selectedTable || !selectedRow) {
                    const firstTable = document.querySelector('.docx-preview-wrapper table.docx-table:not(.header-table)');
                    if (!firstTable) {
                        alert('No editable table found in document.');
                        return;
                    }
                    const rows = firstTable.querySelectorAll('tr');
                    selectedTable = firstTable;
                    selectedRow = rows[rows.length - 1];
                    selectedRow.classList.add('docx-selected-row');
                }

                let templateRow = selectedRow;
                if (templateRow.querySelector('th')) {
                    const nextData = templateRow.nextElementSibling;
                    if (nextData) templateRow = nextData;
                }

                const newRow = templateRow.cloneNode(true);
                newRow.classList.remove('docx-selected-row');
                newRow.setAttribute('data-is-added-row', '1');
                newRow.removeAttribute('data-row-index');

                newRow.querySelectorAll('input, textarea').forEach(function (inp) {
                    const token = 'new_row_' + Math.random().toString(36).substring(2, 9);
                    if (inp.type === 'radio' || inp.type === 'checkbox') {
                        inp.checked = false;
                    } else if (inp.type !== 'file') {
                        inp.value = '';
                    } else {
                        inp.value = '';
                    }
                    if (inp.type !== 'file') {
                        inp.name = 'fields[' + token + ']';
                    }
                    inp.removeAttribute('readonly');
                    inp.disabled = false;
                });
                newRow.querySelectorAll('select').forEach(function (sel) {
                    sel.selectedIndex = 0;
                    sel.disabled = false;
                    sel.name = 'fields[new_row_' + Math.random().toString(36).substring(2, 9) + ']';
                });
                newRow.querySelectorAll('.docx-attachment-link, .docx-saved-file').forEach(function (el) {
                    el.remove();
                });
                newRow.querySelectorAll('.locked').forEach(function (el) {
                    el.classList.remove('locked');
                    el.classList.add('editable');
                });
                newRow.querySelectorAll('td').forEach(function (td) {
                    if (td.querySelector('input, textarea, select')) return;
                    td.textContent = '';
                    td.innerHTML = '<input type="text" class="docx-input" name="fields[new_row_' + Math.random().toString(36).substring(2, 9) + ']" value="">';
                    td.classList.add('editable');
                });

                selectedRow.after(newRow);

                const tIdx = parseInt(selectedTable.getAttribute('data-table-index') || Array.from(document.querySelectorAll('.docx-preview-wrapper table.docx-table')).indexOf(selectedTable));
                const afterRow = parseInt(selectedRow.getAttribute('data-row-index') || Array.from(selectedTable.querySelectorAll('tr')).indexOf(selectedRow));
                const headers = Array.from(selectedTable.querySelectorAll('tr:first-child td, tr:first-child th')).map(function (th) {
                    return th.textContent.trim();
                });

                tableOps.push({
                    op: 'insert',
                    table: tIdx,
                    after_row: afterRow,
                    headers: headers,
                    _rowEl: newRow
                });

                // Move selection to newly added row
                document.querySelectorAll('.docx-preview-wrapper table.docx-table tr.docx-selected-row').forEach(function (r) {
                    r.classList.remove('docx-selected-row');
                });
                newRow.classList.add('docx-selected-row');
                selectedRow = newRow;

                syncTableOpsInput();

                reflowPageOverflow(selectedRow.closest('.docx-a4-page'));
                const addedPage = newRow.closest('.docx-a4-page');
                if (addedPage && addedPage !== selectedRow.closest('.docx-a4-page')) {
                    reflowPageOverflow(addedPage);
                }
                newRow.scrollIntoView({ block: 'center', behavior: 'smooth' });

                if (rowInfo) {
                    rowInfo.innerHTML = '<span class="text-success font-weight-bold">Row Added!</span> Type text into the new cells.';
                }
                const firstInput = newRow.querySelector('input, textarea');
                if (firstInput) firstInput.focus();
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
                syncTableOpsInput();

                if (parentPage) {
                    let guard = 0;
                    while (pageFreeSpace(parentPage.querySelector('.docx-page-body')) >= 28 && pullOneBack(parentPage) && guard < 40) {
                        guard++;
                    }
                    const nextSheet = followingA4Page(parentPage);
                    if (nextSheet) dropEmptySheet(nextSheet);
                    updateAllPageNumbers();
                }

                if (rowInfo) {
                    rowInfo.textContent = 'Row deleted. Click any cell to select another row.';
                }
            });
        }

        function syncTableOpsInput() {
            if (!tableOpsInput) return;
            const serialized = tableOps.map(function (op) {
                if (op.op === 'insert') {
                    const values = op._rowEl
                        ? Array.from(op._rowEl.querySelectorAll('td')).map(function (td) {
                            const inp = td.querySelector('input, textarea, select');
                            return inp ? (inp.value || '').trim() : td.textContent.trim();
                        })
                        : (op.values || []);
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
            if (typeof window.docxScheduleDraftSave === 'function') {
                window.docxScheduleDraftSave();
            }
        }

        window.syncDocxTableOps = syncTableOpsInput;

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

    /** Active users for signature-table approver dropdowns (same labels as fill_docx.blade.php). */
    const docxUsers = @json(
        $users->map(fn ($u) => ['id' => $u->id, 'label' => $u->display_name])->values()
    );

    const approverHiddenByRole = {
        post_prepared_by_name: document.getElementById('post_prepared_by_name_value'),
        post_checked_by_engineering_name: document.getElementById('post_checked_by_engineering_name_value'),
        post_checked_by_production_name: document.getElementById('post_checked_by_production_name_value'),
        post_checked_by_quality_name: document.getElementById('post_checked_by_quality_name_value'),
        post_checked_by_validation_name: document.getElementById('post_checked_by_validation_name_value'),
        post_approved_by_quality_name: document.getElementById('post_approved_by_quality_name_value'),
    };

    /**
     * Infer POST approver role from Veeva approval-page tables (Role | Name | Signature | Date).
     */
    const inferApproverRoleFromApprovalPageTable = function (sel) {
        const row = sel.closest('tr');
        if (!row) return '';
        const roleCell = row.querySelector('td:first-child');
        const roleText = (roleCell ? roleCell.textContent : '').toLowerCase();
        if (roleText.includes('performed') || roleText.includes('recorded') || roleText.includes('complied')) {
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
                'post_checked_by_engineering_name',
                'post_checked_by_production_name',
                'post_checked_by_quality_name',
                'post_checked_by_validation_name',
            ];
            return roles[idx] || '';
        }
        if (title.includes('prepared by')) {
            return 'post_prepared_by_name';
        }
        if (title.includes('approved by')) {
            return 'post_approved_by_quality_name';
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
        if (text.includes('quality') || text.includes('assurance') || /\bqa\b/.test(text)) {
            const tableTitle = ((sel.closest('table') || {}).innerText || '').slice(0, 80).toLowerCase();
            if (tableTitle.includes('checked by') && !tableTitle.includes('approved by')) {
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
        if (text.includes('validation') || text.includes('user/validation')) {
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

    const preApprovedUserNames = @json($preApprovedUserNames ?? []);

    /**
     * Lock pre-approved cells into static readonly text when pre-approval is complete or in post-fill stage.
     * Prevents any pre-approval signature cells from showing editable dropdowns.
     */
    const lockPreApprovedCells = function () {
        if (isPreApprovalMode) return;
        if ('{{ $importSource ?? '' }}' !== 'requalification') return;
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
        if ('{{ $importSource ?? '' }}' !== 'requalification') return;
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

    /** Hydrate signature-table approver placeholders with user options from DB. */
    const hydrateApproverFields = function () {
        document.querySelectorAll('select.approver-field').forEach(function (sel) {
            if (sel.dataset.isPreApprover === '1') return;
            if (/^table_[123]_row_\d+_col_\d+$/.test(sel.dataset.fieldLocation || '')) return;
            if (preApproverRoles.includes(sel.dataset.approverRole || '')) return;
            if (sel.dataset.hydrated === '1') return;
            sel.dataset.hydrated = '1';

            // Post-approval selects must never be required during pre-approval mode
            if (isPreApprovalMode || document.querySelector('input[name="requal_pre_submit"]')) {
                sel.removeAttribute('required');
                sel.required = false;
            }

            let role = sel.dataset.approverRole || '';
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
            if (!role) return;

            const hiddenId = approverHiddenByRole[role] ? approverHiddenByRole[role].value : '';
            docxUsers.forEach(function (user) {
                const opt = document.createElement('option');
                opt.value = user.id;
                opt.textContent = user.label;
                if (String(hiddenId) === String(user.id)) {
                    opt.selected = true;
                }
                sel.appendChild(opt);
            });

            sel.addEventListener('change', function () {
                const userId = sel.value;
                const fieldLoc = sel.dataset.fieldLocation || '';

                if (role && approverHiddenByRole[role]) {
                    approverHiddenByRole[role].value = userId;
                }

                const nameHidden = fieldLoc
                    ? document.querySelector(
                        'input.approver-name-field[data-field-location="' + fieldLoc + '"]'
                    )
                    : null;
                const selected = docxUsers.find(function (u) { return String(u.id) === String(userId); });
                if (nameHidden && selected) {
                    nameHidden.value = selected.label;
                }

                // Date fields stay empty until the user picks a date or an approver stamps on approval.
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
    hydrateApproverFields();
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

            if (form.querySelector('input[name="requal_pre_submit"]')) {
                document.querySelectorAll('.docx-preview-wrapper select.approver-field').forEach(function (sel) {
                    sel.removeAttribute('required');
                    sel.required = false;
                });
            }
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
            preparePreSubmitFields();
            // Do not lock #btnSubmit before #esignModal confirms; esign:confirmed locks it right before POST.
            if (!form.classList.contains('esign-required')) {
                applySubmittingUi();
            }
        });

        form.addEventListener('esign:confirmed', function () {
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

    const coverLocations = @json($coverFieldLocations ?? []);
    const fillFromProtocolUrlTemplate = @json(
        route('reports.docx-import.fill-from-protocol', ['report' => $report->id, 'sourceReport' => '__SOURCE__'])
    );

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

        {{-- if (coverLocations.facility_section && fields[coverLocations.facility_section]) {
            const el = document.getElementById('fill_facility_section');
            if (el) {
                el.value = fields[coverLocations.facility_section];
                el.dispatchEvent(new Event('input', { bubbles: true }));
            }
        } --}}
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
        const text = value == null ? '' : String(value).trim();
        document
            .querySelectorAll('.docx-page-official-header td[data-letterhead-field="' + letterheadKey + '"]')
            .forEach(function (td) {
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

    syncMappingToHeader(
        'fill_equipment_name',
        'header_equipment_name',
        ['header_0_row_2_col_4', coverLocations.equipment_name],
        'equipment_name'
    );
    syncMappingToHeader(
        'fill_equipment_id',
        'header_equipment_id',
        ['header_0_row_3_col_4', coverLocations.equipment_id],
        'equipment_id'
    );
    syncMappingToHeader(
        'fill_protocol_document_no',
        'header_doc_number',
        ['header_0_row_4_col_2', coverLocations.document_no],
        'document_no'
    );
    syncMappingToHeader(
        'fill_reference_protocol_no',
        'header_ref_protocol',
        ['header_ref_protocol', coverLocations.reference_protocol_no],
        'reference_protocol_no'
    );

    const headerFacilityHidden = document.getElementById('header_facility_hidden');
    if (headerFacilityHidden && headerFacilityHidden.value.trim() !== '') {
        syncLetterheadCells('facility_section', headerFacilityHidden.value);
    }
    const headerVersionHidden = document.getElementById('header_version_hidden');
    if (headerVersionHidden && headerVersionHidden.value.trim() !== '') {
        syncLetterheadCells('version', headerVersionHidden.value);
    }

    const documentTitleLocation = @json($documentTitleLocation ?? null);
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
    {{-- syncMappingToHidden('version', 'header_version_hidden'); --}}

    const effectiveDateLocation = @json($effectiveDateLocation ?? null);
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

    {{-- Autofill / draft resume for approver dropdowns from hidden inputs --}}
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

    // ── Autosave / resume draft (no e-sign, no approval start) ──
    const draftSaveUrl = @json(route('reports.docx-import.draft', $report));
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
        const fd = new FormData(form);
        fd.delete('password');
        // Empty file inputs must not be posted. A chosen file is part of the draft.
        form.querySelectorAll('input[type="file"]').forEach(function (input) {
            if (!input.name) return;
            if (!input.files || !input.files.length) {
                fd.delete(input.name);
            }
        });
        return fd;
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
        if ((requalFillLocked && !isPreApprovalMode) || !form || finalSubmitting) {
            return Promise.resolve(false);
        }
        if (draftSaving) {
            draftPending = true;
            draftDirty = true;
            return Promise.resolve(false);
        }
        draftSaving = true;
        draftDirty = false;
        if (!opts.silent) {
            setDraftStatus('<i class="fas fa-spinner fa-spin mr-1"></i> Saving draft…', 'saving');
        }
        if (btnSaveDraft) {
            btnSaveDraft.disabled = true;
        }

        return fetch(draftSaveUrl, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': (form.querySelector('input[name="_token"]') || {}).value || '',
            },
            credentials: 'same-origin',
            body: collectDraftFormData(),
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
                    saveDraftNow();
                }
            });
    };

    const scheduleDraftSave = function () {
        draftDirty = true;
        if (draftTimer) {
            clearTimeout(draftTimer);
        }
        draftTimer = setTimeout(function () {
            saveDraftNow();
        }, 1500);
    };
    window.docxScheduleDraftSave = scheduleDraftSave;
    window.docxSaveDraftNow = saveDraftNow;

    if (form) {
        form.addEventListener('input', function (e) {
            const t = e.target;
            if (!t || t.name === 'password' || (t.type === 'file')) return;
            scheduleDraftSave();
        });
        form.addEventListener('change', function (e) {
            const t = e.target;
            if (!t || t.name === 'password') return;
            if (t.type === 'file') {
                if (!t.files || !t.files.length) return;
                if (draftTimer) {
                    clearTimeout(draftTimer);
                }
                saveDraftNow();
                return;
            }
            scheduleDraftSave();
        });
        form.addEventListener('submit', function () {
            if (draftTimer) {
                clearTimeout(draftTimer);
            }
            if (!form.classList.contains('esign-required')) {
                finalSubmitting = true;
            }
        });
        form.addEventListener('esign:confirmed', function () {
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
            saveDraftNow();
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
        function syncTocPageNumbers(silent) {
            const preview = document.querySelector('.docx-preview-wrapper') || document.querySelector('.docx-preview');
            if (!preview) return;

            const tocInputs = Array.from(document.querySelectorAll('.docx-toc-page-input'));
            if (tocInputs.length === 0) return;

            const syncBtn = document.getElementById('btnAutoSyncToc');
            if (syncBtn && !silent) {
                const icon = syncBtn.querySelector('i');
                if (icon) icon.classList.add('fa-spin');
                syncBtn.disabled = true;
            }

            try {
                // Find potential section headings in preview DOM
                const candidates = Array.from(
                    preview.querySelectorAll('.docx-heading-target, p.docx-para, th.docx-cell, td.docx-cell, h1, h2, h3, h4, h5')
                ).filter(function (el) {
                    return !el.closest('.docx-toc-card') && !el.closest('.docx-toc-line') && !el.classList.contains('docx-toc-page-input');
                });

                const previewRect = preview.getBoundingClientRect();
                const previewTop = previewRect.top + window.scrollY;

                // An A4 page at 96 DPI has standard height ~1123px.
                // With top/bottom margins, body content height is approx 1000px to 1080px.
                const previewWidth = preview.clientWidth || 800;
                const pageHeight = Math.max(880, Math.round(previewWidth * 1.30));

                // Find Section 1.0 (OBJECTIVE) as anchor for Page 3
                let firstHeading = null;
                for (let i = 0; i < candidates.length; i++) {
                    const h = candidates[i];
                    const txt = (h.dataset.headingText || h.textContent || '').trim().toUpperCase();
                    if (/^(1\.0\s+)?OBJECTIVE\b/.test(txt)) {
                        firstHeading = h;
                        break;
                    }
                }

                // Lupin documents start Section 1.0 on Page 3 (Page 1 = Cover/Pre-approval, Page 2 = TOC)
                const basePage = 3;
                const anchorTop = firstHeading
                    ? (firstHeading.getBoundingClientRect().top + window.scrollY - previewTop)
                    : (pageHeight * 2);

                tocInputs.forEach(function (input) {
                    const secNum = (input.dataset.tocSec || '').trim();
                    const secTitle = (input.dataset.tocTitle || '').trim().toUpperCase();
                    const cleanTitle = secTitle.replace(/^\d+(?:\.\d+)*\s*/, '').replace(/[\.\:\-_]+/g, ' ').trim();
                    const words = cleanTitle.split(/\s+/).filter(function (w) {
                        return w.length > 2 && !['AND', 'THE', 'FOR', 'ANY'].includes(w);
                    });

                    let matchedEl = null;

                    // Pass 1: exact section number match (e.g. "1.0", "3.2", "16.0")
                    if (secNum) {
                        const secRegex = new RegExp('^' + secNum.replace(/\./g, '\\.') + '(\\s|$|\\:)');
                        matchedEl = candidates.find(function (h) {
                            const txt = (h.dataset.headingText || h.textContent || '').trim();
                            return secRegex.test(txt);
                        });
                    }

                    // Pass 2: keyword match if section number not in heading text
                    if (!matchedEl && words.length > 0) {
                        matchedEl = candidates.find(function (h) {
                            const txt = (h.dataset.headingText || h.textContent || '').trim().toUpperCase();
                            const matchCount = words.filter(function (kw) {
                                return txt.includes(kw);
                            }).length;
                            return matchCount >= Math.min(2, words.length);
                        });
                    }

                    if (matchedEl) {
                        const elTop = matchedEl.getBoundingClientRect().top + window.scrollY - previewTop;
                        let calculatedPage = basePage + Math.floor((elTop - anchorTop + 40) / pageHeight);
                        if (calculatedPage < 1) calculatedPage = 1;

                        const curVal = parseInt(input.value, 10);
                        if (isNaN(curVal) || curVal !== calculatedPage) {
                            input.value = calculatedPage;
                            input.dispatchEvent(new Event('input', { bubbles: true }));
                            input.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    }
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

        // Attach to sync button clicks
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('#btnAutoSyncToc, .btn-sync-toc');
            if (btn) {
                e.preventDefault();
                syncTocPageNumbers(false);
            }
        });

        // Auto-run once on load after layout is complete
        window.addEventListener('DOMContentLoaded', function () {
            setTimeout(function () { syncTocPageNumbers(true); }, 800);
        });

        if (document.readyState === 'complete' || document.readyState === 'interactive') {
            setTimeout(function () { syncTocPageNumbers(true); }, 800);
        }

        // Optional debounce auto-sync when dynamic textareas or tables grow
        let tocDebounce = null;
        document.addEventListener('input', function (e) {
            if (e.target && (e.target.tagName === 'TEXTAREA' || e.target.classList.contains('docx-para-content'))) {
                clearTimeout(tocDebounce);
                tocDebounce = setTimeout(function () { syncTocPageNumbers(true); }, 1500);
            }
        });
    })();

    @if(($importSource ?? '') === 'requalification' && empty($requalFillLocked))
    (function () {
        const btnEsignStamp = document.getElementById('btnEsignStamp');
        const esignStatusEl = document.getElementById('docxEsignStatus');
        const preview = document.querySelector('.docx-preview-wrapper');
        const signerName = @json(trim((auth()->user()->first_name ?? '').' '.(auth()->user()->last_name ?? '')) ?: (auth()->user()->email ?? 'User'));
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
                || text.indexOf('Verified by sign and date : ' + signerName) === 0;
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
                if (verifiedLabel) {
                    field.value = 'Verified by sign and date : ' + signerName + ' ' + when;
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
    @endif

    /* ── DOCX A4 Page Navigator ── */
    (function () {
        const navEl = document.getElementById('docxPageNavigator');
        if (!navEl) return;

        const selectJump = document.getElementById('docxSelectPageJump');
        const btnPrev = document.getElementById('btnDocxPrevPage');
        const btnNext = document.getElementById('btnDocxNextPage');
        if (typeof window.scheduleDocxPaginate === 'function') {
            window.scheduleDocxPaginate();
        } else if (typeof window.paginateDocxPreview === 'function') {
            window.paginateDocxPreview();
        }
        const pageList = function () {
            return Array.from(document.querySelectorAll('.docx-a4-page'));
        };
        const pages = pageList();

        if (!pages.length) {
            navEl.style.display = 'none';
            return;
        }

        const totalPages = pages.length;
        if (selectJump) {
            selectJump.innerHTML = '';
            pages.forEach(function (page, idx) {
                const pageNum = idx + 1;
                const opt = document.createElement('option');
                opt.value = String(pageNum);
                opt.textContent = 'Page ' + pageNum + ' of ' + totalPages;
                selectJump.appendChild(opt);
            });

            selectJump.addEventListener('change', function () {
                const targetNum = parseInt(selectJump.value, 10);
                const targetPage = document.getElementById('docxPage' + targetNum) || pages[targetNum - 1];
                if (targetPage) {
                    targetPage.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
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
            if (typeof window.scheduleDocxPaginate === 'function') {
                window.scheduleDocxPaginate();
            } else if (typeof window.paginateDocxPreview === 'function') {
                window.paginateDocxPreview();
            }
            if (typeof window.updateAllPageNumbers === 'function') {
                window.updateAllPageNumbers();
            }
        }, 300);
    })();
});
</script>
@endsection
