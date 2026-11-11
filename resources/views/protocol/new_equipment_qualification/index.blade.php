@extends('layouts.app')

@section('title', 'New Equipment Qualification')

@section('content')
<style>
    .neq-preview-shell {
        background: #eef1f6;
        border: 1px solid #e3e6f0;
        border-radius: 0.5rem;
        padding: 1.5rem;
        overflow: auto;
        min-height: 360px;
    }

    .neq-protected-banner {
        background: #fff3cd;
        border: 1px solid #ffc107;
        color: #664d03;
        padding: 12px 16px;
        border-radius: 0.375rem;
        margin-bottom: 1rem;
    }

    .neq-editing-banner {
        background: #d1e7dd;
        border: 1px solid #198754;
        color: #0f5132;
        padding: 12px 16px;
        border-radius: 0.375rem;
        margin-bottom: 1rem;
    }

    .docx-preview-container {
        font-family: 'Calibri', 'Segoe UI', Arial, sans-serif;
        color: #000;
        width: max-content;
        min-width: 100%;
        max-width: none;
        margin: 0 auto;
        padding: 24px 16px;
        background: #fff;
        box-shadow: 0 0 8px rgba(0, 0, 0, 0.1);
        min-height: 280px;
        overflow: visible;
        box-sizing: border-box;
    }

    .docx-preview-container .neq-preview-placeholder {
        color: #6c757d;
        text-align: center;
        padding: 3rem 1rem;
        margin: 0;
    }

    .docx-preview-container .docx-wrapper {
        background: #fff;
        padding: 0;
        overflow: visible;
    }

    .docx-preview-container .docx-wrapper > section.docx {
        margin-bottom: 1rem;
        box-shadow: 0 0 6px rgba(0, 0, 0, 0.08);
        background: #fff;
    }

    .docx-preview-container .docx img,
    .docx-preview-container img {
        display: inline-block;
        visibility: visible;
        max-width: none;
        height: auto;
    }

    .docx-preview-container .docx-wrapper img[data-neq-media] {
        display: block !important;
        visibility: visible !important;
        opacity: 1 !important;
        max-width: 120px !important;
        min-width: 40px !important;
        min-height: 40px !important;
        width: auto !important;
        height: auto !important;
        position: static !important;
        clip-path: none !important;
        -webkit-clip-path: none !important;
        transform: none !important;
        top: auto !important;
        left: auto !important;
        margin: 0 !important;
    }

    .docx-preview-container [data-neq-logo-frame] {
        overflow: hidden !important;
        position: relative !important;
        display: block !important;
        margin: 8px auto !important;
    }

    .docx-preview-container [data-neq-logo-frame] img {
        position: absolute !important;
        max-width: none !important;
        max-height: none !important;
        min-width: 0 !important;
        min-height: 0 !important;
        margin: 0 !important;
        transform: none !important;
        clip-path: none !important;
    }

    .docx-preview-container .neq-server-preview img,
    .docx-preview-container .neq-server-preview img[data-neq-media] {
        display: inline-block !important;
        visibility: visible !important;
        max-width: 140px;
        min-width: 60px;
        min-height: 40px;
        width: auto !important;
        height: auto !important;
        opacity: 1 !important;
    }

    .docx-preview-container .neq-server-preview table.neq-docx-table {
        width: 100%;
        border-collapse: collapse;
        margin: 12px 0;
    }

    .docx-preview-container .neq-server-preview table.neq-docx-table td,
    .docx-preview-container .neq-server-preview table.neq-docx-table th {
        border: 1px solid #000;
        padding: 6px 10px;
        vertical-align: top;
    }

    /* Keep the Word page and its column widths. Do not stretch tables to the card. */
    .docx-preview-container .docx-wrapper {
        counter-reset: neq-page;
        background: #cfcfcf;
        padding: 16px 0 28px;
    }

    /* Heading numbers (1.0, 2.0, …) are a list marker. Match the bold heading words. */
    .docx-preview-container p.neq-bold-num::before {
        font-weight: var(--neq-num-weight, 700) !important;
        font-size: var(--neq-num-size, inherit) !important;
        font-family: var(--neq-num-family, inherit) !important;
    }

    .docx-preview-container .docx-wrapper > section.docx {
        box-sizing: border-box;
        overflow-x: auto;
        font-size: 11pt;
        /* Keep Word's own page size. NEQ2 is Letter (11 in), not A4, so a
           forced 297mm sheet held more text and the view showed fewer pages. */
        overflow: visible !important;
        position: relative;
        margin-left: auto !important;
        margin-right: auto !important;
        margin-bottom: 16px !important;
        counter-increment: neq-page;
    }

    .docx-preview-container .neq-page-no {
        position: absolute;
        left: 0;
        right: 0;
        bottom: 0;
        z-index: 40;
        height: 12mm;
        line-height: 12mm;
        text-align: center;
        font-family: 'Times New Roman', Times, serif;
        font-size: 12pt;
        font-weight: 700;
        color: #000;
        background: #fff;
        pointer-events: none;
    }

    .docx-preview-container .docx-wrapper section.docx table {
        border-collapse: collapse;
        box-sizing: border-box;
    }

    /* Identity/metadata tables: do not override Word column widths (Document Title colspan). */
    .docx-preview-container .docx-wrapper section.docx table.neq-metadata-table {
        table-layout: auto !important;
        width: auto !important;
        max-width: 100% !important;
    }

    /* Keep docx-preview row and column sizes. Do not force height:auto on every row. */
    .docx-preview-container .docx-wrapper section.docx table td,
    .docx-preview-container .docx-wrapper section.docx table th {
        word-wrap: break-word;
        overflow-wrap: break-word;
        box-sizing: border-box;
        vertical-align: top;
    }

    /*
     * Body cells only. A centered cell starts one line lower once the row grows.
     * Header letterhead stays on the vertical alignment Word already uses.
     */
    .docx-preview-container .docx-wrapper section.docx > article table td,
    .docx-preview-container .docx-wrapper section.docx > article table th {
        vertical-align: top !important;
    }

    /*
     * Typed text wraps inside the cell the user clicked.
     * Do not set this on every span — that stretches the letterhead and comparison grid.
     */
    .neq-edit-mode .docx-preview-container td[contenteditable="true"],
    .neq-edit-mode .docx-preview-container th[contenteditable="true"] {
        white-space: pre-wrap !important;
        overflow-wrap: anywhere !important;
        word-break: break-word !important;
    }

    /*
     * Justified paragraphs stretch one typed space across the line.
     * Body Text 2 cells inherit that alignment, so "vijay verma" shows a wide gap.
     * Serial numbers are centered again in script with an inline style.
     */
    .docx-preview-container .docx-wrapper section.docx > article > p[style*="text-align: justify"],
    .docx-preview-container .docx-wrapper section.docx > article > p[style*="text-align:justify"],
    .docx-preview-container .docx-wrapper section.docx > article table p[style*="text-align: justify"],
    .docx-preview-container .docx-wrapper section.docx > article table p[style*="text-align:justify"] {
        text-align: left !important;
        white-space: pre-wrap !important;
    }

    /* Data cells stay inside the original columns. Long text wraps; the table does not grow. */
    .docx-preview-container table.neq-check-matrix {
        table-layout: fixed !important;
        width: 100% !important;
        max-width: 100% !important;
    }

    /*
     * Keep the Word row height in lock and unlock.
     * height:auto and padding:0 were shrinking imported rows until Edit Content
     * added a min-height and the table jumped back to the original size.
     */
    .docx-preview-container table.neq-check-matrix td,
    .neq-edit-mode .docx-preview-container table.neq-check-matrix td {
        vertical-align: top !important;
        overflow-wrap: anywhere !important;
        word-break: break-word !important;
        white-space: normal !important;
        min-width: 0 !important;
        max-width: none;
    }

    .docx-preview-container table.neq-check-matrix td > p,
    .docx-preview-container table.neq-check-matrix td > div,
    .docx-preview-container table.neq-check-matrix td span,
    .docx-preview-container table.neq-check-matrix td [contenteditable="true"],
    .neq-edit-mode .docx-preview-container table.neq-check-matrix td > p,
    .neq-edit-mode .docx-preview-container table.neq-check-matrix td > div,
    .neq-edit-mode .docx-preview-container table.neq-check-matrix td span,
    .neq-edit-mode .docx-preview-container table.neq-check-matrix td [contenteditable="true"] {
        vertical-align: top !important;
        position: static !important;
        float: none !important;
        top: auto !important;
        left: auto !important;
        right: auto !important;
        bottom: auto !important;
        transform: none !important;
        /* Word negative indents (-5.4pt) paint Instrument Description / Sign and Date past the column line. */
        margin-left: 0 !important;
        margin-right: 0 !important;
        margin-inline-start: 0 !important;
        margin-inline-end: 0 !important;
        width: auto !important;
        min-width: 0 !important;
        /* width:auto lets a long value push the column past the page edge. */
        max-width: 100% !important;
        box-sizing: border-box !important;
        overflow-wrap: anywhere !important;
        word-break: break-word !important;
        white-space: pre-wrap !important;
    }

    .docx-preview-container > p {
        margin: 6px 0;
        line-height: 1.5;
    }

    .docx-preview-container h1,
    .docx-preview-container h2,
    .docx-preview-container h3,
    .docx-preview-container h4 {
        font-weight: bold;
        margin: 16px 0 8px;
    }

    /* Stronger edit cues on cells + paragraphs */
    .neq-edit-mode .docx-preview-container [contenteditable="true"] {
        outline: none;
        box-shadow: inset 0 0 0 1.5px rgba(78, 115, 223, 0.55);
        cursor: text;
        white-space: pre-wrap;
        min-height: 1.35em;
    }

    .neq-edit-mode .docx-preview-container td[contenteditable="true"],
    .neq-edit-mode .docx-preview-container th[contenteditable="true"] {
        vertical-align: top;
    }

    /* Unlock must not grow the row. Word's own row height stays in both modes. */
    .neq-edit-mode .docx-preview-container table td[contenteditable="true"],
    .neq-edit-mode .docx-preview-container table th[contenteditable="true"] {
        min-height: 0;
    }

    .neq-edit-mode .docx-preview-container table.neq-check-matrix td[contenteditable="true"] {
        vertical-align: top !important;
        text-align: center !important;
    }

    .neq-edit-mode .docx-preview-container table.neq-check-matrix td [contenteditable="true"] {
        box-shadow: none !important;
        outline: none !important;
        min-height: 0 !important;
    }

    .docx-preview-container a,
    .neq-edit-mode .docx-preview-container a {
        color: inherit;
        text-decoration: none;
        cursor: text;
    }

    .docx-preview-container .neq-tick-mark {
        display: inline-block !important;
        font-family: "Segoe UI Symbol", "Segoe UI", Arial, sans-serif;
        font-size: 16px;
        line-height: 1;
        color: #111111;
        margin: 0 4px 0 0;
        vertical-align: middle;
    }

    #previewShell.neq-edit-mode .neq-tick-mark {
        display: none !important;
    }

    #previewShell:not(.neq-edit-mode) input.neq-checkbox {
        display: none !important;
    }

    #previewShell:not(.neq-edit-mode) .neq-tick-mark {
        display: inline-block !important;
    }

    .docx-preview-container input.neq-checkbox {
        display: none !important;
    }

    .neq-edit-mode .docx-preview-container td[data-neq-applicable-col="1"] > input.neq-checkbox {
        display: inline-block !important;
        visibility: visible !important;
        position: static !important;
        width: 15px;
        height: 15px;
        margin: 0;
    }

    .neq-edit-mode .docx-preview-container .neq-checkbox {
        display: inline-block;
        width: 15px;
        height: 15px;
        margin: 0 8px 0 0;
        vertical-align: -2px;
        cursor: pointer;
        flex-shrink: 0;
        pointer-events: auto !important;
        position: relative;
        z-index: 2;
    }

    /* Keep checklist line inline — flex was floating Word boxes away from text */
    .neq-edit-mode .docx-preview-container [data-neq-checkbox="1"] {
        display: block;
        line-height: 1.45;
    }

    .neq-edit-mode .docx-preview-container [data-neq-cb-hidden="1"] {
        display: none !important;
        visibility: hidden !important;
        width: 0 !important;
        height: 0 !important;
        max-width: 0 !important;
        max-height: 0 !important;
        overflow: hidden !important;
        margin: 0 !important;
        padding: 0 !important;
        border: 0 !important;
        opacity: 0 !important;
        pointer-events: none !important;
    }

    .neq-edit-mode .docx-preview-container [data-neq-toc="1"][contenteditable="true"],
    .neq-edit-mode .docx-preview-container [data-neq-toc="1"] [contenteditable="true"] {
        cursor: text !important;
        box-shadow: inset 0 0 0 1.5px rgba(78, 115, 223, 0.55);
    }

    .neq-structure-toolbar .btn {
        margin-right: 0.35rem;
    }

    /* Fixed top-right dock — must beat layout/transform containing blocks */
    #neqFloatToolbar.neq-float-toolbar {
        position: fixed !important;
        top: 96px !important;
        right: 20px !important;
        bottom: auto !important;
        left: auto !important;
        transform: none !important;
        z-index: 2000 !important;
        width: 200px !important;
        max-width: calc(100vw - 40px);
        padding: 14px 12px;
        border-radius: 12px;
        border: 1px solid #c5d0e6;
        background: #ffffff !important;
        box-shadow: 0 12px 32px rgba(15, 23, 42, 0.22);
        display: flex !important;
        flex-direction: column !important;
        gap: 8px;
        margin: 0 !important;
    }

    #neqFloatToolbar.neq-float-toolbar.d-none {
        display: none !important;
    }

    .neq-float-toolbar-title {
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #4e73df;
        margin: 0 0 2px;
    }

    .neq-float-hint {
        font-size: 0.72rem;
        line-height: 1.35;
        color: #6c757d;
        min-height: 2.6em;
        margin-bottom: 2px;
        word-break: break-word;
    }

    .neq-draft-status {
        display: block;
        font-size: 0.72rem;
        line-height: 1.3;
        color: #1e7e34;
        margin: 0 0 4px;
        word-break: break-word;
    }

    .neq-draft-status.is-saving {
        color: #856404;
    }

    .neq-draft-status.is-error {
        color: #bd2130;
    }

    .neq-float-toolbar .btn {
        width: 100%;
        margin: 0;
        white-space: nowrap;
        font-weight: 700 !important;
        border-width: 2px !important;
        min-height: 42px;
        font-size: 0.85rem !important;
        box-shadow: 0 3px 10px rgba(15, 23, 42, 0.2) !important;
        opacity: 1 !important;
    }

    body #neqFloatToolbar #btnFloatEditContent.btn-neq-edit,
    body #neqFloatToolbar #btnFloatEditContent.btn-success,
    body #neqFloatToolbar #btnFloatEditContent {
        background-color: #1cc88a !important;
        background-image: none !important;
        border-color: #13855c !important;
        color: #ffffff !important;
    }

    body #neqFloatToolbar #btnFloatEditContent.btn-warning,
    body #neqFloatToolbar #btnFloatEditContent.btn-neq-lock {
        background-color: #f6c23e !important;
        background-image: none !important;
        border-color: #d4a017 !important;
        color: #3d2e00 !important;
    }

    body #neqFloatToolbar #btnAddParagraph,
    body #neqFloatToolbar #btnAddParagraph.btn-warning {
        background-color: #fd7e14 !important;
        background-image: none !important;
        border-color: #d9480f !important;
        color: #ffffff !important;
    }

    body #neqFloatToolbar #btnSaveAs,
    body #neqFloatToolbar #btnSaveAs.btn-primary {
        background-color: #4e73df !important;
        background-image: none !important;
        border-color: #2e59d9 !important;
        color: #ffffff !important;
    }

    body #neqFloatToolbar #btnEsignStamp,
    body #neqFloatToolbar #btnEsignStamp.btn-dark {
        background-color: #2c3e50 !important;
        background-image: none !important;
        border-color: #1a252f !important;
        color: #ffffff !important;
    }

    body #neqFloatToolbar #btnSendForApproval,
    body #neqFloatToolbar #btnSendForApproval.btn-info {
        background-color: #36b9cc !important;
        background-image: none !important;
        border-color: #258391 !important;
        color: #ffffff !important;
    }

    body #neqFloatToolbar #btnSendForApproval:disabled {
        opacity: 0.55;
        cursor: not-allowed;
    }

    body #neqFloatToolbar #btnFloatEditContent:hover,
    body #neqFloatToolbar #btnAddParagraph:hover,
    body #neqFloatToolbar #btnSaveAs:hover,
    body #neqFloatToolbar #btnEsignStamp:hover,
    body #neqFloatToolbar #btnSendForApproval:hover:not(:disabled) {
        filter: brightness(0.94);
        color: #ffffff !important;
    }

    body #neqFloatToolbar #btnFloatEditContent.btn-neq-lock:hover {
        color: #3d2e00 !important;
    }

    html.neq-doc-focus,
    body.neq-doc-focus {
        overflow: hidden !important;
    }

    /* Keep sidebar + topbar visible (like Protocol Collection workspace).
       Only hide list / page hero — preview fills the main content area. */
    html.neq-doc-focus .page-hero,
    html.neq-doc-focus #neqListSection,
    body.neq-doc-focus .page-hero,
    body.neq-doc-focus #neqListSection {
        display: none !important;
        visibility: hidden !important;
        pointer-events: none !important;
    }

    html.neq-doc-focus main.app-page,
    body.neq-doc-focus main.app-page {
        position: relative !important;
        min-height: calc(100vh - 110px) !important;
        padding: 0 !important;
        margin: 0 !important;
    }

    html.neq-doc-focus #neqPreviewSection,
    body.neq-doc-focus #neqPreviewSection,
    #neqPreviewSection.neq-preview-overlay {
        position: absolute !important;
        top: 0 !important;
        left: 0 !important;
        right: 0 !important;
        bottom: 0 !important;
        z-index: 20 !important;
        margin: 0 !important;
        border-radius: 0 !important;
        border: none !important;
        display: flex !important;
        flex-direction: column !important;
        background: #f8f9fc !important;
        overflow: hidden !important;
        width: 100% !important;
        height: 100% !important;
        min-height: calc(100vh - 110px) !important;
        max-width: none !important;
    }

    html.neq-doc-focus #neqPreviewSection > .card-header,
    body.neq-doc-focus #neqPreviewSection > .card-header,
    #neqPreviewSection.neq-preview-overlay > .card-header {
        flex: 0 0 auto;
        border-radius: 0 !important;
        background: #fff;
        box-shadow: 0 1px 4px rgba(15, 23, 42, 0.08);
        z-index: 2;
    }

    html.neq-doc-focus #neqPreviewSection > .card-body,
    body.neq-doc-focus #neqPreviewSection > .card-body,
    #neqPreviewSection.neq-preview-overlay > .card-body {
        flex: 1 1 auto;
        overflow: auto !important;
        overflow-anchor: none;
        padding-bottom: 80px;
    }

    html.neq-doc-focus #neqFloatToolbar.neq-float-toolbar,
    body.neq-doc-focus #neqFloatToolbar.neq-float-toolbar {
        top: 120px !important;
        z-index: 1060 !important;
    }

    #btnNeqBackToList {
        white-space: nowrap;
    }

    .neq-edit-mode .docx-preview-container .neq-insert-target {
        box-shadow: inset 0 0 0 2px #4e73df !important;
        background: rgba(78, 115, 223, 0.08) !important;
        outline: none;
        position: relative;
    }

    .neq-edit-mode .docx-preview-container .neq-insert-target::after {
        content: 'Selected — insert after this';
        position: absolute;
        left: 0;
        top: -1.15rem;
        font-size: 10px;
        font-weight: 600;
        color: #4e73df;
        background: #fff;
        padding: 0 4px;
        border-radius: 3px;
        white-space: nowrap;
        z-index: 2;
        pointer-events: none;
    }

    @media (max-width: 575.98px) {
        #neqFloatToolbar.neq-float-toolbar {
            top: 80px !important;
            right: 10px !important;
            width: 160px !important;
            padding: 10px 8px;
        }
    }

    .neq-edit-mode .docx-preview-container header td,
    .neq-edit-mode .docx-preview-container .docx_header td,
    .neq-edit-mode .docx-preview-container footer td,
    .neq-edit-mode .docx-preview-container .docx_footer td {
        cursor: text;
    }

    .neq-edit-mode .docx-preview-container header td[contenteditable="true"],
    .neq-edit-mode .docx-preview-container .docx_header td[contenteditable="true"],
    .neq-edit-mode .docx-preview-container footer td[contenteditable="true"],
    .neq-edit-mode .docx-preview-container .docx_footer td[contenteditable="true"],
    .neq-edit-mode .docx-preview-container table td[contenteditable="true"],
    .neq-edit-mode .docx-preview-container table th[contenteditable="true"] {
        cursor: text;
    }

    .neq-edit-mode .docx-preview-container [contenteditable="true"]:focus {
        box-shadow: inset 0 0 0 1.5px rgba(78, 115, 223, 0.65);
        background: rgba(78, 115, 223, 0.04);
    }

    .neq-table-wrap {
        position: relative;
        overflow: visible;
    }

    .neq-table-toolbar {
        display: flex;
        justify-content: flex-start;
        align-items: center;
        gap: 8px;
        margin: 4px 0 8px;
    }

    .neq-edit-mode tr.neq-data-row {
        cursor: pointer;
    }

    .neq-edit-mode tr.neq-row-selected > td,
    .neq-edit-mode tr.neq-row-selected > th {
        box-shadow: inset 0 0 0 2px #4e73df;
    }

    .js-preview-btn.active {
        box-shadow: inset 0 0 0 2px rgba(255, 255, 255, 0.35);
    }

    .neq-approver-select {
        width: 100%;
        max-width: 100%;
        min-height: 26px;
        font-size: 11pt;
        font-family: inherit;
        border: 1px solid #adb5bd;
        border-radius: 2px;
        padding: 2px 4px;
        background: #fff;
        box-sizing: border-box;
    }

    .neq-section-verified-cell {
        white-space: normal;
    }

    .neq-section-verified-host {
        display: inline-block;
        min-width: 200px;
        max-width: 360px;
        vertical-align: middle;
        margin-left: 4px;
    }

    .neq-section-verified-select {
        width: 100%;
        max-width: 100%;
        min-height: 26px;
        font-size: 11pt;
        font-family: inherit;
        border: 1px solid #adb5bd;
        border-radius: 2px;
        padding: 2px 4px;
        background: #fff;
        box-sizing: border-box;
    }

    .neq-safety-by-host {
        display: inline-block;
        min-width: 200px;
        max-width: 360px;
        vertical-align: middle;
        margin-left: 4px;
    }

    .neq-safety-by-cell {
        white-space: normal;
    }

    .neq-safety-by-select {
        width: 100%;
        max-width: 100%;
        min-height: 26px;
        font-size: 11pt;
        font-family: inherit;
        border: 1px solid #adb5bd;
        border-radius: 2px;
        padding: 2px 4px;
        background: #fff;
        box-sizing: border-box;
    }

    .neq-training-name-input,
    .neq-training-name-select,
    .neq-training-by-select,
    .neq-training-date-input,
    .neq-qa-approver-select {
        width: 100%;
        max-width: 100%;
        min-height: 26px;
        font-size: 11pt;
        font-family: inherit;
        border: 1px solid #adb5bd;
        border-radius: 2px;
        padding: 2px 4px;
        background: #fff;
        box-sizing: border-box;
    }

    .neq-qa-approver-host {
        display: inline-flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 6px;
        margin-top: 4px;
        width: 100%;
    }

    .neq-qa-stamp-line {
        font-size: 11pt;
        white-space: nowrap;
    }

    .neq-stamp-pending {
        color: #999;
        font-style: italic;
    }

    .neq-signature-font {
        font-family: "Segoe Script", "Brush Script MT", cursive;
        font-style: italic;
    }

    td.neq-stamp-cell,
    td.neq-approver-cell {
        background: transparent;
    }

    /* ── Cached Python /preview A4 form styles (Requalification pattern, zero client reflow) ── */
    #neqFillForm {
        width: 100%;
        margin: 0;
        padding: 0;
    }

    body.premium-app #neqFillForm .docx-preview-wrapper {
        overflow-x: auto;
        overflow-y: visible;
        background: #525659;
        border: 1px solid #334155;
        border-radius: 8px;
        padding: 24px 16px;
        min-height: 220px;
        position: relative;
        z-index: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
    }

    body.premium-app #neqFillForm .docx-preview {
        width: 100%;
        display: flex;
        flex-direction: column;
        align-items: center;
    }

    body.premium-app #neqFillForm .docx-a4-page {
        width: 210mm;
        max-width: 100%;
        min-height: 297mm;
        height: auto !important;
        max-height: none !important;
        background: #ffffff !important;
        box-shadow: 0 4px 18px rgba(0, 0, 0, 0.28), 0 1px 4px rgba(0, 0, 0, 0.16);
        border-radius: 2px;
        padding: 10mm 14mm 10mm 14mm;
        margin-bottom: 24px;
        position: relative;
        box-sizing: border-box;
        font-family: inherit;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        overflow: visible !important;
    }

    body.premium-app #neqFillForm .docx-page-body {
        flex: 1 1 auto;
        overflow: visible !important;
        display: flex;
        flex-direction: column;
    }

    body.premium-app #neqFillForm .docx-page-official-header {
        width: 100%;
        margin-bottom: 8px;
        flex-shrink: 0;
    }

    body.premium-app #neqFillForm .docx-page-official-header table.header-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 0;
        table-layout: fixed;
        background: #ffffff;
    }

    body.premium-app #neqFillForm .docx-page-official-header table.header-table td {
        border: 1px solid #334155 !important;
        padding: 3px 6px !important;
        font-size: 11px !important;
        vertical-align: middle !important;
        line-height: 1.25;
        background: #ffffff !important;
        color: #0f172a !important;
    }

    body.premium-app #neqFillForm .docx-page-official-footer {
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

    body.premium-app #neqFillForm .docx-page-break-indicator {
        display: flex;
        align-items: center;
        width: 210mm;
        max-width: 100%;
        margin: 4px 0 20px 0;
        user-select: none;
    }

    body.premium-app #neqFillForm .docx-page-break-line {
        flex-grow: 1;
        border-top: 2px dashed #94a3b8;
        height: 0;
    }

    body.premium-app #neqFillForm .docx-page-break-badge {
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

    body.premium-app #neqFillForm .docx-summary-header-card {
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

    body.premium-app #neqFillForm .docx-summary-header-card table.header-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 0;
        background: #ffffff !important;
        color: #0f172a !important;
    }

    body.premium-app #neqFillForm .docx-summary-header-card table.header-table td {
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

    body.premium-app #neqFillForm .docx-summary-header-card table.header-table input[type="text"].docx-input {
        display: block !important;
        width: 100% !important;
        min-width: 0 !important;
        font-size: 13px !important;
        font-weight: 600;
        padding: 5px 8px !important;
    }

    body.premium-app #neqFillForm .docx-preview-wrapper table.docx-table {
        display: table !important;
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 14px;
        font-size: 13px;
        table-layout: auto;
    }

    body.premium-app #neqFillForm .docx-preview-wrapper table.docx-table tr {
        display: table-row !important;
    }

    body.premium-app #neqFillForm .docx-preview-wrapper table.docx-table td,
    body.premium-app #neqFillForm .docx-preview-wrapper table.docx-table th {
        display: table-cell !important;
    }

    body.premium-app #neqFillForm .docx-preview-wrapper td.docx-cell,
    body.premium-app #neqFillForm .docx-preview-wrapper th.docx-cell {
        border: 1px solid #000;
        padding: 6px 8px;
        vertical-align: middle;
        min-width: 60px;
    }

    body.premium-app #neqFillForm .docx-preview-wrapper th.docx-table-header {
        background-color: #f1f5f9 !important;
        color: #1e293b !important;
        font-weight: 700 !important;
        border: 1px solid #cbd5e1 !important;
        padding: 8px 10px !important;
    }

    body.premium-app #neqFillForm .docx-preview-wrapper td.docx-cell.editable {
        background-color: #fffde7 !important;
        background-image: none !important;
        padding: 2px 4px !important;
        border-bottom: 2px solid #4e73df !important;
        cursor: text;
        position: relative;
    }

    body.premium-app #neqFillForm .docx-preview-wrapper td.docx-cell.locked {
        background-color: #f5f5f5 !important;
        background-image: none !important;
        color: #444 !important;
    }

    body.premium-app #neqFillForm .docx-preview-wrapper tr.neq-selected-table-row > td,
    body.premium-app #neqFillForm .docx-preview-wrapper tr.neq-selected-table-row > th {
        outline: 2px solid #4e73df;
        outline-offset: -2px;
        background-color: #eef2ff !important;
    }

    body.premium-app #neqFillForm .docx-preview-wrapper input[type="text"].docx-input,
    body.premium-app #neqFillForm .docx-preview-wrapper input[type="text"].docx-input-inline,
    body.premium-app #neqFillForm .docx-preview-wrapper textarea.docx-input,
    body.premium-app #neqFillForm .docx-preview-wrapper select.docx-input {
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
        opacity: 1 !important;
        visibility: visible !important;
        pointer-events: auto !important;
    }

    body.premium-app #neqFillForm .docx-preview-wrapper input[type="text"].docx-input-inline {
        display: inline-block !important;
        width: auto !important;
        min-width: 140px !important;
        max-width: 100% !important;
    }

    body.premium-app #neqFillForm .docx-preview-wrapper input[type="date"].docx-input,
    body.premium-app #neqFillForm .docx-preview-wrapper input[type="date"].docx-input-inline {
        display: inline-block !important;
        width: auto !important;
        min-width: 140px !important;
        min-height: 28px !important;
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
    }

    body.premium-app #neqFillForm .docx-preview-wrapper textarea.docx-input {
        min-height: 54px !important;
        resize: vertical;
    }

    body.premium-app #neqFillForm .docx-preview-wrapper select.docx-input.approver-field,
    body.premium-app #neqFillForm .docx-preview-wrapper select.docx-input.neq-approver-select {
        -webkit-appearance: menulist !important;
        appearance: menulist !important;
        cursor: pointer !important;
    }

    body.premium-app #neqFillForm .docx-preview-wrapper .docx-para {
        margin: 6px 0;
        font-size: 13px;
        line-height: 1.5;
    }

    body.premium-app #neqFillForm[data-neq-form-locked="1"] .docx-preview-wrapper input.docx-input,
    body.premium-app #neqFillForm[data-neq-form-locked="1"] .docx-preview-wrapper input.docx-input-inline,
    body.premium-app #neqFillForm[data-neq-form-locked="1"] .docx-preview-wrapper textarea.docx-input,
    body.premium-app #neqFillForm[data-neq-form-locked="1"] .docx-preview-wrapper select.docx-input,
    body.premium-app #neqFillForm[data-neq-form-locked="1"] .docx-preview-wrapper .neq-added-paragraph-input {
        background-color: #f8fafc !important;
        border-bottom-color: #cbd5e1 !important;
        color: #334155 !important;
        cursor: default !important;
    }

    body.premium-app #neqFillForm .neq-added-para-block {
        margin: 8px 0;
        padding: 6px 8px;
        background: #fffbeb;
        border: 1px dashed #f59e0b;
        border-radius: 4px;
    }

    #neqPageNavigator {
        display: flex;
        align-items: center;
        gap: 2px;
        background: #fff;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        overflow: hidden;
        width: 100%;
    }

    #neqPageNavigator button {
        border: none;
        background: #fff;
        color: #1e293b;
        padding: 4px 8px;
        font-size: 12px;
        cursor: pointer;
    }

    #neqPageNavigator button:hover {
        background: #f1f5f9;
    }

    #neqPageNavigator select {
        flex: 1 1 auto;
        border: none;
        border-left: 1px solid #e2e8f0;
        border-right: 1px solid #e2e8f0;
        font-size: 11px;
        font-weight: 600;
        color: #334155;
        padding: 4px 6px;
        background: #fff;
        min-width: 0;
    }

    #neqRowSelectionBadge {
        font-size: 11px;
        line-height: 1.3;
        color: #475569;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 4px;
        padding: 4px 6px;
        text-align: center;
    }
</style>

<div class="container-fluid px-0">
    <section class="page-hero">
        <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between">
            <div>
                <span class="page-kicker">
                    <i class="fas fa-file-word"></i>
                    Protocol Workflow
                </span>
                <h2 class="page-title">New Equipment Qualification</h2>
                <p class="page-subtitle">
                    Import DOCX qualification documents, preview formatted content, and download saved files.
                </p>
            </div>

            <div class="toolbar-row mt-3 mt-lg-0">
                {{-- Opens hidden file input; upload handled by uploadForm below --}}
                <button type="button" class="btn btn-primary btn-compact" id="btnImportDocx">
                    <i class="fas fa-file-upload mr-1"></i>
                    Import New Equipment Qualification
                </button>
            </div>
        </div>
    </section>

    @include('common.alert')

    {{-- Hidden upload form: triggered by Import button, requires e-sign password --}}
    <form id="uploadForm"
          action="{{ route('protocol.new-equipment-qualification.upload') }}"
          method="POST"
          enctype="multipart/form-data"
          class="d-none esign-required"
          autocomplete="off">
        @csrf
        <input type="hidden" name="password" autocomplete="new-password">
        <input type="file" name="docx_file" id="docxFileInput" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
    </form>

    <div id="neqListSection">
    <div class="card mb-4">
        <div class="card-header d-flex flex-column flex-lg-row align-items-lg-center justify-content-between">
            <div class="section-title mb-0">Imported Documents</div>
            @if($uploads->isNotEmpty())
                <div class="small text-muted mt-2 mt-lg-0" id="neqUploadsHeaderCount">
                    {{ $uploads->count() }} document{{ $uploads->count() === 1 ? '' : 's' }}
                </div>
            @endif
        </div>
        <div class="card-body">
            @if($uploads->isEmpty())
                <div class="alert alert-info mb-0">No documents imported yet.</div>
            @else
                {{-- Same filter + table pattern as All Protocol --}}
                <form id="neqUploadFilterForm" class="mb-3" autocomplete="off" onsubmit="return false;">
                    <div class="row g-2 align-items-end">
                        <div class="col-lg-2 col-md-4">
                            <label class="small text-muted" for="neqUploadSearch">Search</label>
                            <input type="text"
                                   id="neqUploadSearch"
                                   name="neq_file_search"
                                   class="form-control"
                                   placeholder="File / NEQ1"
                                   value=""
                                   autocomplete="off"
                                   autocorrect="off"
                                   autocapitalize="off"
                                   spellcheck="false"
                                   data-lpignore="true"
                                   data-form-type="other"
                                   aria-label="Search by file name or NEQ ID"
                                   readonly
                                   onfocus="this.removeAttribute('readonly')">
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <label class="small text-muted" for="neqUploadDocNo">Reference Document No.</label>
                            <input type="text"
                                   id="neqUploadDocNo"
                                   name="neq_doc_no"
                                   class="form-control"
                                   placeholder="QDS-048611"
                                   value=""
                                   autocomplete="off"
                                   spellcheck="false"
                                   aria-label="Filter by reference document number">
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <label class="small text-muted" for="neqUploadStatus">Status</label>
                            <select id="neqUploadStatus" class="form-control" aria-label="Filter by status">
                                <option value="">All</option>
                                <option value="original">Original</option>
                                <option value="edited">Edited</option>
                                <option value="pending">Pending Approval</option>
                                <option value="returned">Sent Back</option>
                                <option value="rejected">Rejected</option>
                                <option value="approved">Approved</option>
                            </select>
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <label class="small text-muted" for="neqUploadFromDate">From Date</label>
                            <input type="date"
                                   id="neqUploadFromDate"
                                   class="form-control"
                                   aria-label="Filter from uploaded date">
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <label class="small text-muted" for="neqUploadToDate">To Date</label>
                            <input type="date"
                                   id="neqUploadToDate"
                                   class="form-control"
                                   aria-label="Filter to uploaded date">
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <label class="small text-muted d-none d-lg-block">&nbsp;</label>
                            <div class="d-flex" style="gap: 0.5rem;">
                                <button type="button" class="btn btn-primary w-50" id="neqUploadFilterBtn">Filter</button>
                                <button type="button" class="btn btn-secondary w-50" id="neqUploadResetBtn">Reset</button>
                            </div>
                        </div>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-bordered mb-0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Reference Document No.</th>
                                <th>File Name</th>
                                <th>Uploaded On</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody id="neqUploadsTbody">
                            @foreach($uploads as $upload)
                                @php
                                    $latestEdit = $upload->editedCopies->first();
                                    $previewRecord = $latestEdit ?: $upload;
                                    $displayName = $upload->displayName();
                                    $rawRefDocNo = trim((string) ($upload->reference_document_no ?? ''));
                                    $refDocNo = ($rawRefDocNo !== '' && \App\Models\NewEquipmentQualification::isPlausibleDocumentNumber($rawRefDocNo))
                                        ? $rawRefDocNo
                                        : '';
                                    $workflowStatus = $upload->workflowStatus();
                                    $fullyApproved = $upload->isFullyApproved();
                                    $listStatus = $upload->listStatusKey();
                                    $statusMeta = [
                                        'pending' => ['Pending Approval', 'badge-info'],
                                        'returned' => ['Sent Back', 'badge-warning'],
                                        'rejected' => ['Rejected', 'badge-danger'],
                                        'approved' => ['Approved', 'badge-success'],
                                        'edited' => ['Edited', 'badge-warning'],
                                        'original' => ['Original', 'badge-secondary'],
                                    ];
                                    [$statusLabel, $statusClass] = $statusMeta[$listStatus] ?? ['Original', 'badge-secondary'];
                                @endphp
                                <tr data-list-id="{{ $upload->id }}"
                                    data-id="{{ $upload->id }}"
                                    data-neq-code="{{ $upload->displayCode() }}"
                                    data-name="{{ $displayName }}"
                                    data-ref-doc="{{ $refDocNo }}"
                                    data-status="{{ $listStatus }}"
                                    data-approval-status="{{ $workflowStatus }}"
                                    data-fully-approved="{{ $fullyApproved ? '1' : '0' }}"
                                    data-uploaded-date="{{ optional($upload->created_at)->format('Y-m-d') }}">
                                    <td class="js-neq-code">{{ $upload->displayCode() }}</td>
                                    <td class="js-neq-ref-doc">{{ $refDocNo !== '' ? $refDocNo : '—' }}</td>
                                    <td>{{ $displayName }}</td>
                                    <td class="js-neq-uploaded-on">{{ optional($upload->created_at)->format('d-m-Y h:i A') }}</td>
                                    <td>
                                        <span class="badge {{ $statusClass }} js-neq-status">{{ $statusLabel }}</span>
                                        <div class="small text-muted js-neq-last-saved {{ $latestEdit ? '' : 'd-none' }}">
                                            @if($latestEdit)
                                                Last saved {{ optional($latestEdit->updated_at)->format('d-m-Y h:i A') }}
                                            @endif
                                        </div>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <button type="button"
                                                class="btn btn-sm btn-primary js-preview-btn"
                                                data-id="{{ $previewRecord->id }}"
                                                data-list-id="{{ $upload->id }}"
                                                data-name="{{ $displayName }}"
                                                data-file-url="{{ route('protocol.new-equipment-qualification.file', $previewRecord) }}"
                                                data-preview-html-url="{{ route('protocol.new-equipment-qualification.preview-html', $previewRecord) }}"
                                                data-text-targets-url="{{ route('protocol.new-equipment-qualification.text-targets', $previewRecord) }}"
                                                data-save-url="{{ route('protocol.new-equipment-qualification.save-as', $previewRecord) }}"
                                                data-send-for-approval-url="{{ route('protocol.new-equipment-qualification.send-for-approval', $upload) }}"
                                                data-approve-url="{{ route('protocol.new-equipment-qualification.approve', $upload) }}"
                                                data-send-back-url="{{ route('protocol.new-equipment-qualification.send-back', $upload) }}"
                                                data-reject-url="{{ route('protocol.new-equipment-qualification.reject', $upload) }}"
                                                data-uploader-id="{{ $upload->uploaded_by }}"
                                                data-download-url="{{ $fullyApproved ? route('protocol.new-equipment-qualification.download', $previewRecord) : '' }}">
                                            View
                                        </button>
                                        <a class="btn btn-sm btn-warning js-neq-edited-download {{ $fullyApproved ? '' : 'd-none' }}"
                                           href="{{ $fullyApproved ? route('protocol.new-equipment-qualification.download', $previewRecord) : '#' }}">
                                            <i class="fas fa-download mr-1"></i>
                                            Download
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                            <tr id="neqUploadEmptyFilter" class="d-none">
                                <td colspan="6" class="text-center text-muted py-4">No documents match the selected filters.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div id="neqUploadsPagination"
                     class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mt-3">
                    <div class="text-muted small" id="neqUploadsPageInfo">Showing 0 to 0 of 0 documents</div>
                    <nav aria-label="Imported documents pages">
                        <ul class="pagination pagination-sm mb-0" id="neqUploadsPager"></ul>
                    </nav>
                </div>
            @endif
        </div>
    </div>
    </div>{{-- /#neqListSection --}}

    <div class="card mb-4" id="neqPreviewSection">
        {{-- Client-side DOCX preview with protected view, editing, and Save As --}}
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap">
            <div class="d-flex align-items-center flex-wrap">
                <button type="button" class="btn btn-sm btn-primary mr-2 mb-1 mb-md-0 d-none" id="btnNeqBackToList" title="Back to imported documents">
                    <i class="fas fa-arrow-left mr-1"></i> Back to list
                </button>
                <div class="section-title mb-0">Document Preview</div>
            </div>
            <span class="small text-muted" id="previewFileName">Select a file from the list and click View to preview</span>
        </div>
        <div class="card-body">
            <div id="protectedViewBanner" class="neq-protected-banner d-none">
                {{-- Read-only preview until user clicks Edit Content --}}
                <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between">
                    <div class="mb-2 mb-md-0">
                        <strong>PROTECTED VIEW</strong><em></em>
                    </div>
                </div>
            </div>

            <div id="editingModeBanner" class="neq-editing-banner d-none">
                {{-- Tools live in the fixed right dock: Add Paragraph / Save --}}
                <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between">
                    <div class="mb-2 mb-md-0">
                        <!-- <strong>Edit Content enabled</strong> — All text &amp; fill fields are unlocked. Click any line to edit, then use the <em>fixed right tools</em> (Add Paragraph / Save). -->
                    </div>
                </div>
            </div>

            <div id="previewLoading" class="text-muted small d-none mb-2">
                <i class="fas fa-spinner fa-spin mr-1"></i> Rendering document preview...
            </div>
            <div id="previewError" class="alert alert-warning d-none mb-2"></div>
            <div class="neq-preview-shell" id="previewShell">
                <div id="docxStyleContainer"></div>
                <div id="docxPreviewArea" class="docx-preview-container">
                    <p class="neq-preview-placeholder mb-0">
                        Select a file from the list above and click <strong>View</strong> to preview.
                    </p>
                </div>
            </div>

            {{-- Bottom workflow actions (same pattern as reports/show Approve Protocol) --}}
            <div id="neqWorkflowActions" class="card border-success mt-3 mb-0 d-none">
                <div class="card-body">
                    <h5 class="mb-3" id="neqWorkflowActionsTitle">Document actions</h5>
                    <div class="d-flex flex-wrap align-items-center">
                        <button type="button" class="btn btn-info mr-2 mb-2 d-none" id="btnSendForApproval"
                                title="Send for approval after Complied By, Checked By, and Approved By are selected">
                            <i class="fas fa-paper-plane mr-1"></i> Send for Approval
                        </button>
                        <button type="button" class="btn btn-success mr-2 mb-2 d-none js-neq-approve-btn">
                            <i class="fas fa-check mr-1"></i> Approve
                        </button>
                        <button type="button" class="btn btn-warning mr-2 mb-2 d-none js-neq-send-back-btn">
                            Send Back
                        </button>
                        <button type="button" class="btn btn-danger mr-2 mb-2 d-none js-neq-reject-btn">
                            Reject
                        </button>
                    </div>
                    <div id="neqDecisionBox" class="card border-warning d-none mt-3 mb-0">
                        <div class="card-body py-3">
                            <label class="form-label mb-1" id="neqDecisionLabel">Reason <span class="text-danger">*</span></label>
                            <textarea id="neqDecisionRemark" class="form-control" rows="3" placeholder="Enter reason..."></textarea>
                            <div id="neqDecisionHint" class="alert alert-warning mt-2 py-2 mb-0 d-none">Please enter a reason.</div>
                            <div id="neqRejectWarning" class="alert alert-danger mt-2 py-2 mb-0 d-none">
                                Reject will close this document. The filler cannot edit or resubmit it.
                            </div>
                            <button type="button" class="btn btn-sm btn-warning mt-3" id="neqDecisionConfirm">Confirm</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Fixed top-right dock: edit & navigation tools --}}
<div id="neqFloatToolbar" class="neq-float-toolbar d-none" aria-label="Document edit tools">
    <div class="neq-float-toolbar-title">Document tools</div>
    <span id="neqDraftStatus" class="neq-draft-status d-none"></span>
    <button type="button" class="btn btn-sm btn-dark d-none" id="btnEsignStamp"
            title="Click a field, then stamp your name and today's date"
            style="background-color:#2c3e50;border-color:#1a252f;color:#fff;">
        <i class="fas fa-signature mr-1"></i> E-Sign
    </button>
    <button type="button" class="btn btn-sm btn-success btn-neq-edit" id="btnFloatEditContent" title="Unlock / lock document editing"
            style="background-color:#1cc88a;border-color:#13855c;color:#fff;">
        <i class="fas fa-edit mr-1"></i> Edit Content
    </button>
    <button type="button" class="btn btn-sm btn-warning" id="btnAddParagraph" title="Add an extra note/paragraph at the selected section or end"
            style="background-color:#fd7e14;border-color:#d9480f;color:#fff;">
        <i class="fas fa-paragraph mr-1"></i> Add Paragraph
    </button>
    {{-- Save stays off. Typing autosaves JSON cleanly.
    <button type="button" class="btn btn-sm btn-primary" id="btnSaveAs"
            style="background-color:#4e73df;border-color:#2e59d9;color:#fff;">
        <i class="fas fa-save mr-1"></i> Save
    </button>
    --}}
</div>
@endsection

@section('scripts')

<script src="{{ asset('js/vendor/jszip.min.js') }}"></script>
<script src="{{ asset('js/vendor/docx-preview.min.js') }}?v=shd1"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const uploadForm = document.getElementById('uploadForm');
    const fileInput = document.getElementById('docxFileInput');
    const importBtn = document.getElementById('btnImportDocx');
    const previewArea = document.getElementById('docxPreviewArea');
    if (previewArea) {
        previewArea.addEventListener('click', function (event) {
            const anchor = event.target && event.target.closest ? event.target.closest('a') : null;
            if (!anchor || !previewArea.contains(anchor)) {
                return;
            }
            event.preventDefault();
        }, true);
    }
    const styleContainer = document.getElementById('docxStyleContainer');
    const previewShell = document.getElementById('previewShell');
    const previewLoading = document.getElementById('previewLoading');
    const previewError = document.getElementById('previewError');
    const previewFileName = document.getElementById('previewFileName');
    const protectedViewBanner = document.getElementById('protectedViewBanner');
    const editingModeBanner = document.getElementById('editingModeBanner');
    const btnEnableEditing = null; // removed from banner; use #btnFloatEditContent
    const btnSaveAs = document.getElementById('btnSaveAs');
    const btnSendForApproval = document.getElementById('btnSendForApproval');
    const neqFloatToolbar = document.getElementById('neqFloatToolbar');
    const neqFloatInsertHint = document.getElementById('neqFloatInsertHint');
    const uploadsTbody = document.getElementById('neqUploadsTbody');
    const csrfToken = document.querySelector('#uploadForm input[name="_token"]')?.value || '';
    const previewPlaceholder = 'Select a file from the list above and click <strong>View</strong> to preview.';
    const LUPIN_LOGO_URL = @json(asset('admin/img/lupin.png'));
    const neqApproverUsers = @json($neqUsers ?? []);
    const neqCurrentUserId = @json((int) auth()->id());
    const neqCurrentUserName = @json(trim((auth()->user()->first_name ?? '').' '.(auth()->user()->last_name ?? '')) ?: (auth()->user()->email ?? 'User'));
    let neqApprovals = @json($neqApprovals ?? []);

    let currentDocxBlob = null;
    let savedFillCache = null;
    let currentRecordId = null;
    let currentFileName = null;
    let currentSaveUrl = null;
    let currentApproveUrl = null;
    let currentSendForApprovalUrl = null;
    let currentSendBackUrl = null;
    let currentRejectUrl = null;
    let currentDecisionType = null;
    let currentDownloadUrl = null;
    let currentTextTargetsUrl = null;
    let currentListId = null;
    let currentUploaderId = null;
    let docxTextTargets = null;
    let neqCachedPreview = null;
    let neqFieldOriginals = {};
    let neqFieldLabels = {};
    let neqTableOpsLog = [];
    let neqSelectedRowInfo = null;
    let isEditingEnabled = false;
    let hasActivePreview = false;
    let pendingTableDeletes = [];
    let neqDraftTimer = null;
    let neqDraftSaving = false;
    let neqSaveQueued = null;
    // Word lines present when this file was opened. A line missing at Save was deleted.
    let savedParagraphSnapshot = [];
    let assignedDocxIndexes = new Set();
    let tocProtectedIndexes = new Set();
    let lastEditableFocus = null;
    let lastStructureAnchor = null;

    if (importBtn && fileInput && uploadForm) {
        importBtn.addEventListener('click', function () {
            fileInput.click();
        });

        fileInput.addEventListener('change', function () {
            if (!fileInput.files || !fileInput.files[0]) {
                return;
            }
            uploadForm.requestSubmit();
        });
    }

    function resetPreviewState() {
        hasActivePreview = false;
        isEditingEnabled = false;
        currentDocxBlob = null;
        currentRecordId = null;
        currentFileName = null;
        currentSaveUrl = null;
        currentApproveUrl = null;
        currentSendForApprovalUrl = null;
        currentSendBackUrl = null;
        currentRejectUrl = null;
        currentDownloadUrl = null;
        currentTextTargetsUrl = null;
        currentDecisionType = null;
        hideNeqDecisionBox();
        docxTextTargets = null;
        neqCachedPreview = null;
        neqFieldOriginals = {};
        neqFieldLabels = {};
        neqTableOpsLog = [];
        neqSelectedRowInfo = null;
        pendingTableDeletes = [];
        savedParagraphSnapshot = [];
        lastEditableFocus = null;
        lastStructureAnchor = null;
        const pageNav = document.getElementById('neqPageNavigator');
        if (pageNav) {
            pageNav.classList.add('d-none');
        }
        const rowBadge = document.getElementById('neqRowSelectionBadge');
        if (rowBadge) {
            rowBadge.textContent = '';
            rowBadge.classList.add('d-none');
        }
        clearInsertTargetHighlight();
        setFloatToolbarVisible(false);
        document.removeEventListener('focusin', onPreviewFocusIn, true);
        document.removeEventListener('mouseup', onPreviewMouseUp, true);
        document.removeEventListener('click', onPreviewClickSelect, true);
        updateDownloadLinks(null);
        protectedViewBanner.classList.add('d-none');
        editingModeBanner.classList.add('d-none');
        previewShell.classList.remove('neq-edit-mode');
    }

    function showPlaceholder() {
        resetPreviewState();
        previewArea.innerHTML = '<p class="neq-preview-placeholder mb-0">' + previewPlaceholder + '</p>';
        styleContainer.innerHTML = '';
        previewFileName.textContent = 'Select a file from the list and click View to preview';
    }

    function showProtectedViewBanner() {
        protectedViewBanner.classList.remove('d-none');
        editingModeBanner.classList.add('d-none');
        setFloatToolbarVisible(true);
        clearInsertTargetHighlight();
        previewShell.classList.remove('neq-edit-mode');
        isEditingEnabled = false;
        updateEditContentButtonState();
    }

    function updateDownloadLinks(url) {
        currentDownloadUrl = url || null;
        document.querySelectorAll('.js-neq-download').forEach(function (link) {
            if (!currentDownloadUrl) {
                link.classList.add('d-none');
                link.setAttribute('href', '#');
                return;
            }

            link.classList.remove('d-none');
            link.setAttribute('href', currentDownloadUrl);
        });
    }

    function showSaveSuccess(message) {
        previewError.textContent = message || 'Document saved successfully.';
        previewError.classList.remove('alert-warning', 'd-none');
        previewError.classList.add('alert-success');
    }

    function setNeqDraftStatus(message, tone) {
        const el = document.getElementById('neqDraftStatus');
        if (!el) {
            return;
        }
        if (!message) {
            el.textContent = '';
            el.classList.add('d-none');
            el.classList.remove('is-saving', 'is-error');
            return;
        }
        el.textContent = message;
        el.classList.remove('d-none', 'is-saving', 'is-error');
        if (tone === 'saving') {
            el.classList.add('is-saving');
        } else if (tone === 'error') {
            el.classList.add('is-error');
        }
    }

    function markAutosavedStructure(stats) {
        pendingTableDeletes = [];
        const root = getEditScopeRoot() || getPreviewContentRoot();
        if (!root) {
            return;
        }
        const inserted = Number(stats && stats.rows_inserted) || 0;
        if (inserted > 0) {
            const grouped = new Map();
            root.querySelectorAll('tr[data-neq-new-row="1"]').forEach(function (row) {
                const table = row.closest ? row.closest('table') : null;
                if (!table) {
                    return;
                }
                if (!grouped.has(table)) {
                    grouped.set(table, []);
                }
                grouped.get(table).push(row);
            });
            grouped.forEach(function (rows, table) {
                let placed = 0;
                rows.forEach(function (row) {
                    const after = Number(row.getAttribute('data-neq-insert-after'));
                    if (!Number.isInteger(after) || after < -1) {
                        return;
                    }
                    const wordRow = after + 1 + placed;
                    getDirectTableRows(table).forEach(function (other) {
                        if (other === row || other.getAttribute('data-neq-new-row') === '1') {
                            return;
                        }
                        const current = Number(other.getAttribute('data-neq-row'));
                        if (Number.isInteger(current) && current >= wordRow) {
                            other.setAttribute('data-neq-row', String(current + 1));
                        }
                    });
                    row.setAttribute('data-neq-row', String(wordRow));
                    row.removeAttribute('data-neq-new-row');
                    row.removeAttribute('data-neq-insert-after');
                    placed += 1;
                });
            });
        }
        root.querySelectorAll('[data-neq-new-para="1"], p[data-neq-user-typed="1"], li[data-neq-user-typed="1"]').forEach(function (node) {
            node.setAttribute('data-neq-saved-text', nodeEditText(node));
        });
    }

    function scheduleNeqAutosave() {
        if (!hasActivePreview || !isEditingEnabled || !currentSaveUrl || !currentUserCanSave()) {
            return;
        }
        if (neqDraftTimer) {
            clearTimeout(neqDraftTimer);
        }
        neqDraftTimer = setTimeout(function () {
            neqDraftTimer = null;
            saveEditedDocument('', { autosave: true });
        }, 1200);
    }

    function hidePreviewMessages() {
        previewError.classList.add('d-none');
        previewError.classList.remove('alert-success', 'alert-warning');
        previewError.textContent = '';
    }

    function mimeFromExtension(filename) {
        const ext = (filename.split('.').pop() || '').toLowerCase();
        const mimeTypes = {
            png: 'image/png',
            jpg: 'image/jpeg',
            jpeg: 'image/jpeg',
            gif: 'image/gif',
            bmp: 'image/bmp',
            svg: 'image/svg+xml',
            webp: 'image/webp',
            emf: 'image/emf',
            wmf: 'image/wmf',
        };

        return mimeTypes[ext] || null;
    }

    function mimeFromBase64(base64) {
        if (!base64) {
            return 'image/png';
        }

        if (base64.indexOf('iVBORw0KGgo') === 0) {
            return 'image/png';
        }

        if (base64.indexOf('/9j/') === 0) {
            return 'image/jpeg';
        }

        if (base64.indexOf('R0lGOD') === 0) {
            return 'image/gif';
        }

        if (base64.indexOf('Qk') === 0) {
            return 'image/bmp';
        }

        if (base64.indexOf('UklGR') === 0) {
            return 'image/webp';
        }

        return 'image/png';
    }

    function buildDataUrl(base64, filename) {
        const mimeType = mimeFromExtension(filename) || mimeFromBase64(base64);
        return 'data:' + mimeType + ';base64,' + base64;
    }

    function hasInvalidImageDataUrl(src) {
        if (!src || src.indexOf('data:') !== 0) {
            return false;
        }

        const mimeType = src.slice(5, src.indexOf(';'));
        return mimeType === 'application/octet-stream' || mimeType.indexOf('image/') !== 0;
    }

    function normalizeDataUrl(src, filename) {
        if (!src || src.indexOf('data:') !== 0) {
            return src;
        }

        const commaIndex = src.indexOf(',');
        if (commaIndex === -1) {
            return src;
        }

        const base64 = src.slice(commaIndex + 1);
        return buildDataUrl(base64, filename || '');
    }

    function resetDocxPreviewImageStyles(img) {
        [
            'clip-path', '-webkit-clip-path', 'transform', 'translate', 'scale',
            'top', 'left', 'right', 'bottom', 'margin', 'margin-top', 'margin-left',
            'object-fit', 'object-position', 'filter',
        ].forEach(function (prop) {
            img.style.removeProperty(prop);
        });

        img.style.setProperty('clip-path', 'none', 'important');
        img.style.setProperty('-webkit-clip-path', 'none', 'important');
        img.style.setProperty('transform', 'none', 'important');
        img.style.setProperty('top', 'auto', 'important');
        img.style.setProperty('left', 'auto', 'important');
        img.style.setProperty('margin', '0', 'important');
    }

    function applyImageToElement(img, dataUrl, meta) {
        resetDocxPreviewImageStyles(img);
        img.src = dataUrl;
        img.alt = (meta && meta.mediaFileName) || 'Embedded image';
        img.setAttribute('data-neq-media', (meta && meta.mediaFileName) || 'injected');
        img.removeAttribute('width');
        img.removeAttribute('height');
        img.style.setProperty('display', 'block', 'important');
        img.style.setProperty('visibility', 'visible', 'important');
        img.style.setProperty('opacity', '1', 'important');
        if (img.closest('header, .docx_header')) {
            img.style.setProperty('max-width', '100%', 'important');
            img.style.setProperty('max-height', '70px', 'important');
            img.style.setProperty('width', 'auto', 'important');
            img.style.setProperty('height', 'auto', 'important');
            console.info('[NEQ Preview] Applied image src:', dataUrl.slice(0, 48) + '...');
            return;
        }
        img.style.setProperty('max-width', '120px', 'important');
        img.style.setProperty('min-width', '40px', 'important');
        img.style.setProperty('min-height', '40px', 'important');
        img.style.setProperty('width', 'auto', 'important');
        img.style.setProperty('height', 'auto', 'important');
        img.style.setProperty('position', 'static', 'important');
        console.info('[NEQ Preview] Applied image src:', dataUrl.slice(0, 48) + '...');
    }

    function normalizeMediaPath(target, partKey) {
        let path = (target || '').replace(/^\.\.\//, '');

        if (path.indexOf('word/media/') === 0) {
            return path;
        }

        if (path.indexOf('media/') === 0) {
            return 'word/' + path;
        }

        if (partKey && partKey.indexOf('header') === 0) {
            return 'word/media/' + path.split('/').pop();
        }

        return 'word/media/' + path.split('/').pop();
    }

    function parseRelationshipAttributes(relXml) {
        const relationships = [];
        const relTagRegex = /<Relationship\b([^>]*)\/?>/gi;
        let tagMatch;

        while ((tagMatch = relTagRegex.exec(relXml)) !== null) {
            const attrs = tagMatch[1];
            const idMatch = attrs.match(/\bId="([^"]+)"/i);
            const targetMatch = attrs.match(/\bTarget="([^"]+)"/i);
            const typeMatch = attrs.match(/\bType="([^"]+)"/i);

            if (!idMatch || !targetMatch || !typeMatch) {
                continue;
            }

            if (typeMatch[1].indexOf('image') === -1) {
                continue;
            }

            relationships.push({
                id: idMatch[1],
                target: targetMatch[1],
            });
        }

        return relationships;
    }

    function cellTextIsEmpty(cell) {
        return (cell.textContent || '')
            .replace(/[\s\u00a0\u200b\uFEFF]/g, '')
            .trim()
            .length === 0;
    }

    async function buildDocxImageIndex(zip) {
        const mediaDataUrls = {};
        const mediaFiles = Object.keys(zip.files).filter(function (name) {
            return name.indexOf('word/media/') === 0 && !name.endsWith('/');
        });

        console.info('[NEQ Preview] word/media files found:', mediaFiles);

        for (const mediaPath of mediaFiles) {
            try {
                const base64 = await zip.file(mediaPath).async('base64');
                const fileName = mediaPath.split('/').pop();
                const dataUrl = buildDataUrl(base64, fileName);
                mediaDataUrls[mediaPath] = dataUrl;
                mediaDataUrls[fileName] = dataUrl;
            } catch (error) {
                console.warn('[NEQ Preview] Failed to read media file:', mediaPath, error);
            }
        }

        const partRelMaps = {};
        const relFiles = Object.keys(zip.files).filter(function (name) {
            return /word\/_rels\/.*\.rels$/.test(name);
        });

        for (const relFile of relFiles) {
            const relXml = await zip.file(relFile).async('string');
            const partKey = relFile.replace('word/_rels/', '').replace('.rels', '');
            partRelMaps[partKey] = partRelMaps[partKey] || {};

            parseRelationshipAttributes(relXml).forEach(function (relationship) {
                const mediaPath = normalizeMediaPath(relationship.target, partKey);
                partRelMaps[partKey][relationship.id] = mediaPath;
                console.info('[NEQ Preview] Relationship mapped:', partKey, relationship.id, '->', mediaPath);
            });
        }

        const placements = [];
        const xmlParts = Object.keys(zip.files).filter(function (name) {
            return /^word\/(document|header\d+|footer\d+)\.xml$/.test(name);
        });

        for (const partPath of xmlParts) {
            const partKey = partPath.replace('word/', '');
            const partXml = await zip.file(partPath).async('string');
            const rels = partRelMaps[partKey] || {};
            const embedRegex = /r:embed="(rId\d+)"/g;
            let embedMatch;

            while ((embedMatch = embedRegex.exec(partXml)) !== null) {
                const embedId = embedMatch[1];
                const mediaPath = rels[embedId];
                if (!mediaPath) {
                    console.warn('[NEQ Preview] No media mapping for', partKey, embedId);
                    continue;
                }

                placements.push({
                    part: partKey,
                    embedId: embedId,
                    mediaPath: mediaPath,
                    mediaFileName: mediaPath.split('/').pop(),
                });
            }
        }

        console.info('[NEQ Preview] Image placements discovered:', placements);

        return {
            mediaDataUrls: mediaDataUrls,
            placements: placements,
        };
    }

    function isImageBroken(img) {
        const src = img.getAttribute('src') || '';

        if (!src || src === '#') {
            return true;
        }

        if (hasInvalidImageDataUrl(src)) {
            return true;
        }

        if (src.indexOf('media/') === 0 || src.indexOf('word/media/') === 0) {
            return true;
        }

        if (!src.startsWith('data:') && !src.startsWith('blob:') && !src.startsWith('http')) {
            return true;
        }

        if (!img.complete) {
            return hasInvalidImageDataUrl(src);
        }

        return img.naturalWidth === 0;
    }

    function findDataUrlForImage(img, imageIndex) {
        const src = img.getAttribute('src') || '';
        const fileName = src.split('/').pop();

        if (fileName && imageIndex.mediaDataUrls[fileName]) {
            return imageIndex.mediaDataUrls[fileName];
        }

        return null;
    }

    function isCellCandidateForImage(cell) {
        const images = Array.from(cell.querySelectorAll('img'));
        const hasWorkingImage = images.some(function (img) {
            return !isImageBroken(img);
        });

        if (hasWorkingImage) {
            return false;
        }

        return cellTextIsEmpty(cell) || images.length > 0;
    }

    function getPreviewDocumentRoot(previewRoot) {
        return previewRoot.querySelector('.docx-wrapper') || previewRoot;
    }

    function letterheadLogoCells(previewRoot) {
        const docRoot = getPreviewDocumentRoot(previewRoot);
        const cells = [];
        docRoot.querySelectorAll('table').forEach(function (table) {
            if (table.parentElement && table.parentElement.closest('td, th')) {
                return;
            }
            const tableText = (table.innerText || table.textContent || '').toLowerCase();
            if (!/\blupin\b/.test(tableText) || !/annex|attachment|document|equipment|facilit/.test(tableText)) {
                return;
            }
            const firstRow = table.rows && table.rows[0];
            const cell = firstRow && firstRow.cells && firstRow.cells[0];
            if (!cell) {
                return;
            }
            const text = (cell.innerText || cell.textContent || '').replace(/\u00a0/g, ' ').trim();
            if (text.length > 24 && !cell.querySelector('img')) {
                return;
            }
            cells.push(cell);
        });
        return cells;
    }

    function findHeaderLogoCell(previewRoot) {
        const cells = letterheadLogoCells(previewRoot);
        return cells.length ? cells[0] : null;
    }

    function containCroppedHeaderLogo(previewRoot) {
        const docRoot = getPreviewDocumentRoot(previewRoot);
        if (!docRoot) {
            return;
        }
        docRoot.querySelectorAll('img').forEach(function (img) {
            if (img.getAttribute('data-neq-official-logo') === '1') {
                return;
            }
            const inHeader = !!(img.closest && img.closest('header, .docx_header'));
            const declared = img.style.transform || window.getComputedStyle(img).transform || '';
            const scaleMatch = declared.match(/scale\(\s*([0-9.]+)/) || declared.match(/matrix\(\s*([0-9.]+)/);
            const scaledUp = !!(scaleMatch && parseFloat(scaleMatch[1]) > 2);
            const huge = (img.naturalWidth || 0) > 500 || (img.offsetWidth || 0) > 280;
            if (!scaledUp && !(inHeader && huge)) {
                return;
            }
            img.style.setProperty('transform', 'none', 'important');
            img.style.setProperty('clip-path', 'none', 'important');
            img.style.setProperty('-webkit-clip-path', 'none', 'important');
            img.style.setProperty('position', 'static', 'important');
            img.style.setProperty('left', 'auto', 'important');
            img.style.setProperty('top', 'auto', 'important');
            img.style.setProperty('width', '72px', 'important');
            img.style.setProperty('height', 'auto', 'important');
            img.style.setProperty('max-width', '88%', 'important');
            img.style.setProperty('max-height', '90px', 'important');
            img.style.setProperty('margin', '6px auto', 'important');
            img.style.setProperty('display', 'block', 'important');
            if (LUPIN_LOGO_URL) {
                img.src = LUPIN_LOGO_URL;
            }
            img.alt = 'Lupin';
            img.setAttribute('data-neq-official-logo', '1');
        });
    }

    function applyOfficialHeaderLogo(previewRoot) {
        containCroppedHeaderLogo(previewRoot);
        return true;
    }

    function findLogoImageHost(cell) {
        if (!cell) {
            return null;
        }

        const candidates = [
            cell.querySelector('p.docx_header div[style*="inline-block"]'),
            cell.querySelector('p span > div[style]'),
            cell.querySelector('div[style*="position: relative"]'),
        ];

        for (let i = 0; i < candidates.length; i++) {
            const placeholder = candidates[i];
            if (!placeholder) {
                continue;
            }

            const hasVisibleImage = Array.from(placeholder.querySelectorAll('img')).some(function (img) {
                return !isImageBroken(img);
            });

            if (!hasVisibleImage) {
                return placeholder;
            }
        }

        return cell;
    }

    function prepareLogoImageHost(host, cell) {
        host.style.setProperty('overflow', 'visible', 'important');
        host.style.setProperty('display', 'block', 'important');
        host.style.setProperty('width', 'auto', 'important');
        host.style.setProperty('height', 'auto', 'important');
        host.style.setProperty('min-width', '40px', 'important');
        host.style.setProperty('min-height', '40px', 'important');
        host.style.setProperty('position', 'static', 'important');
        host.style.setProperty('clip-path', 'none', 'important');
        host.style.setProperty('transform', 'none', 'important');

        if (cell) {
            cell.style.setProperty('overflow', 'visible', 'important');
        }
    }

    function createFreshLogoImage(host) {
        host.innerHTML = '';
        return host.appendChild(document.createElement('img'));
    }

    async function waitForHeaderLogoCell(previewRoot, timeoutMs) {
        const deadline = Date.now() + (timeoutMs || 3000);

        while (Date.now() < deadline) {
            const cell = findHeaderLogoCell(previewRoot);
            if (cell) {
                return cell;
            }

            await new Promise(function (resolve) {
                setTimeout(resolve, 100);
            });
        }

        return null;
    }

    function injectImageIntoCell(cell, dataUrl, meta) {
        const host = findLogoImageHost(cell);
        let img = null;

        prepareLogoImageHost(host !== cell ? host : cell, cell);

        if (host !== cell) {
            img = createFreshLogoImage(host);
            console.info('[NEQ Preview] Replaced docx-preview placeholder with fresh <img> for', meta.mediaFileName);
        } else {
            img = cell.querySelector('img');
            if (!img) {
                img = document.createElement('img');
                cell.insertBefore(img, cell.firstChild);
                console.info('[NEQ Preview] Inserted new <img> into cell for', meta.mediaFileName);
            } else {
                resetDocxPreviewImageStyles(img);
                console.info('[NEQ Preview] Updated existing <img> in cell for', meta.mediaFileName);
            }
        }

        applyImageToElement(img, dataUrl, meta);
    }

    function findEmptyImageCells(previewRoot, limit) {
        const cells = [];
        const seen = new Set();
        const logoCell = findHeaderLogoCell(previewRoot);

        function addCell(cell) {
            if (!cell || seen.has(cell) || cells.length >= limit) {
                return;
            }

            if (logoCell && cell === logoCell) {
                return;
            }

            if (isCellCandidateForImage(cell)) {
                cells.push(cell);
                seen.add(cell);
            }
        }

        const firstTable = previewRoot.querySelector('table');
        if (firstTable) {
            const firstRow = firstTable.querySelector('tr');
            if (firstRow) {
                addCell(firstRow.querySelector('td, th'));
            }
        }

        previewRoot.querySelectorAll('table').forEach(function (table) {
            table.querySelectorAll('tr').forEach(function (row) {
                row.querySelectorAll('td, th').forEach(function (cell) {
                    addCell(cell);
                });
            });
        });

        return cells;
    }

    function headerLogoMissingOrBroken(previewRoot) {
        if (!previewRoot) {
            return false;
        }
        const logoCell = findHeaderLogoCell(previewRoot);
        const candidates = logoCell
            ? Array.from(logoCell.querySelectorAll('img'))
            : Array.from(previewRoot.querySelectorAll('header img, .docx_header img'));
        if (!candidates.length) {
            return true;
        }
        return !candidates.some(function (img) {
            return !isImageBroken(img);
        });
    }

    async function fixPreviewImages(previewRoot, docxBlob, sharedZip) {
        if (!previewRoot || (!sharedZip && (!window.JSZip || !docxBlob))) {
            console.warn('[NEQ Preview] fixPreviewImages skipped — missing JSZip, blob, or preview root.');
            return;
        }

        try {
            const zip = sharedZip || await JSZip.loadAsync(docxBlob);
            const imageIndex = await buildDocxImageIndex(zip);
            const placements = imageIndex.placements.slice();
            const usedPlacements = new Set();

            const images = Array.from(previewRoot.querySelectorAll('img'));
            console.info('[NEQ Preview] <img> tags in rendered HTML:', images.length);

            const logoCell = findHeaderLogoCell(previewRoot);

            images.forEach(function (img) {
                if (img.closest('header, .docx_header') || (logoCell && logoCell.contains(img))) {
                    return;
                }

                const currentSrc = img.getAttribute('src') || '';
                let placement = null;

                if (hasInvalidImageDataUrl(currentSrc)) {
                    placement = placements.find(function (item) {
                        return !usedPlacements.has(item.mediaFileName);
                    });

                    const correctedUrl = placement
                        ? (imageIndex.mediaDataUrls[placement.mediaPath] || imageIndex.mediaDataUrls[placement.mediaFileName])
                        : normalizeDataUrl(currentSrc, 'image1.png');

                    applyImageToElement(img, correctedUrl, placement || { mediaFileName: 'image1.png' });

                    if (placement) {
                        usedPlacements.add(placement.mediaFileName);
                        console.info('[NEQ Preview] Corrected MIME type for', placement.mediaFileName, 'from', placement.part);
                    } else {
                        console.info('[NEQ Preview] Corrected MIME type using PNG signature detection.');
                    }

                    return;
                }

                if (!isImageBroken(img)) {
                    console.info('[NEQ Preview] Image already rendered:', currentSrc.slice(0, 48) + '...');
                    return;
                }

                const srcMatch = currentSrc.match(/image\d+\.[a-z0-9]+/i);

                if (srcMatch) {
                    placement = placements.find(function (item) {
                        return item.mediaFileName === srcMatch[0] && !usedPlacements.has(item.mediaFileName);
                    });
                }

                if (!placement && placements.length) {
                    placement = placements.find(function (item) {
                        return !usedPlacements.has(item.mediaFileName);
                    });
                }

                const dataUrl = placement
                    ? imageIndex.mediaDataUrls[placement.mediaPath] || imageIndex.mediaDataUrls[placement.mediaFileName]
                    : findDataUrlForImage(img, imageIndex);

                if (!dataUrl) {
                    console.warn('[NEQ Preview] Could not resolve data URL for <img> tag.', img);
                    return;
                }

                applyImageToElement(img, dataUrl, placement || { mediaFileName: 'image1.png' });

                if (placement) {
                    usedPlacements.add(placement.mediaFileName);
                    console.info('[NEQ Preview] Fixed broken <img> using', placement.mediaFileName, 'from', placement.part);
                }
            });

            const remainingPlacements = placements.filter(function (placement) {
                return !usedPlacements.has(placement.mediaFileName);
            });

            if (!remainingPlacements.length) {
                applyOfficialHeaderLogo(previewRoot);
                console.info('[NEQ Preview] All embedded images accounted for.');
                return;
            }

            const targetCells = findEmptyImageCells(previewRoot, remainingPlacements.length);
            console.info('[NEQ Preview] Empty image candidate cells found:', targetCells.length);

            remainingPlacements.forEach(function (placement, index) {
                if (placement.part.indexOf('header') === 0) {
                    return;
                }

                const dataUrl = imageIndex.mediaDataUrls[placement.mediaPath]
                    || imageIndex.mediaDataUrls[placement.mediaFileName];

                if (!dataUrl) {
                    console.warn('[NEQ Preview] Missing binary for placement:', placement.mediaFileName);
                    return;
                }

                let cell = targetCells[index];

                if (!cell) {
                    console.warn('[NEQ Preview] No empty cell available for placement:', placement.mediaFileName, 'in', placement.part);
                    return;
                }

                injectImageIntoCell(cell, dataUrl, placement);
                usedPlacements.add(placement.mediaFileName);
                console.info('[NEQ Preview] Injected', placement.mediaFileName, 'into table cell from', placement.part);
            });

            applyOfficialHeaderLogo(previewRoot);
        } catch (error) {
            console.warn('[NEQ Preview] fixPreviewImages failed:', error);
        }
    }

    function getPreviewContentRoot() {
        return previewArea.querySelector('.docx-wrapper')
            || previewArea.querySelector('.neq-server-preview')
            || null;
    }

    function getEditScopeRoot() {
        const wrapper = getPreviewContentRoot();
        if (!wrapper) {
            return null;
        }

        return wrapper;
    }

    // A contents line is "1.0 OBJECTIVE .... 3". Body headings have no page number.
    function isLikelyTocEntryText(text, node) {
        const raw = String(
            (node && (node.innerText || node.textContent)) || text || ''
        );
        const t = normalizeEditText(text || raw);
        if (!t) {
            return false;
        }
        if (/^table\s+of\s+contents\b/i.test(t)) {
            return true;
        }
        if (!/^\d+\.\d+/.test(t)) {
            return false;
        }
        if (/\.{3,}|…|\u2026|·{3,}|\t/.test(raw)) {
            return true;
        }
        // "1.0 OBJECTIVE .... 12" collapsed whitespace still ends with a page no.
        if (/\s\d{1,3}$/.test(t) && t.length > 8) {
            return true;
        }
        if (node && node.querySelector && node.querySelector('a') && /^\d+\.\d+/.test(t)) {
            return true;
        }
        return false;
    }

    function tocEntryRunStarts(blocks, startIndex) {
        let hits = 0;
        for (let i = startIndex; i < blocks.length; i++) {
            if (hits >= 3) {
                return true;
            }
            const block = blocks[i];
            if ((block.tagName || '').toLowerCase() === 'table') {
                return false;
            }
            const raw = block.innerText || block.textContent || '';
            const t = normalizeEditText(raw);
            if (!t) {
                continue;
            }
            if (/^table\s+of\s+contents\b/i.test(t)) {
                return false;
            }
            if (isLikelyTocEntryText(t, block)) {
                hits += 1;
                continue;
            }
            return false;
        }
        return hits >= 3;
    }

    function stampTocRegion(scope) {
        const root = scope || getEditScopeRoot();
        if (!root) {
            return;
        }
        root.querySelectorAll('[data-neq-toc]').forEach(function (el) {
            el.removeAttribute('data-neq-toc');
        });

        const blocks = Array.from(root.querySelectorAll('p, li, h1, h2, h3, h4, h5, h6, table'));
        let inToc = false;
        blocks.forEach(function (block, index) {
            const tag = (block.tagName || '').toLowerCase();
            const t = normalizeEditText(block.innerText || block.textContent || '');
            if (/^table\s+of\s+contents\b/i.test(t) || (tag !== 'table' && /^table\s+of\s+contents\b/i.test(t))) {
                inToc = true;
                block.setAttribute('data-neq-toc', '1');
                return;
            }
            if (!inToc) {
                if (tag === 'table') {
                    const rows = getDirectTableRows(block);
                    let tocHits = 0;
                    rows.slice(0, Math.min(rows.length, 12)).forEach(function (row) {
                        const rowText = normalizeEditText(row.innerText || row.textContent || '');
                        if (isLikelyTocEntryText(rowText, row)) {
                            tocHits += 1;
                        }
                    });
                    if (rows.length >= 3 && tocHits >= Math.ceil(Math.min(rows.length, 12) * 0.6)) {
                        inToc = true;
                        block.setAttribute('data-neq-toc', '1');
                        return;
                    }
                }
                if (tocEntryRunStarts(blocks, index)) {
                    inToc = true;
                } else {
                    return;
                }
            }
            if (tag === 'table') {
                // Keep list-like TOC tables inside the band; stop at first real body table.
                const rows = getDirectTableRows(block);
                let tocHits = 0;
                rows.slice(0, Math.min(rows.length, 12)).forEach(function (row) {
                    const rowText = normalizeEditText(row.innerText || row.textContent || '');
                    if (isLikelyTocEntryText(rowText, row) || /^\d+\.\d+/.test(rowText)) {
                        tocHits += 1;
                    }
                });
                const mostlyToc = rows.length >= 2
                    && tocHits >= Math.ceil(Math.min(rows.length, 12) * 0.6);
                if (mostlyToc || /table\s+of\s+contents/.test(t.toLowerCase())) {
                    block.setAttribute('data-neq-toc', '1');
                    return;
                }
                inToc = false;
                return;
            }
            if (isLikelyTocEntryText(t, block)) {
                block.setAttribute('data-neq-toc', '1');
                return;
            }
            // Blank spacer inside TOC — keep marking while still in TOC band.
            if (!t) {
                block.setAttribute('data-neq-toc', '1');
                return;
            }
            // First real body block ends the TOC band.
            inToc = false;
        });
    }

    function isInsideTocRegion(node) {
        if (!node || !node.closest) {
            return false;
        }
        if (node.getAttribute && node.getAttribute('data-neq-toc') === '1') {
            return true;
        }
        if (node.closest('[data-neq-toc="1"]')) {
            return true;
        }
        // Do NOT treat body prose with "...." / leader dots as TOC — that locked OBJECTIVE/SCOPE.
        // Only a stamped contents line (data-neq-toc) is edited as a contents line.
        return false;
    }

    function rebuildTocProtectedIndexes(targets) {
        tocProtectedIndexes = new Set();
        if (!Array.isArray(targets)) {
            return tocProtectedIndexes;
        }
        let inToc = false;
        targets.forEach(function (target, index) {
            const text = target && target.text != null ? String(target.text) : '';
            const t = normalizeEditText(text);
            const idx = String(target.index);
            if (/^table\s+of\s+contents\b/i.test(t)) {
                inToc = true;
                tocProtectedIndexes.add(idx);
                return;
            }
            if (!inToc) {
                const ahead = [];
                for (let i = index; i < targets.length && ahead.length < 3; i++) {
                    const sample = normalizeEditText(targets[i] && targets[i].text != null ? String(targets[i].text) : '');
                    if (!sample) {
                        continue;
                    }
                    if (/^table\s+of\s+contents\b/i.test(sample) || !isLikelyTocEntryText(sample, null)) {
                        break;
                    }
                    ahead.push(sample);
                }
                if (ahead.length < 3) {
                    return;
                }
                inToc = true;
            }
            if (!t || isLikelyTocEntryText(text, null) || isLikelyTocEntryText(t, null)) {
                tocProtectedIndexes.add(idx);
                return;
            }
            // Non-TOC content after TOC band.
            if (/^\d+\.\d+\s+\S/.test(t) && !isLikelyTocEntryText(t, null)) {
                inToc = false;
                return;
            }
            if (t) {
                inToc = false;
            } else {
                tocProtectedIndexes.add(idx);
            }
        });
        return tocProtectedIndexes;
    }

    function getNodeZone(node) {
        if (!node || !node.closest) {
            return 'body';
        }
        if (
            node.closest('header')
            || node.closest('.docx_header')
            || node.closest('section.docx > header')
        ) {
            return 'header';
        }
        if (
            node.closest('footer')
            || node.closest('.docx_footer')
            || node.closest('section.docx > footer')
        ) {
            return 'footer';
        }
        return 'body';
    }

    function getRepeatRoot(node, zone) {
        if (!node || !node.closest) {
            return null;
        }
        if (zone === 'header') {
            return node.closest('header') || node.closest('.docx_header') || null;
        }
        if (zone === 'footer') {
            return node.closest('footer') || node.closest('.docx_footer') || null;
        }
        return null;
    }

    function getSyncKey(node) {
        const zone = getNodeZone(node);
        if (zone !== 'header' && zone !== 'footer') {
            return '';
        }
        const root = getRepeatRoot(node, zone);
        if (!root) {
            return '';
        }
        const cell = getOwningCell(node);
        if (cell) {
            const table = cell.closest ? cell.closest('table') : null;
            const tables = Array.from(root.querySelectorAll('table'));
            const tableOrd = table ? tables.indexOf(table) : -1;
            const row = cell.parentElement;
            const rows = table ? getDirectTableRows(table) : [];
            const rowOrd = row ? rows.indexOf(row) : -1;
            const col = getRowCells(cell).indexOf(cell);
            return zone + ':t' + tableOrd + ':r' + rowOrd + ':c' + col;
        }
        const paras = Array.from(root.querySelectorAll('p, h1, h2, h3, h4, h5, h6, li')).filter(function (el) {
            return !el.closest('td, th');
        });
        return zone + ':p' + paras.indexOf(node);
    }

    function isInsideOfficialLogoCell(node) {
        if (!node) {
            return false;
        }
        const cell = node.closest ? node.closest('td, th') : null;
        const root = cell || node;
        return !!(root.querySelector && root.querySelector('img[data-neq-official-logo="1"]'));
    }

    function isMetadataFieldTable(table) {
        if (!table) {
            return false;
        }
        // Revision No. / Change / Date is a data grid. A typed cell that says
        // "revision history" must not turn the whole grid into letterhead.
        if (isRevisionHistoryTable(table)) {
            return false;
        }
        const text = normalizeEditText(table.innerText || table.textContent).toLowerCase();
        return /reference\s*document/.test(text)
            || /ref\.?\s*document/.test(text)
            || /document\s*ti/.test(text)
            || /document\s*no/.test(text)
            || (/equipment/.test(text) && (/id\s*no/.test(text) || /system\s*name/.test(text) || /system\s*id/.test(text) || /equipment\s*name/.test(text) || /equipment\s*id/.test(text) || /identification/.test(text) || /description/.test(text)))
            || (/facilit/.test(text) && /sect/.test(text))
            || /revision\s*history/.test(text)
            || /\bversion\b/.test(text)
            || /annex(ure)?\s*no/.test(text)
            || (/ioq\s+attachment|attachment\s+\d+/.test(text) && /equipment|facilit|document/.test(text));
    }

    function isComparisonTable(table) {
        if (!table) {
            return false;
        }
        const text = normalizeEditText(table.innerText || table.textContent).toLowerCase();
        if (/document\s*no|reference\s*document|equipment|facilit/.test(text)
            && /annex|lupin|attachment/.test(text)) {
            return false;
        }
        return /comparison/.test(text)
            || (/requirements/.test(text) && /urs/.test(text) && /dq/.test(text) && /ioq/.test(text));
    }

    function isCommentsOrDeviationsTable(table) {
        if (!table) {
            return false;
        }
        const text = normalizeEditText(table.innerText || table.textContent).toLowerCase();
        return /comments/.test(text)
            || (/deviation/.test(text) && /corrective/.test(text))
            || (/acceptable/.test(text) && /corrective/.test(text))
            || /compliance/.test(text)
            || /mitig/.test(text)
            || (/risk/.test(text) && (/anal/.test(text) || /eval/.test(text) || /assess/.test(text) || /revised/.test(text)));
    }

    function isCommentsBoxTable(table) {
        if (!table || table.__neqCommentsLookup) {
            return false;
        }
        const rows = getDirectTableRows(table);
        if (!rows.length) {
            return false;
        }
        const firstCells = getRowCells(rows[0]);
        if (firstCells.length === 1) {
            const head = columnHeaderMatchText(nodeEditText(firstCells[0]) || firstCells[0].innerText || '');
            if (/^comments?\b/i.test(head)) {
                return true;
            }
        }
        // The Comments body can sit on the next page without the word Comments.
        if (table.getAttribute('data-neq-page-split') === '1' && typeof tableParts === 'function') {
            const parts = tableParts(table);
            for (let i = 0; i < parts.length; i++) {
                if (!parts[i] || parts[i] === table || parts[i].getAttribute('data-neq-page-split') === '1') {
                    continue;
                }
                table.__neqCommentsLookup = true;
                const yes = isCommentsBoxTable(parts[i]);
                delete table.__neqCommentsLookup;
                if (yes) {
                    return true;
                }
            }
        }
        return false;
    }

    function isUnlockableFormTable(table) {
        return isMetadataFieldTable(table) || isComparisonTable(table) || isCommentsOrDeviationsTable(table);
    }

    function isLetterheadCell(cell) {
        if (!cell) {
            return false;
        }
        const zone = getNodeZone(cell);
        if (zone === 'header' || zone === 'footer') {
            return true;
        }
        const table = cell.closest ? cell.closest('table') : null;
        if (isInsideUnlockableFormTable(cell) || isUnlockableFormTable(table)) {
            return true;
        }
        const scope = getEditScopeRoot();
        if (!scope) {
            return false;
        }
        const letterheadRoot = scope.querySelector('header, .docx_header, footer, .docx_footer');
        if (letterheadRoot && letterheadRoot.contains(cell)) {
            return true;
        }
        const firstSection = scope.querySelector('section.docx') || scope;
        const firstTable = firstSection.querySelector('table');
        return !!(firstTable && firstTable.contains(cell));
    }

    function isEmptyHeaderValueCell(cell) {
        if (!cell || isToolCell(cell)) {
            return false;
        }
        const tag = (cell.tagName || '').toLowerCase();
        if (tag !== 'td' && tag !== 'th') {
            return false;
        }
        if (isInsideOfficialLogoCell(cell)) {
            return false;
        }
        if (cell.querySelector('table')) {
            return false;
        }
        return nodeEditText(cell) === '';
    }

    function isLogoOrImageOnlyNode(node) {
        return isInsideOfficialLogoCell(node);
    }

    function isInsideUnlockableFormTable(node) {
        let table = node && node.closest ? node.closest('table') : null;
        while (table) {
            if (isUnlockableFormTable(table) || table.closest('header, .docx_header, footer, .docx_footer')) {
                return true;
            }
            const parent = table.parentElement;
            table = parent && parent.closest ? parent.closest('table') : null;
        }
        return false;
    }

    function normalizeCompareText(value) {
        return String(value || '').replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
    }

    // Keep for callers that need collapsed compare text.
    function normalizeEditText(value) {
        return normalizeCompareText(value);
    }

    // Word auto-list numbers render in docx-preview but are absent from python-docx para.text.
    function stripAutoListPrefix(text) {
        const t = normalizeEditText(text);
        if (!t) {
            return t;
        }
        const m = t.match(/^(\d+(?:\.\d+)+\.?)\s+(.+)$/);
        return m ? m[2] : t;
    }

    function textMatchesWordTarget(nodeText, expected) {
        const live = normalizeEditText(nodeText);
        const exp = normalizeEditText(expected);
        if (!exp) {
            return false;
        }
        if (live === exp) {
            return true;
        }
        return stripAutoListPrefix(live) === exp;
    }

    function hasAutoListPrefix(text) {
        return /^\d+(?:\.\d+)+\.?\s/.test(normalizeEditText(text));
    }

    function prepareTextForSave(text, original) {
        if (text == null) {
            return '';
        }
        const raw = String(text);
        if (hasAutoListPrefix(original)) {
            return raw;
        }
        const normalized = raw.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
        const lines = normalized.split('\n');
        if (!lines.length) {
            return raw;
        }
        const stripped = lines[0].replace(/^(\d+(?:\.\d+)+\.?)\s+/, '');
        if (stripped === lines[0]) {
            return raw;
        }
        lines[0] = stripped;
        return lines.join('\n');
    }

    // Word's Symbol font draws U+00D6 / U+F0D6 as the tick (√). Saving that
    // byte in Arial shows "Ö". Keep the tick the user pasted.
    function decodeLegacySymbolText(text, fontFamily) {
        if (!text) {
            return '';
        }
        const font = String(fontFamily || '').toLowerCase();
        const legacySymbol = font.indexOf('segoe') === -1 && /(^|[,"'\s])symbol($|[,"'\s])/.test(font);
        let out = '';
        for (let i = 0; i < text.length; i++) {
            const code = text.charCodeAt(i);
            if (code === 0xF0D6 || (legacySymbol && (code === 0x00D6 || code === 0x00F6))) {
                out += '\u221A';
                continue;
            }
            out += text.charAt(i);
        }
        return out.replace(/\uF0D6/g, '\u221A').replace(/\(\u00D6\)/g, '(\u221A)').replace(/\(\u00F6\)/g, '(\u221A)');
    }

    function extractMultilineText(root) {
        if (!root) {
            return '';
        }
        let out = '';
        function walk(node) {
            if (!node) {
                return;
            }
            if (node.nodeType === Node.TEXT_NODE) {
                const parent = node.parentElement;
                let fontFamily = '';
                if (parent && parent.ownerDocument && parent.ownerDocument.defaultView) {
                    try {
                        fontFamily = parent.ownerDocument.defaultView.getComputedStyle(parent).fontFamily || '';
                    } catch (e) {
                        fontFamily = (parent.style && parent.style.fontFamily) || '';
                    }
                }
                out += decodeLegacySymbolText(node.nodeValue || '', fontFamily);
                return;
            }
            if (node.nodeType !== Node.ELEMENT_NODE) {
                return;
            }
            const tag = (node.tagName || '').toLowerCase();
            if (tag === 'script' || tag === 'style' || tag === 'select') {
                return;
            }
            // An empty auto-number marker has no text of its own. A serial the
            // user can edit is real text and must be read.
            if (node.className && /docx-num-/.test(String(node.className)) && node.closest) {
                const numCell = node.closest('td, th');
                let first = numCell && numCell.parentElement ? numCell.parentElement.firstElementChild : null;
                while (first && first.classList && first.classList.contains('neq-row-tool-cell')) {
                    first = first.nextElementSibling;
                }
                const own = (node.textContent || '').replace(/\u00a0/g, ' ').trim();
                if (numCell && first === numCell && !own) {
                    return;
                }
            }
            if (node.classList && (
                node.classList.contains('neq-remove-row')
                || node.classList.contains('neq-row-tool-cell')
                || node.classList.contains('neq-checkbox')
                || node.classList.contains('neq-stamp-pending')
            )) {
                return;
            }
            if (tag === 'br') {
                out += '\n';
                return;
            }
            const block = ['p', 'div', 'li', 'tr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'].indexOf(tag) !== -1;
            if (block && out && !out.endsWith('\n')) {
                out += '\n';
            }
            Array.from(node.childNodes || []).forEach(walk);
            if (block && out && !out.endsWith('\n')) {
                out += '\n';
            }
        }
        walk(root);
        return out
            .replace(/\u00a0/g, ' ')
            .replace(/[ \t]+\n/g, '\n')
            .replace(/\n{3,}/g, '\n\n')
            .replace(/^\n+|\n+$/g, '');
    }

    function isToolCell(node) {
        if (!node) {
            return false;
        }
        if (node.classList && node.classList.contains('neq-row-tool-cell')) {
            return true;
        }
        return !!(node.closest && node.closest('.neq-row-tool-cell'));
    }

    function nodeEditText(node) {
        if (!node || isToolCell(node)) {
            return '';
        }
        const select = node.matches && node.matches('select.neq-approver-select')
            ? node
            : (node.querySelector ? node.querySelector('select.neq-approver-select') : null);
        if (select) {
            const opt = select.options[select.selectedIndex];
            if (!opt || !opt.value) {
                return '';
            }
            return normalizeCompareText(opt.getAttribute('data-name') || opt.textContent);
        }
        const hasTools = node.querySelector && node.querySelector('.neq-remove-row, .neq-row-tool-cell, select.neq-approver-select, select.neq-qa-approver-select, select.neq-section-verified-select, select.neq-safety-by-select, select.neq-training-name-select, select.neq-training-by-select, .neq-training-name-input, .neq-training-date-input, .neq-checkbox');
        if (!hasTools) {
            return extractMultilineText(node);
        }
        const clone = node.cloneNode(true);
        clone.querySelectorAll('.neq-remove-row, .neq-row-tool-cell, select.neq-approver-select, select.neq-qa-approver-select, select.neq-section-verified-select, .neq-section-verified-host, select.neq-safety-by-select, .neq-safety-by-host, select.neq-training-name-select, select.neq-training-by-select, .neq-training-name-wrap, .neq-training-date-input, .neq-stamp-pending, .neq-checkbox').forEach(function (el) {
            el.remove();
        });
        // Prefer selected training user name when cloning would have wiped the select.
        const liveSelect = node.querySelector && node.querySelector('select.neq-training-name-select, select.neq-training-by-select');
        if (liveSelect && liveSelect.value) {
            const opt = liveSelect.options[liveSelect.selectedIndex];
            return normalizeEditText((opt && (opt.getAttribute('data-name') || opt.textContent)) || '');
        }
        return extractMultilineText(clone);
    }

    // Update text without wiping Word/docx-preview span structure when possible.
    function setNodePlainText(node, text) {
        if (!node) {
            return;
        }
        if (node.querySelector && node.querySelector('select.neq-approver-select, table')) {
            return;
        }
        const value = text == null ? '' : String(text);
        const texts = [];
        const walker = document.createTreeWalker(node, NodeFilter.SHOW_TEXT, null);
        let current = walker.nextNode();
        while (current) {
            const parent = current.parentElement;
            if (!parent || parent.closest('select, .neq-remove-row, .neq-row-tool-cell, script, style')) {
                current = walker.nextNode();
                continue;
            }
            texts.push(current);
            current = walker.nextNode();
        }
        if (texts.length) {
            texts[0].nodeValue = value;
            for (let i = 1; i < texts.length; i++) {
                texts[i].nodeValue = '';
            }
            return;
        }
        while (node.firstChild) {
            if ((node.firstChild.tagName || '').toLowerCase() === 'table') {
                break;
            }
            node.removeChild(node.firstChild);
        }
        if (value !== '') {
            node.appendChild(document.createTextNode(value));
        }
    }

    function isInsideNewTableRow(node) {
        return !!(node && node.closest && node.closest('tr[data-neq-new-row="1"]'));
    }

    function getRowCells(rowOrCell) {
        if (!rowOrCell) {
            return [];
        }
        const tag = (rowOrCell.tagName || '').toLowerCase();
        // Accept either a <tr> or a <td>/<th> (legacy callers pass both).
        let row = null;
        if (tag === 'tr') {
            row = rowOrCell;
        } else if (tag === 'td' || tag === 'th') {
            row = rowOrCell.parentElement;
        } else {
            return [];
        }
        if (!row) {
            return [];
        }
        return Array.from(row.children).filter(function (el) {
            const t = (el.tagName || '').toLowerCase();
            return (t === 'td' || t === 'th') && !isToolCell(el);
        });
    }

    function getDirectTableRows(table) {
        const rows = [];
        if (!table) {
            return rows;
        }
        Array.from(table.children).forEach(function (child) {
            const tag = (child.tagName || '').toLowerCase();
            if (tag === 'tbody' || tag === 'thead') {
                Array.from(child.children).forEach(function (tr) {
                    if ((tr.tagName || '').toLowerCase() === 'tr') {
                        rows.push(tr);
                    }
                });
            } else if (tag === 'tr') {
                rows.push(child);
            }
        });
        return rows;
    }

    function getMappablePreviewTables() {
        const scope = getEditScopeRoot();
        if (!scope) {
            return [];
        }
        const firstHeader = scope.querySelector('header, .docx_header');
        const firstFooter = scope.querySelector('footer, .docx_footer');
        return Array.from(scope.querySelectorAll('table')).filter(function (table) {
            // docx-preview often wraps cell content in nested <table>s that do not exist
            // in Word. Counting them shifts table indexes so fills miss and only later
            // approval stamps appear to "work". Map only top-level tables.
            const parentCell = table.parentElement && table.parentElement.closest
                ? table.parentElement.closest('td, th')
                : null;
            if (parentCell && scope.contains(parentCell)) {
                return false;
            }
            const header = table.closest('header, .docx_header');
            if (header && firstHeader && header !== firstHeader) {
                return false;
            }
            const footer = table.closest('footer, .docx_footer');
            if (footer && firstFooter && footer !== firstFooter) {
                return false;
            }
            return true;
        });
    }

    function ensureTableWrap(table) {
        if (!table || !table.parentNode) {
            return null;
        }
        const parent = table.parentNode;
        if (parent.classList && parent.classList.contains('neq-table-wrap')) {
            return parent;
        }
        const wrap = document.createElement('div');
        wrap.className = 'neq-table-wrap';
        parent.insertBefore(wrap, table);
        wrap.appendChild(table);
        return wrap;
    }

    function listMarkerText(el) {
        if (!el || !window.getComputedStyle) {
            return '';
        }
        const before = window.getComputedStyle(el, '::before');
        let marker = (before.content || '').trim();
        if (!marker || marker === 'none' || marker === 'normal') {
            return '';
        }
        marker = marker.replace(/^["']+|["']+$/g, '').replace(/\\([0-9a-fA-F]{1,6})/g, function (_, hex) {
            return String.fromCharCode(parseInt(hex, 16));
        }).trim();
        if (!marker || /counter\s*\(/i.test(marker)) {
            return '';
        }
        return marker;
    }

    function stripDocxNumClass(el) {
        if (!el) {
            return;
        }
        el.className = String(el.className || '').replace(/\bdocx-num-\d+-\d+\b/g, '').replace(/\s+/g, ' ').trim();
        el.style.removeProperty('counter-increment');
        el.style.removeProperty('display');
    }

    function dropAutoSerialNumbers(root) {
        if (!root || !root.querySelectorAll) {
            return;
        }
        // Training S. No. is only a Word list marker (1. 2. 3.). The preview
        // counter is not text, so the column looks blank and cannot be edited.
        // Write that number into the cell. Leave a number the user already typed.
        const tables = root.matches && root.matches('table')
            ? [root]
            : Array.from(root.querySelectorAll('table'));
        const seen = new Set();
        tables.forEach(function (table) {
            const parts = typeof tableParts === 'function' ? tableParts(table) : [table];
            const head = parts[0] || table;
            if (seen.has(head)) {
                return;
            }
            seen.add(head);
            let next = 1;
            parts.forEach(function (part) {
            getDirectTableRows(part).forEach(function (row) {
                if (isHeaderLikeRow(row)) {
                    return;
                }
                const cell = getRowCells(row)[0];
                if (!cell || !isSerialOrAnnexNoColumnCell(cell)) {
                    return;
                }
                const numbered = Array.from(cell.querySelectorAll('[class*="docx-num-"]'));
                const live = normalizeCompareText(cell.innerText || cell.textContent || '');
                const userEdited = cell.getAttribute('data-neq-user-edited') === '1'
                    || !!cell.querySelector('[data-neq-user-edited="1"]');
                if (userEdited || (live && !/^\d+(?:\.\d+)*\.?$/.test(live))) {
                    numbered.forEach(stripDocxNumClass);
                    return;
                }
                if (/^\d+(?:\.\d+)*\.?$/.test(live)) {
                    const current = parseInt(live, 10);
                    if (current >= next) {
                        next = current + 1;
                    }
                    numbered.forEach(stripDocxNumClass);
                    return;
                }
                if (!numbered.length) {
                    return;
                }
                let marker = '';
                numbered.forEach(function (el) {
                    const read = listMarkerText(el).replace(/\.$/, '');
                    if (/^\d+(?:\.\d+)*$/.test(read)) {
                        marker = read;
                    }
                });
                const number = marker || String(next);
                const asNumber = parseInt(number, 10);
                if (asNumber >= next) {
                    next = asNumber + 1;
                } else {
                    next += 1;
                }
                numbered[0].textContent = number;
                numbered.forEach(stripDocxNumClass);
            });
            });
        });
    }

    function isSerialOrAnnexNoColumnCell(cell) {
        if (!cell) {
            return false;
        }
        const table = cell.closest ? cell.closest('table') : null;
        if (!table) {
            return false;
        }
        const rowCells = getRowCells(cell);
        const col = rowCells.indexOf(cell);
        if (col < 0) {
            return false;
        }
        const rows = getDirectTableRows(table);
        const headerRow = rows.find(function (row) {
            return isHeaderLikeRow(row);
        }) || rows[0];
        if (!headerRow) {
            return false;
        }
        const headers = getRowCells(headerRow);
        if (col >= headers.length) {
            return false;
        }
        const headerText = normalizeEditText(headers[col].innerText || headers[col].textContent || '');
        if (/^(sr|s)\.?\s*no\.?$/i.test(headerText)
            || /^s\.?\s*no/i.test(headerText)
            || /^annex(ure)?\s*no/i.test(headerText)
            || /^sl\.?\s*no/i.test(headerText)) {
            return true;
        }
        // Page 2 of the training grid has no "S. No." heading. Column 0 is still the serial.
        if (col !== 0 || table.getAttribute('data-neq-page-split') !== '1' || typeof tableParts !== 'function') {
            return false;
        }
        const head = tableParts(table).find(function (part) {
            return part.getAttribute('data-neq-page-split') !== '1';
        });
        if (!head) {
            return false;
        }
        const headRows = getDirectTableRows(head);
        const headRow = headRows.find(function (row) {
            return isHeaderLikeRow(row);
        }) || headRows[0];
        const headCell = headRow ? getRowCells(headRow)[0] : null;
        const headText = normalizeEditText(headCell ? (headCell.innerText || headCell.textContent || '') : '');
        return /^(?:sr|s|sl)\.?\s*no\b/i.test(headText);
    }

    function isDataTableValueColumnCell(cell) {
        if (!cell) {
            return false;
        }
        if (isSerialOrAnnexNoColumnCell(cell)) {
            return true;
        }
        const header = getColumnHeaderTextForCell(cell);
        if (!header) {
            return false;
        }
        return /annex(ure)?\s*no|attachment\s*(no|type)|instrument|location|function|class\b|contact\s*surface|material|ref\s*no|s\.?\s*no/i.test(header)
            || /requirements?|urs|dq|ioq|remark|deviation|corrective|mitig|rpn|status|compliance|risk|evaluation|revised|change|revision/i.test(header)
            || /title\s*of\s*sop|sop\s*(no|number|title)|effective\s*date|performed\s*by|verified\s*by|checked\s*by|approved\s*by|changes?\s*required/i.test(header)
            || /parts?\s*name|acceptance\s*criteria|actual\s*moc|certificate\s*no|sign\s*and\s*date/i.test(header);
    }

    // Annex-13 / comparison / risk / comments matrix cells — always save via cell_edits.
    function isAnnexFormMatrixCell(cell) {
        if (!cell || isToolCell(cell) || isInsideOfficialLogoCell(cell)) {
            return false;
        }
        const table = cell.closest ? cell.closest('table') : null;
        if (!table) {
            return false;
        }
        if (!isUnlockableFormTable(table) && !isInsideUnlockableFormTable(cell) && !isMetadataFieldTable(table)) {
            return false;
        }
        if (isHeaderLikeRow(cell.parentElement)) {
            return false;
        }
        if (isNeqApprovalLockCell(cell)) {
            return false;
        }
        return true;
    }

    function isTwoColLabelValueFormCell(cell) {
        if (!cell) {
            return false;
        }
        const rowCells = getRowCells(cell);
        if (rowCells.length !== 2) {
            return isLetterheadCell(cell) || isUnlockableFormTable(cell.closest ? cell.closest('table') : null);
        }
        const col = rowCells.indexOf(cell);
        if (col !== 0) {
            return false;
        }
        // Multi-column data tables (Annexure / Instruments) must never use label→next redirect.
        if (isDataTableValueColumnCell(cell) || isDataTableValueColumnCell(rowCells[1])) {
            return false;
        }
        return looksLikeFieldLabel(rowCells[0].innerText || rowCells[0].textContent || '');
    }

    // Only letterhead / 2-col label-value blanks may claim Word empty indexes.
    // Data tables (instruments, annexure, etc.) use cell_edits only — claiming
    // empties there was sending typed values into S. No. / wrong paragraphs.
    function shouldBindEmptyCellIndex(cell) {
        if (!cell || isToolCell(cell) || isInsideTocRegion(cell) || isInsideOfficialLogoCell(cell)) {
            return false;
        }
        if (isHeaderLikeRow(cell.parentElement)) {
            return false;
        }
        if (isSerialOrAnnexNoColumnCell(cell) || isDataTableValueColumnCell(cell) || isYesNaApplicableColumnCell(cell)) {
            return false;
        }
        const zone = getNodeZone(cell);
        if (zone === 'header' || zone === 'footer' || isLetterheadCell(cell)) {
            const rowCells = getRowCells(cell);
            const col = rowCells.indexOf(cell);
            return col > 0 || rowCells.length === 1;
        }
        const rowCells = getRowCells(cell);
        if (rowCells.length === 2) {
            const col = rowCells.indexOf(cell);
            return col > 0 && !isDataTableValueColumnCell(cell);
        }
        return false;
    }

    function getColumnHeaderTextForCell(cell) {
        if (!cell) {
            return '';
        }
        const table = cell.closest ? cell.closest('table') : null;
        if (!table) {
            return '';
        }
        const rowCells = getRowCells(cell);
        const col = rowCells.indexOf(cell);
        if (col < 0) {
            return '';
        }
        const rows = getDirectTableRows(table);
        const headerRow = rows.find(function (row) {
            return isHeaderLikeRow(row);
        }) || rows[0];
        if (!headerRow) {
            return '';
        }
        const headers = getRowCells(headerRow);
        if (col >= headers.length) {
            return '';
        }
        return normalizeEditText(headers[col].innerText || headers[col].textContent || '');
    }

    function rowIsAttachmentListRow(row) {
        const cells = getRowCells(row);
        if (cells.length < 3) {
            return false;
        }
        let annex = false;
        let attachment = false;
        cells.forEach(function (cell) {
            const text = normalizeEditText(cell.innerText || cell.textContent || '');
            if (/annex(?:[_-][a-z0-9]+)+/i.test(text)) {
                annex = true;
            }
            if (/ioq\s*attachment\s*\d+/i.test(text)) {
                attachment = true;
            }
        });
        return annex && attachment;
    }

    function isAttachmentListTable(table) {
        if (!table) {
            return false;
        }
        const rows = getDirectTableRows(table);
        if (rows.some(rowIsAttachmentListRow)) {
            return true;
        }
        if (!rows.length) {
            return false;
        }
        const header = getRowCells(rows[0]).map(function (cell) {
            return normalizeEditText(cell.innerText || cell.textContent || '').toLowerCase();
        }).join(' ');
        return /annex/.test(header) && /attachment/.test(header) && /applicable/.test(header);
    }

    function attachmentRowSaveAnchor(row) {
        const cells = getRowCells(row);
        for (let i = 0; i < cells.length; i++) {
            const original = cells[i].getAttribute ? (cells[i].getAttribute('data-neq-original') || '') : '';
            const text = normalizeEditText(original) || normalizeEditText(cells[i].innerText || cells[i].textContent || '');
            const key = (String(text).match(/annex(?:[_-][a-z0-9]+)+/i) || [''])[0].toLowerCase();
            if (key) {
                return key;
            }
        }
        return '';
    }

    function isContactSurfaceTable(table) {
        if (!table) {
            return false;
        }
        const blob = normalizeEditText(table.innerText || table.textContent || '').toLowerCase();
        return /contact\s*surface/.test(blob) && /material\s*of\s*construction/.test(blob);
    }

    function isYesNaApplicableColumnCell(cell) {
        if (!cell) {
            return false;
        }
        // Deviation Acceptable/Not Acceptable is free text (+ optional box) — do NOT
        // treat it as Yes/NA checkbox host (that polluted headers / indexes).
        // A nested layout table must not use its own text ("NAvijay1") as the header.
        let table = cell.closest ? cell.closest('table') : null;
        while (table && table.parentElement && table.parentElement.closest('td, th')) {
            const outer = table.parentElement.closest('table');
            if (!outer || outer === table) {
                break;
            }
            table = outer;
        }
        if (table && (isCommentsOrDeviationsTable(table) || isAttachmentListTable(table) || isContactSurfaceTable(table) || isMocPartsMatrixTable(table))) {
            return false;
        }
        const header = getColumnHeaderTextForCell(cell);
        if (!header || header.length > 48) {
            return false;
        }
        // Typed values such as NAvijay1 / vijasy2NA are not a Yes/NA column.
        return isApplicableChoiceHeader(header)
            && !/acceptable/i.test(header)
            && !/annex(ure)?\s*no|attachment\s*no|instrument|location|function|contact\s*surface|material\s*of\s*construction|certificate/i.test(header);
    }

    function isApplicableChoiceHeader(text) {
        const header = normalizeEditText(text).toLowerCase();
        if (!header || /annex|attachment\s*no/.test(header)) {
            return false;
        }
        return /\bna\b|n\/a|not\s*applicable|yes\s*if\s*applicable|\bapplicable\b/.test(header);
    }

    function markApplicableColumnHosts(scope) {
        if (!scope) {
            return;
        }
        scope.querySelectorAll('table').forEach(function (table) {
            if (table.parentElement && table.parentElement.closest('td, th')) {
                return;
            }
            // 17.0 attachments stay plain text. Do not mark the NA column as a checkbox.
            if (isAttachmentListTable(table)) {
                return;
            }
            const rows = getDirectTableRows(table);
            if (rows.length < 2) {
                return;
            }
            const headerCells = getRowCells(rows[0]);
            let col = -1;
            headerCells.forEach(function (cell, index) {
                if (isApplicableChoiceHeader(cell.innerText || cell.textContent || '')) {
                    col = index;
                }
            });
            if (col < 0) {
                return;
            }
            rows.slice(1).forEach(function (row) {
                if (isHeaderLikeRow(row)) {
                    return;
                }
                const cell = getRowCells(row)[col];
                if (!cell) {
                    return;
                }
                cell.setAttribute('data-neq-applicable-col', '1');
            });
        });
        // Attachment NA checkboxes are paused. The continued rows stay text too.
        /*
        scope.querySelectorAll('tr').forEach(function (row) {
            if (row.closest && row.closest('header, footer, .docx_header, .docx_footer')) {
                return;
            }
            const cells = getRowCells(row);
            if (cells.length < 3) {
                return;
            }
            let annex = -1;
            let attachment = -1;
            cells.forEach(function (cell, index) {
                const text = normalizeEditText(cell.innerText || cell.textContent || '');
                if (annex < 0 && /^annex[_-]/i.test(text)) {
                    annex = index;
                }
                if (attachment < 0 && /ioq\s*attachment\s*\d+/i.test(text)) {
                    attachment = index;
                }
            });
            if (annex < 0 || attachment < 0 || annex === attachment) {
                return;
            }
            for (let i = 0; i < cells.length; i++) {
                if (i !== annex && i !== attachment) {
                    cells[i].setAttribute('data-neq-applicable-col', '1');
                    break;
                }
            }
        });
        */
    }

    function matchSerialNumberStyle(table) {
        if (!table) {
            return;
        }
        const rows = getDirectTableRows(table);
        if (!rows.length) {
            return;
        }
        const headerCell = Array.from(rows[0].children).find(function (cell) {
            const tag = (cell.tagName || '').toLowerCase();
            return (tag === 'td' || tag === 'th') && !isToolCell(cell);
        });
        const header = normalizeCompareText(headerCell ? (headerCell.innerText || headerCell.textContent || '') : '');
        if (!/^(?:sr|s|sl)\.?\s*no\b/i.test(header)) {
            return;
        }
        rows.forEach(function (row) {
            const cell = Array.from(row.children).find(function (node) {
                const tag = (node.tagName || '').toLowerCase();
                return (tag === 'td' || tag === 'th') && !isToolCell(node);
            });
            if (!cell) {
                return;
            }
            const text = normalizeCompareText(cell.innerText || cell.textContent || '');
            if (!/^\d+(?:\.\d+)*\.?$/.test(text)) {
                return;
            }
            // Center the number only. Do not change its font size or bold.
            cell.style.setProperty('text-align', 'center', 'important');
            cell.style.removeProperty('font-weight');
            cell.style.removeProperty('font-size');
            cell.querySelectorAll('p, span').forEach(function (el) {
                el.style.setProperty('text-align', 'center', 'important');
                el.style.removeProperty('font-weight');
                el.style.removeProperty('font-size');
            });
            // "Machine Performance Check." stays on the same left edge as the other titles.
            const title = Array.from(row.children).filter(function (node) {
                const tag = (node.tagName || '').toLowerCase();
                return (tag === 'td' || tag === 'th') && !isToolCell(node);
            })[1];
            if (!title) {
                return;
            }
            const align = window.getComputedStyle(title).textAlign;
            if (align === 'justify') {
                title.style.setProperty('text-align', 'left', 'important');
                title.querySelectorAll('p').forEach(function (el) {
                    el.style.setProperty('text-align', 'left', 'important');
                });
            }
        });
    }

    function stampNeqTables() {
        let tableIndex = 0;
        getMappablePreviewTables().forEach(function (table) {
            // A table continued on the next preview page keeps the original Word index.
            if (table.getAttribute('data-neq-page-split') !== '1') {
                table.setAttribute('data-neq-table', String(tableIndex));
                tableIndex += 1;
            }
            if (isIoqCheckMatrixTable(table) || isSopDataMatrixTable(table) || isSaveableDataMatrixTable(table)
                || isComparisonTable(table) || isCommentsOrDeviationsTable(table)) {
                table.classList.add('neq-check-matrix');
                normalizeCheckMatrixCellAlignment(table);
            }
            getDirectTableRows(table).forEach(function (row, rowIndex) {
                if (row.getAttribute('data-neq-repeat-header') === '1') {
                    row.removeAttribute('data-neq-row');
                    return;
                }
                if (row.getAttribute('data-neq-new-row') === '1') {
                    row.removeAttribute('data-neq-row');
                } else if (!row.hasAttribute('data-neq-row')) {
                    // Keep the original Word absolute index for the session.
                    // Re-stamping after Add-row inflated after_row and misplaced inserts.
                    row.setAttribute('data-neq-row', String(rowIndex));
                }
                let col = 0;
                Array.from(row.children).forEach(function (cell) {
                    const tag = (cell.tagName || '').toLowerCase();
                    if ((tag !== 'td' && tag !== 'th') || isToolCell(cell)) {
                        return;
                    }
                    cell.setAttribute('data-neq-col', String(col));
                    col += 1;
                });
            });
            dropAutoSerialNumbers(table);
            matchSerialNumberStyle(table);
            // Every imported table: wrap inside the original columns.
            wrapTableCellText(table);
            // Body only: first line of every cell starts at the top (view and edit).
            if (getNodeZone(table) === 'body') {
                table.querySelectorAll('td, th').forEach(function (cell) {
                    if (cell.closest('table') !== table) {
                        return;
                    }
                    cell.style.setProperty('vertical-align', 'top', 'important');
                });
            }
        });
        // After cell cleanup, put the Word row height back on every imported table.
        holdImportedRowHeights(getEditScopeRoot() || document);
    }

    function stampCellOriginals() {
        stampNeqTables();
        getMappablePreviewTables().forEach(function (table) {
            getDirectTableRows(table).forEach(function (row) {
                Array.from(row.children).forEach(function (cell) {
                    const tag = (cell.tagName || '').toLowerCase();
                    if ((tag !== 'td' && tag !== 'th') || isToolCell(cell) || isInsideOfficialLogoCell(cell)) {
                        return;
                    }
                    // Still stamp originals when docx-preview nests a layout table inside the cell.
                    if (!cell.hasAttribute('data-neq-original')) {
                        cell.setAttribute('data-neq-original', nodeEditText(cell));
                    }
                });
            });
        });
        // Nested identity / metadata tables (docx-preview) are excluded from mappable
        // indexes — still stamp their cells so label-based save can detect edits.
        stampNestedIdentityOriginals();
        stampNestedDataMatrixOriginals();
    }

    function stampNestedDataMatrixOriginals() {
        const scope = getEditScopeRoot();
        if (!scope) {
            return;
        }
        scope.querySelectorAll('table').forEach(function (table) {
            const isData = isSopDataMatrixTable(table)
                || !!findDataMatrixHeaderInfo(table)
                || isSaveableDataMatrixTable(table);
            if (!isData) {
                return;
            }
            getDirectTableRows(table).forEach(function (row) {
                Array.from(row.children).forEach(function (cell) {
                    const tag = (cell.tagName || '').toLowerCase();
                    if ((tag !== 'td' && tag !== 'th') || isToolCell(cell) || isInsideOfficialLogoCell(cell)) {
                        return;
                    }
                    if (!cell.hasAttribute('data-neq-original')) {
                        cell.setAttribute('data-neq-original', nodeEditText(cell));
                    }
                    cell.querySelectorAll('[contenteditable="true"]').forEach(function (block) {
                        if (!block.hasAttribute('data-neq-original')) {
                            block.setAttribute('data-neq-original', nodeEditText(block));
                        }
                    });
                    // Inner layout tables inside SOP cells — stamp leaf td/th too.
                    cell.querySelectorAll('td, th').forEach(function (inner) {
                        if (inner === cell || inner.querySelector('table') || isToolCell(inner)) {
                            return;
                        }
                        if (!inner.hasAttribute('data-neq-original')) {
                            inner.setAttribute('data-neq-original', nodeEditText(inner));
                        }
                    });
                });
            });
        });
    }

    function stampNestedIdentityOriginals() {
        const scope = getEditScopeRoot();
        if (!scope) {
            return;
        }
        scope.querySelectorAll('table').forEach(function (table) {
            if (!isMetadataFieldTable(table) && !isPreviewIdentityTable(table)) {
                return;
            }
            getDirectTableRows(table).forEach(function (row) {
                Array.from(row.children).forEach(function (cell) {
                    const tag = (cell.tagName || '').toLowerCase();
                    if ((tag !== 'td' && tag !== 'th') || isToolCell(cell) || isInsideOfficialLogoCell(cell)) {
                        return;
                    }
                    if (!cell.hasAttribute('data-neq-original')) {
                        cell.setAttribute('data-neq-original', nodeEditText(cell));
                    }
                    // Inner contenteditable paragraphs used for typing.
                    cell.querySelectorAll('[contenteditable="true"]').forEach(function (block) {
                        if (!block.hasAttribute('data-neq-original')) {
                            block.setAttribute('data-neq-original', nodeEditText(block));
                        }
                    });
                });
            });
        });
    }

    function identityValueCellLiveText(valueCell) {
        if (!valueCell) {
            return '';
        }
        // Prefer the focused/editable inner block when docx-preview nests wrappers.
        const editable = valueCell.querySelector('[contenteditable="true"]');
        if (editable) {
            return nodeEditText(editable);
        }
        return nodeEditText(valueCell);
    }

    function identityValueCellOriginal(valueCell) {
        if (!valueCell) {
            return '';
        }
        if (valueCell.hasAttribute('data-neq-original')) {
            return valueCell.getAttribute('data-neq-original') || '';
        }
        const editable = valueCell.querySelector('[contenteditable="true"][data-neq-original]');
        if (editable) {
            return editable.getAttribute('data-neq-original') || '';
        }
        const anyOrig = valueCell.querySelector('[data-neq-original]');
        return anyOrig ? (anyOrig.getAttribute('data-neq-original') || '') : '';
    }

    function isIdentityFormLabelText(text) {
        const t = normalizeEditText(text);
        if (!t) {
            return false;
        }
        // Training / matrix column titles must never be treated as letterhead identity labels
        // (that turned "Topic" → "dfgdTopic" into match_by_label saves).
        if ((/topic/i.test(t) && t.length < 48) || /applicability/i.test(t) || /^as\s*per\s*document/i.test(t)) {
            return false;
        }
        return isShortIdentityLabel(t)
            || isKnownLabelText(t)
            || looksLikeFieldLabel(t)
            || /^reference\s*document/i.test(t)
            || /^equipment\b/i.test(t)
            || /^facilit(y)?\s*\/?\s*section/i.test(t);
    }

    function restoreDroppedEquipmentLetter(text) {
        const value = normalizeCompareText(text || '');
        if (/^quipment\b/i.test(value)) {
            return 'E' + value;
        }
        return value;
    }

    function stripIdentityLabelPrefix(labelText, valueText) {
        const label = normalizeIdentitySaveLabel(labelText || '');
        let value = normalizeCompareText(valueText || '');
        if (!label || !value) {
            return value;
        }
        const lowerLabel = label.toLowerCase();
        const lowerValue = value.toLowerCase();
        if (lowerValue === lowerLabel) {
            return '';
        }
        if (lowerValue.startsWith(lowerLabel)) {
            const rest = value.slice(label.length).replace(/^[\s/:\-–—]+/, '').trim();
            if (rest && normalizeIdentitySaveLabel(rest) !== label) {
                return rest;
            }
        }
        // Keep phrases the user typed, such as "test equipment system name".
        return value;
    }

    function normalizeIdentitySaveLabel(label) {
        const t = normalizeCompareText(label || '');
        if (!t) {
            return '';
        }
        const lower = t.toLowerCase();
        if (/equipment/.test(lower) && /system\s*name/.test(lower)) {
            return 'Equipment/ system name';
        }
        if (/equipment/.test(lower) && /system\s*id/.test(lower)) {
            return 'Equipment/ system ID No.';
        }
        // Attachment-6 style: "EQUIPMENT NAME" / "EQUIPMENT ID."
        if (/^equipment\s*name\b/.test(lower)) {
            return 'Equipment Name';
        }
        if (/^equipment\s*id\.?\b/.test(lower)) {
            return 'Equipment ID';
        }
        if (/reference\s*document/.test(lower) || /^ref\.?\s*document/.test(lower)) {
            return /version/.test(lower)
                ? 'Reference Document No. (Version No.)'
                : 'Ref. Document No.';
        }
        if (/facilit/.test(lower) && /sect/.test(lower)) {
            return 'Facility / Section';
        }
        if (/^document\s*title\b/.test(lower)) {
            return 'Document Title';
        }
        if (/^document\s*no\b/.test(lower) || /^document\s*number\b/.test(lower)) {
            return /version/.test(lower) ? 'Document No. (Version No.)' : 'Document No.';
        }
        if (/^effective\s*date\b/.test(lower)) {
            return 'Effective Date';
        }
        if (/^version\b/.test(lower)) {
            return 'VERSION';
        }
        return t;
    }

    // Checklist headers / Equipment ID mistaken as Document No. value.
    function isJunkDocumentNumberValue(text) {
        const t = normalizeCompareText(text || '');
        if (!t) {
            return true;
        }
        const lower = t.toLowerCase();
        if (/^(check|method|acceptance\s*criteria|observation\s*\/?\s*result|sign\s+and\s+date|version|n\/?a)$/i.test(lower)) {
            return true;
        }
        // PR-WBL-048, PR-WBL-048 (1), PR11-WBL-048 — Equipment ID, not Document No.
        if (/^pr\d*-[a-z0-9]+-\d+(?:\s*\(\d+\))?$/i.test(t)) {
            return true;
        }
        if (/^pr\d*-[a-z0-9]+-\d+/i.test(t) && !/\b(qds|annex|sop|ioq|val)[-_]?\d/i.test(t)) {
            return true;
        }
        return false;
    }

    // Identity header fields often live in nested preview tables that are not
    // in getMappablePreviewTables — save them by label so Python can find the Word cell.
    function collectIdentityLabelCellEdits() {
        const scope = getEditScopeRoot();
        if (!scope) {
            return [];
        }
        const ops = [];
        const seenLabels = new Set();

        function resolveIdentityValueCell(cell) {
            if (!cell || isToolCell(cell)) {
                return null;
            }
            if (!cell.querySelector('table')) {
                return cell;
            }
            // docx-preview nests layout tables inside letterhead value cells — use leaf cell.
            const resolved = resolveDataMatrixEditableCell(cell);
            return resolved || cell;
        }

        function pushIdentityOp(labelText, valueCell, textOverride) {
            labelText = normalizeIdentitySaveLabel(labelText);
            if (!labelText) {
                return;
            }
            valueCell = resolveIdentityValueCell(valueCell);
            if (!valueCell) {
                return;
            }
            // Still refuse deep nested grids (multi-cell matrices), but allow single leaf.
            if (valueCell.querySelector('table') && getRowCells(valueCell.querySelector('tr') || valueCell).length > 2) {
                return;
            }
            if (!isIdentityFormLabelText(labelText) && !isShortIdentityLabel(labelText)
                && !/^reference\s*document|^equipment\b|^facilit|^version\b/i.test(labelText)) {
                return;
            }
            const valueLiveRaw = textOverride != null ? normalizeCompareText(textOverride) : cellLiveText(valueCell);
            const valueLive = stripIdentityLabelPrefix(labelText, valueLiveRaw);
            // Never treat a neighbouring identity label (Equipment/system ID No.) as the value.
            if (isShortIdentityLabel(valueLiveRaw) || isIdentityFormLabelText(valueLiveRaw)) {
                const liveCanon = normalizeIdentitySaveLabel(valueLiveRaw);
                if (liveCanon && liveCanon !== labelText) {
                    return;
                }
            }
            if (isShortIdentityLabel(valueLive) && !isIdentityLabelContinuation(valueLive)
                && !isIdentityPlaceholderValue(valueLive) && !cellHasUserEdit(valueCell)
                && textOverride == null) {
                return;
            }
            let original = cellOriginalText(valueCell);
            if (isShortIdentityLabel(original) && normalizeIdentitySaveLabel(original) === labelText) {
                original = '';
            }
            const userEdited = cellHasUserEdit(valueCell) || textOverride != null;
            const liveRaw = textOverride != null
                ? normalizeCompareText(String(textOverride))
                : normalizeCompareText(valueLiveRaw);
            let typed = restoreDroppedEquipmentLetter(liveRaw);
            function isOfficialLabelOnly(value) {
                return /^equipment\s*\/\s*system\s+(name|id)\b/i.test(value)
                    || /^document\s*title$/i.test(value)
                    || /^reference\s*document\s*no\b/i.test(value)
                    || /^facilit(?:y)?\s*\/\s*section$/i.test(value);
            }
            // Typed into the label cell: "Reference Document No. (Version No.) PR-01".
            if (typed && (isOfficialLabelOnly(typed) || isIdentityFormLabelText(typed))) {
                const surplus = stripIdentityLabelPrefix(labelText, typed);
                if (surplus
                    && normalizeCompareText(surplus).toLowerCase() !== normalizeCompareText(typed).toLowerCase()
                    && !isIdentityFormLabelText(surplus)
                    && !isShortIdentityLabel(surplus)
                    && !isIdentityPlaceholderValue(surplus)) {
                    typed = surplus;
                }
            }
            const officialLabelOnly = isOfficialLabelOnly(typed);
            if (typed && !officialLabelOnly) {
                seenLabels.add(normalizeCompareText(labelText).toLowerCase());
                ops.push({
                    table: 0,
                    row: 0,
                    col: 0,
                    text: typed,
                    original_text: original || '',
                    zone: getNodeZone(valueCell) || 'body',
                    match_by_label: true,
                    label: labelText,
                    user_edited: userEdited ? 1 : 0,
                    allow_clear: 0,
                });
                return;
            }
            let text = cleanIdentityTypedValue(valueLive, original, labelText);
            text = stripIdentityLabelPrefix(labelText, text);
            if (userEdited && normalizeCompareText(liveRaw) === '' && normalizeCompareText(original || '') !== '') {
                text = '';
            }
            if (!userEdited && normalizeCompareText(liveRaw) === normalizeCompareText(original || '')) {
                // Still persist Ref. Document No. / Document No. so the list column can show QDS-….
                const isDocIdentity = /(?:^|\b)(?:ref\.?\s*)?document\s*no/i.test(labelText)
                    || /reference\s*document/i.test(labelText);
                if (!isDocIdentity) {
                    return;
                }
            }
            if (text === '' && normalizeCompareText(original || '') === '') {
                return;
            }
            if (text === '' && isIdentityPlaceholderValue(liveRaw) && !userEdited) {
                return;
            }
            if (isIdentityPlaceholderValue(text) && text !== '') {
                return;
            }
            if (!text && userEdited && liveRaw && !isIdentityPlaceholderValue(liveRaw)) {
                text = normalizeCompareText(liveRaw);
            }
            if (!text && !(userEdited && normalizeCompareText(liveRaw) === '')) {
                return;
            }
            // Never send another field's label as the typed value.
            if (isShortIdentityLabel(text) && normalizeIdentitySaveLabel(text) !== labelText) {
                return;
            }
            // Document No. / Ref. Document No. must never capture checklist "Check" or Equipment ID PR-WBL-….
            if (/(?:^|\b)(?:ref\.?\s*)?document\s*no/i.test(labelText) && text && isJunkDocumentNumberValue(text)) {
                return;
            }
            const labelKey = normalizeCompareText(labelText).toLowerCase();
            if (seenLabels.has(labelKey)) {
                return;
            }
            seenLabels.add(labelKey);
            ops.push({
                table: 0,
                row: 0,
                col: 0,
                text: text,
                original_text: original || '',
                zone: getNodeZone(valueCell) || 'body',
                match_by_label: true,
                label: labelText,
                user_edited: userEdited ? 1 : 0,
                allow_clear: (text === '' && normalizeCompareText(original || '') !== '') ? 1 : 0,
            });
        }

        scope.querySelectorAll('table').forEach(function (table) {
            // Combined Attachment-11 tables contain BOTH identity + SOP — still collect identity rows.
            // Only skip pure SOP-only grids (no reference/equipment/facility labels).
            if (isIoqCheckMatrixTable(table)) {
                return;
            }
            const hasIdentityRows = getDirectTableRows(table).some(rowIsIdentityFieldRow);
            if (isSopDataMatrixTable(table) && !isMetadataFieldTable(table) && !isPreviewIdentityTable(table)
                && !isLetterheadIdentityTable(table) && !hasIdentityRows) {
                return;
            }
            if (!isMetadataFieldTable(table) && !isPreviewIdentityTable(table) && !isLetterheadIdentityTable(table)
                && !hasIdentityRows) {
                return;
            }
            // Never harvest Topic / Applicability grids as identity label→value pairs.
            const identityBlob = normalizeEditText(table.innerText || table.textContent || '').toLowerCase();
            if (/topic/.test(identityBlob) && /applicability|as\s*per\s*document/.test(identityBlob)) {
                return;
            }
            if (isApprovalOrSignatoryTable(table) || isCommentsOrDeviationsTable(table)) {
                return;
            }
            const sopHeader = findDataMatrixHeaderInfo(table);
            getDirectTableRows(table).forEach(function (row, absIndex) {
                // Do not treat SOP data/header rows as identity label→value pairs.
                if (sopHeader && absIndex >= sopHeader.headerIndex && !rowIsIdentityFieldRow(row)) {
                    return;
                }
                const cells = getRowCells(row);
                if (cells.length < 2) {
                    // Vertical VERSION / Document Title stacks: label row then value row.
                    if (cells.length === 1) {
                        const labelText = nodeEditText(cells[0]);
                        const labelCanon = normalizeIdentitySaveLabel(labelText);
                        if (labelCanon && (isShortIdentityLabel(labelText) || isIdentityFormLabelText(labelText))) {
                            const nextRow = getDirectTableRows(table)[absIndex + 1];
                            if (nextRow) {
                                const nextCells = getRowCells(nextRow);
                                if (nextCells.length === 1 && !isShortIdentityLabel(nodeEditText(nextCells[0]))) {
                                    pushIdentityOp(labelText, nextCells[0], null);
                                }
                            }
                        }
                    }
                    return;
                }

                // Document No. | VERSION (label row) then values on the next row —
                // common Attachment letterhead; horizontal pairing would skip both.
                // NEVER pair Equipment/system name | Equipment/system ID No. onto the
                // Facility row (next row still has labels) — that deleted ID labels.
                const labelLikeCount = cells.filter(function (c) {
                    const t = nodeEditText(c);
                    return isShortIdentityLabel(t) || isIdentityFormLabelText(t);
                }).length;
                if (labelLikeCount === cells.length) {
                    const nextRow = getDirectTableRows(table)[absIndex + 1];
                    if (nextRow) {
                        const nextCells = getRowCells(nextRow);
                        if (nextCells.length === cells.length) {
                            const nextLabelLike = nextCells.filter(function (c) {
                                const t = nodeEditText(c);
                                return isShortIdentityLabel(t) || isIdentityFormLabelText(t);
                            }).length;
                            // Never treat checklist header row (Check | Method | …) as Document No. values.
                            const nextHeaderBlob = nextCells.map(function (c) {
                                return normalizeEditText(c.innerText || c.textContent || '');
                            }).join(' ').toLowerCase();
                            const looksLikeCheckGrid = /\bcheck\b/.test(nextHeaderBlob)
                                && /\bmethod\b/.test(nextHeaderBlob);
                            if (nextLabelLike === 0 && !looksLikeCheckGrid) {
                                let paired = 0;
                                for (let i = 0; i < cells.length; i++) {
                                    const lt = nodeEditText(cells[i]);
                                    const vt = nodeEditText(nextCells[i]);
                                    if (isShortIdentityLabel(vt) || isIdentityFormLabelText(vt)) {
                                        continue;
                                    }
                                    if (isJunkDocumentNumberValue(vt)) {
                                        continue;
                                    }
                                    pushIdentityOp(lt, nextCells[i], null);
                                    paired += 1;
                                }
                                if (paired > 0) {
                                    return;
                                }
                            }
                        }
                    }
                    // Label|label row (Equipment name | Equipment ID No.): only recover
                    // values typed into the label cell itself — never use adjacent label as value.
                    for (let i = 0; i < cells.length; i++) {
                        const labelCell = cells[i];
                        const labelText = nodeEditText(labelCell);
                        const labelOriginal = cellOriginalText(labelCell) || labelText;
                        if (cellHasUserEdit(labelCell)
                            && isIdentityFormLabelText(labelOriginal)
                            && normalizeCompareText(labelText) !== normalizeCompareText(labelOriginal)) {
                            const surplus = stripIdentityLabelPrefix(labelOriginal, labelText);
                            if (surplus && !isIdentityFormLabelText(surplus) && !isShortIdentityLabel(surplus)
                                && normalizeCompareText(surplus).toLowerCase() !== normalizeCompareText(labelOriginal).toLowerCase()) {
                                pushIdentityOp(labelOriginal, labelCell, surplus);
                            } else if (!isIdentityFormLabelText(labelText)) {
                                pushIdentityOp(labelOriginal, labelCell, labelText);
                            }
                        }
                    }
                    return;
                }

                for (let i = 0; i < cells.length - 1; i++) {
                    let labelCell = cells[i];
                    let labelText = nodeEditText(labelCell);
                    let valueCell = cells[i + 1];
                    const labelOriginal = cellOriginalText(labelCell) || labelText;

                    // User typed the value into the label cell itself (common when
                    // placeholder and label look the same). Recover from original label.
                    if (cellHasUserEdit(labelCell)
                        && isIdentityFormLabelText(labelOriginal)
                        && normalizeCompareText(labelText) !== normalizeCompareText(labelOriginal)) {
                        const surplus = stripIdentityLabelPrefix(labelOriginal, labelText);
                        if (surplus && !isIdentityFormLabelText(surplus) && !isShortIdentityLabel(surplus)
                            && normalizeCompareText(surplus).toLowerCase() !== normalizeCompareText(labelOriginal).toLowerCase()) {
                            pushIdentityOp(labelOriginal, labelCell, surplus);
                            continue;
                        }
                        if (!isIdentityFormLabelText(labelText)) {
                            pushIdentityOp(labelOriginal, labelCell, labelText);
                            continue;
                        }
                    }

                    if (isIdentityLabelContinuation(labelText)) {
                        continue;
                    }
                    if (isIncompleteEquipmentLabel(labelText)
                        && valueCell
                        && isIdentityLabelContinuation(nodeEditText(valueCell))
                        && i + 2 < cells.length) {
                        labelText = 'Equipment/ system name';
                        valueCell = cells[i + 2];
                    } else if (/^reference\s*document\b|^ref\.?\s*document\b/i.test(labelText)
                        && valueCell
                        && isIdentityLabelContinuation(nodeEditText(valueCell))
                        && i + 2 < cells.length) {
                        labelText = 'Reference Document No. (Version No.)';
                        valueCell = cells[i + 2];
                    } else if (/^equipment\b/i.test(labelText)
                        && valueCell
                        && isIdentityLabelContinuation(nodeEditText(valueCell))
                        && i + 2 < cells.length) {
                        // Only a wrapped label half ("system name") sits in the next cell.
                        // A typed value such as "equipment system name test" stays here.
                        labelText = 'Equipment/ system name';
                        valueCell = cells[i + 2];
                    }

                    // Next cell is a different field's label — value is further right
                    // (or this pair has no editable value in-row).
                    const nextText = nodeEditText(valueCell);
                    const nextCanon = normalizeIdentitySaveLabel(nextText);
                    const labelCanon = normalizeIdentitySaveLabel(labelText);
                    if (nextCanon && labelCanon && nextCanon !== labelCanon
                        && isShortIdentityLabel(nextText) && !isIdentityPlaceholderValue(nextText)) {
                        if (i + 2 < cells.length) {
                            valueCell = cells[i + 2];
                        } else {
                            // e.g. [DOCUMENT No., VERSION] with values on next row — handled above.
                            continue;
                        }
                    }

                    pushIdentityOp(labelText, valueCell, null);
                }
            });
        });

        // Page copies of the letterhead: keep a value typed on any copy.
        // The first header is sometimes still blank while the visible copy has the text.
        scope.querySelectorAll('header table tr, .docx_header table tr').forEach(function (row) {
            const cells = getRowCells(row);
            for (let i = 0; i < cells.length - 1; i++) {
                const labelText = nodeEditText(cells[i]);
                if (!isIdentityFormLabelText(labelText) && !isShortIdentityLabel(labelText)) {
                    continue;
                }
                let valueCell = cells[i + 1];
                const nextText = nodeEditText(valueCell);
                if (valueCell && isIdentityLabelContinuation(nextText) && cells[i + 2]) {
                    valueCell = cells[i + 2];
                }
                const valueText = nodeEditText(valueCell);
                const valueCanon = normalizeIdentitySaveLabel(valueText);
                const labelCanon = normalizeIdentitySaveLabel(labelText);
                // Neighbouring label (Equipment ID beside Equipment name). A typed
                // value such as "Equipment 5" is not one of these labels.
                const knownCanon = valueCanon === 'Equipment/ system name'
                    || valueCanon === 'Equipment/ system ID No.'
                    || valueCanon === 'Equipment Name'
                    || valueCanon === 'Equipment ID'
                    || valueCanon === 'Reference Document No. (Version No.)'
                    || valueCanon === 'Ref. Document No.'
                    || valueCanon === 'Facility / Section'
                    || valueCanon === 'Document Title'
                    || valueCanon === 'Document No.'
                    || valueCanon === 'Document No. (Version No.)'
                    || valueCanon === 'Effective Date'
                    || valueCanon === 'VERSION';
                if (!valueCell || (knownCanon && valueCanon !== labelCanon)) {
                    continue;
                }
                const live = cellLiveText(valueCell) || nodeEditText(valueCell);
                if (!normalizeCompareText(live)) {
                    continue;
                }
                pushIdentityOp(labelText, valueCell, live);
            }
        });
        return ops;
    }

    function clearDirectRowText(row) {
        getRowCells(row).forEach(function (cell) {
            if (isToolCell(cell)) {
                return;
            }
            const blocks = cell.querySelectorAll('p, li, h1, h2, h3, h4, h5, h6');
            const targets = blocks.length ? Array.from(blocks) : [cell];
            targets.forEach(function (node) {
                if (node.querySelector && node.querySelector('table') && node !== cell) {
                    return;
                }
                setNodePlainText(node, '');
                node.removeAttribute('data-neq-index');
                node.removeAttribute('data-neq-locked');
                node.setAttribute('data-neq-original', '');
                node.setAttribute('contenteditable', 'true');
                node.setAttribute('spellcheck', 'false');
                node.removeEventListener('input', onMappedNodeInput);
                node.addEventListener('input', onMappedNodeInput);
            });
            cell.removeAttribute('data-neq-index');
            cell.setAttribute('data-neq-original', '');
        });
    }

    function collectTableOps() {
        const ops = pendingTableDeletes.slice();
        getMappablePreviewTables().forEach(function (table) {
            const tableIndex = Number(table.getAttribute('data-neq-table'));
            if (!Number.isInteger(tableIndex) || tableIndex < 0) {
                return;
            }
            // Only the repeating page heading is blocked. Annexure tables mention
            // "Annexure No." and must still save a newly added row.
            if (table.closest('header, .docx_header, footer, .docx_footer')) {
                return;
            }
            const headerInfo = findDataMatrixHeaderInfo(table);
            let headerFingerprint = (headerInfo && headerInfo.headers && headerInfo.headers.length)
                ? headerInfo.headers.slice()
                : tableHeaderFingerprint(table);
            if ((!headerFingerprint || !headerFingerprint.length) && isComponentSubsystemMatrixTable(table)) {
                headerFingerprint = componentSubsystemMatrixHeaders(table);
            }
            const deletedBefore = function (originalRow) {
                let count = 0;
                pendingTableDeletes.forEach(function (op) {
                    if (op.table === tableIndex && Number(op.row) < originalRow) {
                        count += 1;
                    }
                });
                return count;
            };
            let lastWordIndex = null;
            // A new row on the next page has no original row above it in that
            // fragment. Anchor it to the last real row of the previous piece,
            // so it stays under that section instead of after Safety By.
            if (table.getAttribute('data-neq-page-split') === '1' && typeof tableParts === 'function') {
                const parts = tableParts(table);
                for (let p = 0; p < parts.length && parts[p] !== table; p++) {
                    getDirectTableRows(parts[p]).forEach(function (prevRow) {
                        if (prevRow.getAttribute('data-neq-new-row') === '1' || isHeaderLikeRow(prevRow)) {
                            return;
                        }
                        const originalRow = Number(prevRow.getAttribute('data-neq-row'));
                        if (Number.isInteger(originalRow) && originalRow >= 0) {
                            lastWordIndex = originalRow - deletedBefore(originalRow);
                        }
                    });
                }
            }
            getDirectTableRows(table).forEach(function (row) {
                // A new row can start with "revision test". That is a value,
                // not the Revision No. heading, and it must still be inserted.
                if (row.getAttribute('data-neq-new-row') !== '1' && isHeaderLikeRow(row)) {
                    return;
                }
                if (row.getAttribute('data-neq-new-row') !== '1') {
                    const originalRow = Number(row.getAttribute('data-neq-row'));
                    if (Number.isInteger(originalRow) && originalRow >= 0) {
                        // Stable Word abs index (not DOM index after Add-row).
                        lastWordIndex = originalRow - deletedBefore(originalRow);
                    }
                    return;
                }
                const values = getRowCells(row).map(function (cell) {
                    return cellLiveText(cell)
                        || nodeEditText(cell)
                        || normalizeEditText(cell.innerText || cell.textContent || '');
                });
                const insertOp = {
                    op: 'insert',
                    table: tableIndex,
                    values: values,
                };
                if (headerFingerprint && headerFingerprint.length) {
                    insertOp.headers = headerFingerprint;
                }
                if (lastWordIndex !== null) {
                    insertOp.after_row = lastWordIndex;
                    row.setAttribute('data-neq-insert-after', String(lastWordIndex));
                }
                const filled = values.map(function (value) {
                    return normalizeEditText(value);
                });
                const meaningful = filled.some(function (value, index) {
                    if (!value) {
                        return false;
                    }
                    // A leftover "0" in Revision No. is the template, not a new row.
                    if (isRevisionHistoryTable(table) && index === 0 && /^(?:0+|010|\.)$/.test(value)) {
                        return false;
                    }
                    return true;
                });
                // An empty added row is only on screen. Do not write a blank line into Word.
                if (!meaningful) {
                    return;
                }
                ops.push(insertOp);
            });
        });
        return ops;
    }

    function cellLiveText(cell) {
        if (!cell) {
            return '';
        }
        const edited = cell.querySelector('[data-neq-user-edited="1"]');
        if (edited) {
            const editedText = nodeEditText(edited);
            if (editedText) {
                return editedText;
            }
        }
        if (cell.getAttribute('data-neq-user-edited') === '1' && cell.getAttribute('contenteditable') === 'true') {
            const selfText = nodeEditText(cell);
            if (selfText) {
                return selfText;
            }
        }
        let best = '';
        const blocks = cell.querySelectorAll('[contenteditable="true"]');
        if (blocks.length) {
            blocks.forEach(function (el) {
                // Add Paragraph text is its own paragraph. Do not save it as this cell's value.
                if (el.getAttribute('data-neq-new-para') === '1' || (el.closest && el.closest('[data-neq-new-para="1"]'))) {
                    return;
                }
                const t = nodeEditText(el);
                if (t.length >= best.length) {
                    best = t;
                }
            });
            if (best) {
                return best;
            }
        }
        if (cell.querySelector && cell.querySelector('[data-neq-new-para="1"]')) {
            const host = cell.closest ? cell.closest('table') : null;
            // A sentence typed in the Comments box is the cell value, even when
            // it was added as its own paragraph.
            if (host && isCommentsBoxTable(host)) {
                return normalizeEditText(cell.innerText || cell.textContent || '');
            }
            const clone = cell.cloneNode(true);
            clone.querySelectorAll('[data-neq-new-para="1"]').forEach(function (el) {
                el.remove();
            });
            return nodeEditText(clone);
        }
        return nodeEditText(cell);
    }

    function cellOriginalText(cell) {
        if (!cell) {
            return '';
        }
        const edited = cell.querySelector('[data-neq-user-edited="1"][data-neq-original]');
        if (edited) {
            return edited.getAttribute('data-neq-original') || '';
        }
        if (cell.getAttribute('data-neq-user-edited') === '1' && cell.hasAttribute('data-neq-original')) {
            return cell.getAttribute('data-neq-original') || '';
        }
        if (cell.hasAttribute('data-neq-original')) {
            return cell.getAttribute('data-neq-original') || '';
        }
        const editable = cell.querySelector('[contenteditable="true"][data-neq-original]');
        if (editable) {
            return editable.getAttribute('data-neq-original') || '';
        }
        const anyOrig = cell.querySelector('[data-neq-original]');
        return anyOrig ? (anyOrig.getAttribute('data-neq-original') || '') : '';
    }

    function cellHasUserEdit(cell) {
        return cellHasUserIdentityEdit(cell);
    }

    function cleanIdentityTypedValue(text, original, labelHint) {
        let value = normalizeCompareText(text);
        const orig = normalizeCompareText(original || '');
        value = stripAccidentalEdPollution(value, orig);
        // Never "clean" a real identity label into fragments like "No.".
        if (isShortIdentityLabel(value) || isIdentityFormLabelText(value)) {
            const canon = normalizeIdentitySaveLabel(value);
            const hintCanon = normalizeIdentitySaveLabel(labelHint || '');
            if (canon && canon !== hintCanon) {
                return '';
            }
            if (canon && canon === hintCanon) {
                return '';
            }
        }
        if (labelHint) {
            value = stripIdentityLabelPrefix(labelHint, value);
        }
        if (!value) {
            return '';
        }
        value = value
            .replace(/\bNo\.?\s*\(?\s*Version\s*No\.?\s*\)?/ig, ' ')
            .replace(/\s+/g, ' ')
            .trim();
        // Fragments left after stripping a mis-captured label.
        if (/^(?:no\.?|id\.?|system|equipment\/?)$/i.test(value)) {
            return '';
        }
        if (isIdentityPlaceholderValue(value)) {
            return '';
        }
        return value;
    }

    // docx-preview often nests a layout <table> inside SOP data cells — resolve to the typed cell.
    function resolveDataMatrixEditableCell(cell) {
        if (!cell || isToolCell(cell) || isInsideOfficialLogoCell(cell)) {
            return null;
        }
        if (!cell.querySelector('table')) {
            return cell;
        }
        const edited = cell.querySelector('[data-neq-user-edited="1"]');
        if (edited) {
            const innerCell = edited.closest ? edited.closest('td, th') : null;
            if (innerCell && cell.contains(innerCell) && !innerCell.querySelector('table')) {
                return innerCell;
            }
        }
        const innerCells = Array.from(cell.querySelectorAll('td, th')).filter(function (el) {
            return !el.querySelector('table') && !isToolCell(el);
        });
        if (innerCells.length === 1) {
            return innerCells[0];
        }
        if (innerCells.length) {
            let best = innerCells[0];
            innerCells.forEach(function (el) {
                if (nodeEditText(el).length >= nodeEditText(best).length) {
                    best = el;
                }
            });
            return best;
        }
        // Nested layout with no leaf td — still treat outer cell as editable shell.
        return cell;
    }

    // Walk ancestors: nested layout tables must resolve to the real SOP / verify grid.
    function findOwningSopGridTable(node) {
        if (!node) {
            return null;
        }
        let el = node;
        while (el && el !== document.body) {
            if ((el.tagName || '').toLowerCase() === 'table') {
                if (isSopDataMatrixTable(el) || findDataMatrixHeaderInfo(el) || isSaveableDataMatrixTable(el)) {
                    return el;
                }
                const blob = normalizeEditText(el.innerText || el.textContent || '').toLowerCase();
                if (/list\s*of\s*sop|verification\s*of\s*draft\s*sop/.test(blob)
                    || (/title/.test(blob) && /sop/.test(blob) && /effective|number|no\.?|sr\.?\s*no/.test(blob))
                    || (/performed/.test(blob) && /verified/.test(blob))) {
                    return el;
                }
            }
            el = el.parentElement;
        }
        return null;
    }

    function locateCellInSopGrid(table, node) {
        if (!table || !node) {
            return null;
        }
        const rows = getDirectTableRows(table);
        for (let i = 0; i < rows.length; i++) {
            if (!rows[i].contains(node)) {
                continue;
            }
            const rowCells = getRowCells(rows[i]);
            const outerCell = rowCells.find(function (c) {
                return c === node || c.contains(node);
            });
            if (!outerCell) {
                continue;
            }
            return {
                row: rows[i],
                absIndex: i,
                outerCell: outerCell,
                colIndex: rowCells.indexOf(outerCell),
                rowCells: rowCells,
            };
        }
        return null;
    }

    function sopGridHeadersForTable(table, headerInfo) {
        if (headerInfo && headerInfo.headers && headerInfo.headers[0] === '__neq_component_matrix__') {
            return headerInfo.headers.slice();
        }
        if (isComponentSubsystemMatrixTable(table)) {
            return componentSubsystemMatrixHeaders(table);
        }
        if (headerInfo && headerInfo.headers && headerInfo.headers.length >= 1) {
            return headerInfo.headers;
        }
        const blob = normalizeEditText(table.innerText || table.textContent || '').toLowerCase();
        if (/performed\s*by/.test(blob) && /verified\s*by/.test(blob)) {
            return ['Sr. No.', 'SOP No.', 'Title of SOP', 'Performed by', 'Verified by', 'Changes required'];
        }
        if (/title/.test(blob) && /sop/.test(blob)) {
            return ['Sr. No.', 'Title of SOP', 'SOP Number', 'Effective Date'];
        }
        if (/^comments?\b/.test(blob) || (getDirectTableRows(table).length >= 2
            && /^comments?\b/i.test(columnHeaderMatchText(nodeEditText(getRowCells(getDirectTableRows(table)[0])[0] || '') || '')))) {
            return ['Comments'];
        }
        return tableHeaderFingerprint(table);
    }

    function resolveSopHeaderIndex(table, headerInfo) {
        const rows = getDirectTableRows(table);
        // Attachment 3: no column-header row — keep -1 so data indexing starts at row 0 (banners skipped separately).
        if (headerInfo && Number.isInteger(headerInfo.headerIndex) && headerInfo.headerIndex === -1) {
            return -1;
        }
        if (headerInfo && Number.isInteger(headerInfo.headerIndex) && headerInfo.headerIndex >= 0) {
            return headerInfo.headerIndex;
        }
        if (isComponentSubsystemMatrixTable(table)) {
            return -1;
        }
        let headerIndex = 0;
        for (let hi = 0; hi < Math.min(rows.length, 24); hi++) {
            const joined = getRowCells(rows[hi]).map(function (c) {
                return columnHeaderMatchText(nodeEditText(c) || '');
            }).join(' ').toLowerCase();
            if ((/title/.test(joined) && /sop/.test(joined))
                || (/performed/.test(joined) && /verified/.test(joined))
                || (/(sr|s)\.?\s*no/.test(joined) && /sop|title|date|number|no|so[pg]/.test(joined))
                || (/effective/.test(joined) && /date/.test(joined) && /(sr|s)\.?\s*no/.test(joined))
                || (/acceptance\s*criteria|observation|sign\s*and\s*date/.test(joined) && /check|method/.test(joined))
                || (/deviation/.test(joined) && /corrective|acceptable/.test(joined))
                || (/revision/.test(joined) && /change/.test(joined) && /\bdate\b/.test(joined))
                || /^comments?\b/i.test(joined)) {
                return hi;
            }
        }
        return headerIndex;
    }

    function matrixDataRowCount(table, headerIndex) {
        let count = 0;
        getDirectTableRows(table).forEach(function (row, absIndex) {
            if (row.getAttribute('data-neq-new-row') === '1' || rowIsIdentityFieldRow(row)) {
                return;
            }
            const rowCells = getRowCells(row);
            if (shouldSkipSopGridRow(row, rowCells, headerIndex, absIndex)) {
                return;
            }
            count += 1;
        });
        return count;
    }

    function priorSplitDataRowCount(table) {
        if (!table || table.getAttribute('data-neq-page-split') !== '1') {
            return 0;
        }
        const parts = typeof tableParts === 'function' ? tableParts(table) : [];
        let offset = 0;
        for (let i = 0; i < parts.length; i++) {
            if (parts[i] === table) {
                break;
            }
            const cached = Number(parts[i].getAttribute('data-neq-header-idx'));
            const headerIndex = Number.isInteger(cached) ? cached : -1;
            offset += matrixDataRowCount(parts[i], headerIndex);
        }
        return offset;
    }

    function rowIsInstrumentCalibrationData(row, rowCells) {
        const table = row && row.closest ? row.closest('table') : null;
        if (!table || !rowCells || !rowCells.length) {
            return false;
        }
        const headers = tableHeaderFingerprint(table).join(' ').toLowerCase();
        if (!/instrument/.test(headers) || !/calibration/.test(headers)) {
            return false;
        }
        return rowCells.some(function (cell) {
            const text = normalizeCompareText(cellLiveText(cell) || nodeEditText(cell) || cell.innerText || '');
            if (!text || isColumnHeaderText(text)) {
                return false;
            }
            return !/^(?:instrument\s*description|id\s*code|calibration\s*details|done\s*on|due\s*on|sign\s*and\s*date)\b/i.test(text);
        });
    }

    function shouldSkipSopGridRow(row, rowCells, headerIndex, absIndex) {
        // A continued attachments table has no heading row. Its first line is
        // still a data row (Attachment 6), so it must be saved.
        if (rowIsAttachmentListRow(row)) {
            return false;
        }
        // A filled instrument-calibration row is data, even when it sits on
        // the page boundary. Skipping it shifted the last values downward.
        if (rowIsInstrumentCalibrationData(row, rowCells)) {
            return false;
        }
        if (absIndex <= headerIndex) {
            return true;
        }
        const table = row && row.closest ? row.closest('table') : null;
        const headerBlob = table ? tableHeaderFingerprint(table).join(' ').toLowerCase() : '';
        // Attachment list rows are data. "Identification of utilities" and
        // "Operational / Functional checks" are names, not section banners.
        if (/annex(ure)?\s*no/.test(headerBlob) && /attachment/.test(headerBlob)) {
            return false;
        }
        const rowText = normalizeEditText(row.innerText || row.textContent || '').toLowerCase();
        if (/^(identified|checked|approved|prepared|reviewed)\s*by$/.test(rowText)
            || (/checked\s*by|approved\s*by/.test(rowText) && /signature|name/.test(rowText))) {
            return true;
        }
        if (isComponentSectionBannerText(rowText)) {
            return true;
        }
        // "Verified by sign and date" is its own row. Python skips it when
        // mapping data rows, so the preview must skip it too. Counting it
        // pushed Mechanical / Electrical values onto the wrong Word rows.
        if (isVerifiedByRowText(rowText)) {
            return true;
        }
        if (isSafetyBySignDateRowText(rowText)) {
            return true;
        }
        if (isUtilitiesSectionTitleText(rowText)) {
            return true;
        }
        if (isDataMatrixSectionBannerRow(row, rowCells)) {
            return true;
        }
        const headerTexts = rowCells.map(function (cell) {
            return columnHeaderMatchText(nodeEditText(cell) || cell.innerText || cell.textContent || '');
        });
        // S | D | L | RPN under Revised Risk Analysis is a second header row.
        // A serial plus a sentence ("1. Checks for major components") is a real
        // section row. Skipping only that row shifted every value onto the title.
        if (rowIsShortColumnSubheader(headerTexts)) {
            return true;
        }
        if (rowCells.length === 2 || rowCells.length === 4) {
            const c0 = normalizeEditText(rowCells[0].innerText || '');
            if (/^reference\s*document|^equipment\b|^facilit|^document\s*(title|no)/i.test(c0)) {
                return true;
            }
        }
        if (rowCells.length <= 2 && /list\s*of\s*sop|verification\s*of\s*draft\s*sop/i.test(rowText)) {
            return true;
        }
        return false;
    }

    function stripMatrixHeaderGlue(text, headers, colIndex) {
        let value = normalizeCompareText(text == null ? '' : String(text));
        if (!value) {
            return '';
        }
        const headerList = Array.isArray(headers) ? headers : [];
        const colHeader = normalizeCompareText(
            (colIndex != null && headerList[colIndex] != null) ? headerList[colIndex] : ''
        );
        const allHeaders = headerList.map(function (h) {
            return normalizeCompareText(h);
        }).filter(Boolean);

        // Strip known column-header phrases glued to the typed value.
        // For Comments body, only strip an exact leading header token (not the word "comment").
        const isCommentsCol = colHeader && /^comments?\b/i.test(colHeader);
        const headerPhrases = (isCommentsCol ? [] : allHeaders).concat([
            'Deviation (If Any)',
            'Deviation (if any)',
            'Acceptable/Not Acceptable',
            'Corrective Actions',
        ]);
        headerPhrases.forEach(function (phrase) {
            if (!phrase || !/\s/.test(phrase)) {
                // One-word headers ("test", "result") are real typed values. Do not delete them.
                return;
            }
            const esc = phrase.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/\s+/g, '\\s*');
            value = value.replace(new RegExp(esc, 'ig'), ' ');
        });
        // Generic deviation / corrective chrome (not the word "comment" — body text may say it).
        value = value
            .replace(/\bdeviation\s*\([^)]*\)/ig, ' ')
            .replace(/\bacceptable\s*\/?\s*not\s*acceptable\b/ig, ' ')
            .replace(/\bcorrective\s*actions?\b/ig, ' ')
            .replace(/\s+/g, ' ')
            .trim();
        // Only strip a leading Comments header token, not user words like "add comment".
            if (isCommentsCol) {
                value = value.replace(/^comments\s*:\s*/i, '').trim();
            }
        // A multi-word header copied into the cell is still a label. A one-word header
        // such as "test" or "result" is a value the user typed.
        if (colHeader && /\s/.test(colHeader) && normalizeCompareText(value).toLowerCase() === colHeader.toLowerCase()) {
            return '';
        }
        if (isColumnHeaderText(value) && isKnownLabelText(value)) {
            return '';
        }
        return value.trim();
    }

    function canonicalizeMatrixHeaders(headers, table) {
        if (headers && headers[0] === '__neq_component_matrix__') {
            return headers.slice();
        }
        const blob = normalizeEditText((table && (table.innerText || table.textContent)) || '').toLowerCase();
        const joined = (headers || []).map(function (h) {
            return columnHeaderMatchText(h) || h;
        }).join(' ').toLowerCase();
        if ((/deviation/.test(joined) || /deviation/.test(blob))
            && (/corrective|acceptable/.test(joined) || /corrective|acceptable/.test(blob))) {
            // This document's own titles (Deviation / Corrective Action / Remarks)
            // must stay. Replacing them with another grid's titles wiped the header
            // and left the typed words as lines above the table.
            if (/deviation/.test(joined) && /corrective|remarks?|preventive/.test(joined)) {
                return (headers || []).map(function (h) {
                    return columnHeaderMatchText(h) || h;
                }).filter(Boolean);
            }
            return ['Deviation (If Any)', 'Acceptable/Not Acceptable', 'Corrective Actions'];
        }
        if ((headers && headers.length === 1 && /^comments?\b/i.test(String(headers[0] || '')))
            || /^comments?\b/.test(blob)) {
            return ['Comments'];
        }
        // Training Attachment-12 — restore clean titles even after "dfgdTopic" pollution.
        if ((/topic/.test(joined) || /topic/.test(blob))
            && (/applicability|as\s*per\s*document/.test(joined) || /applicability|as\s*per\s*document/.test(blob))) {
            return ['S. No.', 'Topic', 'As per Document No.', 'Applicability to Department'];
        }
        return (headers || []).map(function (h) {
            return columnHeaderMatchText(h) || h;
        }).filter(Boolean);
    }

    function pushSopGridCellOp(ops, seen, table, headerInfo, headerIndex, dataRowIndex, colIndex, dataCell) {
        const zone = getNodeZone(dataCell) || getNodeZone(table) || 'body';
        if (zone === 'header' || zone === 'footer' || rowIsIdentityFieldRow(dataCell && dataCell.parentElement)) {
            return;
        }
        const dataCellResolved = resolveDataMatrixEditableCell(dataCell);
        if (!dataCellResolved) {
            return;
        }
        const attachmentTextCell = isAttachmentListTable(table);
        if (!attachmentTextCell && (
            dataCell.getAttribute('data-neq-applicable-col') === '1'
            || dataCell.getAttribute('data-neq-checkbox') === '1'
            || dataCell.querySelector('input.neq-checkbox')
            || dataCellResolved.getAttribute('data-neq-checkbox') === '1'
        )) {
            return;
        }
        // Never persist column-header row cells as data fills.
        if (isHeaderLikeRow(dataCell.parentElement) || isHeaderLikeRow(dataCellResolved.parentElement)) {
            return;
        }
        const textRaw = cellLiveText(dataCellResolved);
        let original = cellOriginalText(dataCellResolved);
        const userEdited = cellHasUserEdit(dataCellResolved) || cellHasUserEdit(dataCell);
        // Heading parenthetical "(If any)" is not a deviation the user typed.
        if (isHeaderParentheticalFragment(textRaw) && !normalizeCompareText(original || '')) {
            return;
        }
        if (original === null || original === undefined) {
            original = '';
        }
        if (isCheckboxGlyphOnly(textRaw) || isCheckboxGlyphOnly(original)) {
            return;
        }
        let headers = sopGridHeadersForTable(table, headerInfo);
        if (!headers || headers.length < 1) {
            const blob = normalizeEditText(table.innerText || table.textContent || '').toLowerCase();
            if (/performed/.test(blob) && /verified/.test(blob)) {
                headers = ['Sr. No.', 'SOP No.', 'Title of SOP', 'Performed by', 'Verified by', 'Changes required'];
            } else if (isComponentSubsystemMatrixTable(table)) {
                headers = componentSubsystemMatrixHeaders(table);
            } else if ((/acceptance\s*criteria/.test(blob) || /observation/.test(blob))
                && (/\bcheck\b/.test(blob) || /\bmethod\b/.test(blob))) {
                // Real utilities grids only — do NOT treat "Verified by sign and date" alone as headers
                // (Attachment 3 was synthesizing Check/Method and then failing to find the Word table).
                headers = tableHeaderFingerprint(table);
                if (headers.length < 2) {
                    headers = ['Check', 'Method', 'Acceptance Criteria', 'Observation/ Result', 'Sign and Date'];
                }
            } else if (/deviation/.test(blob) && /corrective|acceptable/.test(blob)) {
                headers = ['Deviation (If Any)', 'Acceptable/Not Acceptable', 'Corrective Actions'];
            } else if (/^comments?\b/.test(blob) || /\bcomments?\s*:?\s*$/m.test(blob)) {
                headers = ['Comments'];
            } else {
                headers = ['Sr. No.', 'Title of SOP', 'SOP Number', 'Effective Date'];
            }
        }
        headers = canonicalizeMatrixHeaders(headers, table);
        if (!headers.length) {
            return;
        }
        const isCommentsOnly = headers.length === 1 && /^comments?\b/i.test(String(headers[0] || ''));
        if (headers.length < 2 && !isCommentsOnly) {
            return;
        }
        const text = stripMatrixHeaderGlue(
            stripAccidentalEdPollution(textRaw, original),
            headers,
            colIndex
        );
        const originalClean = stripMatrixHeaderGlue(
            stripAccidentalEdPollution(original, ''),
            headers,
            colIndex
        );
        // Skip a cell that is only the section title. A value the user typed
        // ("mechanical components check1") must still be saved. The prefix
        // check used to treat that value as the banner and drop it.
        if (isUtilitiesSectionTitleText(text) || isUtilitiesSectionTitleText(originalClean)
            || isExactComponentBannerText(text) || isExactComponentBannerText(originalClean)
            || isSafetyBySignDateRowText(text) || isSafetyBySignDateRowText(originalClean)) {
            return;
        }
        // Always keep user-typed SOP cells even when original stamp is missing/stale.
        if (!userEdited && normalizeCompareText(text) === normalizeCompareText(originalClean)) {
            return;
        }
        if (!userEdited && normalizeCompareText(text) === normalizeCompareText(original)) {
            return;
        }
        if (isShortIdentityLabel(text) && isIdentityPlaceholderValue(text) && !userEdited) {
            return;
        }
        if (!normalizeCompareText(text) && !userEdited) {
            return;
        }
        const parentRow = dataCell && dataCell.parentElement;
        const absRow = parentRow && parentRow.hasAttribute('data-neq-row')
            ? Number(parentRow.getAttribute('data-neq-row'))
            : null;
        const headerBlob = (headers || []).join(' ').toLowerCase();
        // SOP list / verification: write the stamped Word row. Recounting data
        // rows skipped the filled row once it contained "sop no", so the new
        // row's add1–add5 landed on the row above and the original values were lost.
        const componentAbs = String((headers || [])[0] || '') === '__neq_component_matrix__';
        // Parts Name grid: write the stamped Word row. A shared parts-name
        // label used to pull later columns onto the first matching row, so
        // the other rows stayed blank after save and Send for Approval.
        const partsAbs = /parts?\s*name/.test(headerBlob) && /acceptance/.test(headerBlob);
        const sopAbs = componentAbs
            || partsAbs
            || /performed\s*by|verified\s*by|title\s*of\s*sop|sop\s*number|effective\s*date|deviation|revision|comments?/.test(headerBlob);
        // Instrument calibration: the Word row stamp is the address.
        // Recounting across a page split wrote the last row into the blank
        // rows under it (vibration monitors57 appeared two extra times).
        const instrumentAbs = /instrument\s*description/.test(headerBlob) && /calibration/.test(headerBlob);
        const useStampedRow = (sopAbs || (instrumentAbs && absRow >= 2))
            && Number.isInteger(absRow)
            && absRow > (Number.isInteger(headerIndex) ? headerIndex : -1);
        // A continued page recounts rows from 0. Keying only that recount
        // dropped the last row's Sign and Date because it matched an earlier row.
        const key = 'hdr:' + normalizeCompareText(headers.join('|')).toLowerCase()
            + ':' + String(headerIndex)
            + ':' + (useStampedRow ? ('abs' + String(absRow)) : ('d' + String(dataRowIndex)))
            + ':' + String(colIndex);
        if (seen.has(key)) {
            return;
        }
        seen.add(key);
        const isAnnexGrid = attachmentTextCell || (/annex(ure)?\s*no/.test(headerBlob) && /attachment/.test(headerBlob));
        const rowAnchor = isAnnexGrid ? attachmentRowSaveAnchor(parentRow) : '';
        // Parts Name on this row. The numeric index can slip by one when the
        // table continues on the next page; the name still finds the Word row.
        let rowLabel = '';
        if (/parts?\s*name/.test(headerBlob) && /acceptance/.test(headerBlob) && parentRow) {
            const labelCells = getRowCells(parentRow);
            if (labelCells.length) {
                const fromName = colIndex === 0
                    ? (originalClean || original)
                    : (cellLiveText(labelCells[0]) || nodeEditText(labelCells[0]));
                const cleanedLabel = normalizeCompareText(fromName);
                if (cleanedLabel && !rowTextsAreColumnTitles([cleanedLabel])) {
                    rowLabel = cleanedLabel;
                }
            }
        }
        ops.push({
            table: Number(table.getAttribute('data-neq-table')) || 0,
            row: (useStampedRow || (isAnnexGrid && Number.isInteger(absRow) && absRow >= 0)) ? absRow : dataRowIndex,
            col: colIndex,
            text: text,
            original_text: originalClean || original,
            zone: getNodeZone(dataCellResolved) || 'body',
            match_by_headers: true,
            headers: headers,
            header_row_index: (headerInfo && Number.isInteger(headerInfo.wordHeaderIndex) && headerInfo.wordHeaderIndex >= 0)
                ? headerInfo.wordHeaderIndex
                : (Number.isInteger(headerIndex) && headerIndex >= 0 ? headerIndex : null),
            user_edited: userEdited ? 1 : 0,
            allow_clear: (normalizeCompareText(text) === '' && normalizeCompareText(originalClean || original) !== '') ? 1 : 0,
            abs_row: (useStampedRow || isAnnexGrid) ? 1 : 0,
            row_anchor: rowAnchor,
            row_label: rowLabel,
        });
    }

    // "(If any)" is part of the Deviation heading, not a value the user typed.
    function isHeaderParentheticalFragment(text) {
        const t = normalizeCompareText(text).toLowerCase().replace(/[.:]+$/g, '').trim();
        return t === '(if any)' || t === 'if any';
    }

    function extractHeaderTypedSurplus(headerLive, headerCanon) {
        const live = normalizeCompareText(headerLive || '');
        const canon = normalizeCompareText(headerCanon || '');
        if (!live) {
            return '';
        }
        // The heading itself ("Deviation (If any)") must never be saved as the body value.
        if (/^deviation\s*\(\s*if\s*any\s*\)$/i.test(live)) {
            return '';
        }
        function keepSurplus(value) {
            if (!value || isHeaderParentheticalFragment(value)) {
                return '';
            }
            if (canon && normalizeCompareText(value).toLowerCase() === canon.toLowerCase()) {
                return '';
            }
            return value;
        }
        // Pure typed value left in a header cell after label was lost ("fill").
        if (canon && !/^comments?\b/i.test(live) && !/deviation|corrective|acceptable/i.test(live)
            && live.toLowerCase() !== canon.toLowerCase()
            && !isColumnHeaderText(live) && !isKnownLabelText(live)) {
            // Only treat as surplus when it does not look like a header label itself.
            if (!/^(sr\.?\s*no|check|method|observation|sign|role|name|signature|date)$/i.test(live)) {
                // If live is clearly not the header text, keep it as surplus.
                if (canon && live.toLowerCase().indexOf(canon.toLowerCase()) === -1) {
                    return keepSurplus(live);
                }
            }
        }
        // "Comments\nfill" or "Comments fill" / "Corrective Actions fill"
        const nl = live.match(/^([\s\S]+?)[\n\r]+([\s\S]+)$/);
        if (nl) {
            const head = columnHeaderMatchText(nl[1]);
            const tail = normalizeCompareText(nl[2]);
            if (tail && (
                (canon && head.indexOf(columnHeaderMatchText(canon)) === 0)
                || /^comments?\b/i.test(head)
                || /deviation|corrective|acceptable/i.test(head)
            )) {
                return keepSurplus(tail);
            }
        }
        if (canon) {
            const lowerLive = live.toLowerCase();
            const lowerCanon = canon.toLowerCase();
            if (lowerLive.startsWith(lowerCanon) && live.length > canon.length) {
                const rest = live.slice(canon.length).replace(/^[\s:.\-–—]+/, '').trim();
                if (rest && !isColumnHeaderText(rest)) {
                    return keepSurplus(rest);
                }
            }
            // Prefix junk glued onto header: "dfgdTopic", "gkfdApplicability to Department"
            if (lowerLive.endsWith(lowerCanon) && live.length > canon.length) {
                const prefix = live.slice(0, live.length - canon.length).replace(/[\s:.\-–—]+$/, '').trim();
                if (prefix && !isColumnHeaderText(prefix) && prefix.length < 40) {
                    return prefix;
                }
            }
            // Glued without space: "Corrective Actionsfill"
            if (lowerLive.replace(/\s+/g, '').startsWith(lowerCanon.replace(/\s+/g, ''))
                && live.replace(/\s+/g, '').length > canon.replace(/\s+/g, '').length) {
                const glued = live.replace(/\s+/g, '').slice(canon.replace(/\s+/g, '').length);
                if (glued && !isColumnHeaderText(glued)) {
                    return glued;
                }
            }
            // Prefix glued without space onto multi-word headers
            const liveCompact = lowerLive.replace(/\s+/g, '');
            const canonCompact = lowerCanon.replace(/\s+/g, '');
            if (liveCompact.endsWith(canonCompact) && liveCompact.length > canonCompact.length) {
                const gluedPrefix = live.replace(/\s+/g, '').slice(0, live.replace(/\s+/g, '').length - canon.replace(/\s+/g, '').length);
                if (gluedPrefix && !isColumnHeaderText(gluedPrefix) && gluedPrefix.length < 40) {
                    return gluedPrefix;
                }
            }
        }
        // Topic / Applicability with unknown typed prefix (canon may be empty after pollution).
        const topicPrefix = live.match(/^(.{1,24}?)(topic)$/i);
        if (topicPrefix && topicPrefix[1] && !/^(s\.?\s*no|sr)/i.test(topicPrefix[1])) {
            return normalizeCompareText(topicPrefix[1]);
        }
        const applPrefix = live.match(/^(.{1,24}?)(applicability(?:\s+to\s+department)?)$/i);
        if (applPrefix && applPrefix[1]) {
            return normalizeCompareText(applPrefix[1]);
        }
        // Comments with unknown surplus: "comments fill"
        const cm = live.match(/^comments\s*:\s+(.+)$/i);
        if (cm && cm[1] && !/^comments?\s*:?\s*$/i.test(cm[1])) {
            return normalizeCompareText(cm[1]);
        }
        // Deviation / corrective header with trailing typed token.
        const dv = live.match(/^(deviation(?:\s*\([^)]*\))?|acceptable\s*\/?\s*not\s*acceptable|corrective\s*actions?)\s*[:.\-–—]?\s+(.+)$/i);
        if (dv && dv[2] && !isColumnHeaderText(dv[2])) {
            return keepSurplus(normalizeCompareText(dv[2]));
        }
        return '';
    }

    function collectHeaderMatchedTableCellEdits() {
        // Content-addressed saves: find Word table by header text (not fragile HTML table index).
        const scope = getEditScopeRoot();
        if (!scope) {
            return [];
        }
        const ops = [];
        const seen = new Set();
        const seenRowStamps = new Set();
        scope.querySelectorAll('table').forEach(function (table) {
            if (isApprovalOrSignatoryTable(table) && !isSopDataMatrixTable(table)
                && !findDataMatrixHeaderInfo(table)) {
                return;
            }
            let headerInfo = findDataMatrixHeaderInfo(table);
            const isSop = isSopDataMatrixTable(table) || !!headerInfo;
            if (!isSop && !isSaveableDataMatrixTable(table) && !isComparisonTable(table)
                && !isCommentsOrDeviationsTable(table) && !isNestedPreviewTable(table)
                && !isAttachmentListTable(table)) {
                return;
            }
            // Letterhead (Document Title / Equipment / Facility) is saved by label.
            // Do not invent SOP headers just because the table text mentions "title".
            if (!headerInfo && getDirectTableRows(table).filter(rowIsIdentityFieldRow).length >= 2) {
                return;
            }
            // Pure identity letterhead with no SOP/data header — skip here.
            if (!headerInfo && (isMetadataFieldTable(table) || isPreviewIdentityTable(table) || isLetterheadIdentityTable(table))
                && !isSop && !isComponentSubsystemMatrixTable(table)) {
                return;
            }
            // Attachment 3 component grids first — before SOP / fingerprint false positives.
            if (!headerInfo && isComponentSubsystemMatrixTable(table)) {
                headerInfo = {
                    headerIndex: -1,
                    headers: componentSubsystemMatrixHeaders(table),
                };
            }
            // A page split drops the attachments heading. Keep the real column names
            // so Yes / No is written on that annex row, not dropped.
            if (!headerInfo && isAttachmentListTable(table)) {
                headerInfo = {
                    headerIndex: -1,
                    headers: ['Annexure No.', 'Attachment No. / Attachment Type', 'NA if not applicable/ Yes if applicable'],
                };
            }
            // Combined identity+SOP table: if header sniff failed, still try canonical SOP headers
            // when the table text clearly contains a SOP list/verify grid.
            if (!headerInfo && isSop) {
                const blob = normalizeEditText(table.innerText || table.textContent || '').toLowerCase();
                if (/performed\s*by/.test(blob) && /verified\s*by/.test(blob)) {
                    headerInfo = {
                        headerIndex: -1,
                        headers: ['Sr. No.', 'SOP No.', 'Title of SOP', 'Performed by', 'Verified by', 'Changes required'],
                    };
                } else if ((/title/.test(blob) && /sop/.test(blob))
                    || (/effective/.test(blob) && /date/.test(blob) && /(sr|s)\.?\s*no/.test(blob))
                    || (/so[pg]/.test(blob) && /number|no\.?/.test(blob))) {
                    headerInfo = {
                        headerIndex: -1,
                        headers: ['Sr. No.', 'Title of SOP', 'SOP Number', 'Effective Date'],
                    };
                }
            }
            if (!headerInfo && !isSop) {
                // Utilities / deviation / comments grids: synthesize header info from fingerprint.
                if (isSaveableDataMatrixTable(table) || isCommentsOrDeviationsTable(table) || isComparisonTable(table)) {
                    const fp = tableHeaderFingerprint(table).map(function (h) {
                        return columnHeaderMatchText(h) || h;
                    }).filter(Boolean);
                    const blob = normalizeEditText(table.innerText || table.textContent || '').toLowerCase();
                    // Prefer component sentinel over accidental fingerprint of filled data cells.
                    if (isComponentSubsystemMatrixTable(table)) {
                        headerInfo = {
                            headerIndex: -1,
                            headers: componentSubsystemMatrixHeaders(table),
                        };
                    } else if (fp.length >= 2 && rowTextsAreColumnTitles(fp)) {
                        headerInfo = { headerIndex: 0, headers: fp };
                    } else if (fp.length >= 2) {
                        // The first row on a continued page is data ("VIJAY VERAM test 4"),
                        // not Parts Name / Acceptance Criteria. Keep it and save it.
                        headerInfo = columnTitlesFromTableFamily(table) || {
                            headerIndex: -1,
                            headers: (/acceptance/.test(blob) && /certificate|sign/.test(blob))
                                ? ['Parts Name', 'Acceptance Criteria', 'Actual MOC', 'Certificate No.', 'Sign and Date']
                                : fp,
                            wordHeaderIndex: 0,
                        };
                    } else if (fp.length === 1 && /^comments?\b/i.test(fp[0])) {
                        headerInfo = { headerIndex: 0, headers: ['Comments'] };
                    } else if (/^comments?\b/i.test(blob.split('\n')[0] || '') || (
                        /comments/.test(blob) && getDirectTableRows(table).length >= 2 && getRowCells(getDirectTableRows(table)[0]).length <= 2
                    )) {
                        headerInfo = { headerIndex: 0, headers: ['Comments'] };
                    } else if (/deviation/.test(blob) && /corrective|acceptable/.test(blob)) {
                        headerInfo = {
                            headerIndex: 0,
                            headers: fp.length ? fp : ['Deviation (If Any)', 'Acceptable/Not Acceptable', 'Corrective Actions'],
                        };
                    } else {
                        return;
                    }
                } else {
                    return;
                }
            }
            if (!headerInfo && isComponentSubsystemMatrixTable(table)) {
                headerInfo = {
                    headerIndex: -1,
                    headers: componentSubsystemMatrixHeaders(table),
                };
            }
            let headers = sopGridHeadersForTable(table, headerInfo);
            const isCommentsGrid = headers && headers.length === 1
                && /^comments?\b/i.test(String(headers[0] || ''));
            const isComponentMatrix = headers && headers[0] === '__neq_component_matrix__';
            if (!headers || (headers.length < 2 && !isCommentsGrid && !isComponentMatrix)) {
                if (isComponentSubsystemMatrixTable(table)) {
                    headers = componentSubsystemMatrixHeaders(table);
                } else {
                    headers = (headerInfo && headerInfo.headers) || ['Sr. No.', 'Title of SOP', 'SOP Number', 'Effective Date'];
                }
            }
            if (!headerInfo) {
                headerInfo = { headerIndex: -1, headers: headers };
            }
            const headerIndex = resolveSopHeaderIndex(table, headerInfo);
            const rows = getDirectTableRows(table);
            // Recover values typed into header cells (Comments/Deviation) when body is blank.
            const headerSurplusByCol = {};
            if (headerIndex >= 0 && headerIndex < rows.length) {
                const hdrCells = getRowCells(rows[headerIndex]);
                hdrCells.forEach(function (hCell, hCol) {
                    const live = cellLiveText(hCell) || nodeEditText(hCell);
                    const canon = (headers && headers[hCol]) || columnHeaderMatchText(live);
                    const surplus = extractHeaderTypedSurplus(live, canon);
                    if (surplus) {
                        headerSurplusByCol[hCol] = surplus;
                    }
                });
            }
            let dataRow = priorSplitDataRowCount(table);
            rows.forEach(function (row, absIndex) {
                // Include newly added rows in the walk, but do NOT map them through
                // match_by_headers — table_ops owns insert+values. Counting them as
                // data rows also shifted every following row's content address.
                const isNewRow = row.getAttribute('data-neq-new-row') === '1';
                if (rowIsIdentityFieldRow(row)) {
                    return;
                }
                const rowCells = getRowCells(row);
                // A filled Parts Name row is data even when a banner check
                // would skip it. Skipping it left that row blank after save.
                const mocRowHasText = isMocPartsMatrixTable(table) && absIndex > headerIndex
                    && rowCells.some(function (cell) {
                        return !!normalizeCompareText(cellLiveText(cell) || nodeEditText(cell));
                    });
                if (!isNewRow && !mocRowHasText && shouldSkipSopGridRow(row, rowCells, headerIndex, absIndex)) {
                    return;
                }
                if (isNewRow) {
                    return;
                }
                const stamp = row.getAttribute('data-neq-row');
                const tableKey = table.getAttribute('data-neq-table') || '';
                if (stamp && tableKey) {
                    const stampId = tableKey + ':' + stamp;
                    if (seenRowStamps.has(stampId)) {
                        return;
                    }
                    seenRowStamps.add(stampId);
                }
                const rowIndex = dataRow;
                dataRow += 1;
                rowCells.forEach(function (cell, colIndex) {
                    if (isToolCell(cell) || isInsideOfficialLogoCell(cell) || isNeqApprovalLockCell(cell)) {
                        return;
                    }
                    // First data row: if body empty but header had typed surplus, treat as body edit.
                    if (rowIndex === 0 && headerSurplusByCol[colIndex]
                        && !normalizeCompareText(cellLiveText(cell))) {
                        const dataCellResolved = resolveDataMatrixEditableCell(cell) || cell;
                        const key = 'hdr-surplus:' + normalizeCompareText((headers || []).join('|')).toLowerCase()
                            + ':' + String(headerIndex) + ':' + String(rowIndex) + ':' + String(colIndex);
                        if (!seen.has(key)) {
                            seen.add(key);
                            ops.push({
                                table: Number(table.getAttribute('data-neq-table')) || 0,
                                row: rowIndex,
                                col: colIndex,
                                text: headerSurplusByCol[colIndex],
                                original_text: cellOriginalText(dataCellResolved) || '',
                                zone: getNodeZone(dataCellResolved) || 'body',
                                match_by_headers: true,
                                headers: headers,
                                header_row_index: (headerInfo && Number.isInteger(headerInfo.wordHeaderIndex) && headerInfo.wordHeaderIndex >= 0)
                ? headerInfo.wordHeaderIndex
                : (Number.isInteger(headerIndex) && headerIndex >= 0 ? headerIndex : null),
                                user_edited: 1,
                                allow_clear: 0,
                            });
                        }
                        return;
                    }
                    pushSopGridCellOp(ops, seen, table, headerInfo, headerIndex, rowIndex, colIndex, cell);
                });
            });
            // Comments box with only a header row (typed into header, no body row yet).
            if (dataRow === 0 && Object.keys(headerSurplusByCol).length) {
                Object.keys(headerSurplusByCol).forEach(function (colKey) {
                    const colIndex = Number(colKey);
                    const key = 'hdr-surplus-only:' + normalizeCompareText((headers || []).join('|')).toLowerCase()
                        + ':' + String(headerIndex) + ':0:' + String(colIndex);
                    if (seen.has(key)) {
                        return;
                    }
                    seen.add(key);
                    ops.push({
                        table: Number(table.getAttribute('data-neq-table')) || 0,
                        row: 0,
                        col: colIndex,
                        text: headerSurplusByCol[colIndex],
                        original_text: '',
                        zone: 'body',
                        match_by_headers: true,
                        headers: isCommentsGrid ? ['Comments'] : headers,
                        header_row_index: headerIndex >= 0 ? headerIndex : 0,
                        user_edited: 1,
                        allow_clear: 0,
                    });
                });
            }
        });
        return ops;
    }

    // Safety net: any user-edited cell inside a SOP grid must reach save-as even when
    // docx-preview nesting prevented the main collector from seeing the change.
    function collectUserEditedSopCellEdits() {
        const scope = getEditScopeRoot();
        if (!scope) {
            return [];
        }
        const ops = [];
        const seen = new Set();
        scope.querySelectorAll('[data-neq-user-edited="1"]').forEach(function (node) {
            if (isToolCell(node)) {
                return;
            }
            // Skip pure identity letterhead edits (handled by match_by_label).
            const nearestTable = node.closest ? node.closest('table') : null;
            if (nearestTable
                && (isMetadataFieldTable(nearestTable) || isPreviewIdentityTable(nearestTable) || isLetterheadIdentityTable(nearestTable))
                && !isSopDataMatrixTable(nearestTable)
                && !findDataMatrixHeaderInfo(nearestTable)
                && !findOwningSopGridTable(node)) {
                return;
            }

            const table = findOwningSopGridTable(node);
            if (!table) {
                return;
            }
            if (isApprovalOrSignatoryTable(table) && !isSopDataMatrixTable(table) && !findDataMatrixHeaderInfo(table)) {
                return;
            }

            const headerInfo = findDataMatrixHeaderInfo(table);
            const headerIndex = resolveSopHeaderIndex(table, headerInfo);
            const located = locateCellInSopGrid(table, node);
            if (!located || located.colIndex < 0) {
                return;
            }
            if (located.row && located.row.getAttribute('data-neq-new-row') === '1') {
                // New rows save via table_ops only.
                return;
            }
            const mocEditedCell = isMocPartsMatrixTable(table)
                && !!normalizeCompareText(cellLiveText(located.outerCell) || nodeEditText(located.outerCell));
            if (!mocEditedCell && (rowIsIdentityFieldRow(located.row)
                || shouldSkipSopGridRow(located.row, located.rowCells, headerIndex, located.absIndex))) {
                return;
            }

            let dataRowIndex = priorSplitDataRowCount(table);
            const rows = getDirectTableRows(table);
            for (let i = headerIndex + 1; i < located.absIndex; i++) {
                const r = rows[i];
                if (!r || r.getAttribute('data-neq-new-row') === '1' || rowIsIdentityFieldRow(r)) {
                    continue;
                }
                const rc = getRowCells(r);
                if (shouldSkipSopGridRow(r, rc, headerIndex, i)) {
                    continue;
                }
                dataRowIndex += 1;
            }
            pushSopGridCellOp(
                ops,
                seen,
                table,
                headerInfo,
                headerIndex,
                dataRowIndex,
                located.colIndex,
                located.outerCell
            );
        });
        return ops;
    }

    // Last resort: dirty cells inside tables → match_by_headers AND/OR mapped index edits.
    // Fixes "typed values could not be collected" when nested layout tables break SOP sniffing.
    function collectRescueCommentsDeviationEdits() {
        // Last-resort: every filled Comments / Deviation body cell, even when
        // dirty markers / nested layout prevented the normal collectors.
        const scope = getEditScopeRoot();
        if (!scope) {
            return [];
        }
        const ops = [];
        const seen = new Set();
        scope.querySelectorAll('table').forEach(function (table) {
            // The Comments box is one column. Its value lives in the row under
            // the word Comments, including when that row has moved to the next page.
            if (isCommentsBoxTable(table)) {
                getDirectTableRows(table).forEach(function (row) {
                    const cells = getRowCells(row);
                    if (cells.length !== 1) {
                        return;
                    }
                    let text = normalizeEditText(cells[0].innerText || cells[0].textContent || '');
                    text = text.replace(/^comments\s*:\s*/i, '').trim();
                    if (!text || /^comments?\s*:?\s*$/i.test(text)) {
                        return;
                    }
                    const stamped = Number(row.getAttribute('data-neq-row'));
                    const wordRow = Number.isInteger(stamped) && stamped > 0 ? stamped : 1;
                    const key = 'comments-box:' + String(wordRow) + ':' + normalizeCompareText(text);
                    if (seen.has(key)) {
                        return;
                    }
                    seen.add(key);
                    ops.push({
                        table: Number(table.getAttribute('data-neq-table')) || 0,
                        row: wordRow,
                        col: 0,
                        text: text,
                        original_text: '',
                        zone: 'body',
                        match_by_headers: true,
                        headers: ['Comments'],
                        header_row_index: 0,
                        user_edited: 1,
                        allow_clear: 0,
                        abs_row: 1,
                    });
                });
                return;
            }
            // Only Comments / Deviation — not Attachment-3 / other saveable matrices.
            if (!isCommentsOrDeviationsTable(table)) {
                return;
            }
            let headerInfo = findDataMatrixHeaderInfo(table);
            const blob = normalizeEditText(table.innerText || table.textContent || '').toLowerCase();
            if (!headerInfo) {
                if (/deviation/.test(blob) && /corrective|acceptable/.test(blob)) {
                    headerInfo = {
                        headerIndex: 0,
                        headers: ['Deviation (If Any)', 'Acceptable/Not Acceptable', 'Corrective Actions'],
                    };
                } else if (/comments/.test(blob)) {
                    headerInfo = { headerIndex: 0, headers: ['Comments'] };
                } else {
                    return;
                }
            }
            const headers = canonicalizeMatrixHeaders(sopGridHeadersForTable(table, headerInfo), table);
            const headerIndex = resolveSopHeaderIndex(table, headerInfo);
            const rows = getDirectTableRows(table);
            let dataRow = priorSplitDataRowCount(table);
            rows.forEach(function (row, absIndex) {
                if (absIndex <= headerIndex || isHeaderLikeRow(row)) {
                    return;
                }
                // Add-row clones are owned by table_ops — counting them here shifted
                // every following data_row and duplicated the inserted values.
                if (row.getAttribute('data-neq-new-row') === '1') {
                    return;
                }
                const rowCells = getRowCells(row);
                if (shouldSkipSopGridRow(row, rowCells, headerIndex, absIndex)) {
                    return;
                }
                const rowIndex = dataRow;
                dataRow += 1;
                rowCells.forEach(function (cell, colIndex) {
                    if (isToolCell(cell) || isInsideOfficialLogoCell(cell)) {
                        return;
                    }
                    // Acceptable column may host a checkbox — still save typed text only.
                    const resolved = resolveDataMatrixEditableCell(cell) || cell;
                    const text = stripMatrixHeaderGlue(
                        stripAccidentalEdPollution(cellLiveText(resolved), ''),
                        headers,
                        colIndex
                    );
                    const original = stripMatrixHeaderGlue(
                        stripAccidentalEdPollution(cellOriginalText(resolved) || '', ''),
                        headers,
                        colIndex
                    );
                    if (!normalizeCompareText(text)) {
                        return;
                    }
                    // "(If any)" is the Deviation heading, not a typed deviation.
                    if (isHeaderParentheticalFragment(text) && !normalizeCompareText(original)) {
                        return;
                    }
                    // Prefer changed / user-edited cells. Also keep non-empty body fills when
                    // original stamp is missing/blank (common after Add-row / unlock).
                    const changed = normalizeCompareText(text) !== normalizeCompareText(original);
                    const dirty = cellHasUserEdit(resolved) || cellHasUserEdit(cell);
                    if (!changed && !dirty && original !== '') {
                        return;
                    }
                    if (isColumnHeaderText(text) && isKnownLabelText(text)) {
                        return;
                    }
                    const key = 'rescue:' + normalizeCompareText((headers || []).join('|')).toLowerCase()
                        + ':' + String(rowIndex) + ':' + String(colIndex);
                    if (seen.has(key)) {
                        return;
                    }
                    seen.add(key);
                    ops.push({
                        table: Number(table.getAttribute('data-neq-table')) || 0,
                        row: rowIndex,
                        col: colIndex,
                        text: text,
                        original_text: original,
                        zone: getNodeZone(resolved) || 'body',
                        match_by_headers: true,
                        headers: headers,
                        header_row_index: (headerInfo && Number.isInteger(headerInfo.wordHeaderIndex) && headerInfo.wordHeaderIndex >= 0)
                ? headerInfo.wordHeaderIndex
                : (Number.isInteger(headerIndex) && headerIndex >= 0 ? headerIndex : null),
                        user_edited: 1,
                        allow_clear: 0,
                    });
                });
            });
        });
        return ops;
    }

    function collectForceDirtyCellEdits() {
        const scope = getEditScopeRoot();
        if (!scope) {
            return { cellEdits: [], textEdits: [] };
        }
        const cellEdits = [];
        const textEdits = [];
        const seenCell = new Set();
        const seenIndex = new Set();

        function resolveMappedIndex(node) {
            if (!node) {
                return null;
            }
            if (node.hasAttribute && node.hasAttribute('data-neq-index')) {
                const n = Number(node.getAttribute('data-neq-index'));
                return Number.isInteger(n) && n >= 0 ? n : null;
            }
            const withIndex = node.closest ? node.closest('[data-neq-index]') : null;
            if (withIndex) {
                const n = Number(withIndex.getAttribute('data-neq-index'));
                return Number.isInteger(n) && n >= 0 ? n : null;
            }
            const cell = node.closest ? node.closest('td, th') : null;
            if (cell) {
                if (cell.hasAttribute('data-neq-index')) {
                    const n = Number(cell.getAttribute('data-neq-index'));
                    return Number.isInteger(n) && n >= 0 ? n : null;
                }
                const inner = cell.querySelector('[data-neq-index]');
                if (inner) {
                    const n = Number(inner.getAttribute('data-neq-index'));
                    return Number.isInteger(n) && n >= 0 ? n : null;
                }
            }
            return null;
        }

        scope.querySelectorAll('[data-neq-user-edited="1"]').forEach(function (node) {
            if (isToolCell(node) || isInsideTocRegion(node)) {
                return;
            }
            const cell = node.closest ? node.closest('td, th') : null;
            if (!cell || isToolCell(cell) || isNeqApprovalLockCell(cell)) {
                return;
            }
            if (isHeaderLikeRow(cell.parentElement) || !isFillInField(cell)) {
                return;
            }

            const live = cellLiveText(cell) || nodeEditText(node);
            const original = cellOriginalText(cell) || node.getAttribute('data-neq-original') || '';
            if (normalizeCompareText(live) === normalizeCompareText(original) && !cellHasUserEdit(cell)) {
                return;
            }

            // Prefer header-matched cell_edits for data grids — paragraph indexes often
            // write Observation/Result into the wrong Word slots (cells_applied stayed 0).
            let table = findOwningSopGridTable(cell) || findOwningSopGridTable(node);
            if (!table) {
                // Any ancestor table with 3+ columns that looks like a data grid.
                let el = cell.parentElement;
                while (el && el !== scope) {
                    if ((el.tagName || '').toLowerCase() === 'table') {
                        const cols = getDirectTableRows(el)[0] ? getRowCells(getDirectTableRows(el)[0]).length : 0;
                        const blob = normalizeEditText(el.innerText || '').toLowerCase();
                        if (cols >= 3 && (/sop|title|effective|performed|verified|sr\.?\s*no|acceptance\s*criteria|observation|sign\s*and\s*date|\bcheck\b|\bmethod\b|deviation|corrective|acceptable|topic|applicability/.test(blob))) {
                            table = el;
                            break;
                        }
                    }
                    el = el.parentElement;
                }
            }
            if (!table && (isCommentsOrDeviationsTable(cell.closest('table')) || isComparisonTable(cell.closest('table')))) {
                table = cell.closest('table');
            }

            const matrixTable = table
                && (
                    !!findDataMatrixHeaderInfo(table)
                    || isSaveableDataMatrixTable(table)
                    || isComponentSubsystemMatrixTable(table)
                    || isCommentsOrDeviationsTable(table)
                    || isComparisonTable(table)
                    || isSopDataMatrixTable(table)
                );

            // 1) Paragraph index only when this is NOT a saveable matrix cell.
            if (!matrixTable) {
                let mappedIndex = resolveMappedIndex(node);
                if (mappedIndex == null) {
                    mappedIndex = resolveMappedIndex(cell);
                }
                if (mappedIndex != null && !seenIndex.has(mappedIndex)) {
                    seenIndex.add(mappedIndex);
                    textEdits.push({
                        index: mappedIndex,
                        text: live,
                        original_text: original,
                        zone: getNodeZone(cell) || 'body',
                    });
                }
            }

            // 2) Emit header-matched cell op for matrix / SOP / utilities grids.
            if (!table) {
                return;
            }
            if (isApprovalOrSignatoryTable(table) && !isSopDataMatrixTable(table) && !findDataMatrixHeaderInfo(table)) {
                return;
            }
            const tableBlob = normalizeEditText(table.innerText || '').toLowerCase();
            const headerInfo = findDataMatrixHeaderInfo(table) || {
                headerIndex: -1,
                headers: isComponentSubsystemMatrixTable(table)
                    ? componentSubsystemMatrixHeaders(table)
                    : ((/performed/.test(tableBlob) && /verified/.test(tableBlob))
                        ? ['Sr. No.', 'SOP No.', 'Title of SOP', 'Performed by', 'Verified by', 'Changes required']
                        : (((/acceptance\s*criteria/.test(tableBlob) || /observation/.test(tableBlob))
                            && (/\bcheck\b/.test(tableBlob) || /\bmethod\b/.test(tableBlob)))
                            ? ['Check', 'Method', 'Acceptance Criteria', 'Observation/ Result', 'Sign and Date']
                            : ((/deviation/.test(tableBlob) && /corrective|acceptable/.test(tableBlob))
                                ? ['Deviation (If Any)', 'Acceptable/Not Acceptable', 'Corrective Actions']
                                : ['Sr. No.', 'Title of SOP', 'SOP Number', 'Effective Date']))),
            };
            const headerIndex = resolveSopHeaderIndex(table, headerInfo);
            const located = locateCellInSopGrid(table, cell);
            if (!located || located.colIndex < 0) {
                return;
            }
            if (located.row && located.row.getAttribute('data-neq-new-row') === '1') {
                return;
            }
            if (located.absIndex <= headerIndex) {
                return;
            }
            const mocForceCell = isMocPartsMatrixTable(table)
                && !!normalizeCompareText(cellLiveText(located.outerCell) || nodeEditText(cell));
            if (!mocForceCell && (rowIsIdentityFieldRow(located.row)
                || shouldSkipSopGridRow(located.row, located.rowCells, headerIndex, located.absIndex))) {
                return;
            }
            let dataRowIndex = priorSplitDataRowCount(table);
            const rows = getDirectTableRows(table);
            for (let i = headerIndex + 1; i < located.absIndex; i++) {
                const r = rows[i];
                if (!r || r.getAttribute('data-neq-new-row') === '1' || rowIsIdentityFieldRow(r)) {
                    continue;
                }
                const rc = getRowCells(r);
                if (shouldSkipSopGridRow(r, rc, headerIndex, i)) {
                    continue;
                }
                dataRowIndex += 1;
            }
            pushSopGridCellOp(
                cellEdits,
                seenCell,
                table,
                headerInfo,
                headerIndex,
                dataRowIndex,
                located.colIndex,
                located.outerCell
            );
        });

        return { cellEdits: cellEdits, textEdits: textEdits };
    }

    function repairLetterheadDocumentRow(scope) {
        if (!scope) {
            return;
        }
        scope.querySelectorAll('table').forEach(function (table) {
            if (table.closest && table.closest('td, th')) {
                return;
            }
            getDirectTableRows(table).forEach(function (row) {
                const cells = getRowCells(row);
                if (cells.length < 3) {
                    return;
                }
                const texts = cells.map(function (cell) {
                    return normalizeEditText(nodeEditText(cell));
                });
                const docIdx = texts.findIndex(function (text) {
                    return /^document\s*no\.?$/i.test(text);
                });
                if (docIdx < 0 || docIdx >= cells.length - 1) {
                    return;
                }
                const valueCell = cells[docIdx + 1];
                const valueText = texts[docIdx + 1] || '';
                if (valueCell && /^(check|method|acceptance\s*criteria|observation(?:\s*\/\s*result)?|sign\s+and\s+date)$/i.test(valueText)) {
                    setNodePlainText(valueCell, '');
                    valueCell.removeAttribute('data-neq-user-edited');
                    texts[docIdx + 1] = '';
                }
                let versionIdx = texts.findIndex(function (text) {
                    return /^version$/i.test(text);
                });
                const numberIdx = texts.findIndex(function (text, index) {
                    return index > docIdx && /^\d+(?:\.\d+)?$/.test(text);
                });
                if (versionIdx < 0 && numberIdx > docIdx) {
                    const labelCell = cells[numberIdx - 1];
                    const labelText = texts[numberIdx - 1] || '';
                    if (labelCell && labelCell !== cells[docIdx] && !labelText) {
                        setNodePlainText(labelCell, 'VERSION');
                        labelCell.removeAttribute('data-neq-user-edited');
                        if (!labelCell.hasAttribute('data-neq-original') || labelCell.getAttribute('data-neq-original') === '') {
                            labelCell.setAttribute('data-neq-original', 'VERSION');
                        }
                    }
                }
            });
        });
    }

    function clearIdentityPlaceholderValueCells() {
        // So the user types a clean value instead of editing inside "system name" / wrap fragments.
        const scope = getEditScopeRoot();
        if (!scope) {
            return;
        }
        scope.querySelectorAll('table').forEach(function (table) {
            if (!isMetadataFieldTable(table) && !isPreviewIdentityTable(table) && !isLetterheadIdentityTable(table)) {
                return;
            }
            const sopHeader = findDataMatrixHeaderInfo(table);
            // Pure SOP-only grids have no identity value cells to clear.
            if (isSopDataMatrixTable(table) && !isMetadataFieldTable(table) && !sopHeader) {
                return;
            }
            getDirectTableRows(table).forEach(function (row, absIndex) {
                if (sopHeader && absIndex >= sopHeader.headerIndex && !rowIsIdentityFieldRow(row)) {
                    return;
                }
                const cells = getRowCells(row);
                cells.forEach(function (cell, col) {
                    if (col < 1 || cell.querySelector('table') || isToolCell(cell)) {
                        return;
                    }
                    const prev = cells[col - 1];
                    const prevText = prev ? nodeEditText(prev) : '';
                    if (!isIdentityFormLabelText(prevText) && !isShortIdentityLabel(prevText) && !looksLikeFieldLabel(prevText)) {
                        return;
                    }
                    const live = cellLiveText(cell);
                    // VERSION, EQUIPMENT ID. and DOCUMENT No. are labels in this row.
                    // Wiping them left a blank cell and let "Check" sit in their place.
                    if (isShortIdentityLabel(live) || isIdentityFormLabelText(live) || looksLikeFieldLabel(live)) {
                        return;
                    }
                    if (!live || !isIdentityPlaceholderValue(live)) {
                        return;
                    }
                    if (cellHasUserEdit(cell)) {
                        return;
                    }
                    // Keep original as the placeholder so empty→typed is detected as a change.
                    if (!cell.hasAttribute('data-neq-original')) {
                        cell.setAttribute('data-neq-original', live);
                    }
                    setNodePlainText(cell, '');
                    cell.querySelectorAll('[contenteditable="true"]').forEach(function (block) {
                        if (!block.hasAttribute('data-neq-original')) {
                            block.setAttribute('data-neq-original', live);
                        }
                        setNodePlainText(block, '');
                    });
                });
            });
        });
    }

    // Section banners (Main Body / Mechanical / Electrical) are locked — do not persist edits.
    function collectSectionBannerCellEdits() {
        return [];
    }

    // "Verified by sign and date :" stays. A signature typed beside it is saved in that same cell.
    function collectVerifiedBySignEdits() {
        const ops = [];
        stampNeqTables();
        getMappablePreviewTables().forEach(function (table) {
            if (!table || getNodeZone(table) !== 'body') {
                return;
            }
            const tableIndex = Number(table.getAttribute('data-neq-table')) || 0;
            getDirectTableRows(table).forEach(function (row) {
                const cells = getRowCells(row);
                if (!cells.length) {
                    return;
                }
                const cell = cells[0];
                const live = normalizeEditText(cellLiveText(cell) || row.innerText || '');
                if (!/^verified\s*by\s*sign\s*and\s*date\b/i.test(live)) {
                    return;
                }
                if (/^verified\s*by\s*sign\s*and\s*date\s*:?\s*$/i.test(live)) {
                    return;
                }
                const original = normalizeEditText(cellOriginalText(cell) || '');
                if (normalizeCompareText(live) === normalizeCompareText(original)) {
                    return;
                }
                const rowIndex = Number(row.getAttribute('data-neq-row'));
                if (!Number.isInteger(rowIndex) || rowIndex < 0) {
                    return;
                }
                ops.push({
                    table: tableIndex,
                    row: rowIndex,
                    col: 0,
                    text: live,
                    original_text: original || 'Verified by sign and date :',
                    zone: 'body',
                    allow_banner_edit: 1,
                    match_abs_row: 1,
                    user_edited: 1,
                });
            });
        });
        return ops;
    }

    function collectCellEdits() {
        // Prefer content-addressed writes (label / headers). HTML table indexes often
        // disagree with Word table indexes after docx-preview nesting — that caused
        // "saved" values to land in the wrong cells so reload still showed old text.
        stampNeqTables();
        dropDuplicateSplitRows(getEditScopeRoot());
        const ops = [];
        const seen = new Set();

        function pushOp(op, key) {
            if (!op || seen.has(key)) {
                return;
            }
            let text = op.text == null ? '' : String(op.text);
            const original = op.original_text == null ? '' : String(op.original_text);
            // Always scrub accidental "ed" glue before any other cleaners / compares.
            text = stripAccidentalEdPollution(text, original);
            text = unwrapGluedPrefixes(text);
            op.text = text;
            if (op.match_by_label) {
                if (op.label) {
                    op.label = normalizeIdentitySaveLabel(op.label) || normalizeCompareText(op.label);
                }
                if (!op.allow_clear) {
                    text = cleanIdentityTypedValue(text, original, op.label);
                    text = stripIdentityLabelPrefix(op.label, text);
                    text = stripAccidentalEdPollution(text, original);
                    // If cleaner wiped a real short name, keep the raw typed value.
                    if (!text && op.user_edited && original !== undefined) {
                        const raw = stripAccidentalEdPollution(
                            stripIdentityLabelPrefix(
                                op.label,
                                String(op.text == null ? '' : op.text)
                            ),
                            original
                        );
                        if (raw && !isIdentityPlaceholderValue(raw) && !isShortIdentityLabel(raw)) {
                            text = normalizeCompareText(raw);
                        }
                    }
                    op.text = text;
                }
            }
            // Never persist section titles (Attachment 3/4).
            // "Verified by sign and date" is typed text and must be saved.
            if (!op.allow_banner_edit) {
                if (isUtilitiesSectionTitleText(text) || isUtilitiesSectionTitleText(original)) {
                    return;
                }
                if (isComponentSectionBannerText(text) || isComponentSectionBannerText(original)) {
                    return;
                }
            }
            if (normalizeCompareText(text) === normalizeCompareText(stripAccidentalEdPollution(original, ''))) {
                return;
            }
            if (normalizeCompareText(text) === normalizeCompareText(original)) {
                return;
            }
            // Never write label/placeholder chrome into Word via positional saves.
            if (!op.match_by_label && !op.match_by_headers) {
                if (isIdentityPlaceholderValue(text) || isShortIdentityLabel(text) || isKnownLabelText(text)) {
                    return;
                }
            }
            // Never save column-header text (Check/Method/Topic/…) even if marked user-edited.
            if (isColumnHeaderText(text) && isKnownLabelText(text)) {
                return;
            }
            if (isColumnHeaderText(text) && /^(check|method|observation|result|deviation|version|name|signature|date|role|topic)$/i.test(columnHeaderMatchText(text))) {
                return;
            }
            // The Topic / Applicability titles stay put. A filled value that
            // merely contains those words must still be saved.
            if (isTrainingColumnTitleText(text)
                && (!columnHeaderMatchText(original || '') || isTrainingColumnTitleText(original))) {
                return;
            }
            // Allow intentional clears (empty new text, non-empty original).
            if (text === '' && normalizeCompareText(original) === '') {
                return;
            }
            if (op.match_by_label && text === '' && !op.allow_clear && !op.user_edited) {
                return;
            }
            seen.add(key);
            ops.push(op);
        }

        function pushCell(tableIndex, rowIndex, colIndex, text, original, zone) {
            if (!Number.isInteger(tableIndex) || !Number.isInteger(rowIndex) || !Number.isInteger(colIndex)) {
                return;
            }
            if (tableIndex < 0 || rowIndex < 0 || colIndex < 0) {
                return;
            }
            pushOp({
                table: tableIndex,
                row: rowIndex,
                col: colIndex,
                text: text,
                original_text: original,
                zone: zone || 'body',
            }, tableIndex + ':' + rowIndex + ':' + colIndex);
        }

        // Canonical key so hdr / sopdirty / force / nest collectors do not triple-send
        // the same Attachment-3 cell (was ~3x duplicate cell_edits in save payload).
        function matrixOpKey(op) {
            if (op && op.match_by_label) {
                return 'label:' + normalizeCompareText(op.label || '').toLowerCase();
            }
            if (op && op.allow_banner_edit) {
                return 'banner:' + String(op.table) + ':' + String(op.row) + ':' + String(op.col);
            }
            if (op && op.match_by_headers) {
                return 'hdr:' + normalizeCompareText((op.headers || []).join('|')).toLowerCase()
                    + ':' + String(op.header_row_index != null ? op.header_row_index : '')
                    + ':' + String(op.row) + ':' + String(op.col);
            }
            return 'pos:' + String(op.table) + ':' + String(op.row) + ':' + String(op.col);
        }

        // 1) Identity fields — label-based only (never fragile table indexes).
        collectIdentityLabelCellEdits().forEach(function (op) {
            pushOp(op, matrixOpKey(op));
        });

        // 1b) Section banners + Verified-by lines (absolute Word row, not data-row index).
        collectSectionBannerCellEdits().forEach(function (op) {
            pushOp(op, matrixOpKey(op));
        });
        collectVerifiedBySignEdits().forEach(function (op) {
            pushOp(op, matrixOpKey(op));
        });

        // 2) SOP / verification / annex grids — header fingerprint (top-level + nested).
        collectHeaderMatchedTableCellEdits().forEach(function (op) {
            pushOp(op, matrixOpKey(op));
        });

        // 2a) Force-include any user-edited SOP cell (nested layout tables often hide changes).
        collectUserEditedSopCellEdits().forEach(function (op) {
            pushOp(op, matrixOpKey(op));
        });

        // 2a2) Last-resort dirty body/SOP cells (prevents "could not be collected" block).
        collectForceDirtyCellEdits().cellEdits.forEach(function (op) {
            pushOp(op, matrixOpKey(op));
        });

        // 2b) Nested preview tables that header-match may miss (docx-preview wrappers).
        collectNestedDataTableCellEdits().forEach(function (op) {
            pushOp(op, matrixOpKey(op));
        });

        // 2c) Training personnel Name/Department/Signature/Training/Date fills.
        collectTrainingPersonnelCellEdits().forEach(function (op) {
            pushOp(op, 'train:' + op.table + ':' + op.row + ':' + op.col);
        });

        // 3) Remaining positional edits only for non-identity / non-SOP mappable tables.
        getMappablePreviewTables().forEach(function (table) {
            if (isMetadataFieldTable(table) || isPreviewIdentityTable(table) || isLetterheadIdentityTable(table)) {
                return;
            }
            if (isSopDataMatrixTable(table) || isSaveableDataMatrixTable(table)) {
                return; // already handled by header match
            }
            if (isTrainingPersonnelTable(table)) {
                return; // handled by collectTrainingPersonnelCellEdits
            }
            if (isApprovalOrSignatoryTable(table)) {
                return; // approver names merged server-side
            }
            const tableIndex = Number(table.getAttribute('data-neq-table'));
            if (!Number.isInteger(tableIndex) || tableIndex < 0) {
                return;
            }
            getDirectTableRows(table).forEach(function (row) {
                if (row.getAttribute('data-neq-new-row') === '1') {
                    return;
                }
                if (isHeaderLikeRow(row)) {
                    return;
                }
                const rowIndex = Number(row.getAttribute('data-neq-row'));
                if (!Number.isInteger(rowIndex) || rowIndex < 0) {
                    return;
                }
                Array.from(row.children).forEach(function (cell) {
                    const tag = (cell.tagName || '').toLowerCase();
                    if ((tag !== 'td' && tag !== 'th') || isToolCell(cell) || isInsideOfficialLogoCell(cell)) {
                        return;
                    }
                    if (isInsideTocRegion(cell) || isNeqApprovalLockCell(cell)) {
                        return;
                    }
                    if (cell.getAttribute('data-neq-checkbox') === '1'
                        || cell.querySelector('[data-neq-checkbox="1"], input.neq-checkbox')) {
                        return;
                    }
                    if (cell.querySelector('table')) {
                        return;
                    }
                    const colIndex = Number(cell.getAttribute('data-neq-col'));
                    const text = cellLiveText(cell);
                    const original = cellOriginalText(cell);
                    const zone = getNodeZone(cell);
                    const userEdited = cellHasUserEdit(cell);
                    if (!userEdited && normalizeCompareText(text) === normalizeCompareText(original || '')) {
                        return;
                    }
                    if (isAnnexFormMatrixCell(cell)
                        || isSerialOrAnnexNoColumnCell(cell)
                        || isDataTableValueColumnCell(cell)
                        || userEdited
                        || shouldPersistEditedContent(cell, original, text)) {
                        pushCell(tableIndex, rowIndex, colIndex, text, original || '', zone);
                    }
                });
            });
        });

        return ops;
    }

    function rowTextsAreColumnTitles(headers) {
        if (!headers || !headers.length) {
            return false;
        }
        const filled = headers.map(function (h) {
            return columnHeaderMatchText(h) || String(h || '');
        }).filter(function (h) {
            return normalizeCompareText(h) !== '';
        });
        if (filled.length < 2) {
            return filled.length === 1 && /^comments?\b/i.test(filled[0]);
        }
        if (typeof rowLooksLikeMocPartsColumnHeaders === 'function' && rowLooksLikeMocPartsColumnHeaders(filled)) {
            return true;
        }
        if (typeof rowLooksLikeColumnHeaders === 'function' && rowLooksLikeColumnHeaders(filled)
            && filled.every(function (h) {
                return isColumnHeaderText(h) || String(h).length <= 32;
            })) {
            return true;
        }
        const joined = filled.join(' ').toLowerCase();
        if (/deviation/.test(joined) && /corrective|acceptable/.test(joined)
            && filled.every(function (h) { return String(h).length < 48; })) {
            return true;
        }
        return false;
    }

    function columnTitlesFromTableFamily(table) {
        const parts = typeof tableParts === 'function' ? tableParts(table) : [];
        for (let i = 0; i < parts.length; i++) {
            if (!parts[i] || parts[i] === table) {
                continue;
            }
            const info = findDataMatrixHeaderInfo(parts[i]);
            if (info && info.headers && info.headers.length && rowTextsAreColumnTitles(info.headers)) {
                return {
                    headerIndex: -1,
                    headers: info.headers.slice(),
                    wordHeaderIndex: Number.isInteger(info.wordHeaderIndex) && info.wordHeaderIndex >= 0
                        ? info.wordHeaderIndex
                        : (Number.isInteger(info.headerIndex) && info.headerIndex >= 0 ? info.headerIndex : 0),
                };
            }
        }
        return null;
    }

    function tableHeaderFingerprint(table) {
        if (!table) {
            return [];
        }
        // A page split keeps the column titles on the first piece only.
        // The continuation's first row is data (criteria 5, set1, …). Using
        // that row as the header made later certificate cells miss the Word table.
        if (table.getAttribute('data-neq-page-split') === '1' && !table.__neqFpLookup) {
            const parts = typeof tableParts === 'function' ? tableParts(table) : [];
            const head = parts.find(function (part) {
                return part.getAttribute('data-neq-page-split') !== '1';
            });
            if (head && head !== table) {
                table.__neqFpLookup = true;
                const headers = tableHeaderFingerprint(head);
                delete table.__neqFpLookup;
                if (headers.length) {
                    return headers;
                }
            }
        }
        const rows = getDirectTableRows(table);
        for (let i = 0; i < Math.min(rows.length, 8); i++) {
            const headers = getRowCells(rows[i]).map(function (cell) {
                return columnHeaderMatchText(nodeEditText(cell) || cell.innerText || cell.textContent || '');
            }).filter(Boolean);
            if (headers.length >= 2) {
                return headers;
            }
            // Single-column Comments box.
            if (headers.length === 1 && /^comments?\b/i.test(headers[0])) {
                return ['Comments'];
            }
        }
        return [];
    }

    function isNestedPreviewTable(table) {
        if (!table || !table.parentElement) {
            return false;
        }
        const scope = getEditScopeRoot();
        if (!scope) {
            return false;
        }
        const parentCell = table.parentElement.closest
            ? table.parentElement.closest('td, th')
            : null;
        return !!(parentCell && scope.contains(parentCell));
    }

    function isSaveableDataMatrixTable(table) {
        if (!table) {
            return false;
        }
        if (isSopDataMatrixTable(table)) {
            return true;
        }
        if (isApprovalOrSignatoryTable(table) || isLetterheadIdentityTable(table)) {
            return false;
        }
        if (isComparisonTable(table) || isCommentsOrDeviationsTable(table)) {
            return true;
        }
        if (isComponentSubsystemMatrixTable(table)) {
            return true;
        }
        if (isIoqCheckMatrixTable(table)) {
            return true;
        }
        if (isMocPartsMatrixTable(table)) {
            return true;
        }
        const headers = tableHeaderFingerprint(table).join(' ').toLowerCase();
        return /title\s*of\s*sop|sop\s*(no|number)|performed\s*by|verified\s*by|annex(ure)?\s*no|instrument|requirements?|deviation|corrective|acceptance\s*criteria|observation|sign\s*and\s*date|checked\s*by\s*sign|specification|\bcheck\b|\bmethod\b|topic|applicability|parts?\s*name|actual\s*moc|certificate\s*no/.test(headers)
            || (/revision/.test(headers) && /change/.test(headers) && /\bdate\b/.test(headers));
    }

    function rowIsIdentityFieldRow(row) {
        if (!row) {
            return false;
        }
        const cells = getRowCells(row);
        if (!cells.length || cells.length > 6) {
            return false;
        }
        return cells.some(function (cell) {
            const text = normalizeEditText(cell.innerText || cell.textContent || '');
            if (!text || text.length > 90) {
                return false;
            }
            return isShortIdentityLabel(text)
                || /^document\s*title\b/i.test(text)
                || /^reference\s*document\b/i.test(text)
                || /^equipment\s*\/?\s*system\b/i.test(text)
                || /^facilit(?:y)?\s*\/\s*section\b/i.test(text);
        });
    }

    function collectNestedDataTableCellEdits() {
        const scope = getEditScopeRoot();
        if (!scope) {
            return [];
        }
        const ops = [];
        scope.querySelectorAll('table').forEach(function (table) {
            if (!isNestedPreviewTable(table)) {
                return;
            }
            const headerInfo = findDataMatrixHeaderInfo(table);
            const isSop = isSopDataMatrixTable(table) || !!headerInfo;
            // Letterhead rows are saved by label, never as an SOP grid.
            if (!headerInfo && getDirectTableRows(table).some(rowIsIdentityFieldRow)) {
                return;
            }
            // Pure identity nested tables are handled by label-based edits.
            if (!isSop && (isMetadataFieldTable(table) || isPreviewIdentityTable(table))) {
                return;
            }
            if (isApprovalOrSignatoryTable(table) && !isSop) {
                return;
            }
            const headers = sopGridHeadersForTable(table, headerInfo);
            if (headers.length < 1) {
                return;
            }
            const headerIndex = resolveSopHeaderIndex(table, headerInfo);
            const rows = getDirectTableRows(table);
            const seen = new Set();
            let dataRow = priorSplitDataRowCount(table);
            rows.forEach(function (row, absIndex) {
                if (row.getAttribute('data-neq-new-row') === '1') {
                    return;
                }
                if (rowIsIdentityFieldRow(row)) {
                    return;
                }
                const rowCells = getRowCells(row);
                if (shouldSkipSopGridRow(row, rowCells, headerIndex, absIndex)) {
                    return;
                }
                const rowIndex = dataRow;
                dataRow += 1;
                rowCells.forEach(function (cell, colIndex) {
                    if (isToolCell(cell) || isInsideOfficialLogoCell(cell) || isNeqApprovalLockCell(cell)) {
                        return;
                    }
                    pushSopGridCellOp(ops, seen, table, headerInfo, headerIndex, rowIndex, colIndex, cell);
                });
            });
        });
        return ops;
    }

    function isRevisionHistoryTable(table) {
        const rows = getDirectTableRows(table);
        if (!rows.length) {
            return false;
        }
        const header = getRowCells(rows[0]).map(function (cell) {
            return normalizeEditText(cell.innerText || cell.textContent || '').toLowerCase();
        }).join(' ');
        return /revision/.test(header) && /change/.test(header) && /\bdate\b/.test(header)
            && getRowCells(rows[0]).length <= 4;
    }

    function directRowFromClick(table, target) {
        const direct = getDirectTableRows(table);
        let node = target;
        while (node && node !== table) {
            if ((node.tagName || '').toLowerCase() === 'tr' && direct.indexOf(node) !== -1) {
                return node;
            }
            node = node.parentElement;
        }
        return null;
    }

    function getSelectableTableRows(table) {
        return getDirectTableRows(table).filter(function (row) {
            if (isHeaderLikeRow(row)) {
                return false;
            }
            return !isDataMatrixSectionBannerRow(row, getRowCells(row));
        });
    }

    function getSelectedTableRow(table) {
        const selected = getDirectTableRows(table).find(function (row) {
            return row.classList.contains('neq-row-selected');
        });
        if (!selected || isHeaderLikeRow(selected)) {
            return null;
        }
        return selected;
    }

    function selectTableRow(table, row, allowHeader) {
        const parts = typeof tableParts === 'function' ? tableParts(table) : [table];
        parts.forEach(function (part) {
            getDirectTableRows(part).forEach(function (item) {
                item.classList.remove('neq-row-selected');
            });
        });
        if (!row || getDirectTableRows(table).indexOf(row) === -1) {
            return;
        }
        if (!allowHeader && isHeaderLikeRow(row)) {
            return;
        }
        row.classList.add('neq-row-selected');
    }

    function showTableRowHint(message) {
        if (!previewError) {
            return;
        }
        previewError.textContent = message;
        previewError.classList.remove('alert-success');
        previewError.classList.add('alert-warning');
        previewError.classList.remove('d-none');
        if (typeof previewError.scrollIntoView === 'function') {
            previewError.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    function tableParts(table) {
        const key = table && table.getAttribute ? table.getAttribute('data-neq-table') : '';
        const scope = typeof getEditScopeRoot === 'function' ? getEditScopeRoot() : null;
        if (!key || !scope || !scope.querySelectorAll) {
            return table ? [table] : [];
        }
        return Array.from(scope.querySelectorAll('table[data-neq-table="' + key + '"]')).filter(function (item) {
            return !item.closest('header, footer, .docx_header, .docx_footer');
        });
    }

    function findSelectedAcrossParts(table) {
        const parts = tableParts(table);
        for (let i = 0; i < parts.length; i++) {
            const marked = getDirectTableRows(parts[i]).find(function (row) {
                return row.classList.contains('neq-row-selected');
            });
            if (marked) {
                return { table: parts[i], row: marked };
            }
        }
        return null;
    }

    function onAddTableRow(table) {
        const found = findSelectedAcrossParts(table);
        const activeTable = found ? found.table : table;
        const dataRows = getSelectableTableRows(activeTable);
        const marked = found ? found.row : null;
        const headerClicked = !!(marked && isHeaderLikeRow(marked));
        let selected = headerClicked ? null : (marked && !isHeaderLikeRow(marked) ? marked : null);
        if (!selected) {
            // A click on the heading still adds a row. It is placed at the end.
            selected = dataRows.length ? dataRows[dataRows.length - 1] : (marked || getDirectTableRows(table)[0]);
        }
        if (!selected) {
            showTableRowHint('This table has no row to add under.');
            return;
        }
        const clone = selected.cloneNode(true);
        clone.removeAttribute('data-neq-row');
        clone.classList.remove('neq-row-selected');
        clone.classList.add('neq-data-row');
        clone.setAttribute('data-neq-new-row', '1');
        clone.querySelectorAll('.neq-row-tool-cell, .neq-remove-row').forEach(function (el) {
            el.remove();
        });
        clone.querySelectorAll('th').forEach(function (cell) {
            const td = document.createElement('td');
            Array.from(cell.attributes).forEach(function (attr) {
                td.setAttribute(attr.name, attr.value);
            });
            cell.parentNode.replaceChild(td, cell);
        });
        clearDirectRowText(clone);
        getRowCells(clone).forEach(function (cell) {
            cell.textContent = '';
            cell.setAttribute('data-neq-original', '');
            cell.setAttribute('contenteditable', 'true');
        });
        const after = headerClicked && dataRows.length ? dataRows[dataRows.length - 1] : selected;
        if (after.nextSibling) {
            after.parentNode.insertBefore(clone, after.nextSibling);
        } else {
            after.parentNode.appendChild(clone);
        }
        selectTableRow(activeTable, clone);
        bindApproverDropdowns();
        if (previewError) {
            previewError.classList.add('d-none');
        }
        reflowPreviewKeepingCaret(activeTable);
        scheduleNeqAutosave();
    }

    function onRemoveSelectedTableRow(table) {
        const found = findSelectedAcrossParts(table);
        const selected = found ? found.row : null;
        const owner = found ? found.table : table;
        if (!selected || isHeaderLikeRow(selected)) {
            showTableRowHint('Select a row first, then click Remove.');
            return;
        }
        const dataRows = tableParts(table).reduce(function (count, part) {
            return count + getSelectableTableRows(part).length;
        }, 0);
        const revision = tableParts(table).some(function (part) {
            return isRevisionHistoryTable(part);
        });
        if (dataRows <= 1 && !revision) {
            showTableRowHint('The last data row cannot be removed.');
            return;
        }
        const next = selected.nextElementSibling && !isHeaderLikeRow(selected.nextElementSibling)
            ? selected.nextElementSibling
            : selected.previousElementSibling;
        onRemoveTableRow(selected, owner);
        if (owner.getAttribute('data-neq-page-split') === '1' && !getSelectableTableRows(owner).length) {
            const wrap = owner.closest('.neq-table-wrap');
            if (wrap && wrap.parentNode) {
                wrap.remove();
            } else if (owner.parentNode) {
                owner.remove();
            }
        }
        if (next && getDirectTableRows(owner).indexOf(next) !== -1 && !isHeaderLikeRow(next)) {
            selectTableRow(owner, next);
        }
        if (previewError) {
            previewError.classList.add('d-none');
        }
        reflowPreviewKeepingCaret(table);
    }

    function reflowPreviewKeepingCaret() {
        return;
    }

    function onTableRowClick(event) {
        if (!isEditingEnabled) {
            return;
        }
        if (event.target.closest('.neq-table-toolbar')) {
            return;
        }
        const table = event.currentTarget;
        const row = directRowFromClick(table, event.target);
        if (!row) {
            return;
        }
        // The column heading can be clicked, then Add still inserts a row.
        selectTableRow(table, row, isHeaderLikeRow(row));
    }

    function onRemoveTableRow(row, table) {
        const parts = tableParts(table);
        const total = parts.reduce(function (count, part) {
            return count + getSelectableTableRows(part).length;
        }, 0);
        const revision = parts.some(function (part) {
            return isRevisionHistoryTable(part);
        });
        // A row that moved to the next page is the only row in that copy.
        // It still belongs to the same table, so Remove must delete it.
        if (total <= 1 && !revision && table.getAttribute('data-neq-page-split') !== '1') {
            return;
        }
        if (row.getAttribute('data-neq-new-row') === '1') {
            row.remove();
            scheduleNeqAutosave();
            return;
        }
        const rowIndex = Number(row.getAttribute('data-neq-row'));
        const tableIndex = Number(table.getAttribute('data-neq-table'));
        if (Number.isInteger(tableIndex) && Number.isInteger(rowIndex) && rowIndex >= 0) {
            pendingTableDeletes.push({
                op: 'delete',
                table: tableIndex,
                row: rowIndex,
            });
            const scope = getEditScopeRoot();
            if (scope) {
                scope.querySelectorAll('table[data-neq-table="' + tableIndex + '"] tr[data-neq-row="' + rowIndex + '"]').forEach(function (other) {
                    if (other !== row) {
                        other.remove();
                    }
                });
            }
        }
        row.remove();
        scheduleNeqAutosave();
    }

    function isTocRelatedTable(table) {
        if (!table) {
            return false;
        }
        if (isInsideTocRegion(table)) {
            return true;
        }
        const text = normalizeEditText(table.innerText || table.textContent || '').toLowerCase();
        if (/table\s+of\s+contents/.test(text)) {
            return true;
        }
        // TOC band often sits just above a list-like table of section entries.
        let prev = table.previousElementSibling;
        let hops = 0;
        while (prev && hops < 3) {
            const heading = normalizeEditText(prev.innerText || prev.textContent || '');
            if (/^table\s+of\s+contents\b/i.test(heading) || prev.getAttribute('data-neq-toc') === '1') {
                return true;
            }
            if (heading && !isLikelyTocEntryText(heading, prev)) {
                break;
            }
            prev = prev.previousElementSibling;
            hops += 1;
        }
        const rows = getDirectTableRows(table);
        if (rows.length >= 3) {
            let tocHits = 0;
            rows.slice(0, Math.min(rows.length, 12)).forEach(function (row) {
                const rowText = normalizeEditText(row.innerText || row.textContent || '');
                if (isLikelyTocEntryText(rowText, row) || /^\d+\.\d+/.test(rowText)) {
                    tocHits += 1;
                }
            });
            if (tocHits >= Math.ceil(Math.min(rows.length, 12) * 0.6)
                && /report\s*approval|objective|scope|\d+\.\d+/.test(text)) {
                return true;
            }
        }
        return false;
    }

    function bindTableRowTools(enabled) {
        const scope = getEditScopeRoot();
        if (!scope) {
            return;
        }
        scope.querySelectorAll('.neq-table-toolbar, .neq-remove-row').forEach(function (el) {
            el.remove();
        });
        scope.querySelectorAll('.neq-row-tool-cell').forEach(function (el) {
            el.remove();
        });
        if (!enabled) {
            scope.querySelectorAll('tr.neq-row-selected').forEach(function (row) {
                row.classList.remove('neq-row-selected');
            });
            return;
        }
        stampTocRegion(scope);
        stampNeqTables();
        getMappablePreviewTables().forEach(function (table) {
            if (getNodeZone(table) !== 'body') {
                return;
            }
            // Performed / Checked / Approved By + signatory tables: fixed roles — no Add/Remove.
            if (isApprovalOrSignatoryTable(table)) {
                return;
            }
            // Training personnel fill-in grid: fixed template rows — no Add/Remove.
            if (isTrainingPersonnelTable(table)) {
                return;
            }
            // Table of Contents must never get Add/Remove row tools.
            if (isTocRelatedTable(table)) {
                return;
            }
            if (isIoqCheckMatrixTable(table) || isSopDataMatrixTable(table) || isSaveableDataMatrixTable(table)
                || isComparisonTable(table) || isCommentsOrDeviationsTable(table)) {
                table.classList.add('neq-check-matrix');
                normalizeCheckMatrixCellAlignment(table);
            }
            getDirectTableRows(table).forEach(function (row) {
                row.classList.toggle('neq-data-row', !isHeaderLikeRow(row));
            });
            // The next page keeps only the extra rows. One heading, and one Add/Remove, on the first page.
            if (table.getAttribute('data-neq-page-split') === '1') {
                table.removeEventListener('click', onTableRowClick);
                table.addEventListener('click', onTableRowClick);
                return;
            }
            const wrap = ensureTableWrap(table);
            const toolbar = document.createElement('div');
            toolbar.className = 'neq-table-toolbar';
            const addBtn = document.createElement('button');
            addBtn.type = 'button';
            addBtn.className = 'btn btn-sm btn-outline-primary';
            addBtn.textContent = '+ Add row';
            addBtn.title = 'Insert a blank row below the selected row';
            addBtn.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                onAddTableRow(table);
            });
            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn btn-sm btn-outline-danger';
            removeBtn.textContent = '− Remove';
            removeBtn.title = 'Remove the selected row';
            removeBtn.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                onRemoveSelectedTableRow(table);
            });
            toolbar.appendChild(addBtn);
            toolbar.appendChild(removeBtn);
            if (wrap) {
                wrap.insertBefore(toolbar, table);
            } else {
                table.parentNode.insertBefore(toolbar, table);
            }
            // A page split copies the marker onto the next page but not the click.
            // Bind again so a row on that page can be selected and removed.
            table.removeEventListener('click', onTableRowClick);
            table.addEventListener('click', onTableRowClick);
        });
    }

    function dropBlankLinesInsideDataCell(cell) {
        if (!cell || !cell.querySelectorAll) {
            return;
        }
        const ownerTable = cell.parentElement ? cell.parentElement.closest('table') : null;
        Array.from(cell.querySelectorAll('p, div, br')).forEach(function (el) {
            const nestedTable = el.closest('table');
            if (ownerTable && nestedTable && nestedTable !== ownerTable) {
                return;
            }
            if (el.querySelector && el.querySelector('input, select, button, img, table')) {
                return;
            }
            const tag = (el.tagName || '').toLowerCase();
            if (tag === 'br') {
                const parentText = normalizeEditText(el.parentElement ? (el.parentElement.innerText || '') : '');
                if (!parentText) {
                    el.remove();
                }
                return;
            }
            if (!normalizeEditText(el.innerText || el.textContent || '')) {
                // The only paragraph in an empty cell is what keeps the Word row open.
                const blocks = Array.from(cell.children).filter(function (node) {
                    const blockTag = (node.tagName || '').toLowerCase();
                    return blockTag === 'p' || blockTag === 'div';
                });
                if (blocks.length <= 1) {
                    return;
                }
                el.remove();
            }
        });
    }

    function keepMatrixHeaderTextInsideColumns(table) {
        if (!table) {
            return;
        }
        table.querySelectorAll('td, th').forEach(function (cell) {
            if (cell.closest('table') !== table) {
                return;
            }
            cell.querySelectorAll('p, span, div').forEach(function (el) {
                if (el.closest('table') !== table) {
                    return;
                }
                // Negative Word indents draw the label across the column border on unlock.
                el.style.setProperty('margin-left', '0', 'important');
                el.style.setProperty('margin-right', '0', 'important');
                el.style.setProperty('margin-inline-start', '0', 'important');
                el.style.setProperty('margin-inline-end', '0', 'important');
                el.style.setProperty('width', 'auto', 'important');
                el.style.setProperty('min-width', '0', 'important');
                el.style.setProperty('max-width', '100%', 'important');
                el.style.setProperty('box-sizing', 'border-box', 'important');
            });
        });
    }

    function normalizeCheckMatrixCellAlignment(table) {
        if (!table) {
            return;
        }
        keepMatrixHeaderTextInsideColumns(table);
        getDirectTableRows(table).forEach(function (row) {
            if (isHeaderLikeRow(row) || isDataMatrixSectionBannerRow(row, getRowCells(row))) {
                return;
            }
            getRowCells(row).forEach(function (cell) {
                if (isInsideNestedLayoutTable(cell)) {
                    return;
                }
                dropBlankLinesInsideDataCell(cell);
                // First line starts at the top. Do not zero padding or row height:
                // that made locked view shorter than the original Word row.
                cell.style.setProperty('vertical-align', 'top', 'important');
                cell.style.removeProperty('height');
                cell.style.removeProperty('min-height');
                cell.querySelectorAll('p, span, div, [contenteditable="true"]').forEach(function (el) {
                    if (el !== cell && el.closest && el.closest('table') !== table) {
                        return;
                    }
                    el.style.setProperty('vertical-align', 'top', 'important');
                    el.style.setProperty('position', 'static', 'important');
                    el.style.setProperty('float', 'none', 'important');
                    el.style.setProperty('transform', 'none', 'important');
                    el.style.removeProperty('height');
                    el.style.removeProperty('min-height');
                    el.style.removeProperty('line-height');
                    el.style.removeProperty('margin');
                    el.style.removeProperty('padding');
                });
            });
        });
    }

    function isSopDataMatrixTable(table) {
        if (!table) {
            return false;
        }
        const text = normalizeEditText(table.innerText || table.textContent || '').toLowerCase();
        if (!text) {
            return false;
        }
        // Attachment 3/4: section banners + "Verified by sign and date" are NOT SOP grids.
        // (Inline check — do not call isComponentSubsystemMatrixTable; that can recurse via letterhead.)
        if (/mechanical\s*components?|electrical\s*components?|\bmain\s*body\b/.test(text)
            && !/title\s*of\s*sop|list\s*of\s*sop|sop\s*(no|number)|changes\s*required/.test(text)) {
            return false;
        }
        if (/verified\s*by\s*(sign|date)/.test(text)
            && !/performed\s*by|title\s*of\s*sop|list\s*of\s*sop|sop\s*(no|number)|changes\s*required/.test(text)) {
            return false;
        }
        // Fuzzy: headers may be corrupted ("Title/dvdsf of SOP", "SOGfdfP Number") after a bad prior save.
        const sopLike = /sop\s*no|so[pg].*number|title\s*of\s*sop|title.*sop|list\s*of\s*sop|verification\s*of\s*draft\s*sop|changes\s*required|performed\s*by/.test(text)
            || (/performed\s*by/.test(text) && /verified\s*by/.test(text));
        if (!sopLike) {
            return false;
        }
        return /performed\s*by|effective.*date|sr\.?\s*no|s\.?\s*no|title.*sop|so[pg].*number|changes\s*required/.test(text)
            || (/performed\s*by/.test(text) && /verified\s*by/.test(text));
    }

    function findDataMatrixHeaderInfo(table) {
        if (!table) {
            return null;
        }
        // Continuation pages do not repeat Parts Name / Deviation headings.
        // Keep the real column titles and treat every row here as a data row.
        if (table.getAttribute('data-neq-page-split') === '1' && !table.__neqHeaderLookup) {
            const parts = typeof tableParts === 'function' ? tableParts(table) : [];
            const head = parts.find(function (part) {
                return part.getAttribute('data-neq-page-split') !== '1';
            });
            if (head && head !== table) {
                table.__neqHeaderLookup = true;
                const info = findDataMatrixHeaderInfo(head);
                delete table.__neqHeaderLookup;
                if (info && info.headers && info.headers.length) {
                    return {
                        headerIndex: -1,
                        headers: info.headers.slice(),
                        previewHeaders: (info.previewHeaders || info.headers).slice(),
                        wordHeaderIndex: Number.isInteger(info.wordHeaderIndex) && info.wordHeaderIndex >= 0
                            ? info.wordHeaderIndex
                            : info.headerIndex,
                    };
                }
            }
        }
        const rows = getDirectTableRows(table);
        for (let i = 0; i < Math.min(rows.length, 16); i++) {
            const cells = getRowCells(rows[i]);
            if (!cells.length) {
                continue;
            }
            const headers = cells.map(function (cell) {
                return columnHeaderMatchText(nodeEditText(cell) || cell.innerText || cell.textContent || '');
            }).filter(Boolean);
            const rowBlob = headers.join(' ');
            if (/annex(?:[_-][a-z0-9]+)+/i.test(rowBlob) && /ioq\s*attachment\s*\d+/i.test(rowBlob)) {
                continue;
            }
            // Single-column Comments box: header row is just "Comments" (maybe with typed surplus).
            if (headers.length === 1 && /^comments?\b/i.test(headers[0]) && cells.length <= 2) {
                return {
                    headerIndex: i,
                    headers: ['Comments'],
                    previewHeaders: headers,
                };
            }
            if (cells.length < 2 || headers.length < 2) {
                continue;
            }
            const joined = headers.join(' ').toLowerCase();
            // Fuzzy match even when prior bad saves corrupted header text
            // e.g. "Titledvdsf of SOP", "SOfgdP Number", "Effectivegdsfd Date".
            // The cell must be the column title itself. "rohan sop no test1" contains
            // those words but it is a value, not the Verification of draft SOPs header.
            const looksLikeMoc = rowLooksLikeMocPartsColumnHeaders(headers);
            // Parts Name / Actual MOC is not a SOP list. Treating it as one
            // replaced the headings with Sr. No. / Title of SOP and left "MOC"
            // on the next line, and textContent wiped the heading font size.
            const looksLikeSopVerify = !looksLikeMoc && rowLooksLikeSopColumnHeaders(headers)
                && /performed/.test(joined) && /verified/.test(joined);
            const looksLikeSopList = !looksLikeMoc && !looksLikeSopVerify && rowLooksLikeSopColumnHeaders(headers);
            const looksLikeAnnex = /annex/.test(joined) || /instrument/.test(joined);
            // IOQ / utilities / OQ check grids (Attachment 5/6/10 style).
            const looksLikeUtilities = (
                (/acceptance\s*criteria/.test(joined)
                    || /observation/.test(joined)
                    || /sign\s*and\s*date/.test(joined)
                    || /checked\s*by\s*sign/.test(joined)
                    || /specification/.test(joined))
                && (/check/.test(joined) || /method/.test(joined) || /sr\.?\s*no|s\.?\s*no/.test(joined))
            );
            const looksLikeDeviation = /deviation/.test(joined)
                && (/corrective/.test(joined) || /acceptable/.test(joined));
            const looksLikeRevision = /revision/.test(joined) && /change/.test(joined)
                && /\bdate\b/.test(joined) && cells.length <= 4;
            const looksLikeComments = headers.length >= 1
                && headers.every(function (h) {
                    return /^comments?\b/i.test(String(h || '').trim());
                });
            // Training Attachment-12: S.No | Topic | As per Document No. | Applicability…
            // Allow typed prefix pollution ("dfgdTopic") — no leading \b on topic.
            const looksLikeTrainingTopic = /topic/.test(joined)
                && (/applicability|as\s*per\s*document|department/.test(joined));
            if (!looksLikeSopList && !looksLikeSopVerify && !looksLikeAnnex
                && !looksLikeUtilities && !looksLikeDeviation && !looksLikeComments
                && !looksLikeTrainingTopic && !looksLikeMoc && !looksLikeRevision
                && !((/(sr\.?\s*no|s\.?\s*no)/.test(joined) && /sop|title|date/.test(joined)))) {
                continue;
            }
            // Need at least 3 columns for SOP list/verify grids (avoid identity 2-col false positives).
            if ((looksLikeSopList || looksLikeSopVerify) && cells.length < 3 && headers.length < 3) {
                continue;
            }
            let saveHeaders = headers;
            if (looksLikeMoc) {
                saveHeaders = headers;
            } else if (looksLikeSopVerify) {
                saveHeaders = ['Sr. No.', 'SOP No.', 'Title of SOP', 'Performed by', 'Verified by', 'Changes required'];
            } else if (looksLikeSopList) {
                saveHeaders = ['Sr. No.', 'Title of SOP', 'SOP Number', 'Effective Date'];
            } else if (looksLikeUtilities) { 
                // Keep live header labels (stripped of "ed") so Python can fingerprint the Word table.
                saveHeaders = headers;
            } else if (looksLikeTrainingTopic) {
                saveHeaders = ['S. No.', 'Topic', 'As per Document No.', 'Applicability to Department'];
            } else if (looksLikeDeviation || looksLikeMoc) {
                saveHeaders = headers;
            } else if (looksLikeComments) {
                saveHeaders = ['Comments'];
            }
            return {
                headerIndex: i,
                headers: saveHeaders,
                previewHeaders: headers,
            };
        }
        return null;
    }

    function isIoqCheckMatrixTable(table) {
        if (!table) {
            return false;
        }
        // Do not call isLetterheadIdentityTable / hasApprovalTableContext here — they recurse.
        const headers = tableHeaderFingerprint(table).join(' ').toLowerCase();
        const text = normalizeEditText(table.innerText || table.textContent || '').toLowerCase();
        // Attachment performance / general / safety check grids
        // (Sr.No | Check | Method | Specification | Observation | Checked by sign and date).
        if (/\bcheck\b/.test(headers)
            && /\bmethod\b/.test(headers)
            && (/specification|observation|acceptance\s*criteria|checked\s*by\s*sign|sign\s*and\s*date/.test(headers))) {
            return true;
        }
        if (/general\s*checks?|mechanical\s*adjustments|machine\s*performance|safety\s*(and\s*)?performance|operational\s*\/?\s*functional/.test(text)
            && /\bcheck\b/.test(text)
            && (/method|specification|observation/.test(text))) {
            return true;
        }
        return false;
    }

    function hasApprovalTableContext(table) {
        if (!table || isSopDataMatrixTable(table) || isIoqCheckMatrixTable(table)) {
            return false;
        }
        const text = normalizeEditText(table.innerText || table.textContent || '').toLowerCase();
        if (!text) {
            return false;
        }
        if (/approval\s*page|signator|approval\s*matrix/.test(text) && /(name|signature|date|role)/.test(text)) {
            return true;
        }
        // Real signatory blocks — not check-grid "Checked by sign and date" columns.
        if (/prepared\s*by|checked\s*by|approved\s*by|reviewed\s*by|compiled\s*by|complied\s*by|recorded\s*by/.test(text)
            && /(name|signature|date|role|designation)/.test(text)) {
            // Column header "Checked by sign and date" inside a Check/Method grid is not a signatory table.
            if (/checked\s*by\s*sign\s*(and|&)?\s*date/.test(text)
                && /\bcheck\b/.test(text)
                && (/method|specification|observation|acceptance/.test(text))) {
                return false;
            }
            return true;
        }
        if (/performed\s*by/.test(text) && /signature/.test(text) && /name|designation|role/.test(text)
            && !isSopDataMatrixTable(table)) {
            return true;
        }
        let prev = table.previousElementSibling;
        let hops = 0;
        while (prev && hops < 4) {
            const tag = (prev.tagName || '').toLowerCase();
            if (tag === 'p' || tag === 'h1' || tag === 'h2' || tag === 'h3' || tag === 'h4' || tag === 'h5' || tag === 'h6') {
                const heading = normalizeEditText(prev.innerText || prev.textContent || '').toLowerCase();
                if (/approval\s*page|signator|prepared\s*by|checked\s*by|approved\s*by|reviewed\s*by|compiled\s*by|complied\s*by|recorded\s*by/.test(heading)) {
                    return true;
                }
            }
            if (tag === 'table') {
                break;
            }
            prev = prev.previousElementSibling;
            hops += 1;
        }
        return false;
    }

    function isApprovalOrSignatoryTable(table) {
        if (!table || isLetterheadIdentityTable(table) || isSopDataMatrixTable(table) || isIoqCheckMatrixTable(table)) {
            return false;
        }
        if (getApprovalColumnMap(table)) {
            return true;
        }
        return hasApprovalTableContext(table);
    }

    function isLetterheadIdentityTable(table) {
        if (!table) {
            return false;
        }
        if (isSopDataMatrixTable(table)) {
            return false;
        }
        if (isMetadataFieldTable(table)) {
            return true;
        }
        const text = normalizeEditText(table.innerText || table.textContent || '').toLowerCase();
        if (!text || /signature/.test(text) || hasApprovalTableContext(table)) {
            return false;
        }
        if (/list\s*of\s*sop|title\s*of\s*sop|verification\s*of\s*draft\s*sop|sop\s*(no|number)/.test(text)) {
            return false;
        }
        // Protocol header / identity block (Facility, Effective Date, VERSION, etc.) — not approver tables.
        return /facilit\s*\/\s*sect|system\s*id|doc\.?\s*no|document\s*title|equipment\s*\/\s*system\s*name|equipment\s*name|equipment\s*id|\bversion\b/
            .test(text)
            || (/equipment/.test(text) && /system\s*name/.test(text) && /facilit/.test(text))
            || (/effective\s*date/.test(text) && /document\s*title|document\s*no|reference\s*document|equipment|facilit/.test(text));
    }

    function isTrainingPersonnelTable(table) {
        return !!getTrainingPersonnelColumnMap(table);
    }

    function getTrainingPersonnelColumnMap(table) {
        if (!table || isLetterheadIdentityTable(table)) {
            return null;
        }
        const rows = getDirectTableRows(table);
        const limit = Math.min(3, rows.length);
        for (let i = 0; i < limit; i++) {
            const cells = Array.from(rows[i].children).filter(function (el) {
                return (el.tagName === 'TD' || el.tagName === 'TH') && !isToolCell(el);
            });
            const texts = cells.map(function (cell) {
                return normalizeEditText(cell.innerText || cell.textContent).toLowerCase();
            });
            const joined = texts.join(' | ');
            // Layout fingerprint: Name + Department + Signature + Training Imparted by + Date
            if (!/training\s*imparted/.test(joined) || !/name/.test(joined) || !/signature/.test(joined)) {
                continue;
            }
            let nameCol = -1;
            let departmentCol = -1;
            let signatureCol = -1;
            let trainingCol = -1;
            let dateCol = -1;
            texts.forEach(function (text, idx) {
                if (nameCol < 0 && /^name\b/.test(text) && !/equipment|system|document|file/.test(text)) {
                    nameCol = idx;
                }
                if (departmentCol < 0 && /(department|designation)/.test(text) && !/name/.test(text) && !/training/.test(text)) {
                    departmentCol = idx;
                }
                if (signatureCol < 0 && /signature/.test(text) && !/training/.test(text)) {
                    signatureCol = idx;
                }
                if (trainingCol < 0 && /training\s*imparted/.test(text)) {
                    trainingCol = idx;
                }
                if (dateCol < 0 && /^date\b|date$/.test(text) && !/effective/.test(text) && !/training/.test(text)) {
                    dateCol = idx;
                }
            });
            if (nameCol >= 0 && signatureCol >= 0 && trainingCol >= 0) {
                return {
                    headerIndex: i,
                    wordHeaderIndex: i,
                    name: nameCol,
                    department: departmentCol,
                    signature: signatureCol,
                    training: trainingCol,
                    date: dateCol,
                };
            }
        }
        // The table continues on the next page without the column titles.
        // Those Date cells are still the same Word rows and must keep saving.
        if (table.getAttribute('data-neq-page-split') === '1' && typeof tableParts === 'function' && !table.__neqTrainMap) {
            const parts = tableParts(table);
            for (let p = 0; p < parts.length; p++) {
                if (parts[p] === table || parts[p].getAttribute('data-neq-page-split') === '1') {
                    continue;
                }
                table.__neqTrainMap = true;
                const head = getTrainingPersonnelColumnMap(parts[p]);
                delete table.__neqTrainMap;
                if (!head) {
                    continue;
                }
                return {
                    headerIndex: -1,
                    wordHeaderIndex: Number.isInteger(head.wordHeaderIndex) ? head.wordHeaderIndex : head.headerIndex,
                    name: head.name,
                    department: head.department,
                    signature: head.signature,
                    training: head.training,
                    date: head.date,
                };
            }
        }
        return null;
    }

    function getApprovalColumnMap(table) {
        if (!table || isLetterheadIdentityTable(table)) {
            return null;
        }
        // Training personnel grids are fill-in forms, not approval workflows.
        if (isTrainingPersonnelTable(table)) {
            return null;
        }
        // Check/Method/Specification grids — not Prepared/Checked/Approved By signatory tables.
        if (isIoqCheckMatrixTable(table)) {
            return null;
        }
        const rows = getDirectTableRows(table);
        const limit = Math.min(3, rows.length);
        for (let i = 0; i < limit; i++) {
            const cells = Array.from(rows[i].children).filter(function (el) {
                return (el.tagName === 'TD' || el.tagName === 'TH') && !isToolCell(el);
            });
            const texts = cells.map(function (cell) {
                // Strip accidental "ed" prefix so edName / edSignature still map.
                return columnHeaderMatchText(cell.innerText || cell.textContent);
            });
            let nameCol = -1;
            let signatureCol = -1;
            let dateCol = -1;
            let roleCol = -1;
            let departmentCol = -1;
            texts.forEach(function (text, idx) {
                // Never treat "Equipment / System Name" as an approver Name column.
                if (nameCol < 0
                    && /name/.test(text)
                    && !/equipment|system\s*name|document\s*name|file\s*name/.test(text)) {
                    nameCol = idx;
                }
                if (signatureCol < 0 && /signature/.test(text)) {
                    signatureCol = idx;
                }
                // Ignore "Effective Date" from protocol letterhead.
                if (dateCol < 0 && /date/.test(text) && !/effective\s*date/.test(text)) {
                    dateCol = idx;
                }
                if (roleCol < 0 && /^role/.test(text)) {
                    roleCol = idx;
                }
                // Separate Department / Designation column (not "Name & Designation").
                if (departmentCol < 0
                    && /(department|designation)/.test(text)
                    && !/name/.test(text)
                    && !/training/.test(text)) {
                    departmentCol = idx;
                }
            });
            // Skip pure section-label rows (Prepared By) when scanning for column headers.
            if (nameCol < 0 && signatureCol < 0
                && texts.some(function (text) {
                    return /^(prepared|checked|approved|reviewed|performed|compiled|complied|recorded)\s*by\b/.test(text);
                })) {
                continue;
            }
            // Require a real Signature header. Do not invent signature from Effective Date
            // (that incorrectly turned the letterhead into an approver table).
            if (nameCol >= 0 && signatureCol >= 0) {
                if (dateCol < 0) {
                    dateCol = signatureCol;
                }
                // Combined "Name & Designation" header → never treat a sibling as department.
                const nameHeader = texts[nameCol] || '';
                const nameIsCombined = /name\s*(?:&|\/|and)\s*designation/.test(nameHeader);
                if (nameIsCombined || departmentCol === nameCol) {
                    departmentCol = -1;
                }
                // Unlabeled first column (empty / not "Role") before Name → still a role column
                // so Checked By / Approved By rows are not mistaken for section headings.
                if (roleCol < 0 && nameCol > 0) {
                    const candidate = nameCol - 1;
                    if (candidate !== signatureCol && candidate !== dateCol && candidate !== departmentCol) {
                        const headerRoleText = texts[candidate] || '';
                        if (!headerRoleText || /^role\b/.test(headerRoleText)) {
                            roleCol = candidate;
                        } else {
                            const rolePattern = /^(prepared|checked|approved|reviewed|performed|compiled|complied|recorded)\s*by\b/;
                            const hasRoleValues = rows.some(function (row, rowIdx) {
                                if (rowIdx === i) {
                                    return false;
                                }
                                const rowCells = Array.from(row.children).filter(function (el) {
                                    return (el.tagName === 'TD' || el.tagName === 'TH') && !isToolCell(el);
                                });
                                const roleText = normalizeEditText(
                                    (rowCells[candidate] && (rowCells[candidate].innerText || rowCells[candidate].textContent)) || ''
                                ).toLowerCase();
                                return rolePattern.test(roleText);
                            });
                            if (hasRoleValues) {
                                roleCol = candidate;
                            } else if (nameCol === 1 && candidate === 0) {
                                // Unlabeled left column before Name — treat as Role even before values load.
                                roleCol = candidate;
                            }
                        }
                    }
                }
                return {
                    headerIndex: i,
                    name: nameCol,
                    signature: signatureCol,
                    date: dateCol,
                    role: roleCol,
                    department: departmentCol,
                    nameCombined: nameIsCombined,
                };
            }
        }
        // A page split can leave Engineering / Quality Assurance / Production
        // without the "Name & Designation" header. Those rows still need Select User.
        if (rows.some(function (row) {
            return rowLooksLikeSignatoryDepartment(row) || rowLooksLikeSignatoryRole(row);
        })) {
            const wide = rows.some(function (row) {
                return rowLooksLikeSignatoryRole(row) && getRowCells(row).length >= 4;
            });
            return {
                headerIndex: -1,
                name: 1,
                signature: 2,
                date: wide ? 3 : 2,
                role: 0,
                department: -1,
                nameCombined: !wide,
            };
        }
        // A split page keeps only the value row ("Super Admin | — | —").
        // The Prepared By / Checked By heading is on the previous piece.
        if (!table.__neqMapLookup && typeof tableParts === 'function') {
            table.__neqMapLookup = true;
            let found = null;
            tableParts(table).some(function (part) {
                if (part === table) {
                    return false;
                }
                found = getApprovalColumnMap(part);
                return !!found;
            });
            delete table.__neqMapLookup;
            if (found) {
                return found;
            }
        }
        return null;
    }

    function rowLooksLikeSignatoryRole(row) {
        if (!row) {
            return false;
        }
        const cells = Array.from(row.children).filter(function (el) {
            return (el.tagName === 'TD' || el.tagName === 'TH') && !isToolCell(el);
        });
        if (cells.length < 3) {
            return false;
        }
        const role = columnHeaderMatchText(cells[0].innerText || cells[0].textContent || '');
        return /^(prepared|checked|approved|reviewed|performed|compiled|complied|recorded)\s*by\b/.test(role);
    }

    function rowLooksLikeSignatoryDepartment(row) {
        if (!row) {
            return false;
        }
        const cells = Array.from(row.children).filter(function (el) {
            return (el.tagName === 'TD' || el.tagName === 'TH') && !isToolCell(el);
        });
        if (cells.length < 3) {
            return false;
        }
        const role = columnHeaderMatchText(cells[0].innerText || cells[0].textContent || '');
        return /^(quality\s*assurance|engineering|production|user\s*\/\s*validation)\b/.test(role);
    }

    function isNeqApprovalLockCell(node) {
        const cell = getOwningCell(node);
        if (!cell) {
            return false;
        }
        const table = cell.closest ? cell.closest('table') : null;
        if (isTrainingPersonnelTable(table)) {
            return false;
        }
        if (cell.classList && (cell.classList.contains('neq-approver-cell') || cell.classList.contains('neq-stamp-cell'))) {
            return true;
        }
        const map = getApprovalColumnMap(table);
        if (!map || isHeaderLikeRow(cell.parentElement)) {
            return false;
        }
        const col = getRowCells(cell).indexOf(cell);
        return col === map.role || col === map.name || col === map.signature || col === map.date
            || (Number.isInteger(map.department) && map.department >= 0 && col === map.department);
    }

    function approvalsForCurrentList() {
        if (!currentListId) {
            return [];
        }
        return neqApprovals[String(currentListId)] || [];
    }

    function findSavedApproval(tableIndex, rowIndex) {
        return approvalsForCurrentList().find(function (item) {
            return Number(item.table) === Number(tableIndex) && Number(item.row) === Number(rowIndex);
        }) || null;
    }

    function currentWorkflowStatus() {
        if (!currentListId || !uploadsTbody) {
            return 'draft';
        }
        const row = uploadsTbody.querySelector('tr[data-list-id="' + currentListId + '"]');
        return String((row && row.getAttribute('data-approval-status')) || 'draft');
    }

    function currentPendingApproval() {
        return approvalsForCurrentList()
            .filter(function (item) {
                return item.user_id && !item.approved_at && !item.rejected_at;
            })
            .sort(function (a, b) {
                return Number(a.step_order || 0) - Number(b.step_order || 0);
            })[0] || null;
    }

    function approverSelectUserId(sel) {
        if (!sel) {
            return 0;
        }
        const live = Number(sel.value || 0);
        if (live > 0) {
            return live;
        }
        return Number(sel.getAttribute('data-neq-picked-user') || 0);
    }

    function isSendForApprovalSelect(sel) {
        if (!sel || !sel.classList) {
            return false;
        }
        // "Verified by sign and date" is a signature line, not an approval step.
        if (sel.classList.contains('neq-section-verified-select')) {
            return false;
        }
        if (sel.classList.contains('neq-safety-by-select')) {
            return false;
        }
        return sel.classList.contains('neq-approver-select')
            || sel.classList.contains('neq-qa-approver-select');
    }

    function isRoleApprovalSelect(sel) {
        if (!isSendForApprovalSelect(sel)) {
            return false;
        }
        if (sel.classList.contains('neq-qa-approver-select')) {
            return true;
        }
        const row = sel.closest ? sel.closest('tr') : null;
        const text = row
            ? (row.innerText || row.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase()
            : '';
        if (/^verified\s*by\b/.test(text) || /\bverified\s*by\s+sign\b/.test(text)) {
            return false;
        }
        if (/\b(prepared|checked|approved|reviewed|performed|compiled|complied|recorded)\s*by\b/.test(text)) {
            return true;
        }
        // Name | Signature | Date rows do not repeat "Prepared By" on the row.
        // They still have to be selected before Send for Approval.
        const table = row && row.closest ? row.closest('table') : null;
        const map = table ? getApprovalColumnMap(table) : null;
        if (!map || !row) {
            return false;
        }
        if (isApprovalHeaderRow(row, map) || isApprovalSectionLabelRow(row, map)) {
            return false;
        }
        const cells = getRowCells(row);
        const nameCell = cells[map.name];
        if (!nameCell || !nameCell.contains(sel)) {
            return false;
        }
        if (isApproverNameHeaderCell(nameCell)) {
            return false;
        }
        const nameText = normalizeEditText(nameCell.innerText || nameCell.textContent || '').toLowerCase();
        if (/^(prepared|checked|approved|reviewed|performed|compiled|complied|recorded)\s*by\b/.test(nameText)) {
            return false;
        }
        return true;
    }

    function allApproversSelected() {
        const selects = previewArea
            ? Array.from(previewArea.querySelectorAll(
                'select.neq-approver-select[data-neq-approver-slot="1"], select.neq-qa-approver-select'
            ))
            : [];
        if (selects.length) {
            return selects.every(function (sel) {
                return approverSelectUserId(sel) > 0;
            });
        }
        const saved = approvalsForCurrentList();
        return saved.length > 0 && saved.every(function (item) {
            return Number(item.user_id) > 0;
        });
    }

    function selectRowIsVisible(sel) {
        if (!sel || !sel.isConnected) {
            return false;
        }
        let node = sel;
        while (node && node !== document.body) {
            if (node.classList && node.classList.contains('d-none')) {
                return false;
            }
            if (node.style && (node.style.display === 'none' || node.style.visibility === 'hidden')) {
                return false;
            }
            node = node.parentElement;
        }
        return true;
    }

    function currentUserCanApprove() {
        // Approve / Send Back / Reject only after Send for Approval (status pending).
        const status = currentWorkflowStatus();
        if (status !== 'pending') {
            return false;
        }
        const current = currentPendingApproval();
        return !!(current && Number(current.user_id) === Number(neqCurrentUserId));
    }

    function currentUserCanSave() {
        if (!currentUploaderId || Number(currentUploaderId) !== Number(neqCurrentUserId)) {
            return false;
        }
        const status = currentWorkflowStatus();
        return status === 'draft' || status === 'returned' || !status;
    }

    function currentUserCanSendForApproval() {
        return currentUserCanSave() && allApproversSelected();
    }

    function hideNeqDecisionBox() {
        const box = document.getElementById('neqDecisionBox');
        const remark = document.getElementById('neqDecisionRemark');
        const hint = document.getElementById('neqDecisionHint');
        const warning = document.getElementById('neqRejectWarning');
        if (box) {
            box.classList.add('d-none');
        }
        if (remark) {
            remark.value = '';
            remark.classList.remove('is-invalid');
        }
        if (hint) {
            hint.classList.add('d-none');
        }
        if (warning) {
            warning.classList.add('d-none');
        }
        currentDecisionType = null;
    }

    function showNeqDecisionBox(type) {
        const box = document.getElementById('neqDecisionBox');
        const label = document.getElementById('neqDecisionLabel');
        const remark = document.getElementById('neqDecisionRemark');
        const hint = document.getElementById('neqDecisionHint');
        const warning = document.getElementById('neqRejectWarning');
        const confirmBtn = document.getElementById('neqDecisionConfirm');
        currentDecisionType = type;
        if (!box) {
            return;
        }
        box.classList.remove('d-none');
        if (label) {
            label.innerHTML = (type === 'reject' ? 'Reject Reason' : 'Send Back Reason')
                + ' <span class="text-danger">*</span>';
        }
        if (remark) {
            remark.placeholder = type === 'reject'
                ? 'Enter reason for rejection...'
                : 'Enter reason to send back for correction...';
            remark.classList.remove('is-invalid');
            remark.focus();
        }
        if (hint) {
            hint.classList.add('d-none');
        }
        if (warning) {
            warning.classList.toggle('d-none', type !== 'reject');
        }
        if (confirmBtn) {
            confirmBtn.textContent = type === 'reject' ? 'Confirm Reject' : 'Confirm Send Back';
            confirmBtn.className = 'btn btn-sm mt-3 ' + (type === 'reject' ? 'btn-danger' : 'btn-warning');
        }
        box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function updateApprovalActionButtons() {
        const canApprove = currentUserCanApprove();
        const canSave = currentUserCanSave();
        const canSend = currentUserCanSendForApproval();
        const workflowBox = document.getElementById('neqWorkflowActions');
        const workflowTitle = document.getElementById('neqWorkflowActionsTitle');
        const showWorkflow = hasActivePreview && (canApprove || canSave);
        if (workflowBox) {
            workflowBox.classList.toggle('d-none', !showWorkflow);
        }
        if (workflowTitle) {
            if (canApprove) {
                workflowTitle.textContent = 'You are the current approver';
                workflowTitle.className = 'mb-3 text-success';
            } else if (canSave) {
                workflowTitle.textContent = 'Send for Approval';
                workflowTitle.className = 'mb-3';
            }
        }
        document.querySelectorAll('.js-neq-approve-btn').forEach(function (btn) {
            btn.classList.toggle('d-none', !canApprove);
        });
        document.querySelectorAll('.js-neq-send-back-btn').forEach(function (btn) {
            btn.classList.toggle('d-none', !canApprove);
        });
        document.querySelectorAll('.js-neq-reject-btn').forEach(function (btn) {
            btn.classList.toggle('d-none', !canApprove);
        });
        if (btnSaveAs) {
            btnSaveAs.classList.toggle('d-none', !canSave);
        }
        const btnFloatEdit = document.getElementById('btnFloatEditContent');
        const btnAddParagraph = document.getElementById('btnAddParagraph');
        if (btnFloatEdit) {
            btnFloatEdit.classList.toggle('d-none', !canSave);
            btnFloatEdit.disabled = !canSave;
        }
        if (btnAddParagraph) {
            btnAddParagraph.classList.toggle('d-none', !canSave || !isEditingEnabled);
            btnAddParagraph.disabled = !canSave || !isEditingEnabled;
        }
        const btnEsignStamp = document.getElementById('btnEsignStamp');
        if (btnEsignStamp) {
            btnEsignStamp.classList.toggle('d-none', !canSave);
            btnEsignStamp.disabled = !canSave;
        }
        if (btnSendForApproval) {
            btnSendForApproval.classList.toggle('d-none', !canSave);
            btnSendForApproval.disabled = !canSave;
            btnSendForApproval.title = canSend
                ? 'Send for approval — editing will lock until Send Back'
                : 'Select all approvers before sending for approval';
        }
        if (btnEnableEditing) {
            btnEnableEditing.classList.toggle('d-none', !canSave);
        }
        if (!canApprove) {
            hideNeqDecisionBox();
        }
        if (!canSave && isEditingEnabled) {
            disableEditing();
            showProtectedViewBanner();
        }
    }

    function chosenApproverSelect(row, nameCell) {
        const pool = [];
        if (nameCell && nameCell.querySelectorAll) {
            nameCell.querySelectorAll('select.neq-approver-select').forEach(function (sel) {
                pool.push(sel);
            });
        }
        if (row && row.querySelectorAll) {
            row.querySelectorAll('select.neq-approver-select').forEach(function (sel) {
                if (pool.indexOf(sel) === -1) {
                    pool.push(sel);
                }
            });
        }
        const visible = pool.filter(selectRowIsVisible);
        const source = visible.length ? visible : pool;
        return source.find(function (sel) { return Number(sel.value) > 0; }) || source[0] || null;
    }

    function collectApprovers() {
        const ops = [];
        if (!previewArea) {
            return ops;
        }

        // Optional section Verified-by / Safety By banner dropdowns (only when chosen).
        previewArea.querySelectorAll('select.neq-section-verified-select').forEach(function (sel) {
            const uid = approverSelectUserId(sel);
            if (uid <= 0) {
                return;
            }
            ops.push({
                user_id: uid,
                role: sel.dataset.neqRole || 'Verified by',
                table: Number(sel.dataset.neqTable || 0),
                row: Number(sel.dataset.neqRow || 0),
                name_col: 0,
                department_col: null,
                name_includes_designation: false,
                signature_col: 0,
                date_col: 0,
                section_verified: true,
            });
        });

        previewArea.querySelectorAll('select.neq-safety-by-select').forEach(function (sel) {
            const uid = approverSelectUserId(sel);
            if (uid <= 0) {
                return;
            }
            ops.push({
                user_id: uid,
                role: sel.dataset.neqRole || 'Safety By',
                table: Number(sel.dataset.neqTable || 0),
                row: Number(sel.dataset.neqRow || 0),
                name_col: 0,
                department_col: null,
                name_includes_designation: false,
                signature_col: 0,
                date_col: 0,
                safety_by: true,
            });
        });

        // Required signatory table approver rows.
        previewArea.querySelectorAll('select.neq-approver-select[data-neq-approver-slot="1"]').forEach(function (sel) {
            const uid = approverSelectUserId(sel);
            const table = Number(sel.dataset.neqTable || 0);
            const row = Number(sel.dataset.neqRow || 0);
            const nameCol = Number(sel.dataset.neqNameCol || 0);
            const deptColRaw = sel.dataset.neqDepartmentCol;
            const deptCol = (deptColRaw !== undefined && deptColRaw !== '') ? Number(deptColRaw) : null;
            const sigCol = Number(sel.dataset.neqSignatureCol || nameCol);
            const dateCol = Number(sel.dataset.neqDateCol || sigCol);
            ops.push({
                user_id: uid > 0 ? uid : null,
                role: sel.dataset.neqRole || '',
                table: table,
                row: row,
                name_col: nameCol,
                department_col: deptCol,
                name_includes_designation: sel.dataset.neqNameCombined === '1',
                signature_col: sigCol,
                date_col: dateCol,
            });
        });

        // Validation / Quality Assurance paragraph stamp (Attachment 01).
        previewArea.querySelectorAll('select.neq-qa-approver-select').forEach(function (qaSel) {
            const uid = approverSelectUserId(qaSel);
            if (uid <= 0) {
                return;
            }
            const paraTargetIdx = qaSel.dataset.neqParaTargetIndex !== undefined && qaSel.dataset.neqParaTargetIndex !== ''
                ? Number(qaSel.dataset.neqParaTargetIndex)
                : null;
            ops.push({
                user_id: uid,
                role: 'Validation / Quality Assurance',
                table: 900000,
                row: 900000 + (Number.isInteger(paraTargetIdx) && paraTargetIdx >= 0 ? paraTargetIdx : 0),
                name_col: 0,
                department_col: null,
                signature_col: 0,
                date_col: 0,
                paragraph_index: Number.isInteger(paraTargetIdx) && paraTargetIdx >= 0 ? paraTargetIdx : null,
                qa_stamp: true,
            });
        });

        // Fallback to previously saved approvals when rendered read-only without select widgets.
        if (!ops.length) {
            const saved = approvalsForCurrentList();
            if (saved.length) {
                return saved.map(function (item) {
                    return {
                        user_id: item.user_id || null,
                        role: item.role || '',
                        table: Number(item.table || 0),
                        row: Number(item.row || 0),
                        name_col: item.name_col !== undefined ? item.name_col : 0,
                        department_col: item.department_col !== undefined ? item.department_col : null,
                        name_includes_designation: !!item.name_includes_designation,
                        signature_col: item.signature_col !== undefined ? item.signature_col : 0,
                        date_col: item.date_col !== undefined ? item.date_col : 0,
                        paragraph_index: item.paragraph_index !== undefined ? item.paragraph_index : null,
                        qa_stamp: !!item.qa_stamp,
                        section_verified: !!item.section_verified,
                        safety_by: !!item.safety_by,
                    };
                });
            }
        }

        const byKey = new Map();
        ops.forEach(function (op, index) {
            op._order = index;
            const key = String(op.table) + ':' + String(op.row) + ':' + String(op.role || '');
            const prev = byKey.get(key);
            if (!prev || (!prev.user_id && op.user_id)) {
                byKey.set(key, op);
            }
        });
        const ranked = Array.from(byKey.values());
        function approvalRoleRank(role) {
            const text = String(role || '').toLowerCase();
            if (/\bprepared\s*by\b/.test(text)) return 1;
            if (/\bchecked\s*by\b/.test(text)) return 2;
            if (/\breviewed\s*by\b/.test(text)) return 3;
            if (/\bapproved\s*by\b/.test(text)) return 4;
            return 0;
        }
        ranked.sort(function (a, b) {
            const rankA = approvalRoleRank(a.role);
            const rankB = approvalRoleRank(b.role);
            if ((rankA === 0) !== (rankB === 0)) return rankA === 0 ? -1 : 1;
            if (rankA !== rankB) return rankA - rankB;
            if (rankA > 0 && Number(a.table) !== Number(b.table)) return Number(a.table) - Number(b.table);
            if (rankA > 0 && Number(a.row) !== Number(b.row)) return Number(a.row) - Number(b.row);
            return a._order - b._order;
        });
        ranked.forEach(function (op) { delete op._order; });
        return ranked;
    }

    function approvalSectionTitle(table) {
        const parts = typeof tableParts === 'function' ? tableParts(table) : [table];
        for (let i = 0; i < parts.length; i++) {
            const rows = getDirectTableRows(parts[i]);
            for (let r = 0; r < rows.length; r++) {
                const text = normalizeEditText(rows[r].innerText || rows[r].textContent || '').toLowerCase();
                if (/^prepared\s*by\b/.test(text)) {
                    return 'Prepared By';
                }
                if (/^checked\s*by\b/.test(text)) {
                    return 'Checked By';
                }
                if (/^reviewed\s*by\b/.test(text)) {
                    return 'Reviewed By';
                }
                if (/^approved\s*by\b/.test(text)) {
                    return 'Approved By';
                }
            }
        }
        return '';
    }

    function resolveQaParagraphIndexFromTargets(host) {
        const needle = normalizeEditText(
            (host && (host.getAttribute('data-neq-qa-base') || host.getAttribute('data-neq-original') || host.innerText || '')) || ''
        ).toLowerCase();
        const targets = Array.isArray(docxTextTargets) ? docxTextTargets : [];
        let best = null;
        targets.forEach(function (target) {
            const text = normalizeEditText(target && target.text || '').toLowerCase();
            if (!/validation\s*\/?\s*quality\s*assurance/.test(text)) {
                return;
            }
            const index = Number(target.index);
            if (!Number.isInteger(index) || index < 0) {
                return;
            }
            if (needle && text === needle) {
                best = index;
                return;
            }
            if (best === null) {
                best = index;
            }
        });
        return best;
    }

    function resolveQaStampTarget(qaSelect) {
        const hostMount = qaSelect
            ? (qaSelect.closest('[data-neq-qa-host="1"]') || qaSelect.parentElement)
            : (previewArea && previewArea.querySelector('[data-neq-qa-host="1"]'));
        const host = hostMount
            ? (hostMount.closest('p, td, th') || hostMount.parentElement || hostMount)
            : findValidationQaHostNode();
        let paraIndex = hostMount ? Number(hostMount.getAttribute('data-neq-qa-para-index')) : NaN;
        if (!Number.isInteger(paraIndex) || paraIndex < 0) {
            paraIndex = host ? Number(host.getAttribute('data-neq-index')) : NaN;
        }
        if (!Number.isInteger(paraIndex) || paraIndex < 0) {
            const mapped = resolveQaParagraphIndexFromTargets(host || hostMount);
            if (Number.isInteger(mapped) && mapped >= 0) {
                paraIndex = mapped;
            }
        }
        if (hostMount && Number.isInteger(paraIndex) && paraIndex >= 0) {
            hostMount.setAttribute('data-neq-qa-para-index', String(paraIndex));
        }
        if (host && Number.isInteger(paraIndex) && paraIndex >= 0 && !host.getAttribute('data-neq-index')) {
            host.setAttribute('data-neq-index', String(paraIndex));
        }

        const tableIndex = hostMount ? Number(hostMount.getAttribute('data-neq-qa-table')) : NaN;
        const rowIndex = hostMount ? Number(hostMount.getAttribute('data-neq-qa-row')) : NaN;
        const nameCol = hostMount ? Number(hostMount.getAttribute('data-neq-qa-name-col')) : NaN;
        const signatureCol = hostMount ? Number(hostMount.getAttribute('data-neq-qa-signature-col')) : NaN;
        const dateCol = hostMount ? Number(hostMount.getAttribute('data-neq-qa-date-col')) : NaN;
        const hasCellTarget = Number.isInteger(tableIndex) && tableIndex >= 0
            && Number.isInteger(rowIndex) && rowIndex >= 0
            && Number.isInteger(nameCol) && nameCol >= 0
            && tableIndex < 900000;

        return {
            host: host,
            hostMount: hostMount,
            paraIndex: Number.isInteger(paraIndex) && paraIndex >= 0 ? paraIndex : null,
            hasCellTarget: hasCellTarget,
            tableIndex: tableIndex,
            rowIndex: rowIndex,
            nameCol: nameCol,
            signatureCol: signatureCol,
            dateCol: dateCol,
        };
    }

    function approvalCellLiveText(cell) {
        if (!cell) {
            return '';
        }
        if (cell.querySelector && cell.querySelector('select.neq-approver-select')) {
            return '';
        }
        return normalizeEditText(cell.innerText || cell.textContent || '');
    }

    function cellLooksLikeDocxApprovalStamp(nameCell, signatureCell, dateCell) {
        const name = approvalCellLiveText(nameCell);
        const sig = approvalCellLiveText(signatureCell).toLowerCase();
        const date = approvalCellLiveText(dateCell);
        const combined = signatureCell && dateCell && signatureCell === dateCell
            ? approvalCellLiveText(signatureCell)
            : '';
        if (/^approved\b|^rejected\b/.test(sig) || /\bapproved\b|\brejected\b/i.test(combined)) {
            return true;
        }
        if (/\d{1,2}-\d{1,2}-\d{2,4}/.test(date) && name && !/^--\s*select/.test(name.toLowerCase())) {
            return true;
        }
        if (/\d{1,2}-\d{1,2}-\d{2,4}/.test(combined) && /\bapproved\b|\brejected\b/i.test(combined)) {
            return true;
        }
        // Name already stamped from Word (e.g. "Amit User - Regulatory Affairs") with non-empty sig/date.
        if (name && / - /.test(name) && (sig || date || combined) && !/^—$|^-$/.test(sig) && !/^—$|^-$/.test(date)) {
            if (/approved|rejected|\d{1,2}-\d{1,2}-\d{2,4}/i.test(sig + ' ' + date + ' ' + combined)) {
                return true;
            }
        }
        return false;
    }

    function approverNameCellText(user, nameCombined) {
        if (!user) {
            return '';
        }
        const full = normalizeEditText(user.full_name || '');
        const designation = normalizeEditText(user.designation || '');
        if (nameCombined && designation) {
            return full ? (full + ' - ' + designation) : designation;
        }
        if (full) {
            return full;
        }
        return normalizeEditText(user.name || '').replace(/\s+-\s+.+$/, '').trim();
    }

    function pinApproverSelect(select) {
        if (!select) {
            return;
        }
        const value = select.value ? String(select.value) : '';
        if (value) {
            select.setAttribute('data-neq-picked-user', value);
        } else {
            select.removeAttribute('data-neq-picked-user');
        }
        const row = select.closest ? select.closest('tr') : null;
        if (row && select.classList.contains('neq-approver-select')
            && !select.classList.contains('neq-safety-by-select')
            && !select.classList.contains('neq-section-verified-select')
            && !select.classList.contains('neq-qa-approver-select')) {
            if (value) {
                row.setAttribute('data-neq-picked-user', value);
            } else {
                row.removeAttribute('data-neq-picked-user');
            }
        }
        Array.from(select.options || []).forEach(function (opt) {
            if (value && opt.value === value) {
                opt.setAttribute('selected', 'selected');
            } else {
                opt.removeAttribute('selected');
            }
        });
    }

    function restorePinnedApproverSelects(root) {
        if (!root || !root.querySelectorAll) {
            return;
        }
        root.querySelectorAll('select.neq-approver-select, select.neq-qa-approver-select, select.neq-training-name-select, select.neq-training-by-select').forEach(function (select) {
            const row = select.closest ? select.closest('tr') : null;
            const pinned = select.getAttribute('data-neq-picked-user')
                || (select.classList.contains('neq-training-by-select')
                    ? ''
                    : (row ? (row.getAttribute('data-neq-picked-user') || '') : ''));
            if (!pinned) {
                return;
            }
            if (select.value !== pinned) {
                select.value = pinned;
            }
            pinApproverSelect(select);
        });
    }

    function copySelectValues(fromRoot, toRoot) {
        if (!fromRoot || !toRoot || !fromRoot.querySelectorAll || !toRoot.querySelectorAll) {
            return;
        }
        const source = fromRoot.querySelectorAll('select');
        const copy = toRoot.querySelectorAll('select');
        const count = Math.min(source.length, copy.length);
        for (let i = 0; i < count; i++) {
            const value = source[i].value ? String(source[i].value) : '';
            if (!value) {
                continue;
            }
            copy[i].value = value;
            copy[i].setAttribute('data-neq-picked-user', value);
            pinApproverSelect(copy[i]);
        }
        // datetime-local keeps the picked time on the element, not in HTML.
        // A page split used to clone an empty Date cell, so refresh lost it.
        const sourceDates = fromRoot.querySelectorAll('input.neq-training-date-input');
        const copyDates = toRoot.querySelectorAll('input.neq-training-date-input');
        const dateCount = Math.min(sourceDates.length, copyDates.length);
        for (let d = 0; d < dateCount; d++) {
            const picked = sourceDates[d].value || '';
            const display = sourceDates[d].getAttribute('data-neq-display') || '';
            if (picked) {
                copyDates[d].value = picked;
            }
            if (display) {
                copyDates[d].setAttribute('data-neq-display', display);
            }
            const sourceCell = sourceDates[d].closest ? sourceDates[d].closest('td, th') : null;
            const copyCell = copyDates[d].closest ? copyDates[d].closest('td, th') : null;
            if (sourceCell && copyCell && sourceCell.getAttribute('data-neq-user-edited') === '1') {
                copyCell.setAttribute('data-neq-user-edited', '1');
            }
        }
    }

    function paintApproverDepartmentFromSelect(departmentCell, select) {
        if (!departmentCell) {
            return;
        }
        const userId = select && select.value ? String(select.value) : '';
        const user = userId
            ? (neqApproverUsers || []).find(function (item) {
                return String(item.id) === userId;
            })
            : null;
        departmentCell.innerHTML = '';
        departmentCell.textContent = user ? normalizeEditText(user.designation || '') : '';
        departmentCell.removeAttribute('contenteditable');
    }

    function paintCenteredCellText(cell, text) {
        if (!cell) {
            return;
        }
        const value = text || '';
        const para = cell.querySelector('p');
        if (para && !para.querySelector('table, select')) {
            para.textContent = value;
        } else {
            cell.textContent = value;
        }
        cell.style.setProperty('text-align', 'center', 'important');
        cell.querySelectorAll('p').forEach(function (block) {
            block.style.setProperty('text-align', 'center', 'important');
        });
        cell.removeAttribute('contenteditable');
    }

    function paintApprovalStampCells(nameCell, signatureCell, dateCell, nameText, sigLabel, dateLabel, departmentCell, departmentText) {
        if (nameCell) {
            paintCenteredCellText(nameCell, nameText || '');
        }
        if (departmentCell) {
            paintCenteredCellText(departmentCell, departmentText || '');
        }
        const approvedWord = /^approved$/i.test(normalizeEditText(sigLabel || ''));
        if (approvedWord) {
            sigLabel = [nameText || '', dateLabel || ''].filter(Boolean).join('\n');
        }
        if (signatureCell && dateCell && signatureCell === dateCell) {
            signatureCell.innerHTML = '';
            const sig = document.createElement('span');
            sig.className = 'neq-signature-font';
            sig.textContent = sigLabel || '';
            signatureCell.appendChild(sig);
            if (dateLabel && !approvedWord && sigLabel.indexOf(dateLabel) < 0) {
                signatureCell.appendChild(document.createElement('br'));
                signatureCell.appendChild(document.createTextNode(dateLabel));
            }
            signatureCell.removeAttribute('contenteditable');
            return;
        }
        if (signatureCell) {
            signatureCell.innerHTML = '';
            const sig = document.createElement('span');
            sig.className = 'neq-signature-font';
            sig.style.whiteSpace = 'pre-line';
            sig.textContent = sigLabel || '';
            signatureCell.appendChild(sig);
            signatureCell.removeAttribute('contenteditable');
        }
        if (dateCell) {
            dateCell.innerHTML = '';
            dateCell.textContent = dateLabel || '';
            dateCell.removeAttribute('contenteditable');
        }
    }

    function findSavedApprovalFlexible(tableIndex, rowIndex, nameCell) {
        let saved = Number.isInteger(rowIndex) ? findSavedApproval(tableIndex, rowIndex) : null;
        if (saved && (saved.approved_at || saved.rejected_at || saved.user_id)) {
            return saved;
        }
        const liveName = approvalCellLiveText(nameCell).toLowerCase();
        if (!liveName) {
            return saved;
        }
        const matches = approvalsForCurrentList().filter(function (item) {
            return Number(item.table) === Number(tableIndex)
                && item.name_text
                && normalizeEditText(item.name_text).toLowerCase() === liveName;
        });
        const decided = matches.find(function (item) {
            return item.approved_at || item.rejected_at;
        });
        return decided || matches[0] || saved;
    }

    function bindApproverDropdowns() {
        // Handled cleanly inside hydrateCachedApprovers() when cached preview renders.
        updateApprovalActionButtons();
    }

    function isSafetyBySignDateRowText(text) {
        const t = normalizeEditText(text || '');
        if (!t || t.length > 180) {
            return false;
        }
        // "Safety By (Sign./Date)", "Safety Checks By (Sign./Date)", "Safety s By ..."
        return /^safety\s*(?:checks?\s*)?s?\s*by\b/i.test(t) && /sign/i.test(t);
    }

    function safetyByCleanLabel() {
        return 'Safety By (Sign./Date)';
    }

    function signerNameAndTime(name, sig, when) {
        const n = normalizeEditText(name || '');
        const s = normalizeEditText(sig || '');
        const d = normalizeEditText(when || '');
        if (/^rejected$/i.test(s)) {
            return [n, 'Rejected', d].filter(Boolean).join(' / ');
        }
        // A chosen name is not an approval. Date and time wait until Approve.
        if (!s || s === '—' || s === '-') {
            return n;
        }
        return [n, d].filter(Boolean).join(' ');
    }

    function safetyByStampDisplayText(name, sig, when) {
        const n = normalizeEditText(name || '');
        if (!n) {
            return safetyByCleanLabel();
        }
        return safetyByCleanLabel() + ' : ' + signerNameAndTime(name, sig, when);
    }

    function isSafetyByStampText(text) {
        const t = normalizeEditText(text || '');
        return /^safety\s*(?:checks?\s*)?s?\s*by\b/i.test(t) && /\b(approved|rejected)\b/i.test(t);
    }

    function collapseSafetyByPreviewRow(row, cells) {
        if (!row || !cells || !cells.length) {
            return cells && cells[0] ? cells[0] : null;
        }
        const first = cells[0];
        let extra = '';
        for (let i = 1; i < cells.length; i++) {
            const t = normalizeEditText(cellLiveText(cells[i]) || '');
            if (t && t !== '—' && t !== '-') {
                extra = extra ? (extra + ' / ' + t) : t;
            }
            cells[i].style.display = 'none';
            cells[i].setAttribute('data-neq-safety-hidden', '1');
            cells[i].removeAttribute('contenteditable');
        }
        first.setAttribute('colspan', String(cells.length));
        first.classList.add('neq-safety-by-cell');
        if (extra && !isSafetyByStampText(normalizeEditText(cellLiveText(first) || ''))) {
            const base = safetyByCleanLabel();
            const live = normalizeEditText(cellLiveText(first) || '');
            if (isSafetyBySignDateRowText(live) || !live) {
                first.setAttribute('data-neq-safety-pending-extra', extra);
            }
        }
        return first;
    }

    function findSavedSafetyByApproval(tableIndex, rowIndex) {
        const list = approvalsForCurrentList();
        let saved = Number.isInteger(rowIndex) ? findSavedApproval(tableIndex, rowIndex) : null;
        if (saved && /^safety(\s*checks?)?\s*by\b/i.test(String(saved.role || ''))) {
            return saved;
        }
        return list.find(function (item) {
            return Number(item.table) === Number(tableIndex)
                && Number(item.row) === Number(rowIndex)
                && /^safety(\s*checks?)?\s*by\b/i.test(String(item.role || ''));
        }) || null;
    }

    function bindSafetyByApprovers() {
        stampNeqTables();
        const allowSelect = currentUserCanSave();
        getMappablePreviewTables().forEach(function (table) {
            if (!table || isApprovalOrSignatoryTable(table) || isLetterheadIdentityTable(table)) {
                return;
            }
            if (getNodeZone(table) !== 'body') {
                return;
            }
            const tableIndex = Number(table.getAttribute('data-neq-table')) || 0;
            getDirectTableRows(table).forEach(function (row, absIndex) {
                const cells = getRowCells(row);
                if (!cells.length) {
                    return;
                }
                const probe = cells[0];
                const liveRow = normalizeEditText(row.innerText || '');
                const liveLabel = normalizeEditText(cellLiveText(probe) || '');
                const originalLabel = cellOriginalText(probe) || '';
                const existingHost = row.querySelector('.neq-safety-by-host');
                if (!isSafetyBySignDateRowText(liveLabel)
                    && !isSafetyBySignDateRowText(originalLabel)
                    && !isSafetyBySignDateRowText(liveRow)
                    && !isSafetyByStampText(liveLabel)
                    && !isSafetyByStampText(liveRow)
                    && !existingHost) {
                    return;
                }

                const cell = collapseSafetyByPreviewRow(row, cells);
                if (!cell) {
                    return;
                }
                const stampedRow = Number(row.getAttribute('data-neq-row'));
                const rowIndex = Number.isInteger(stampedRow) && stampedRow >= 0 ? stampedRow : absIndex;
                const saved = findSavedSafetyByApproval(tableIndex, rowIndex);
                const pendingExtra = cell.getAttribute('data-neq-safety-pending-extra') || '';
                cell.removeAttribute('data-neq-safety-pending-extra');

                cell.removeAttribute('contenteditable');
                cell.querySelectorAll('[contenteditable="true"]').forEach(function (block) {
                    block.removeAttribute('contenteditable');
                });

                let liveText = normalizeEditText(cellLiveText(cell) || '');
                if (pendingExtra && !isSafetyByStampText(liveText)) {
                    liveText = safetyByCleanLabel() + ' : ' + pendingExtra;
                }

                if (isSafetyByStampText(liveText)
                    || (saved && (saved.approved_at || saved.rejected_at))) {
                    const name = (saved && saved.name_text) || '';
                    const sig = (saved && saved.signature_text)
                        || (saved && saved.rejected_at ? 'Rejected' : 'Approved');
                    const when = (saved && (saved.date_text || saved.approved_at || saved.rejected_at)) || '';
                    const line = isSafetyByStampText(liveText)
                        ? liveText
                        : safetyByStampDisplayText(name, sig, when);
                    cell.innerHTML = '';
                    cell.textContent = line;
                    if (!cell.getAttribute('data-neq-original')) {
                        cell.setAttribute('data-neq-original', safetyByCleanLabel());
                    }
                    return;
                }

                if (!allowSelect) {
                    if (saved && saved.user_id) {
                        const user = (neqApproverUsers || []).find(function (u) {
                            return Number(u.id) === Number(saved.user_id);
                        });
                        const name = user
                            ? (user.full_name || user.name || user.label || '')
                            : (saved.name_text || '');
                        cell.innerHTML = '';
                        cell.textContent = safetyByStampDisplayText(name, '—', '');
                    }
                    return;
                }

                let host = cell.querySelector('.neq-safety-by-host');
                if (!host) {
                    cell.innerHTML = '';
                    cell.appendChild(document.createTextNode(safetyByCleanLabel() + ' : '));
                    host = document.createElement('span');
                    host.className = 'neq-safety-by-host';
                    host.setAttribute('data-neq-sb-host', '1');
                    cell.appendChild(host);
                }
                host.setAttribute('data-neq-sb-role', 'Safety By');
                host.setAttribute('data-neq-sb-table', String(tableIndex));
                host.setAttribute('data-neq-sb-row', String(rowIndex));

                let select = host.querySelector('select.neq-safety-by-select');
                if (!select) {
                    select = document.createElement('select');
                    select.className = 'neq-approver-select neq-safety-by-select';
                    const blank = document.createElement('option');
                    blank.value = '';
                    blank.textContent = '-- Select User --';
                    select.appendChild(blank);
                    (neqApproverUsers || []).forEach(function (user) {
                        const opt = document.createElement('option');
                        opt.value = String(user.id);
                        opt.textContent = user.label;
                        opt.setAttribute('data-name', user.full_name || user.name || user.label);
                        select.appendChild(opt);
                    });
                    host.appendChild(select);
                    select.addEventListener('change', function () {
                        if (select.value) {
                            host.setAttribute('data-neq-sb-user-id', String(select.value));
                        } else {
                            host.removeAttribute('data-neq-sb-user-id');
                        }
                        updateApprovalActionButtons();
                    });
                }
                if (saved && saved.user_id) {
                    select.value = String(saved.user_id);
                    host.setAttribute('data-neq-sb-user-id', String(saved.user_id));
                }
                if (!cell.getAttribute('data-neq-original')) {
                    cell.setAttribute('data-neq-original', safetyByCleanLabel());
                }
            });
        });
        updateApprovalActionButtons();
    }

    function collectSafetyByApprovers() {
        const ops = [];
        const seen = new Set();
        getMappablePreviewTables().forEach(function (table) {
            if (!table || isApprovalOrSignatoryTable(table) || isLetterheadIdentityTable(table)) {
                return;
            }
            if (getNodeZone(table) !== 'body') {
                return;
            }
            const tableIndex = Number(table.getAttribute('data-neq-table')) || 0;
            getDirectTableRows(table).forEach(function (row, absIndex) {
                const cells = getRowCells(row);
                if (!cells.length) {
                    return;
                }
                const cell = cells[0];
                const liveText = normalizeEditText(cellLiveText(cell) || row.innerText || '');
                const original = cellOriginalText(cell) || '';
                const host = row.querySelector('.neq-safety-by-host');
                const select = row.querySelector('select.neq-safety-by-select');
                if (!host && !select
                    && !isSafetyBySignDateRowText(liveText)
                    && !isSafetyBySignDateRowText(original)
                    && !isSafetyByStampText(liveText)) {
                    return;
                }

                const stampedRow = Number(row.getAttribute('data-neq-row'));
                const rowIndex = Number.isInteger(stampedRow) && stampedRow >= 0 ? stampedRow : absIndex;
                const key = tableIndex + ':' + rowIndex;
                if (seen.has(key)) {
                    return;
                }
                seen.add(key);

                let userId = 0;
                if (select && select.value) {
                    userId = Number(select.value);
                } else if (host && host.getAttribute('data-neq-sb-user-id')) {
                    userId = Number(host.getAttribute('data-neq-sb-user-id'));
                } else {
                    const saved = findSavedSafetyByApproval(tableIndex, rowIndex);
                    if (saved && saved.user_id) {
                        userId = Number(saved.user_id);
                    }
                }

                if (userId <= 0) {
                    return;
                }
                ops.push({
                    user_id: userId,
                    role: 'Safety By',
                    table: tableIndex,
                    row: rowIndex,
                    name_col: 0,
                    department_col: null,
                    signature_col: 0,
                    date_col: 0,
                    safety_by: true,
                });
            });
        });
        return ops;
    }

    function sectionVerifiedStampDisplayText(name, sig, when) {
        const n = normalizeEditText(name || '');
        if (!n) {
            return 'Verified by sign and date :';
        }
        return 'Verified by sign and date : ' + signerNameAndTime(name, sig, when);
    }

    function isSectionVerifiedStampText(text) {
        const t = normalizeEditText(text || '');
        return /^verified\s*by\b/i.test(t) && /\b(approved|rejected)\b/i.test(t);
    }

    function sectionVerifiedRoleLabel(sectionLabel) {
        const label = normalizeEditText(sectionLabel || 'Section') || 'Section';
        return 'Verified By (' + label + ')';
    }

    function resolveSectionLabelFromBannerText(text) {
        const t = normalizeEditText(text || '');
        if (/^main\s*body/i.test(t)) {
            return 'Main Body';
        }
        if (/mechanical/i.test(t)) {
            return 'Mechanical Components';
        }
        if (/electrical/i.test(t)) {
            return 'Electrical Components';
        }
        return t ? t.slice(0, 48) : 'Section';
    }

    function findSavedSectionVerifiedApproval(tableIndex, rowIndex, roleLabel) {
        const list = approvalsForCurrentList();
        let saved = Number.isInteger(rowIndex) ? findSavedApproval(tableIndex, rowIndex) : null;
        if (saved && /^verified\s*by\b/i.test(String(saved.role || ''))) {
            return saved;
        }
        if (roleLabel) {
            const byRole = list.find(function (item) {
                return /^verified\s*by\b/i.test(String(item.role || ''))
                    && normalizeEditText(item.role || '').toLowerCase()
                        === normalizeEditText(roleLabel).toLowerCase();
            });
            if (byRole) {
                return byRole;
            }
        }
        return list.find(function (item) {
            return Number(item.table) === Number(tableIndex)
                && Number(item.row) === Number(rowIndex)
                && /^verified\s*by\b/i.test(String(item.role || ''));
        }) || null;
    }

    function bindSectionVerifiedByApprovers() {
        // Attachment 07: "Verified by sign and date" is a normal text cell.
        // Do not replace those three rows with a user dropdown.
        return;
        const allowSelect = currentUserCanSave();
        getMappablePreviewTables().forEach(function (table) {
            if (!isComponentSubsystemMatrixTable(table) || getNodeZone(table) !== 'body') {
                return;
            }
            const tableIndex = Number(table.getAttribute('data-neq-table')) || 0;
            let sectionLabel = 'Section';
            getDirectTableRows(table).forEach(function (row, absIndex) {
                const cells = getRowCells(row);
                if (!cells.length) {
                    return;
                }
                const cell = cells[0];
                const liveText = normalizeEditText(cellLiveText(cell) || row.innerText || '');
                const original = cellOriginalText(cell) || '';
                const hasHost = !!cell.querySelector('.neq-section-verified-host');

                if ((isComponentSectionBannerText(liveText) || isComponentSectionBannerText(original))
                    && !isVerifiedByRowText(liveText) && !isVerifiedByRowText(original)
                    && !hasHost) {
                    sectionLabel = resolveSectionLabelFromBannerText(liveText || original);
                    return;
                }

                const isVerified = isVerifiedByRowText(liveText)
                    || isVerifiedByRowText(original)
                    || hasHost
                    || isSectionVerifiedStampText(liveText);
                if (!isVerified) {
                    return;
                }

                const stampedRow = Number(row.getAttribute('data-neq-row'));
                const rowIndex = Number.isInteger(stampedRow) && stampedRow >= 0 ? stampedRow : absIndex;
                const roleLabel = sectionVerifiedRoleLabel(sectionLabel);
                const saved = findSavedSectionVerifiedApproval(tableIndex, rowIndex, roleLabel);

                cell.classList.add('neq-section-verified-cell');
                cell.removeAttribute('contenteditable');
                cell.querySelectorAll('[contenteditable="true"]').forEach(function (block) {
                    block.removeAttribute('contenteditable');
                });

                // Already stamped in Word / after approve — show one line only.
                if (isSectionVerifiedStampText(liveText)
                    || (saved && (saved.approved_at || saved.rejected_at))) {
                    const name = (saved && saved.name_text) || '';
                    const sig = (saved && saved.signature_text)
                        || (saved && saved.rejected_at ? 'Rejected' : 'Approved');
                    const when = (saved && (saved.date_text || saved.approved_at || saved.rejected_at)) || '';
                    const line = isSectionVerifiedStampText(liveText)
                        ? liveText
                        : sectionVerifiedStampDisplayText(name, sig, when);
                    cell.innerHTML = '';
                    cell.textContent = line;
                    if (!cell.getAttribute('data-neq-original')) {
                        cell.setAttribute('data-neq-original', 'Verified by sign and date :');
                    }
                    return;
                }

                if (!allowSelect) {
                    // Assigned but pending — keep label readable for assignees.
                    if (saved && saved.user_id) {
                        const user = (neqApproverUsers || []).find(function (u) {
                            return Number(u.id) === Number(saved.user_id);
                        });
                        const name = user
                            ? (user.full_name || user.name || user.label || '')
                            : (saved.name_text || '');
                        cell.innerHTML = '';
                        cell.textContent = sectionVerifiedStampDisplayText(name, '—', '');
                    }
                    return;
                }

                let host = cell.querySelector('.neq-section-verified-host');
                if (!host) {
                    cell.innerHTML = '';
                    cell.appendChild(document.createTextNode('Verified by sign and date : '));
                    host = document.createElement('span');
                    host.className = 'neq-section-verified-host';
                    host.setAttribute('data-neq-sv-host', '1');
                    cell.appendChild(host);
                }
                host.setAttribute('data-neq-sv-role', roleLabel);
                host.setAttribute('data-neq-sv-table', String(tableIndex));
                host.setAttribute('data-neq-sv-row', String(rowIndex));

                let select = host.querySelector('select.neq-section-verified-select');
                if (!select) {
                    select = document.createElement('select');
                    select.className = 'neq-approver-select neq-section-verified-select';
                    const blank = document.createElement('option');
                    blank.value = '';
                    blank.textContent = '-- Select User --';
                    select.appendChild(blank);
                    (neqApproverUsers || []).forEach(function (user) {
                        const opt = document.createElement('option');
                        opt.value = String(user.id);
                        opt.textContent = user.label;
                        opt.setAttribute('data-name', user.full_name || user.name || user.label);
                        select.appendChild(opt);
                    });
                    host.appendChild(select);
                    select.addEventListener('change', function () {
                        if (select.value) {
                            host.setAttribute('data-neq-sv-user-id', String(select.value));
                        } else {
                            host.removeAttribute('data-neq-sv-user-id');
                        }
                        updateApprovalActionButtons();
                    });
                }
                if (saved && saved.user_id) {
                    select.value = String(saved.user_id);
                    host.setAttribute('data-neq-sv-user-id', String(saved.user_id));
                }
                if (!cell.getAttribute('data-neq-original')) {
                    cell.setAttribute('data-neq-original', 'Verified by sign and date :');
                }
            });
        });
        updateApprovalActionButtons();
    }

    function collectSectionVerifiedApprovers() {
        // Those rows are typed text, not approver picks.
        return [];
        const seen = new Set();
        getMappablePreviewTables().forEach(function (table) {
            if (!isComponentSubsystemMatrixTable(table) || getNodeZone(table) !== 'body') {
                return;
            }
            const tableIndex = Number(table.getAttribute('data-neq-table')) || 0;
            let sectionLabel = 'Section';
            getDirectTableRows(table).forEach(function (row, absIndex) {
                const cells = getRowCells(row);
                if (!cells.length) {
                    return;
                }
                const cell = cells[0];
                const liveText = normalizeEditText(cellLiveText(cell) || row.innerText || '');
                const original = cellOriginalText(cell) || '';
                const host = cell.querySelector('.neq-section-verified-host');

                if ((isComponentSectionBannerText(liveText) || isComponentSectionBannerText(original))
                    && !isVerifiedByRowText(liveText) && !isVerifiedByRowText(original)
                    && !host) {
                    sectionLabel = resolveSectionLabelFromBannerText(liveText || original);
                    return;
                }

                const select = cell.querySelector('select.neq-section-verified-select');
                const isVerified = !!host
                    || !!select
                    || isVerifiedByRowText(liveText)
                    || isVerifiedByRowText(original)
                    || isSectionVerifiedStampText(liveText);
                if (!isVerified) {
                    return;
                }

                const stampedRow = Number(row.getAttribute('data-neq-row'));
                const rowIndex = Number.isInteger(stampedRow) && stampedRow >= 0 ? stampedRow : absIndex;
                const key = tableIndex + ':' + rowIndex;
                if (seen.has(key)) {
                    return;
                }
                seen.add(key);

                const roleLabel = (host && host.getAttribute('data-neq-sv-role'))
                    || sectionVerifiedRoleLabel(sectionLabel);
                let userId = 0;
                if (select && select.value) {
                    userId = Number(select.value);
                } else if (host && host.getAttribute('data-neq-sv-user-id')) {
                    userId = Number(host.getAttribute('data-neq-sv-user-id'));
                } else {
                    const saved = findSavedSectionVerifiedApproval(tableIndex, rowIndex, roleLabel);
                    if (saved && saved.user_id) {
                        userId = Number(saved.user_id);
                    }
                }

                ops.push({
                    user_id: userId > 0 ? userId : 0,
                    role: roleLabel,
                    table: tableIndex,
                    row: rowIndex,
                    name_col: 0,
                    department_col: null,
                    signature_col: 0,
                    date_col: 0,
                    section_verified: true,
                });
            });
        });
        return ops;
    }

    function formatNeqDateTimeDisplay(dateObj) {
        const d = dateObj instanceof Date ? dateObj : new Date();
        if (Number.isNaN(d.getTime())) {
            return '';
        }
        const pad = function (n) { return String(n).padStart(2, '0'); };
        let hours = d.getHours();
        const ampm = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12;
        if (hours === 0) {
            hours = 12;
        }
        return pad(d.getDate()) + '-' + pad(d.getMonth() + 1) + '-' + d.getFullYear()
            + ' ' + pad(hours) + ':' + pad(d.getMinutes()) + ' ' + ampm;
    }

    function parseNeqDateTimeLocal(value) {
        if (!value) {
            return '';
        }
        const d = new Date(value);
        return formatNeqDateTimeDisplay(d);
    }

    function formatNeqDateDisplay(dateObj) {
        const d = dateObj instanceof Date ? dateObj : new Date();
        if (Number.isNaN(d.getTime())) {
            return '';
        }
        const pad = function (n) { return String(n).padStart(2, '0'); };
        return pad(d.getDate()) + '-' + pad(d.getMonth() + 1) + '-' + d.getFullYear();
    }

    function parseNeqDateOnly(value) {
        const raw = normalizeEditText(value || '');
        if (!raw) {
            return '';
        }
        const iso = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (iso) {
            return iso[3] + '-' + iso[2] + '-' + iso[1];
        }
        const shown = raw.match(/(\d{1,2})-(\d{1,2})-(\d{2,4})/);
        if (!shown) {
            return '';
        }
        const pad = function (n) { return String(n).padStart(2, '0'); };
        let year = Number(shown[3]);
        if (year < 100) {
            year += 2000;
        }
        return pad(Number(shown[1])) + '-' + pad(Number(shown[2])) + '-' + year;
    }

    function toNeqDateInputValue(text) {
        const shown = parseNeqDateOnly(text);
        const m = shown.match(/^(\d{2})-(\d{2})-(\d{4})$/);
        if (!m) {
            return '';
        }
        return m[3] + '-' + m[2] + '-' + m[1];
    }

    function toNeqDateTimeLocalValue(text) {
        const raw = normalizeEditText(text || '');
        const m = raw.match(/(\d{1,2})-(\d{1,2})-(\d{2,4})\s+(\d{1,2}):(\d{2})\s*(AM|PM)?/i);
        if (!m) {
            return '';
        }
        let year = Number(m[3]);
        if (year < 100) {
            year += 2000;
        }
        let hours = Number(m[4]);
        const minutes = Number(m[5]);
        const ampm = (m[6] || '').toUpperCase();
        if (ampm === 'PM' && hours < 12) {
            hours += 12;
        }
        if (ampm === 'AM' && hours === 12) {
            hours = 0;
        }
        const pad = function (n) { return String(n).padStart(2, '0'); };
        return year + '-' + pad(Number(m[2])) + '-' + pad(Number(m[1]))
            + 'T' + pad(hours) + ':' + pad(minutes);
    }

    function findUserByTrainingName(nameText) {
        const needle = normalizeEditText(nameText || '').toLowerCase();
        if (!needle) {
            return null;
        }
        const users = neqApproverUsers || [];
        let found = users.find(function (user) {
            const full = normalizeEditText(user.full_name || '').toLowerCase();
            const combined = normalizeEditText(user.name || '').toLowerCase();
            return (full && full === needle) || (combined && combined === needle);
        });
        if (found) {
            return found;
        }
        found = users.find(function (user) {
            const full = normalizeEditText(user.full_name || '').toLowerCase();
            const label = normalizeEditText(user.label || '').toLowerCase();
            const labelName = sanitizeTrainingNameDefault(user.label || '').toLowerCase();
            return (full && needle.indexOf(full) === 0)
                || (labelName && labelName === needle)
                || (label && label.indexOf(needle) === 0);
        });
        return found || null;
    }

    function trainingRowCells(row) {
        if (!row) {
            return [];
        }
        let cells = Array.from(row.children).filter(function (el) {
            return (el.tagName === 'TD' || el.tagName === 'TH') && !isToolCell(el);
        });
        // Malformed last rows sometimes nest cells; fall back to direct td/th query.
        if (cells.length < 3) {
            cells = Array.from(row.querySelectorAll(':scope > td, :scope > th')).filter(function (el) {
                return !isToolCell(el);
            });
        }
        return cells;
    }

    function applyTrainingDepartmentFromName(row, map, nameValue, designationHint) {
        if (!row || !map || !Number.isInteger(map.department) || map.department < 0) {
            return;
        }
        const cells = trainingRowCells(row);
        const deptCell = cells[map.department] || null;
        if (!deptCell) {
            return;
        }
        const matched = findUserByTrainingName(nameValue);
        const designation = normalizeEditText(
            designationHint || (matched ? (matched.designation || '') : '')
        );
        deptCell.classList.remove('neq-approver-cell', 'neq-stamp-cell');
        deptCell.removeAttribute('contenteditable');
        deptCell.innerHTML = '';
        deptCell.textContent = designation;
        deptCell.setAttribute('data-neq-user-edited', designation || nameValue ? '1' : '0');
    }

    function trainingNameSelectDetails(select) {
        const opt = select && select.options ? select.options[select.selectedIndex] : null;
        if (!opt || !opt.value) {
            return { display: '', designation: '' };
        }
        return {
            display: opt.getAttribute('data-name') || opt.textContent || '',
            designation: opt.getAttribute('data-designation') || '',
        };
    }

    function syncTrainingDepartmentFromSelect(select) {
        const row = select && select.closest ? select.closest('tr') : null;
        const table = row ? row.closest('table') : null;
        if (!row || !table) {
            return;
        }
        const map = getTrainingPersonnelColumnMap(table);
        if (!map) {
            return;
        }
        const picked = trainingNameSelectDetails(select);
        const nameCell = select.closest('td, th');
        if (nameCell) {
            nameCell.setAttribute('data-neq-user-edited', select.value ? '1' : '0');
        }
        applyTrainingDepartmentFromName(row, map, picked.display, picked.designation);
    }

    // Page split copies the dropdown onto the next sheet without its listener.
    // A document-level handler still fills Department for that copied dropdown.
    if (!window.__neqTrainingDeptDelegate) {
        window.__neqTrainingDeptDelegate = true;
        document.addEventListener('change', function (event) {
            const select = event.target;
            if (!select || !select.classList || !select.classList.contains('neq-training-name-select')) {
                return;
            }
            syncTrainingDepartmentFromSelect(select);
        }, true);
    }

    function healTrainingDepartments(root) {
        if (!root || !root.querySelectorAll || !isEditingEnabled || !currentUserCanSave()) {
            return;
        }
        let wrote = false;
        root.querySelectorAll('select.neq-training-name-select').forEach(function (select) {
            if (!select.value) {
                return;
            }
            const row = select.closest('tr');
            const table = row ? row.closest('table') : null;
            const map = table ? getTrainingPersonnelColumnMap(table) : null;
            if (!row || !map || !Number.isInteger(map.department) || map.department < 0) {
                return;
            }
            const cells = trainingRowCells(row);
            const deptCell = cells[map.department];
            if (!deptCell) {
                return;
            }
            const live = normalizeEditText(deptCell.innerText || deptCell.textContent || '');
            if (live) {
                return;
            }
            syncTrainingDepartmentFromSelect(select);
            wrote = true;
        });
        if (wrote && typeof scheduleNeqAutosave === 'function') {
            scheduleNeqAutosave();
        }
    }

    function trainingUserDisplayName(user) {
        if (!user) {
            return '';
        }
        return sanitizeTrainingNameDefault(user.full_name || '')
            || sanitizeTrainingNameDefault(user.name || '')
            || sanitizeTrainingNameDefault(String(user.label || '').replace(/\s*\([^)]*\)\s*$/, ''));
    }

    function trainingCellLiveValue(cell) {
        if (!cell) {
            return '';
        }
        const nameSelect = cell.querySelector('select.neq-training-name-select, select.neq-training-by-select');
        if (nameSelect) {
            const opt = nameSelect.options[nameSelect.selectedIndex];
            if (opt && opt.value) {
                return normalizeEditText(opt.getAttribute('data-name') || opt.textContent || '');
            }
            return '';
        }
        const nameInput = cell.querySelector('.neq-training-name-input');
        if (nameInput) {
            return normalizeEditText(nameInput.value || '');
        }
        const dateInput = cell.querySelector('.neq-training-date-input');
        if (dateInput) {
            return parseNeqDateOnly(dateInput.value)
                || parseNeqDateOnly(dateInput.getAttribute('data-neq-display') || '');
        }
        return normalizeEditText(cell.innerText || cell.textContent || '');
    }

    function clearTrainingApprovalChrome(cell) {
        if (!cell) {
            return;
        }
        cell.classList.remove('neq-approver-cell', 'neq-stamp-cell');
        cell.querySelectorAll('select.neq-approver-select, .neq-stamp-pending, .neq-signature-font').forEach(function (el) {
            el.remove();
        });
    }

    function sanitizeTrainingNameDefault(value) {
        let name = normalizeEditText(value || '');
        if (!name) {
            return '';
        }
        // Strip designation suffix: "Name - Dept"
        name = name.replace(/\s+-\s+.+$/, '').trim();
        // Never prefill emails, placeholders, stamps, or select labels.
        if (/@/.test(name)
            || /^approved$|^rejected$|^—$|^-$/i.test(name)
            || /^select\s+or\s+type/i.test(name)
            || /^--\s*select/i.test(name)
            || /\(admin@/i.test(name)
            || /^user\s*#\d+$/i.test(name)) {
            return '';
        }
        // Label-like "Super Admin (admin@...)" → keep only name part if no @ left after strip.
        name = name.replace(/\s*\([^)]*@[^)]*\)\s*$/, '').trim();
        if (/@/.test(name) || !name) {
            return '';
        }
        return name;
    }
    function bindTrainingPersonnelEditors() {
        stampNeqTables();
        const allowEdit = currentUserCanSave() && isEditingEnabled;
        getMappablePreviewTables().forEach(function (table) {
            const map = getTrainingPersonnelColumnMap(table);
            if (!map || getNodeZone(table) !== 'body') {
                return;
            }
            getDirectTableRows(table).forEach(function (row, rowPos) {
                if (rowPos <= map.headerIndex || isHeaderLikeRow(row)) {
                    return;
                }
                const cells = trainingRowCells(row);
                const nameCell = cells[map.name];
                const deptCell = map.department >= 0 ? cells[map.department] : null;
                const sigCell = map.signature >= 0 ? cells[map.signature] : null;
                const trainingCell = map.training >= 0 ? cells[map.training] : null;
                const dateCell = map.date >= 0 ? cells[map.date] : null;
                if (!nameCell) {
                    return;
                }

                [nameCell, deptCell, sigCell, trainingCell, dateCell].forEach(clearTrainingApprovalChrome);

                const existingName = sanitizeTrainingNameDefault(
                    trainingCellLiveValue(nameCell)
                        || normalizeEditText(nameCell.getAttribute('data-neq-original') || '')
                );
                const existingDept = deptCell
                    ? (trainingCellLiveValue(deptCell) || normalizeEditText(deptCell.getAttribute('data-neq-original') || ''))
                    : '';
                const existingSig = sigCell
                    ? (trainingCellLiveValue(sigCell) || normalizeEditText(sigCell.getAttribute('data-neq-original') || ''))
                    : '';
                const existingTraining = trainingCell
                    ? (trainingCellLiveValue(trainingCell) || normalizeEditText(trainingCell.getAttribute('data-neq-original') || ''))
                    : '';
                const existingDate = dateCell
                    ? (trainingCellLiveValue(dateCell) || normalizeEditText(dateCell.getAttribute('data-neq-original') || ''))
                    : '';

                if (!allowEdit) {
                    return;
                }

                // Reliable user <select> on every row (incl. last) → Department auto-fill.
                nameCell.querySelectorAll(
                    '.neq-training-name-wrap, .neq-training-name-input, select.neq-training-name-select'
                ).forEach(function (el) {
                    el.remove();
                });
                nameCell.innerHTML = '';
                const select = document.createElement('select');
                select.className = 'neq-training-name-select';
                select.disabled = false;
                select.setAttribute('autocomplete', 'off');
                const blank = document.createElement('option');
                blank.value = '';
                blank.textContent = '-- Select User --';
                select.appendChild(blank);
                (neqApproverUsers || []).forEach(function (user) {
                    const display = trainingUserDisplayName(user);
                    if (!display) {
                        return;
                    }
                    const opt = document.createElement('option');
                    opt.value = String(user.id);
                    opt.textContent = display;
                    opt.setAttribute('data-name', display);
                    opt.setAttribute('data-designation', user.designation || '');
                    select.appendChild(opt);
                });
                nameCell.appendChild(select);

                // Restore prior name selection when possible.
                if (existingName) {
                    const matched = findUserByTrainingName(existingName);
                    if (matched) {
                        select.value = String(matched.id);
                    }
                }
                if (!select.value) {
                    select.value = '';
                }

                function syncDeptFromSelect() {
                    syncTrainingDepartmentFromSelect(select);
                }

                select.addEventListener('change', syncDeptFromSelect);
                // Initial department sync for restored / blank rows.
                if (select.value) {
                    syncDeptFromSelect();
                } else if (deptCell) {
                    const deptText = normalizeEditText(existingDept || '');
                    if (!deptText || /^—$|^-$|^approved$/i.test(deptText) || /@/.test(deptText)) {
                        deptCell.textContent = '';
                    }
                    deptCell.removeAttribute('contenteditable');
                }

                if (deptCell) {
                    deptCell.removeAttribute('contenteditable');
                }

                function unlockManualCell(cell, value, placeholder) {
                    if (!cell) {
                        return;
                    }
                    let text = value;
                    if (/^—$|^-$|^approved$|^rejected$/i.test(normalizeEditText(text))) {
                        text = '';
                    }
                    cell.innerHTML = '';
                    cell.textContent = text || '';
                    cell.setAttribute('contenteditable', 'true');
                    cell.setAttribute('data-neq-placeholder', placeholder || '');
                    cell.addEventListener('input', function () {
                        cell.setAttribute('data-neq-user-edited', '1');
                    });
                }

                unlockManualCell(sigCell, existingSig, 'Signature');

                if (trainingCell) {
                    trainingCell.querySelectorAll('select.neq-training-by-select, .neq-training-name-input').forEach(function (el) {
                        el.remove();
                    });
                    trainingCell.innerHTML = '';
                    trainingCell.removeAttribute('contenteditable');
                    const bySelect = document.createElement('select');
                    bySelect.className = 'neq-training-by-select';
                    bySelect.disabled = false;
                    bySelect.setAttribute('autocomplete', 'off');
                    const byBlank = document.createElement('option');
                    byBlank.value = '';
                    byBlank.textContent = '-- Select User --';
                    bySelect.appendChild(byBlank);
                    (neqApproverUsers || []).forEach(function (user) {
                        const display = trainingUserDisplayName(user);
                        if (!display) {
                            return;
                        }
                        const opt = document.createElement('option');
                        opt.value = String(user.id);
                        opt.textContent = display;
                        opt.setAttribute('data-name', display);
                        bySelect.appendChild(opt);
                    });
                    const savedBy = sanitizeTrainingNameDefault(existingTraining);
                    if (savedBy) {
                        const matchedBy = findUserByTrainingName(savedBy);
                        if (matchedBy) {
                            bySelect.value = String(matchedBy.id);
                        } else {
                            const keep = document.createElement('option');
                            keep.value = savedBy;
                            keep.textContent = savedBy;
                            keep.setAttribute('data-name', savedBy);
                            bySelect.appendChild(keep);
                            bySelect.value = savedBy;
                        }
                    }
                    bySelect.addEventListener('change', function () {
                        trainingCell.setAttribute('data-neq-user-edited', bySelect.value ? '1' : '0');
                        if (bySelect.value) {
                            bySelect.setAttribute('data-neq-picked-user', bySelect.value);
                        } else {
                            bySelect.removeAttribute('data-neq-picked-user');
                        }
                    });
                    trainingCell.appendChild(bySelect);
                }

                if (dateCell && !dateCell.querySelector('.neq-training-date-input')) {
                    let displayDate = existingDate;
                    if (/^—$|^-$|^approved$|^rejected$/i.test(normalizeEditText(displayDate))) {
                        displayDate = '';
                    }
                    dateCell.innerHTML = '';
                    const dateInput = document.createElement('input');
                    dateInput.type = 'date';
                    dateInput.className = 'neq-training-date-input';
                    dateInput.value = toNeqDateInputValue(displayDate);
                    dateInput.setAttribute('data-neq-display', parseNeqDateOnly(displayDate));
                    dateCell.appendChild(dateInput);
                    dateCell.setAttribute('data-neq-user-edited', dateInput.value ? '1' : '0');
                    dateInput.addEventListener('change', function () {
                        const shown = parseNeqDateOnly(dateInput.value);
                        dateInput.setAttribute('data-neq-display', shown);
                        dateCell.setAttribute('data-neq-user-edited', '1');
                        scheduleNeqAutosave();
                    });
                } else if (dateCell) {
                    const existingInput = dateCell.querySelector('.neq-training-date-input');
                    if (existingInput && !existingInput.value) {
                        const shown = existingInput.getAttribute('data-neq-display') || existingDate;
                        if (shown) {
                            existingInput.value = toNeqDateInputValue(shown);
                            existingInput.setAttribute('data-neq-display', parseNeqDateOnly(shown));
                        }
                    }
                }
            });
        });
    }

    function documentHasTrainingPersonnelTable() {
        return getMappablePreviewTables().some(function (table) {
            return isTrainingPersonnelTable(table);
        });
    }

    function findValidationQaHostNode() {
        const scope = getEditScopeRoot();
        if (!scope) {
            return null;
        }
        const nodes = Array.from(scope.querySelectorAll('p, td, th'));
        let best = null;
        let bestScore = -1;
        nodes.forEach(function (node) {
            if (node.closest && node.closest('.neq-qa-approver-host, select, .neq-training-name-wrap')) {
                return;
            }
            const text = normalizeEditText(node.innerText || node.textContent || '');
            const lower = text.toLowerCase();
            if (!lower || lower.length > 240) {
                return;
            }
            if (!/validation\s*\/?\s*quality\s*assurance/.test(lower)
                && !(/quality\s*assurance/.test(lower) && /sign/.test(lower))) {
                return;
            }
            if (/training\s*imparted/.test(lower) && !/validation|quality\s*assurance/.test(lower)) {
                return;
            }
            let score = 0;
            if (/validation\s*\/?\s*quality\s*assurance/.test(lower)) {
                score += 10;
            }
            if (/sign\s*\/?\s*date|sign\/date|\(sign/.test(lower)) {
                score += 5;
            }
            if ((node.tagName || '').toLowerCase() === 'p') {
                score += 3;
            }
            if (/\bapproved\b|\brejected\b/.test(lower) && /\d{1,2}-\d{1,2}-\d{2,4}/.test(lower)) {
                score -= 4;
            }
            if (lower.length < 80) {
                score += 2;
            }
            if (score > bestScore) {
                bestScore = score;
                best = node;
            }
        });
        return best;
    }

    function clearAllValidationQaWidgets() {
        const scope = getEditScopeRoot();
        if (!scope) {
            return;
        }
        scope.querySelectorAll('.neq-qa-approver-host, select.neq-qa-approver-select').forEach(function (el) {
            el.remove();
        });
    }

    function qaStampDisplayText(name, sig, when) {
        return 'Validation /Quality Assurance: ' + signerNameAndTime(name, sig, when);
    }

    function bindValidationQaApprover() {
        if (!documentHasTrainingPersonnelTable()) {
            return;
        }
        // Always one QA control — drop any leftover duplicates first.
        clearAllValidationQaWidgets();

        const host = findValidationQaHostNode();
        if (!host) {
            console.warn('[NEQ QA] Validation / Quality Assurance host not found');
            return;
        }
        const allowSelect = currentUserCanSave();
        const rawBase = normalizeEditText(host.getAttribute('data-neq-original') || host.innerText || '');
        const cleanBase = rawBase
            .replace(/\s*--\s*select validation.*/i, '')
            .trim();
        host.setAttribute(
            'data-neq-qa-base',
            (/validation\s*\/?\s*quality\s*assurance/i.test(cleanBase) && cleanBase.length < 100)
                ? cleanBase.replace(/\s*\/\s*[^/]+\/\s*\d{1,2}-\d{1,2}-\d{2,4}.*/i, '').trim()
                    || 'Validation /Quality Assurance: (Sign/Date)'
                : 'Validation /Quality Assurance: (Sign/Date)'
        );

        const baseLabel = host.getAttribute('data-neq-qa-base');
        Array.from(host.childNodes).forEach(function (child) {
            if (child.nodeType === 1 && child.querySelector && child.querySelector('input.neq-checkbox')) {
                return;
            }
            if (child.nodeType === 1 && (child.tagName || '').toLowerCase() === 'input') {
                return;
            }
            host.removeChild(child);
        });
        host.appendChild(document.createTextNode(baseLabel + ' '));

        const mount = document.createElement('div');
        mount.className = 'neq-qa-approver-host';
        mount.setAttribute('data-neq-qa-host', '1');
        host.appendChild(mount);

        let paraIndex = Number(host.getAttribute('data-neq-index'));
        if (!Number.isInteger(paraIndex) || paraIndex < 0) {
            const mapped = resolveQaParagraphIndexFromTargets(host);
            if (Number.isInteger(mapped) && mapped >= 0) {
                paraIndex = mapped;
                host.setAttribute('data-neq-index', String(paraIndex));
            }
        }
        if (Number.isInteger(paraIndex) && paraIndex >= 0) {
            mount.setAttribute('data-neq-qa-para-index', String(paraIndex));
        }

        const cell = getOwningCell(host)
            || ((host.tagName || '').toLowerCase() === 'td' || (host.tagName || '').toLowerCase() === 'th' ? host : null);
        if (cell) {
            const table = cell.closest('table');
            const tableIndex = table ? Number(table.getAttribute('data-neq-table')) : NaN;
            const row = cell.parentElement;
            const rowIndex = row ? Number(row.getAttribute('data-neq-row')) : NaN;
            const colIndex = Number(cell.getAttribute('data-neq-col'));
            if (Number.isInteger(tableIndex) && tableIndex >= 0 && tableIndex < 900000) {
                mount.setAttribute('data-neq-qa-table', String(tableIndex));
            }
            if (Number.isInteger(rowIndex) && rowIndex >= 0) {
                mount.setAttribute('data-neq-qa-row', String(rowIndex));
            }
            if (Number.isInteger(colIndex) && colIndex >= 0) {
                mount.setAttribute('data-neq-qa-name-col', String(colIndex));
                mount.setAttribute('data-neq-qa-signature-col', String(colIndex));
                mount.setAttribute('data-neq-qa-date-col', String(colIndex));
            }
        }

        const savedQa = approvalsForCurrentList().find(function (item) {
            return /validation|quality\s*assurance/i.test(String(item.role || ''))
                || Number(item.table) >= 900000
                || item.paragraph_index != null;
        }) || null;

        const stampLine = document.createElement('span');
        stampLine.className = 'neq-qa-stamp-line';
        mount.appendChild(stampLine);

        function paintQaStamp(userId, forceApproved) {
            const user = (neqApproverUsers || []).find(function (u) {
                return Number(u.id) === Number(userId);
            });
            const name = user
                ? (user.full_name || sanitizeTrainingNameDefault(user.name) || sanitizeTrainingNameDefault(user.label) || '')
                : (savedQa && savedQa.name_text
                    ? sanitizeTrainingNameDefault(String(savedQa.name_text).split(' / ')[0])
                    : '');
            if (!name) {
                stampLine.textContent = '';
                return;
            }
            const approved = !!(forceApproved || (savedQa && savedQa.approved_at));
            const rejected = !!(savedQa && savedQa.rejected_at);
            const when = (approved || rejected)
                ? ((savedQa && (savedQa.date_text || savedQa.approved_at || savedQa.rejected_at)) || '')
                : '';
            // Pending: name only. Date and time are written when this step is approved.
            const sig = approved
                ? (savedQa && savedQa.signature_text ? savedQa.signature_text : 'Approved')
                : (rejected ? 'Rejected' : '—');

            // One line only — never paint host text AND stampLine (that showed approve twice).
            const stamped = qaStampDisplayText(name, sig, when);
            host.setAttribute('data-neq-user-edited', '1');
            if (userId) {
                mount.setAttribute('data-neq-qa-user-id', String(userId));
            } else {
                mount.removeAttribute('data-neq-qa-user-id');
            }
            Array.from(host.childNodes).forEach(function (child) {
                if (child === mount) {
                    return;
                }
                if (child.nodeType === 1 && child.querySelector && child.querySelector('input.neq-checkbox')) {
                    return;
                }
                if (child.nodeType === 1 && (child.tagName || '').toLowerCase() === 'input') {
                    return;
                }
                host.removeChild(child);
            });
            // Keep select in DOM (hidden) after pending pick so Send for Approval can still read user_id.
            // Remove only after final approve/reject.
            mount.querySelectorAll('select.neq-qa-approver-select').forEach(function (el) {
                if (approved || rejected) {
                    el.remove();
                } else {
                    if (userId) {
                        el.value = String(userId);
                    }
                    el.style.display = 'none';
                }
            });
            stampLine.textContent = '';
            host.insertBefore(document.createTextNode(stamped), mount);
        }

        if (savedQa && (savedQa.approved_at || savedQa.rejected_at)) {
            paintQaStamp(savedQa.user_id, true);
            return;
        }

        if (!allowSelect) {
            if (savedQa && savedQa.user_id) {
                paintQaStamp(savedQa.user_id, false);
            }
            return;
        }

        const select = document.createElement('select');
        select.className = 'neq-qa-approver-select';
        const blank = document.createElement('option');
        blank.value = '';
        blank.textContent = '-- Select Validation / QA --';
        blank.selected = true;
        select.appendChild(blank);
        (neqApproverUsers || []).forEach(function (user) {
            const opt = document.createElement('option');
            opt.value = String(user.id);
            opt.textContent = user.label;
            opt.setAttribute(
                'data-name',
                user.full_name || sanitizeTrainingNameDefault(user.name) || user.label
            );
            select.appendChild(opt);
        });
        mount.insertBefore(select, stampLine);
        // Start blank; restore only if this doc already has a QA assignee saved.
        select.value = '';
        stampLine.textContent = '';
        if (savedQa && savedQa.user_id && !savedQa.approved_at && !savedQa.rejected_at) {
            select.value = String(savedQa.user_id);
            if (select.value) {
                paintQaStamp(savedQa.user_id, false);
            }
        }

        select.addEventListener('change', function () {
            if (select.value) {
                paintQaStamp(select.value, false);
            } else {
                mount.removeAttribute('data-neq-qa-user-id');
                select.style.display = '';
                stampLine.textContent = '';
                const base = host.getAttribute('data-neq-qa-base')
                    || 'Validation /Quality Assurance: (Sign/Date)';
                Array.from(host.childNodes).forEach(function (child) {
                    if (child === mount) {
                        return;
                    }
                    host.removeChild(child);
                });
                host.insertBefore(document.createTextNode(base + ' '), mount);
            }
            updateApprovalActionButtons();
        });
    }

    function collectTrainingPersonnelCellEdits() {
        const ops = [];
        stampNeqTables();
        getMappablePreviewTables().forEach(function (table) {
            const map = getTrainingPersonnelColumnMap(table);
            if (!map) {
                return;
            }
            const tableIndex = Number(table.getAttribute('data-neq-table'));
            if (!Number.isInteger(tableIndex) || tableIndex < 0) {
                return;
            }
            const rows = getDirectTableRows(table);
            const headerRow = rows[map.headerIndex];
            const headers = headerRow
                ? Array.from(headerRow.children).filter(function (el) {
                    return (el.tagName === 'TD' || el.tagName === 'TH') && !isToolCell(el);
                }).map(function (cell) {
                    return normalizeEditText(cell.innerText || cell.textContent || '');
                })
                : ['Name', 'Department', 'Signature', 'Training Imparted by', 'Date'];

            let dataRow = 0;
            rows.forEach(function (row, rowPos) {
                if (rowPos <= map.headerIndex || isHeaderLikeRow(row)) {
                    return;
                }
                // Ensure row index exists (last rows sometimes lost data-neq-row).
                let rowIndex = Number(row.getAttribute('data-neq-row'));
                if (!Number.isInteger(rowIndex) || rowIndex < 0) {
                    rowIndex = rowPos;
                    row.setAttribute('data-neq-row', String(rowIndex));
                }
                const dataRowIndex = dataRow;
                dataRow += 1;

                const cells = trainingRowCells(row);
                [map.name, map.department, map.signature, map.training, map.date].forEach(function (col) {
                    if (!Number.isInteger(col) || col < 0 || !cells[col]) {
                        return;
                    }
                    const cell = cells[col];
                    let text = col === map.name
                        ? sanitizeTrainingNameDefault(trainingCellLiveValue(cell))
                        : trainingCellLiveValue(cell);
                    if (col === map.date) {
                        const dateInput = cell.querySelector('.neq-training-date-input');
                        if (dateInput && (dateInput.value || dateInput.getAttribute('data-neq-display'))) {
                            text = parseNeqDateOnly(dateInput.value)
                                || parseNeqDateOnly(dateInput.getAttribute('data-neq-display') || '')
                                || text;
                        }
                    }
                    const original = cellOriginalText(cell) || '';
                    const userEdited = cell.getAttribute('data-neq-user-edited') === '1'
                        || !!(cell.querySelector && (
                            cell.querySelector('select.neq-training-name-select')
                            || cell.querySelector('select.neq-training-by-select')
                            || cell.querySelector('.neq-training-name-input')
                            || cell.querySelector('.neq-training-date-input')
                            || cell.getAttribute('contenteditable') === 'true'
                        ));

                    // Clear emails previously saved into the Name column.
                    if (col === map.name && /@/.test(original) && !text) {
                        ops.push({
                            table: tableIndex,
                            row: dataRowIndex,
                            col: col,
                            text: '',
                            original_text: original,
                            zone: getNodeZone(cell) || 'body',
                            match_by_headers: true,
                            headers: headers,
                            header_row_index: map.headerIndex,
                            user_edited: true,
                            allow_clear: true,
                        });
                        return;
                    }
                    if (!text && !original) {
                        return;
                    }
                    if (col === map.name && /@/.test(String(text || ''))) {
                        return;
                    }
                    // Always persist non-empty Name when a training name select/input holds a value.
                    const nameSelect = cell.querySelector && cell.querySelector('select.neq-training-name-select');
                    const nameInput = cell.querySelector && cell.querySelector('.neq-training-name-input');
                    const forceName = col === map.name && (
                        (nameSelect && nameSelect.value)
                        || (nameInput && sanitizeTrainingNameDefault(nameInput.value))
                    );
                    if (!forceName
                        && normalizeCompareText(text) === normalizeCompareText(original)
                        && !userEdited) {
                        return;
                    }
                    if (forceName) {
                        text = nameSelect && nameSelect.value
                            ? trainingCellLiveValue(cell)
                            : sanitizeTrainingNameDefault(nameInput.value);
                    }
                    ops.push({
                        table: tableIndex,
                        row: rowIndex,
                        col: col,
                        text: text,
                        original_text: original,
                        zone: getNodeZone(cell) || 'body',
                        match_by_headers: true,
                        headers: headers,
                        header_row_index: Number.isInteger(map.wordHeaderIndex) ? map.wordHeaderIndex : map.headerIndex,
                        abs_row: 1,
                        user_edited: true,
                        allow_clear: (!text && !!original) ? true : false,
                    });
                });
            });
        });
        return ops;
    }

    function getOwningCell(node) {
        if (!node) {
            return null;
        }
        const tag = (node.tagName || '').toLowerCase();
        if (tag === 'td' || tag === 'th') {
            return node;
        }
        return node.closest ? node.closest('td, th') : null;
    }

    // Template labels / headers must stay mapped for matching, but must NOT be typed into.
    // Keep this STRICT: only short/static label chrome — not long cell content that merely
    // mentions "equipment" / "mitigation" (that was locking fill-in cells on Attachment 13).
    function isTrainingColumnTitleText(text) {
        const t = columnHeaderMatchText(text);
        if (!t || t.length > 40 || /\d/.test(t)) {
            return false;
        }
        if (/^(?:s\.?\s*no\.?|sr\.?\s*no\.?|topic|applicability(?:\s+to\s+department)?|as\s*per\s*document(?:\s*no\.?)?)$/i.test(t)) {
            return true;
        }
        // "dfgdTopic" stays a title. "vijay verma test topic 1" is a value.
        if (/topic$/i.test(t) && t.length <= 24) {
            return true;
        }
        return /applicability(?:\s+to\s+department)?$/i.test(t) && t.split(/\s+/).length <= 4;
    }

    function isKnownLabelText(text) {
        const t = columnHeaderMatchText(text);
        if (!t || t.length > 120) {
            return false;
        }
        if (/^annex[\s-]?\d*/.test(t) || /^lup[i]?n\s+limited/.test(t)) {
            return true;
        }
        if (/^document\s*title\b|^document\s*no\b|^reference\s*document/.test(t)) {
            return true;
        }
        // Attachment 3 chrome / performance section titles must stay locked.
        if (isAttachmentChromeTitleText(t) || isMajorComponentColumnHeaderText(t)
            || isUtilitiesSectionTitleText(t) || isComponentSectionBannerText(t)) {
            return true;
        }
        // Label wrap fragment that must never be treated as a typed value.
        if (/^no\.?\s*\(?\s*version(\s*no\.?)?\)?$/.test(t) || /^\(?\s*version\s*no\.?\s*\)?$/.test(t)) {
            return true;
        }
        if (/^system\s+name\b|^system\s+id(\s*no\.?)?$|^id\s*no\.?$|^equipment\s*\/?\s*$/.test(t)) {
            return true;
        }
        if (/^equipment/.test(t) && (/system/.test(t) || /\bid\b/.test(t) || /\bname\b/.test(t))) {
            return true;
        }
        if (/^facilit/.test(t) && /sect/.test(t)) {
            return true;
        }
        if (/^(sr|s)\.?\s*no\.?$/.test(t) || /^requirements?(\s*\[|$)/.test(t)) {
            return true;
        }
        if (/^(dq|ioq|urs|rpn)$/.test(t) || /^remark/.test(t) || /^acceptable/.test(t)) {
            return true;
        }
        if (/^comments?\s*:?\s*$/.test(t) || /^deviation/.test(t) || /^corrective\s*actions?/.test(t)) {
            return true;
        }
        if (/^role$|^name$|^signature$|^date$|^change$|^revision(?:\s*no\.?)?$|^check$|^method$|^version$/.test(t)) {
            return true;
        }
        if (/^observation|^sign\s*and\s*date|^acceptance\s*criteria|^checked\s*by\s*sign/.test(t)) {
            return true;
        }
        // Training Attachment-12 column titles only. A filled Topic or
        // Applicability cell must stay editable and must save.
        if (isTrainingColumnTitleText(t)) {
            return true;
        }
        // MOC / Parts identification column titles.
        if (/^(parts?\s*name|acceptance\s*criteria|actual\s*moc|certificate\s*no\.?|sign\s*and\s*date)\s*:?$/i.test(t)) {
            return true;
        }
        if (/parts?\s*name|actual\s*moc|certificate\s*no/.test(t) && t.length < 40) {
            return true;
        }
        return false;
    }

    // IOQ Attachment 3 (and similar) document titles — never editable.
    function isAttachmentChromeTitleText(text) {
        const t = columnHeaderMatchText(text);
        if (!t) {
            return false;
        }
        if (/ioq\s*attachment\s*\d+/i.test(t)) {
            return true;
        }
        if (/major\s*component/i.test(t) && /sub\s*system/i.test(t)) {
            return true;
        }
        if (/identification\s*(verification)?\s*(of)?\s*major\s*component/i.test(t)) {
            return true;
        }
        return false;
    }

    function isMajorComponentColumnHeaderText(text) {
        const t = columnHeaderMatchText(text);
        if (!t || t.length > 64) {
            return false;
        }
        return /^(major\s*components?|sub\s*systems?|component\s*\/\s*sub\s*system|s\.?\s*no\.?|sr\.?\s*no\.?)$/i.test(t)
            || (/^major\s*component/i.test(t) && t.length < 40)
            || (/^sub\s*system/i.test(t) && t.length < 40);
    }

    function looksLikeFieldLabel(text) {
        const t = normalizeEditText(text);
        if (!t) {
            return false;
        }
        // Real form labels are short chrome ("Model No.:", "Name", "Document Title").
        // Full sentences ending with ":" (e.g. "The protocol also provides ... for:")
        // must NOT be treated as labels or Save will drop body edits.
        if (t.length > 48) {
            return false;
        }
        if (isKnownLabelText(t)) {
            return true;
        }
        const words = t.replace(/:+\s*$/, '').trim().split(/\s+/).filter(Boolean);
        if (words.length > 5) {
            return false;
        }
        if (/:$/.test(t)) {
            return true;
        }
        // Short "X No." / name|title|id|section phrases only — not mid-sentence mentions.
        if (/^(?:[\w./()-]+\s+){0,3}(?:no\.?|name|title|id|section)\s*:?$/i.test(t)) {
            return true;
        }
        return false;
    }

    // Save gate: keep short form-label chrome out of Word overwrite, but persist
    // any real body/prose/section text the user changed while unlocked.
    function shouldPersistEditedContent(node, original, text) {
        if (!node) {
            return false;
        }
        if (isToolCell(node) || isInsideOfficialLogoCell(node) || isNeqApprovalLockCell(node)) {
            return false;
        }
        if (isInsideTocRegion(node)) {
            return normalizeCompareText(text) !== normalizeCompareText(original);
        }
        const gateCell = getOwningCell(node) || ((((node.tagName || '').toLowerCase() === 'td' || (node.tagName || '').toLowerCase() === 'th') ? node : null));
        // Never persist edits typed into column-header / label chrome.
        if (gateCell && isHeaderLikeRow(gateCell.parentElement)) {
            return false;
        }
        if (!isFillInField(node)) {
            return false;
        }
        if (normalizeCompareText(text) === normalizeCompareText(original)) {
            return false;
        }
        // Never write label-wrap fragments ("No. (Version No.)", "system name") as the saved value.
        // Do NOT block when original was a fragment and the user typed a real value.
        if (isIdentityLabelContinuation(text) || (normalizeEditText(text) && isShortIdentityLabel(text) && isIdentityPlaceholderValue(text))) {
            return false;
        }
        // Preview list-number vs Word text without number is not a real edit.
        if (textMatchesWordTarget(text, original)
            && normalizeCompareText(stripAutoListPrefix(text)) === normalizeCompareText(stripAutoListPrefix(original) || original)) {
            return false;
        }
        const orig = normalizeEditText(original);
        if (/table\s+of\s+contents/i.test(orig) || isLikelyTocEntryText(orig, null) || isLikelyTocEntryText(original, null)) {
            return false;
        }
        const cell = getOwningCell(node) || ((((node.tagName || '').toLowerCase() === 'td' || (node.tagName || '').toLowerCase() === 'th') ? node : null));
        // Annexure No. / S. No. / instrument / attachment / Annex-13 matrix cells always persist.
        if (cell && (isSerialOrAnnexNoColumnCell(cell) || isDataTableValueColumnCell(cell) || isAnnexFormMatrixCell(cell))) {
            return true;
        }
        // Metadata / letterhead value cell (after a label): always persist real typed values,
        // even when data-neq-original was a wrap fragment like "system name".
        if (cell) {
            const rowCells = getRowCells(cell);
            const col = rowCells.indexOf(cell);
            const prevLabel = col > 0 ? (rowCells[col - 1].innerText || rowCells[col - 1].textContent || '') : '';
            if (col > 0 && (looksLikeFieldLabel(prevLabel) || isShortIdentityLabel(prevLabel) || isKnownLabelText(prevLabel))) {
                if (text === '') {
                    return true; // allow clearing a bogus placeholder from Word
                }
                return !isIdentityPlaceholderValue(text);
            }
            const table = cell.closest ? cell.closest('table') : null;
            if (isMetadataFieldTable(table) && col > 0) {
                if (text === '') {
                    return true;
                }
                return !isIdentityPlaceholderValue(text);
            }
        }
        if (isFillInField(node)) {
            return true;
        }
        // Allow replacing a placeholder original with a real typed value.
        if (isIdentityPlaceholderValue(orig) && !isIdentityPlaceholderValue(text)) {
            return true;
        }
        // Non-fill-in: still save rewritten body/section lines; skip only true short labels.
        if (looksLikeFieldLabel(orig) || isKnownLabelText(orig)) {
            return false;
        }
        return true;
    }

    function isEmptyFillCandidate(node) {
        if (isToolCell(node) || nodeEditText(node) !== '') {
            return false;
        }
        const tag = (node.tagName || '').toLowerCase();
        // Only empty table cells consume leftover Word blanks.
        // Inner empty <p> tags used to steal Document Title / Equipment ID slots.
        return tag === 'td' || tag === 'th';
    }

    function takeNextEmptyWordTarget(afterIndex, preferredZone) {
        let best = null;
        (docxTextTargets || []).forEach(function (target) {
            if (assignedDocxIndexes.has(String(target.index))) {
                return;
            }
            if (tocProtectedIndexes.has(String(target.index))) {
                return;
            }
            if (normalizeEditText(target.text) !== '') {
                return;
            }
            if (preferredZone && (target.zone || 'body') !== preferredZone) {
                return;
            }
            if (afterIndex !== null && Number.isInteger(Number(afterIndex)) && Number(target.index) <= Number(afterIndex)) {
                return;
            }
            if (!best || Number(target.index) < Number(best.index)) {
                best = target;
            }
        });
        return best;
    }

    function mappedIndexOfCell(cell) {
        if (!cell) {
            return null;
        }
        if (cell.hasAttribute('data-neq-index')) {
            const index = Number(cell.getAttribute('data-neq-index'));
            return Number.isInteger(index) ? index : null;
        }
        const inner = cell.querySelector('[data-neq-index]');
        if (!inner) {
            return null;
        }
        const index = Number(inner.getAttribute('data-neq-index'));
        return Number.isInteger(index) ? index : null;
    }

    function previousMappedIndex(node) {
        const cell = getOwningCell(node) || node;
        if (!cell) {
            return null;
        }
        const row = cell.parentElement;
        const table = cell.closest ? cell.closest('table') : null;
        const rowCells = getRowCells(cell);
        const col = rowCells.indexOf(cell);
        for (let i = col - 1; i >= 0; i -= 1) {
            const index = mappedIndexOfCell(rowCells[i]);
            if (index !== null) {
                return index;
            }
        }
        if (!table || !row) {
            return null;
        }
        const rows = getDirectTableRows(table);
        const rowIndex = rows.indexOf(row);
        for (let r = rowIndex - 1; r >= 0; r -= 1) {
            const cells = Array.from(rows[r].children).filter(function (el) {
                return (el.tagName === 'TD' || el.tagName === 'TH') && !isToolCell(el);
            });
            for (let i = cells.length - 1; i >= 0; i -= 1) {
                const index = mappedIndexOfCell(cells[i]);
                if (index !== null) {
                    return index;
                }
            }
        }
        return null;
    }

    function extractTypedValueFromLabel(original, text) {
        const orig = normalizeEditText(original);
        const live = normalizeEditText(text);
        if (!orig || live === orig) {
            return null;
        }
        if (!looksLikeFieldLabel(orig) && !isKnownLabelText(orig)) {
            return null;
        }
        const at = live.indexOf(orig);
        if (at < 0) {
            return null;
        }
        const extra = normalizeEditText(live.slice(0, at) + ' ' + live.slice(at + orig.length));
        if (!extra || extra.length > 80) {
            return null;
        }
        // Reject typo/corruption fragments like "ed", not short real IDs ("A1", "12").
        if (/^ed$/i.test(extra) || /^ed[a-z]/i.test(extra)) {
            return null;
        }
        return extra;
    }

    function unassignNodeIndex(node) {
        if (!node) {
            return;
        }
        const indexAttr = node.getAttribute('data-neq-index');
        if (indexAttr !== null) {
            assignedDocxIndexes.delete(String(indexAttr));
        }
        node.removeAttribute('data-neq-index');
        node.removeAttribute('data-neq-original');
        node.removeAttribute('data-neq-zone');
        node.removeAttribute('data-neq-locked');
        node.removeAttribute('data-neq-sync-key');
        node.removeAttribute('contenteditable');
    }

    function getTableRows(table) {
        if (!table) {
            return [];
        }
        return Array.from(table.querySelectorAll('tr')).filter(function (row) {
            return row.querySelector('td, th');
        });
    }

    function columnHeaderMatchText(text) {
        return normalizeEditText(text)
            .toLowerCase()
            .replace(/^ed(?=[a-z])/, '');
    }

    // Strip accidental "ed" glued onto typed values (121ed, ed123ed, edfilled, edad).
    // Do NOT turn real words like "filled" / "edited" into something else.
    function stripAccidentalEdPollution(text, original) {
        let value = normalizeCompareText(text == null ? '' : String(text));
        if (!value) {
            return '';
        }
        const orig = normalizeCompareText(original == null ? '' : String(original));
        if (orig) {
            if (value.toLowerCase() === (orig + 'ed').toLowerCase()) {
                return orig;
            }
            if (value.toLowerCase() === ('ed' + orig).toLowerCase()) {
                return orig;
            }
            if (value.toLowerCase() === ('ed' + orig + 'ed').toLowerCase()) {
                return orig;
            }
        }
        // Leading junk: ed123, edPower, edfilled, edad
        value = value.replace(/^ed(?=[A-Za-z0-9])/i, '');
        // Trailing junk after digits / decimals: 121ed, 0.2ed
        if (/[0-9.]ed$/i.test(value)) {
            value = value.replace(/ed$/i, '');
        }
        // Trailing junk after ALL-CAPS token: BALANCEed, CONNECTIONsed
        if (/[A-Z]{2,}ed$/.test(value) && !/(FILLED|EDITED|NEEDED|BASED|NAMED)$/.test(value)) {
            value = value.replace(/ed$/i, '');
        }
        // Section titles that grew an "ed" tail
        if (/(connections|section|criteria|observation|method|check)ed$/i.test(value)
            && !/^(filled|edited)$/i.test(value)) {
            value = value.replace(/ed$/i, '');
        }
        // Second pass for ed123ed → 123ed → 123
        value = value.replace(/^ed(?=[A-Za-z0-9])/i, '');
        if (/[0-9.]ed$/i.test(value)) {
            value = value.replace(/ed$/i, '');
        }
        return value.trim();
    }

    function isUtilitiesSectionTitleText(text) {
        const t = columnHeaderMatchText(text);
        if (!t || t.length > 120) {
            return false;
        }
        if (/^(machine\s*performance|mechanical\s*adjustments?|general\s*checks?|safety\s*(and\s*)?performance|operational\s*\/?\s*functional(\s*checks?)?)\b/i.test(t)
            && !/acceptance\s*criteria|observation|sign\s*and\s*date|\bcheck\b.*\bmethod\b/i.test(t)) {
            return true;
        }
        return /power\s*\/?\s*electrical|electrical\s*connections|identification\s*of\s*utilities|water\s*\/?\s*steam|compressed\s*air/i.test(t)
            && !/acceptance\s*criteria|observation|sign\s*and\s*date/.test(t);
    }

    function isVerifiedByRowText(text) {
        const t = columnHeaderMatchText(text);
        return !!t && /^verified\s*by\b/i.test(t);
    }

    function isExactComponentBannerText(text) {
        const t = columnHeaderMatchText(text);
        if (!t || t.length > 40) {
            return false;
        }
        return /^(main\s*body|mechanical\s*components?|electrical\s*components?)\s*:?$/i.test(t);
    }

    function isComponentSectionBannerText(text) {
        const t = columnHeaderMatchText(text);
        if (!t || t.length > 120) {
            return false;
        }
        // Attachment-3 / 4 / 6 grey section titles (ignore "ed" / "ed test" pollution).
        // Prefix match (no trailing \b) so edited titles like "Main Bodydsadas" /
        // "Mechanical Componentsccccc" still count as banners — not data rows.
        const cleaned = t
            .replace(/\s+ed\b/gi, ' ')
            .replace(/^ed(?=[A-Za-z])/i, '')
            .replace(/\s+/g, ' ')
            .trim();
        return /^(main\s*body|mechanical\s*components?|electrical\s*components?|machine\s*performance|mechanical\s*adjustments?|general\s*checks?|safety\s*(and\s*)?performance|operational\s*\/?\s*functional(\s*checks?)?|pressure\s*gauge|temperature\s*sensor|humidity\s*sensor|flow\s*meter|differential\s*pressure|vacuum\s*gauge|level\s*sensor|weighing\s*balance)/i.test(cleaned)
            || isUtilitiesSectionTitleText(cleaned);
    }

    // Attachment 3 Major Component / Sub System — section banners, no Check/Method header row.
    // Sections may be one table or split (Main Body / Mechanical / Electrical each).
    function isComponentSubsystemMatrixTable(table) {
        if (!table || isApprovalOrSignatoryTable(table) || isLetterheadIdentityTable(table)) {
            return false;
        }
        if (isCommentsOrDeviationsTable(table)) {
            return false;
        }
        const blob = normalizeEditText(table.innerText || table.textContent || '').toLowerCase();
        if (!blob) {
            return false;
        }
        // Identity letterhead phrases — never treat as component matrix.
        if (/reference\s*document|document\s*title|equipment\s*\/\s*system|facilit\s*\/\s*sect/.test(blob)
            && !/mechanical\s*components?|electrical\s*components?|main\s*body/.test(blob)) {
            return false;
        }
        // A Check / Method / Specification grid can mention "electrical
        // components" inside a requirement. That row is still a check, not
        // the Major Component matrix.
        const headers = tableHeaderFingerprint(table).join(' ').toLowerCase();
        if (/\bcheck\b/.test(headers) && /\bmethod\b/.test(headers)
            && /specification|observation|acceptance|checked\s*by/.test(headers)) {
            return false;
        }
        const rows = getDirectTableRows(table);
        for (let i = 0; i < rows.length; i++) {
            const cells = getRowCells(rows[i]);
            if (!cells.length) {
                continue;
            }
            const title = columnHeaderMatchText(nodeEditText(cells[0]) || cells[0].innerText || '');
            if (/^(main\s*body|mechanical\s*components?|electrical\s*components?)/i.test(title)) {
                return true;
            }
        }
        return false;
    }

    function componentSubsystemMatrixHeaders(table) {
        // Sentinel headers so Python finds this table by section banners (not fake Check/Method).
        let cols = 5;
        const rows = getDirectTableRows(table);
        for (let i = 0; i < rows.length; i++) {
            const cells = getRowCells(rows[i]);
            if (cells.length < 2) {
                continue;
            }
            if (isComponentSectionBannerText(rows[i].innerText || '') || isVerifiedByRowText(rows[i].innerText || '')) {
                continue;
            }
            cols = Math.max(2, cells.length);
            break;
        }
        const headers = ['__neq_component_matrix__'];
        for (let c = 1; c < cols; c++) {
            headers.push('__neq_cm_' + c);
        }
        return headers;
    }

    // Attachment-3/4 style category banners — not fill-in data rows.
    function isDataMatrixSectionBannerRow(row, rowCells) {
        if (!row || !rowCells || !rowCells.length) {
            return false;
        }
        const texts = rowCells.map(function (cell) {
            return columnHeaderMatchText(nodeEditText(cell) || cell.innerText || cell.textContent || '');
        }).filter(Boolean);
        if (!texts.length) {
            return false;
        }
        if (rowLooksLikeColumnHeaders(texts)) {
            return false;
        }
        const unique = [];
        texts.forEach(function (t) {
            const key = String(t).toLowerCase();
            if (unique.indexOf(key) < 0) {
                unique.push(key);
            }
        });
        const title = texts[0];
        if (isComponentSectionBannerText(title)) {
            return true;
        }
        // Real data rows usually have distinct column values.
        if (unique.length >= 2) {
            return false;
        }
        return false;
    }

    function isColumnHeaderText(text) {
        const t = columnHeaderMatchText(text);
        if (!t) {
            return false;
        }
        // Column headers are short chrome only — never treat body paragraphs as headers.
        if (t.length > 72) {
            return false;
        }
        if (isKnownLabelText(t)) {
            return true;
        }
        if (/name\s*&\s*designation|signature\s*&\s*date|prepared\s*by|checked\s*by|approved\s*by|reviewed\s*by|performed\s*by|verified\s*by|compiled\s*by|complied\s*by|recorded\s*by|department\)/.test(t)) {
            return true;
        }
        // SOP / utilities / deviation / training / component / MOC parts grid column titles.
        if (/acceptance\s*criteria|observation\s*\/?\s*result|sign\s*and\s*date|corrective\s*actions?|facility\s*\/?\s*section|equipment\s*(name|id)|ref\.?\s*document|document\s*no|weighing\s*balance|major\s*components?|sub\s*systems?|parts?\s*name|actual\s*moc|certificate\s*no/.test(t)) {
            return true;
        }
        // List of SOPs / verification grids — lock the column title only.
        // A value such as "rohan sop no test1" must not count as the SOP No. header,
        // or the row under it is saved one place too high.
        if (/^(title\s+of\s+sop|sop\s*(no\.?|number|num)\.?|effective\s*date|changes?\s*required|identification\s*of\s+sop|list\s+of\s+sops?)\s*:?$/i.test(t)) {
            return true;
        }
        // MOC / Parts identification grid headers.
        if (/^(parts?\s*name|acceptance\s*criteria|actual\s*moc|certificate\s*no\.?|sign\s*and\s*date)\s*:?$/i.test(t)) {
            return true;
        }
        if (/parts?\s*name|actual\s*moc|certificate\s*no|acceptance\s*criteria|sign\s*and\s*date/.test(t) && t.length <= 40) {
            return true;
        }
        if (/^(s\.?\s*no\.?|sr\.?\s*no\.?|sop\s*(no\.?|number)?|title(\s+of\s+sop)?|effective(\s*date)?|performed(\s*by)?|verified(\s*by)?|changes?(\s*required)?)$/i.test(t)) {
            return true;
        }
        // 7.4 instruments grid: S. No. / Instrument / Location / Function / Class.
        if (/^location\s*in\s*the\s*system$|^function$|^class$/.test(t)
            || /c\/nc|other\s*type/.test(t)) {
            return true;
        }
        return /^(sr\.?\s*no\.?|s\.?\s*no\.?|revision(\s*no\.?)?|change|date|role|name|signature|designation|remark|comments?|dq|ioq|urs|rpn|[sdl]|check|method|observation|result|deviation|version|annex\.?|specification|instrument)$/i.test(t)
            || /revision\s*no|requirements?|acceptable|not acceptable|checked\s*by\s*sign/.test(t);
    }

    /** Cache matrix header row index on the table so that row always stays locked. */
    function getCachedMatrixHeaderIndex(table) {
        if (!table) {
            return -1;
        }
        if (table.hasAttribute('data-neq-header-idx')) {
            const cached = Number(table.getAttribute('data-neq-header-idx'));
            // A cached -1 was stored before the Parts Name header was recognized.
            // Recompute so continuation rows are not shifted by one.
            if (Number.isInteger(cached) && cached >= 0) {
                return cached;
            }
        }
        const info = findDataMatrixHeaderInfo(table);
        const idx = info && Number.isInteger(info.headerIndex) ? info.headerIndex : -1;
        table.setAttribute('data-neq-header-idx', String(idx));
        return idx;
    }

    function rowLooksLikeMocPartsColumnHeaders(texts) {
        if (!texts || texts.length < 3) {
            return false;
        }
        const hasParts = texts.some(function (t) {
            return /parts?\s*name/i.test(String(t || '')) && String(t || '').length <= 40;
        });
        const hasAcceptance = texts.some(function (t) {
            return /acceptance\s*criteria/i.test(String(t || '')) && String(t || '').length <= 40;
        });
        const hasMoc = texts.some(function (t) {
            return /actual\s*moc|\bmoc\b/i.test(String(t || '')) && String(t || '').length <= 40;
        });
        const hasCertificate = texts.some(function (t) {
            return /certificate\s*no/i.test(String(t || '')) && String(t || '').length <= 40;
        });
        const hasSignDate = texts.some(function (t) {
            return /sign\s*and\s*date/i.test(String(t || '')) && String(t || '').length <= 40;
        });
        // Parts Name + Acceptance Criteria + (Actual MOC or Certificate or Sign and Date)
        if (hasParts && hasAcceptance && (hasMoc || hasCertificate || hasSignDate)) {
            return true;
        }
        if (hasParts && hasMoc && (hasCertificate || hasSignDate || hasAcceptance)) {
            return true;
        }
        return false;
    }

    // Material of Construction / Parts Name identification grids — text fills only (no checkboxes).
    function isMocPartsMatrixTable(table) {
        if (!table || isApprovalOrSignatoryTable(table) || isLetterheadIdentityTable(table)) {
            return false;
        }
        if (isCommentsOrDeviationsTable(table)) {
            return false;
        }
        const headers = tableHeaderFingerprint(table);
        if (rowLooksLikeMocPartsColumnHeaders(headers)) {
            return true;
        }
        const blob = normalizeEditText(table.innerText || table.textContent || '').toLowerCase();
        if (!blob) {
            return false;
        }
        return /parts?\s*name/.test(blob)
            && /acceptance\s*criteria/.test(blob)
            && (/actual\s*moc|\bmoc\b|certificate\s*no|sign\s*and\s*date/.test(blob));
    }

    function rowLooksLikeSopColumnHeaders(texts) {
        if (!texts || texts.length < 3) {
            return false;
        }
        // All cells must look like short header chrome (not body prose).
        const allShort = texts.every(function (t) {
            return String(t || '').trim().length > 0 && String(t || '').trim().length <= 48;
        });
        if (!allShort) {
            return false;
        }
        // MOC / Parts Name grid: Parts Name | Acceptance Criteria | Actual MOC | Certificate No. | Sign and Date
        if (rowLooksLikeMocPartsColumnHeaders(texts)) {
            return true;
        }
        const hasSr = texts.some(function (t) {
            return /^(s\.?\s*no\.?|sr\.?\s*no\.?)$/i.test(String(t || '').trim());
        });
        const hasTitleSop = texts.some(function (t) {
            return /title\s*(of\s*)?sop/i.test(String(t || '')) && String(t || '').length <= 40;
        });
        const hasSopNo = texts.some(function (t) {
            return /^(sop\s*(no\.?|number|num)\.?)$/i.test(String(t || '').trim())
                || (/sop\s*(no\.?|number)/i.test(String(t || '')) && String(t || '').length <= 24);
        });
        const hasEffective = texts.some(function (t) {
            return /^effective(\s*date)?$/i.test(String(t || '').trim());
        });
        const hasPerformed = texts.some(function (t) {
            return /^performed\s*by$/i.test(String(t || '').trim());
        });
        const hasVerified = texts.some(function (t) {
            return /^verified\s*by$/i.test(String(t || '').trim());
        });
        const hasChanges = texts.some(function (t) {
            return /^changes?\s*required$/i.test(String(t || '').trim());
        });
        // List of SOPs: Sr | Title of SOP | SOP Number | Effective Date
        if (hasSr && hasTitleSop && (hasSopNo || hasEffective)) {
            return true;
        }
        if (hasSr && hasSopNo && hasEffective) {
            return true;
        }
        // SOP verification: Sr | SOP No | Title | Performed by | Verified by | Changes required
        if ((hasSr || hasSopNo) && (hasPerformed || hasVerified || hasChanges)
            && (hasTitleSop || hasSopNo)) {
            return true;
        }
        return false;
    }

    function isApprovalSectionLabelRow(row, map) {
        if (!row) {
            return false;
        }
        // Role | Name | Signature | Date — "Checked By" / "Approved By" are role values;
        // the Name column still needs Select User. Do not treat these as section headings.
        if (map && Number(map.role) >= 0) {
            return false;
        }
        const table = row.closest ? row.closest('table') : null;
        if (table) {
            const autoMap = map || getApprovalColumnMap(table);
            if (autoMap && Number(autoMap.role) >= 0) {
                return false;
            }
            // Even without an explicit role index: multi-column Name|Signature body rows are not headings.
            if (autoMap
                && Number(autoMap.name) >= 0
                && Number(autoMap.signature) >= 0) {
                const probeCells = Array.from(row.children).filter(function (el) {
                    return (el.tagName === 'TD' || el.tagName === 'TH') && !isToolCell(el);
                });
                if (probeCells.length >= 3) {
                    const rolePattern = /^(prepared|checked|approved|reviewed|performed|compiled|complied|recorded)\s*by\b/;
                    const nameText = normalizeEditText(
                        (probeCells[autoMap.name] && (probeCells[autoMap.name].innerText || probeCells[autoMap.name].textContent)) || ''
                    ).toLowerCase();
                    const roleElsewhere = probeCells.some(function (cell, idx) {
                        if (idx === autoMap.name) {
                            return false;
                        }
                        const t = normalizeEditText(cell.innerText || cell.textContent || '').toLowerCase();
                        return rolePattern.test(t);
                    });
                    if (roleElsewhere && !rolePattern.test(nameText)) {
                        return false;
                    }
                }
            }
        }
        const cells = Array.from(row.children).filter(function (el) {
            return (el.tagName === 'TD' || el.tagName === 'TH') && !isToolCell(el);
        });
        if (!cells.length) {
            return false;
        }
        const texts = cells.map(function (cell) {
            // Ignore select placeholders when re-checking after a bad bind.
            const select = cell.querySelector && cell.querySelector('select.neq-approver-select');
            if (select) {
                return '';
            }
            return normalizeEditText(cell.innerText || cell.textContent).toLowerCase();
        });
        // Pending dash placeholders are empty for section-label detection.
        const nonEmpty = texts.filter(function (t) {
            return t !== '' && t !== '—' && t !== '-' && t !== '--';
        });
        if (!nonEmpty.length) {
            return false;
        }
        // Entire row is only a section label like "Prepared By" (Attachment 14 layout without Role col).
        if (nonEmpty.length === 1
            && /^(prepared|checked|approved|reviewed|performed|compiled|complied|recorded)\s*by\b/.test(nonEmpty[0])
            && !/name|signature|date|designation|identified/.test(nonEmpty[0])) {
            return true;
        }
        const joined = texts.join(' | ');
        if (/^(prepared|checked|approved|reviewed|performed|compiled|complied|recorded)\s*by\b/.test(joined)
            && !/name\s*[&\/]\s*designation|signature|\brole\b/.test(joined)
            && nonEmpty.length === 1) {
            return true;
        }
        return false;
    }

    function isApprovalNameColumnHeaderText(text) {
        const t = normalizeEditText(text).toLowerCase();
        if (!t) {
            return false;
        }
        return /name\s*&\s*designation/.test(t)
            || /name\s*\/\s*designation/.test(t)
            || /^name(\s*\/\s*designation)?$/.test(t)
            || /^name\s*\/\s*designation$/.test(t);
    }

    function isApprovalHeaderRow(row, map) {
        if (!row) {
            return false;
        }
        // "Checked By | Amit User - designation" is a filled approver row.
        // The word designation is the person's title, not a column heading.
        const leadCell = getRowCells(row)[0];
        const lead = columnHeaderMatchText(
            (leadCell && (leadCell.innerText || leadCell.textContent)) || ''
        );
        if (/^(prepared|checked|approved|reviewed|performed|compiled|complied|recorded)\s*by\b/.test(lead)) {
            return false;
        }
        if (isApprovalSectionLabelRow(row, map)) {
            return true;
        }
        if (map && Number.isInteger(map.headerIndex)) {
            const rows = getDirectTableRows(row.closest ? row.closest('table') : null);
            if (rows.indexOf(row) === map.headerIndex) {
                return true;
            }
        }
        if (isHeaderLikeRow(row)) {
            return true;
        }
        const cells = Array.from(row.children).filter(function (el) {
            return (el.tagName === 'TD' || el.tagName === 'TH') && !isToolCell(el);
        });
        if (!cells.length) {
            return false;
        }
        const texts = cells.map(function (cell) {
            return normalizeEditText(cell.innerText || cell.textContent).toLowerCase();
        });
        const joined = texts.join(' | ');
        // Classic approval header: Name & Designation | Signature | Date (or Name/ Designation)
        if ((/name\s*&\s*designation|name\s*\/\s*designation/.test(joined) || texts.some(isApprovalNameColumnHeaderText))
            && /signature/.test(joined)) {
            return true;
        }
        if (/prepared\s*by|checked\s*by|approved\s*by|reviewed\s*by|performed\s*by|compiled\s*by|complied\s*by/.test(joined)
            && /signature|name\s*[&\/]\s*designation|designation/.test(joined)) {
            // Role data rows ("Checked By |  | — | —") are not column-header rows.
            const roleOnly = texts.filter(function (t) {
                return t && t !== '—' && t !== '-' && t !== '--';
            });
            if (roleOnly.length === 1
                && /^(prepared|checked|approved|reviewed|performed|compiled|complied|recorded)\s*by\b/.test(roleOnly[0])) {
                return false;
            }
            return true;
        }
        return false;
    }

    function isApproverNameHeaderCell(cell) {
        if (!cell) {
            return false;
        }
        const text = normalizeEditText(cell.innerText || cell.textContent).toLowerCase();
        return isApprovalNameColumnHeaderText(text)
            || (/designation/.test(text) && /name/.test(text));
    }

    function rowIsShortColumnSubheader(texts) {
        const filled = (texts || []).filter(function (text) {
            return text !== '';
        });
        if (filled.length < 3) {
            return false;
        }
        if (filled.some(function (text) {
            return String(text).length > 28;
        })) {
            return false;
        }
        const hits = filled.filter(function (text) {
            return /^(s|d|l|rpn|remarks?)$/i.test(text)
                || /high\s*#?\s*\/\s*medium/i.test(text)
                || isColumnHeaderText(text);
        }).length;
        return hits >= Math.ceil(filled.length * 0.6);
    }

    function rowLooksLikeColumnHeaders(texts) {
        const filled = texts.filter(function (text) {
            return text !== '';
        });
        if (!filled.length) {
            return false;
        }
        const hits = filled.filter(isColumnHeaderText).length;
        return hits >= Math.max(1, Math.ceil(filled.length / 2));
    }

    function isHeaderLikeRow(row) {
        if (!row) {
            return false;
        }
        // Explicit <th> row = always a header.
        const rawCells = Array.from(row.children).filter(function (el) {
            return (el.tagName === 'TD' || el.tagName === 'TH') && !isToolCell(el);
        });
        if (rawCells.length && rawCells.every(function (el) {
            return (el.tagName || '').toUpperCase() === 'TH';
        })) {
            const thTexts = rawCells.map(function (cell) {
                return columnHeaderMatchText(cell.innerText || cell.textContent || '');
            });
            // A continued page can render the first data row as th. User values stay editable.
            if (rowTextsAreColumnTitles(thTexts)) {
                return true;
            }
        }
        const cells = rawCells;
        if (!cells.length) {
            return false;
        }
        // Strip accidental "ed" prefixes so edCheck|edMethod still counts as a header row.
        const texts = cells.map(function (cell) {
            return columnHeaderMatchText(cell.innerText || cell.textContent);
        });
        const table = row.closest ? row.closest('table') : null;
        // Known data-matrix header row from fingerprint (SOP list / verify / utilities…).
        if (table) {
            const headerIdx = getCachedMatrixHeaderIndex(table);
            if (headerIdx >= 0) {
                const rows = getDirectTableRows(table);
                if (rows.indexOf(row) === headerIdx && rowTextsAreColumnTitles(texts)) {
                    return true;
                }
            }
        }
        // Single-column Comments header row must stay locked (starts with Comments).
        // Do not lock body rows like "add comment comment2".
        if (texts.length === 1 && /^comments?\s*:?\s*$/i.test(String(texts[0] || '').trim())) {
            return true;
        }
        // Deviation header row: cells must START as header labels.
        // Do NOT lock data rows polluted as "adddeviation (if any)4".
        if (texts.length >= 2) {
            const pureHeaderStarts = texts.filter(function (t) {
                const s = String(t || '').trim();
                return /^(deviation(\s*\([^)]*\))?|acceptable\s*\/?\s*not\s*acceptable|corrective\s*actions?)\b/i.test(s);
            }).length;
            if (pureHeaderStarts >= 2
                && texts.some(function (t) { return /^deviation\b/i.test(String(t || '').trim()); })
                && texts.some(function (t) { return /^(corrective|acceptable)/i.test(String(t || '').trim()); })) {
                return true;
            }
        }
        // Training Attachment-12 Topic / Applicability header (often 2–4 cols) must stay locked
        // even when cells.length <= 2 (that blanket used to unlock Topic headers).
        if (texts.length >= 2 && texts.length <= 5) {
            const topicish = texts.filter(function (t) {
                return isTrainingColumnTitleText(t);
            }).length;
            if (topicish >= 2
                && texts.some(function (t) { return isTrainingColumnTitleText(t) && /topic/i.test(String(t || '')); })
                && texts.some(function (t) {
                    return isTrainingColumnTitleText(t) && /applicability|as\s*per\s*document/i.test(String(t || ''));
                })) {
                return true;
            }
        }
        // Attachment 3 Major Component / Sub System column header row.
        if (texts.length >= 2) {
            const componentHdr = texts.filter(function (t) {
                return isMajorComponentColumnHeaderText(t);
            }).length;
            if (componentHdr >= 2
                || (texts.some(function (t) { return /major\s*component/i.test(String(t || '')); })
                    && texts.some(function (t) { return /sub\s*system/i.test(String(t || '')); }))) {
                return true;
            }
        }
        // Component section banners (Main Body / Mechanical / Electrical).
        if (isDataMatrixSectionBannerRow(row, cells)
            || texts.every(function (t) {
                return !t || isComponentSectionBannerText(t) || isVerifiedByRowText(t);
            }) && texts.some(function (t) { return isComponentSectionBannerText(t); })) {
            return true;
        }
        // SOP List / SOP Verification column headers (any attachment).
        if (rowLooksLikeSopColumnHeaders(texts)) {
            return true;
        }
        // 7.4 Process Control Instruments column titles stay locked.
        if (texts.length >= 4) {
            const instrumentHead = texts.join(' ');
            if (/instrument/.test(instrumentHead)
                && (/location\s*in\s*the\s*system/.test(instrumentHead) || /c\/nc/.test(instrumentHead))
                && (/function/.test(instrumentHead) || /c\/nc/.test(instrumentHead))) {
                return true;
            }
        }
        if (texts.length >= 2 && texts.length <= 4) {
            const revisionHead = texts.join(' ');
            if (/revision/.test(revisionHead) && /change/.test(revisionHead) && /\bdate\b/.test(revisionHead)) {
                return true;
            }
            if (/deviation/.test(revisionHead) && /corrective|remarks?|preventive/.test(revisionHead)
                && texts.every(function (t) { return String(t || '').length < 48; })) {
                return true;
            }
        }
        if (isMetadataFieldTable(table) || cells.length <= 2) {
            return false;
        }
        const emptyCount = texts.filter(function (text) {
            return text === '';
        }).length;
        if (emptyCount > 0) {
            return false;
        }
        return rowLooksLikeColumnHeaders(texts);
    }

    // True for the box the user should type in.
    // Lock only real labels (left name, or a header row). Never lock a value
    // just because its text still says "Facility" / "Equipment ID" after a bad save.
    function isFillInField(node) {
        if (!node || isInsideOfficialLogoCell(node)) {
            return false;
        }
        const original = normalizeEditText(node.getAttribute('data-neq-original') || '');
        const live = normalizeEditText(node.innerText || node.textContent);
        const text = original || live;
        const cell = getOwningCell(node);
        const tag = (node.tagName || '').toLowerCase();

        if (text === '') {
            return true;
        }
        // Attachment letterhead stores the name in this cell ("EQUIPMENT NAME").
        if (/^equipment\s*name\b/i.test(text)) {
            const nameCell = getOwningCell(node) || (((tag === 'td' || tag === 'th') ? node : null));
            const nameRow = nameCell ? getRowCells(nameCell) : [];
            if (nameCell && nameRow.indexOf(nameCell) > 0) {
                return true;
            }
        }
        if (!cell) {
            // Body chrome / TOC must never be treated as fill-in (prevents mid-word corruption).
            if (/table\s+of\s+contents/i.test(text)) {
                return false;
            }
            // Short section titles only ("1.0 OBJECTIVE") — long body prose stays editable.
            if (/^\d+\.\d+(\.\d+)?\s+\S/.test(text) && text.length <= 72) {
                return false;
            }
            return !isKnownLabelText(text);
        }

        const table = cell.closest ? cell.closest('table') : null;
        const rowCells = getRowCells(cell);
        const col = rowCells.indexOf(cell);
        const row = cell.parentElement;
        // S. No. / 1 / 5.1 / 9 is a value. The heading "S. No." stays locked.
        if (isSerialOrAnnexNoColumnCell(cell) && !isHeaderLikeRow(row)) {
            return true;
        }
        const outerTable = (function () {
            let found = table;
            let current = table;
            while (current) {
                if (isUnlockableFormTable(current)) {
                    found = current;
                }
                const parent = current.parentElement;
                current = parent && parent.closest ? parent.closest('table') : null;
            }
            return found;
        }());
        const outerRow = (function () {
            let currentRow = cell.closest ? cell.closest('tr') : row;
            while (currentRow) {
                if (currentRow.closest('table') === outerTable) {
                    return currentRow;
                }
                const parent = currentRow.parentElement;
                currentRow = parent && parent.closest ? parent.closest('tr') : null;
            }
            return row;
        }());

        // Value cell immediately after a label (2-col or 4-col letterhead rows).
        // Placeholder fragments in the value cell ("system name") still count as fill-in.
        if (col > 0 && (looksLikeFieldLabel(rowCells[col - 1].innerText || rowCells[col - 1].textContent)
            || isShortIdentityLabel(rowCells[col - 1].innerText || rowCells[col - 1].textContent)
            || isKnownLabelText(rowCells[col - 1].innerText || rowCells[col - 1].textContent))) {
            return true;
        }

        // Explicit label cell before a value/empty neighbour — not fill-in.
        if (col >= 0 && col < rowCells.length - 1 && looksLikeFieldLabel(text) && !isIdentityLabelContinuation(text)) {
            const nextText = normalizeEditText(rowCells[col + 1].innerText || rowCells[col + 1].textContent);
            if (nextText === '' || !looksLikeFieldLabel(nextText) || isIdentityPlaceholderValue(nextText)) {
                return false;
            }
        }

        if (isMetadataFieldTable(outerTable || table) || (rowCells.length === 2 && looksLikeFieldLabel(rowCells[0].innerText || rowCells[0].textContent))) {
            if (col <= 0) {
                return false;
            }
            // Value column is always fill-in, even when it currently shows a label fragment.
            return true;
        }

        if (isComparisonTable(outerTable || table) || isCommentsOrDeviationsTable(outerTable || table) || isInsideUnlockableFormTable(cell)) {
            if (isHeaderLikeRow(outerRow)) {
                return false;
            }
            if (isDataMatrixSectionBannerRow(outerRow, rowCells)
                || isComponentSectionBannerText(text)
                || isAttachmentChromeTitleText(text)) {
                return false;
            }
            // Attachment 3 component grids: section banners stay locked.
            if (isComponentSubsystemMatrixTable(outerTable || table)) {
                const rowText = normalizeEditText((outerRow && (outerRow.innerText || outerRow.textContent)) || '');
                if (isComponentSectionBannerText(rowText) || isUtilitiesSectionTitleText(rowText)) {
                    return false;
                }
            }
            return true;
        }

        if (tag === 'th' && isHeaderLikeRow(outerRow)) {
            return false;
        }

        if (isComponentSectionBannerText(text) || isAttachmentChromeTitleText(text)
            || isMajorComponentColumnHeaderText(text)) {
            return false;
        }
        if (isDataMatrixSectionBannerRow(outerRow, rowCells)) {
            return false;
        }

        if (col === 0 && isKnownLabelText(text) && rowCells.length > 1) {
            return false;
        }

        return true;
    }

    // Unlock fill-in fields only. Headers, labels, and approval chrome stay locked.
    function shouldKeepReadOnly(node) {
        if (!node || isToolCell(node)) {
            return true;
        }
        if (isInsideOfficialLogoCell(node)) {
            return true;
        }
        const serialCell = getOwningCell(node) || (
            ((node.tagName || '').toLowerCase() === 'td' || (node.tagName || '').toLowerCase() === 'th') ? node : null
        );
        if (serialCell && isSerialOrAnnexNoColumnCell(serialCell) && !isHeaderLikeRow(serialCell.parentElement)) {
            return false;
        }
        // A continued table has no column-title row. Its first line is still
        // a value cell, including the Sign and Date box the user clicks.
        const continuedCell = getOwningCell(node) || (
            ((node.tagName || '').toLowerCase() === 'td' || (node.tagName || '').toLowerCase() === 'th') ? node : null
        );
        const continuedTable = continuedCell && continuedCell.closest ? continuedCell.closest('table') : null;
        if (continuedTable && continuedTable.getAttribute('data-neq-page-split') === '1'
            && continuedCell.parentElement && !isHeaderLikeRow(continuedCell.parentElement)) {
            return false;
        }
        // Contents lines stay typeable so the page number can be changed.
        if (isInsideTocRegion(node)) {
            return false;
        }
        if (isNeqApprovalLockCell(node)) {
            return true;
        }
        const bodyTag = (node.tagName || '').toLowerCase();
        // Every body paragraph, heading, and bullet stays typeable.
        // Short titles such as "15.0 REFERENCES" and lines such as
        // "Deviation & corrective actions taken." were being locked.
        if ((bodyTag === 'p' || bodyTag === 'li' || /^h[1-6]$/.test(bodyTag))
            && !(node.closest && node.closest('td, th, header, footer, .docx_header, .docx_footer'))) {
            return false;
        }
        const cell = getOwningCell(node) || (
            ((node.tagName || '').toLowerCase() === 'td' || (node.tagName || '').toLowerCase() === 'th') ? node : null
        );
        const prose = normalizeEditText(
            (node.getAttribute && node.getAttribute('data-neq-original')) || node.innerText || node.textContent || ''
        );
        // Attachment 3 title + Main Body / Mechanical / Electrical section banners — always lock
        // (even when the title is longer than typical chrome).
        if (isAttachmentChromeTitleText(prose) || isComponentSectionBannerText(prose)
            || isMajorComponentColumnHeaderText(prose)) {
            return true;
        }
        if (cell && cell.parentElement) {
            const rowCells = getRowCells(cell.parentElement);
            if (isDataMatrixSectionBannerRow(cell.parentElement, rowCells)) {
                return true;
            }
            const table = cell.closest ? cell.closest('table') : null;
            if (table && isComponentSubsystemMatrixTable(table)) {
                const rowText = normalizeEditText(cell.parentElement.innerText || cell.parentElement.textContent || '');
                if (isComponentSectionBannerText(rowText) || isUtilitiesSectionTitleText(rowText)) {
                    return true;
                }
            }
        }
        // Body / section prose must stay editable (headers are short chrome only).
        if (prose.length > 72) {
            if (cell && isHeaderLikeRow(cell.parentElement)) {
                return true;
            }
            return false;
        }
        if (!isFillInField(node)) {
            return true;
        }
        if (cell && isHeaderLikeRow(cell.parentElement)) {
            return true;
        }
        // Extra safety: TH cells, or short header-label cells in a header-like row.
        if (cell) {
            if ((cell.tagName || '').toLowerCase() === 'th') {
                return true;
            }
            const cellText = columnHeaderMatchText(cell.innerText || cell.textContent || '');
            if (cellText && cellText.length <= 48 && isColumnHeaderText(cellText) && cell.parentElement) {
                const siblings = Array.from(cell.parentElement.children).filter(function (el) {
                    return (el.tagName === 'TD' || el.tagName === 'TH') && !isToolCell(el);
                });
                if (siblings.length >= 3) {
                    const siblingTexts = siblings.map(function (el) {
                        return columnHeaderMatchText(el.innerText || el.textContent || '');
                    });
                    const allShort = siblingTexts.every(function (t) {
                        return !t || t.length <= 48;
                    });
                    if (allShort && (rowLooksLikeColumnHeaders(siblingTexts) || rowLooksLikeSopColumnHeaders(siblingTexts))) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    function lockNodeReadOnly(node) {
        if (!node) {
            return;
        }
        node.removeAttribute('contenteditable');
        node.setAttribute('data-neq-locked', '1');
        node.removeEventListener('input', onMappedNodeInput);
        if (node.querySelectorAll) {
            node.querySelectorAll('[contenteditable="true"]').forEach(function (child) {
                child.removeAttribute('contenteditable');
                child.setAttribute('data-neq-locked', '1');
                child.removeEventListener('input', onMappedNodeInput);
            });
        }
    }

    // Undo accidental "ed" typing into labels/headers/values (edCheck → Check, 121ed → 121).
    function sanitizeCorruptedLabelAndHeaderCells(scope) {
        if (!scope) {
            return;
        }
        // Move typed surplus out of Comments / Deviation headers into the body cell.
        scope.querySelectorAll('table').forEach(function (table) {
            if (!isCommentsOrDeviationsTable(table) && !isSaveableDataMatrixTable(table)) {
                return;
            }
            const headerInfo = findDataMatrixHeaderInfo(table);
            if (!headerInfo) {
                return;
            }
            const headers = canonicalizeMatrixHeaders(headerInfo.headers || [], table);
            const rows = getDirectTableRows(table);
            const hi = headerInfo.headerIndex;
            if (hi < 0 || hi >= rows.length) {
                return;
            }
            const hdrCells = getRowCells(rows[hi]);
            const headerLives = hdrCells.map(function (hCell) {
                return cellLiveText(hCell) || nodeEditText(hCell);
            });
            // Parts Name | Acceptance Criteria | Actual MOC must stay as printed.
            // Rewriting them to SOP titles left a stray "MOC" and dropped the font size.
            if (rowLooksLikeMocPartsColumnHeaders(headerLives)) {
                return;
            }
            let bodyRow = rows[hi + 1] || null;
            if ((!bodyRow || isHeaderLikeRow(bodyRow)) && isCommentsBoxTable(table) && typeof tableParts === 'function') {
                const parts = tableParts(table);
                for (let p = 0; p < parts.length && (!bodyRow || isHeaderLikeRow(bodyRow)); p++) {
                    if (!parts[p] || parts[p] === table) {
                        continue;
                    }
                    const partRows = getDirectTableRows(parts[p]);
                    for (let r = 0; r < partRows.length; r++) {
                        if (!isHeaderLikeRow(partRows[r])) {
                            bodyRow = partRows[r];
                            break;
                        }
                    }
                }
            }
            const bodyCells = bodyRow ? getRowCells(bodyRow) : [];
            hdrCells.forEach(function (hCell, col) {
                const live = cellLiveText(hCell) || nodeEditText(hCell);
                const canon = headers[col] || (/^comments?\b/i.test(live) ? 'Comments' : columnHeaderMatchText(live));
                const surplus = extractHeaderTypedSurplus(live, canon);
                // Always restore clean header chrome.
                const hdrTarget = hCell.querySelector('p, li, h1, h2, h3, h4, h5, h6') || hCell;
                // Case-only difference is not pollution. Replacing textContent
                // drops Word's font size, so unlock made 7.4 headings smaller
                // than C/NC/Other type and lock kept the smaller text.
                const sameHeader = canon
                    && normalizeCompareText(live).toLowerCase() === normalizeCompareText(canon).toLowerCase();
                // A value typed over "Comments" must move into the body cell.
                // Restoring the header first used to delete that sentence.
                const replacedHeader = !surplus && canon && !sameHeader
                    && normalizeCompareText(live)
                    && normalizeCompareText(live).toLowerCase().indexOf(normalizeCompareText(canon).toLowerCase()) === -1
                    && !isColumnHeaderText(live)
                    && !isKnownLabelText(live);
                const typedIntoHeader = surplus || (replacedHeader ? normalizeCompareText(live) : '');
                const bodyEmpty = bodyCells[col]
                    && !normalizeCompareText(stripMatrixHeaderGlue(cellLiveText(bodyCells[col]), headers, col));
                if (typedIntoHeader && bodyEmpty) {
                    const bCell = resolveDataMatrixEditableCell(bodyCells[col]) || bodyCells[col];
                    const bTarget = bCell.querySelector('[contenteditable="true"]')
                        || bCell.querySelector('p, li, h1, h2, h3, h4, h5, h6')
                        || bCell;
                    bTarget.textContent = typedIntoHeader;
                    bCell.setAttribute('data-neq-user-edited', '1');
                    bCell.setAttribute('data-neq-original', cellOriginalText(bCell) || '');
                    if (bTarget !== bCell) {
                        bTarget.setAttribute('data-neq-user-edited', '1');
                    }
                }
                if (canon && !sameHeader && (!typedIntoHeader || bodyEmpty)) {
                    hdrTarget.textContent = canon;
                    hCell.setAttribute('data-neq-original', canon);
                    hCell.removeAttribute('data-neq-user-edited');
                    if (hdrTarget !== hCell) {
                        hdrTarget.setAttribute('data-neq-original', canon);
                        hdrTarget.removeAttribute('data-neq-user-edited');
                    }
                }
            });
            // Scrub already-glued body cells (filldeviation (if any)1 → fill).
            rows.forEach(function (row, absIndex) {
                if (absIndex <= hi || isHeaderLikeRow(row)) {
                    return;
                }
                getRowCells(row).forEach(function (cell, col) {
                    const live = cellLiveText(cell);
                    const cleaned = stripMatrixHeaderGlue(live, headers, col);
                    if (cleaned && normalizeCompareText(cleaned) !== normalizeCompareText(live)) {
                        const target = cell.querySelector('[contenteditable="true"]')
                            || cell.querySelector('p, li, h1, h2, h3, h4, h5, h6')
                            || cell;
                        target.textContent = cleaned;
                        cell.setAttribute('data-neq-user-edited', '1');
                    }
                });
            });
        });
        scope.querySelectorAll('td, th').forEach(function (cell) {
            if (isToolCell(cell)) {
                return;
            }
            const live = normalizeEditText(cell.innerText || cell.textContent || '');
            if (!live) {
                return;
            }
            const cleaned = stripAccidentalEdPollution(live, '');
            const row = cell.parentElement;
            const rowCells = getRowCells(cell);
            const col = rowCells.indexOf(cell);
            const isHeader = isHeaderLikeRow(row)
                || rowLooksLikeColumnHeaders(rowCells.map(function (c) {
                    return columnHeaderMatchText(c.innerText || c.textContent || '');
                }));
            const isLeftLabel = col === 0 && rowCells.length >= 2
                && (isKnownLabelText(cleaned) || looksLikeFieldLabel(cleaned) || isColumnHeaderText(cleaned));
            const isValuePollution = cleaned !== live
                && cleaned
                && (isHeader || isLeftLabel || cell.getAttribute('data-neq-user-edited') === '1'
                    || cell.querySelector('[data-neq-user-edited="1"]'));
            if (!isValuePollution && !(isHeader || isLeftLabel) && !/^ed(?=[A-Za-z])/i.test(live)) {
                return;
            }
            if ((isHeader || isLeftLabel) && /^ed(?=[A-Za-z])/i.test(live)) {
                const stripped = live.replace(/^ed(?=[A-Za-z])/i, '');
                if (!stripped || (!isColumnHeaderText(stripped) && !isKnownLabelText(stripped) && !looksLikeFieldLabel(stripped))) {
                    return;
                }
                const target = cell.querySelector('p, li, h1, h2, h3, h4, h5, h6') || cell;
                target.textContent = stripped;
                cell.removeAttribute('data-neq-user-edited');
                cell.setAttribute('data-neq-original', stripped);
                if (target !== cell) {
                    target.removeAttribute('data-neq-user-edited');
                    target.setAttribute('data-neq-original', stripped);
                }
                return;
            }
            if (cleaned && cleaned !== live && !isUtilitiesSectionTitleText(cleaned)) {
                const target = cell.querySelector('[contenteditable="true"]')
                    || cell.querySelector('p, li, h1, h2, h3, h4, h5, h6')
                    || cell;
                if (target.getAttribute && target.getAttribute('contenteditable') === 'true') {
                    target.textContent = cleaned;
                    if (target.hasAttribute('data-neq-original')) {
                        target.setAttribute('data-neq-original', stripAccidentalEdPollution(target.getAttribute('data-neq-original') || '', ''));
                    }
                } else if (isHeader || isLeftLabel) {
                    target.textContent = cleaned;
                    cell.setAttribute('data-neq-original', cleaned);
                }
            }
        });
    }

    function isLabelOriginal(original) {
        const t = normalizeEditText(original);
        if (!t) {
            return false;
        }
        return looksLikeFieldLabel(t) || isKnownLabelText(t) || /table\s+of\s+contents/i.test(t);
    }

    function getCandidateNodes() {
        const scope = getEditScopeRoot();
        if (!scope) {
            return [];
        }

        const selector = 'td, th, p, h1, h2, h3, h4, h5, h6, li';
        const all = Array.from(scope.querySelectorAll(selector));

        return all.filter(function (node) {
            if (isToolCell(node)) {
                return false;
            }

            const tag = (node.tagName || '').toLowerCase();

            // Empty letterhead value cells (Equipment ID, Facility, etc.):
            // edit the whole td so the box is clickable, not a collapsed inner <p>.
            if (tag === 'td' || tag === 'th') {
                if (node.querySelector('table')) {
                    return false;
                }
                if (isEmptyHeaderValueCell(node)) {
                    return true;
                }
                if (node.querySelector(selector) !== null) {
                    return false;
                }
                return true;
            }

            const parentCell = node.closest('td, th');
            if (parentCell && isEmptyHeaderValueCell(parentCell)) {
                return false;
            }

            if (node.querySelector(selector) !== null) {
                return false;
            }
            return true;
        });
    }

    function clearMappedEditAttrs(nodes) {
        (nodes || getCandidateNodes()).forEach(function (node) {
            node.removeAttribute('data-neq-index');
            node.removeAttribute('data-neq-original');
            node.removeAttribute('data-neq-zone');
            node.removeAttribute('data-neq-locked');
            node.removeAttribute('data-neq-sync-key');
            node.removeAttribute('contenteditable');
            node.removeEventListener('input', onMappedNodeInput);
        });
    }

    function onMappedNodeInput(event) {
        const source = event.currentTarget;
        markIdentityUserEdit(source);
        // Do not claim random empty Word indexes while typing — wrong binds corrupt save.
        const index = source.getAttribute('data-neq-index');
        if (index === null) {
            return;
        }

        const zone = getNodeZone(source);
        if (zone !== 'header' && zone !== 'footer') {
            return;
        }
        const syncKey = source.getAttribute('data-neq-sync-key') || getSyncKey(source);
        if (!syncKey) {
            return;
        }

        const text = nodeEditText(source);
        getCandidateNodes().forEach(function (node) {
            if (node === source) {
                return;
            }
            if (node.getAttribute('data-neq-index') !== index) {
                return;
            }
            if (getNodeZone(node) !== zone) {
                return;
            }
            if ((node.getAttribute('data-neq-sync-key') || getSyncKey(node)) !== syncKey) {
                return;
            }
            if (node.getAttribute('contenteditable') !== 'true') {
                return;
            }
            if (nodeEditText(node) === text) {
                return;
            }
            setNodePlainText(node, text);
        });
    }

    // How Save mapping works (read this if values land in the wrong Word cell):
    // 1) Python lists every writable Word paragraph/cell and gives each an index.
    // 2) This function stamps that index on the matching HTML node (data-neq-index).
    // 3) On Save, collectTextEdits sends {index, text}. Python writes into that Word slot.
    // Wrong index = value appears in the wrong place, or as a new line outside the table.
    function mapDomNodesToDocxTargets(targets) {
        const scope = getEditScopeRoot();
        stampTocRegion(scope);
        rebuildTocProtectedIndexes(targets);
        const candidates = getCandidateNodes();
        clearMappedEditAttrs(candidates);

        if (!Array.isArray(targets) || !targets.length) {
            return { mapped: 0, total: 0 };
        }

        const usedNodes = new Set();
        let mapped = 0;
        assignedDocxIndexes = new Set();

        function assignNode(node, index, expected, zone) {
            usedNodes.add(node);
            assignedDocxIndexes.add(String(index));
            node.setAttribute('data-neq-index', String(index));
            const liveText = nodeEditText(node);
            const expectedNorm = normalizeEditText(expected);
            // Prefer on-screen text as original when it already matches the Word slot
            // (incl. preview auto-list "7.0 …"). Storing Word-only text made every
            // heading look dirty and produced 100+ false edits that corrupted tables.
            const originalText = (liveText && textMatchesWordTarget(liveText, expectedNorm))
                ? liveText
                : (expectedNorm || liveText);
            node.setAttribute('data-neq-original', originalText);
            node.setAttribute('data-neq-zone', zone);
            const syncKey = getSyncKey(node);
            if (syncKey) {
                node.setAttribute('data-neq-sync-key', syncKey);
            } else {
                node.removeAttribute('data-neq-sync-key');
            }
        }

        function linkRepeatedHeaderFooterCopies() {
            ['header', 'footer'].forEach(function (zone) {
                const zoneNodes = candidates.filter(function (node) {
                    return getNodeZone(node) === zone;
                });
                if (zoneNodes.length < 2) {
                    return;
                }

                const groups = [];
                const groupMap = new Map();
                zoneNodes.forEach(function (node) {
                    const root = getRepeatRoot(node, zone);
                    const key = root || '__flat__';
                    if (!groupMap.has(key)) {
                        const group = [];
                        groupMap.set(key, group);
                        groups.push(group);
                    }
                    groupMap.get(key).push(node);
                });

                if (groups.length < 2) {
                    return;
                }

                const primary = groups[0];
                groups.slice(1).forEach(function (group) {
                    primary.forEach(function (source) {
                        const indexAttr = source.getAttribute('data-neq-index');
                        if (indexAttr === null) {
                            return;
                        }
                        const syncKey = source.getAttribute('data-neq-sync-key') || getSyncKey(source);
                        if (!syncKey) {
                            return;
                        }
                        group.forEach(function (copy) {
                            if (usedNodes.has(copy)) {
                                return;
                            }
                            if (getSyncKey(copy) !== syncKey) {
                                return;
                            }
                            assignNode(
                                copy,
                                Number(indexAttr),
                                source.getAttribute('data-neq-original') || '',
                                zone
                            );
                        });
                    });
                });
            });
        }

        function bindEmptySlotsToEmptyTableCells() {
            function promoteInnerEmptyToCell(cell) {
                const inner = cell.querySelector('[data-neq-index]');
                if (!inner || cell.hasAttribute('data-neq-index')) {
                    return;
                }
                const innerOriginal = normalizeEditText(
                    inner.getAttribute('data-neq-original') || inner.innerText || inner.textContent
                );
                if (innerOriginal !== '') {
                    return;
                }
                const innerIndex = Number(inner.getAttribute('data-neq-index'));
                usedNodes.delete(inner);
                unassignNodeIndex(inner);
                assignedDocxIndexes.add(String(innerIndex));
                assignNode(cell, innerIndex, '', getNodeZone(cell));
                mapped += 1;
            }

            function clearCellIndexes(cell) {
                unassignNodeIndex(cell);
                usedNodes.delete(cell);
                cell.querySelectorAll('[data-neq-index]').forEach(function (inner) {
                    usedNodes.delete(inner);
                    unassignNodeIndex(inner);
                });
            }

            function bindEmptyCell(cell, afterIndex) {
                if (!cell || isToolCell(cell) || cell.querySelector('table')) {
                    return afterIndex;
                }
                if (!shouldBindEmptyCellIndex(cell)) {
                    return afterIndex;
                }
                if (nodeEditText(cell) !== '') {
                    const filledIndex = mappedIndexOfCell(cell);
                    return filledIndex !== null ? filledIndex : afterIndex;
                }
                promoteInnerEmptyToCell(cell);
                let current = mappedIndexOfCell(cell);
                if (current !== null && (afterIndex === null || current > afterIndex)) {
                    return current;
                }
                clearCellIndexes(cell);
                const pick = takeNextEmptyWordTarget(afterIndex, getNodeZone(cell));
                if (!pick) {
                    return afterIndex;
                }
                assignNode(cell, Number(pick.index), '', pick.zone || getNodeZone(cell));
                mapped += 1;
                return Number(pick.index);
            }

            stampNeqTables();
            getMappablePreviewTables().forEach(function (table) {
                let lastIndex = null;
                getDirectTableRows(table).forEach(function (row) {
                    Array.from(row.children).forEach(function (cell) {
                        const tag = (cell.tagName || '').toLowerCase();
                        if ((tag !== 'td' && tag !== 'th') || isToolCell(cell)) {
                            return;
                        }
                        lastIndex = bindEmptyCell(cell, lastIndex);
                    });
                });
            });
        }

        function assignLeftoverEmptyHeaderCells() {
            bindEmptySlotsToEmptyTableCells();

            const leftoverAssigned = new Set();
            candidates.forEach(function (node) {
                const indexAttr = node.getAttribute('data-neq-index');
                if (indexAttr !== null) {
                    leftoverAssigned.add(indexAttr);
                }
            });

            const leftoverTargets = targets.filter(function (target) {
                if (normalizeEditText(target.text) !== '') {
                    return false;
                }
                if (assignedDocxIndexes.has(String(target.index))) {
                    return false;
                }
                return !leftoverAssigned.has(String(target.index));
            });

            const leftoverNodes = candidates.filter(function (node) {
                if (usedNodes.has(node)) {
                    return false;
                }
                if (!isLetterheadCell(node) && getNodeZone(node) !== 'header' && getNodeZone(node) !== 'footer') {
                    return false;
                }
                return nodeEditText(node) === '';
            });

            // Remaining empty header/footer boxes only get leftover EMPTY slots
            // in the same zone. A leftover body blank line must not be bound to
            // a letterhead cell (that saves header text as a paragraph outside the table).
            leftoverNodes.forEach(function (node) {
                const nodeZone = getNodeZone(node);
                let foundAt = -1;
                for (let i = 0; i < leftoverTargets.length; i += 1) {
                    if ((leftoverTargets[i].zone || 'body') === nodeZone) {
                        foundAt = i;
                        break;
                    }
                }
                if (foundAt < 0) {
                    return;
                }
                const target = leftoverTargets.splice(foundAt, 1)[0];
                assignNode(node, Number(target.index), '', target.zone || nodeZone);
                mapped += 1;
            });
        }

        targets.forEach(function (target) {
            const index = Number(target.index);
            const expected = normalizeEditText(target.text);
            const zone = target.zone || 'body';
            if (!Number.isInteger(index) || index < 0) {
                return;
            }

            // Empty slots are mapped later onto empty TABLE cells only.
            // Matching them here would bind blank lines between tables and skip
            // Document Title / Equipment ID / risk-table boxes.
            if (expected === '') {
                return;
            }

            let zoneCandidates = candidates.filter(function (node) {
                return getNodeZone(node) === zone && !usedNodes.has(node);
            });

            let matches = zoneCandidates.filter(function (node) {
                return textMatchesWordTarget(nodeEditText(node), expected);
            });

            if (!matches.length) {
                return;
            }

            // Always 1:1. Empty header cells (Document Title, Equipment name, etc.)
            // must not share one index. Page copies are linked after this pass.
            let nodeToAssign = matches[0];
            if (zone === 'header' || zone === 'footer') {
                const firstRoot = getRepeatRoot(matches[0], zone);
                if (firstRoot) {
                    const scoped = matches.filter(function (node) {
                        return getRepeatRoot(node, zone) === firstRoot;
                    });
                    if (scoped.length) {
                        nodeToAssign = scoped[0];
                    }
                }
            }

            assignNode(nodeToAssign, index, expected, zone);
            mapped += 1;
        });

        linkRepeatedHeaderFooterCopies();
        assignLeftoverEmptyHeaderCells();
        stampNeqTables();
        rememberSavedParagraphs(scope);

        console.info('[NEQ Edit Map] mapped', mapped, 'of', targets.length, 'DOCX targets from', candidates.length, 'DOM candidates');
        return { mapped: mapped, total: targets.length };
    }

    function rememberSavedParagraphs(scope) {
        if (!isEditingEnabled || savedParagraphSnapshot.length || !scope) {
            return;
        }
        const seen = new Set();
        scope.querySelectorAll('[data-neq-index]').forEach(function (node) {
            if (node.closest && node.closest('header, footer, .docx_header, .docx_footer')) {
                return;
            }
            const index = node.getAttribute('data-neq-index');
            if (index === null || index === '' || seen.has(index)) {
                return;
            }
            const tag = (node.tagName || '').toLowerCase();
            const inToc = isInsideTocRegion(node);
            if ((tag === 'td' || tag === 'th') && !inToc) {
                return;
            }
            seen.add(index);
            savedParagraphSnapshot.push({
                index: Number(index),
                original: node.getAttribute('data-neq-original') || '',
                zone: node.getAttribute('data-neq-zone') || 'body',
                toc: inToc,
                inTable: !!(node.closest && node.closest('td, th')),
            });
        });
    }

    function previousLineText(node) {
        if (!node) {
            return '';
        }
        let prev = node.previousElementSibling;
        while (prev) {
            const text = normalizeEditText(prev.innerText || prev.textContent || '');
            if (text) {
                return text;
            }
            prev = prev.previousElementSibling;
        }
        const row = node.closest ? node.closest('tr') : null;
        if (row && row.previousElementSibling) {
            return normalizeEditText(row.previousElementSibling.innerText || row.previousElementSibling.textContent || '');
        }
        return '';
    }

    function previousBodyAnchor(node) {
        if (!node) {
            return null;
        }
        let prev = node.previousElementSibling;
        while (prev) {
            if (prev.getAttribute && prev.getAttribute('data-neq-new-para') === '1') {
                prev = prev.previousElementSibling;
                continue;
            }
            const text = normalizeEditText(prev.innerText || prev.textContent || '');
            let idx = prev.getAttribute ? prev.getAttribute('data-neq-index') : null;
            if ((idx === null || idx === '') && prev.querySelector) {
                const inner = prev.querySelector('[data-neq-index]');
                if (inner && !(inner.closest && inner.closest('td, th'))) {
                    idx = inner.getAttribute('data-neq-index');
                }
            }
            if (text || (idx !== null && idx !== '')) {
                return { text: text, index: idx };
            }
            prev = prev.previousElementSibling;
        }
        return null;
    }

    function collectDeletedParagraphEdits(scope) {
        const edits = [];
        if (!scope || !savedParagraphSnapshot.length) {
            return edits;
        }
        savedParagraphSnapshot.forEach(function (item) {
            if (!Number.isInteger(item.index) || item.index < 0) {
                return;
            }
            let liveNode = null;
            scope.querySelectorAll('[data-neq-index="' + item.index + '"]').forEach(function (node) {
                if (liveNode) {
                    return;
                }
                if (node.closest && node.closest('header, footer, .docx_header, .docx_footer')) {
                    return;
                }
                liveNode = node;
            });
            const original = item.original || '';
            if (isCheckboxGlyphOnly(original)) {
                return;
            }
            if (isProtectedApprovalTailText(original) || (liveNode && isProtectedApprovalTailText(nodeEditText(liveNode)))) {
                return;
            }
            // Table text is saved as a cell edit. Replacing a cell with a
            // date picker or a name list removes the paragraph node, and a
            // paragraph clear was wiping Name, Department, and Signature.
            if (item.inTable || (liveNode && liveNode.closest && liveNode.closest('td, th'))) {
                return;
            }
            if (!liveNode) {
                if (!normalizeEditText(original)) {
                    return;
                }
                edits.push({
                    index: item.index,
                    text: '',
                    original_text: original,
                    zone: item.zone || 'body',
                    clear: true,
                });
                return;
            }
            const live = nodeEditText(liveNode);
            if (normalizeCompareText(live) === normalizeCompareText(original)) {
                return;
            }
            if (!normalizeEditText(live)) {
                edits.push({
                    index: item.index,
                    text: '',
                    original_text: original,
                    zone: item.zone || 'body',
                    clear: true,
                });
                return;
            }
            if (item.toc || isInsideTocRegion(liveNode) || isLikelyTocEntryText(live, liveNode) || isLikelyTocEntryText(original, null)) {
                edits.push({
                    index: item.index,
                    text: live,
                    original_text: original,
                    zone: item.zone || 'body',
                    toc_line: true,
                });
            }
        });
        return edits;
    }

    function getLetterheadCells() {
        const scope = getEditScopeRoot();
        if (!scope) {
            return [];
        }

        const cells = [];
        scope.querySelectorAll('header, .docx_header, footer, .docx_footer').forEach(function (root) {
            root.querySelectorAll('td, th').forEach(function (cell) {
                cells.push(cell);
            });
        });
        scope.querySelectorAll('table').forEach(function (table) {
            if (!isUnlockableFormTable(table) && !isInsideUnlockableFormTable(table)) {
                return;
            }
            table.querySelectorAll('td, th').forEach(function (cell) {
                if (cells.indexOf(cell) === -1) {
                    cells.push(cell);
                }
            });
        });
        if (!cells.length) {
            const firstSection = scope.querySelector('section.docx') || scope;
            const firstTable = firstSection.querySelector('table');
            if (firstTable) {
                firstTable.querySelectorAll('td, th').forEach(function (cell) {
                    cells.push(cell);
                });
            }
        }
        return cells;
    }

    function findFillInForCell(cell) {
        if (!cell) {
            return null;
        }
        if (cell.getAttribute('contenteditable') === 'true') {
            return cell;
        }
        const inner = cell.querySelector('[contenteditable="true"]');
        if (inner) {
            return inner;
        }
        const rowCells = getRowCells(cell);
        const next = rowCells[rowCells.indexOf(cell) + 1];
        if (!next) {
            return null;
        }
        if (next.getAttribute('contenteditable') === 'true') {
            return next;
        }
        return next.querySelector('[contenteditable="true"]');
    }

    function bindLetterheadCellClicks(enabled) {
        getLetterheadCells().forEach(function (cell) {
            cell.removeEventListener('click', onLetterheadCellClick);
            if (enabled) {
                cell.addEventListener('click', onLetterheadCellClick);
            }
        });
    }

    function getEditableTargets() {
        return getUnlockableNodes().filter(function (node) {
            return node.hasAttribute('data-neq-index');
        });
    }

    function getUnlockableNodes() {
        const nodes = getCandidateNodes().filter(function (node) {
            return !isToolCell(node);
        });
        const scope = getEditScopeRoot();
        if (!scope) {
            return nodes;
        }
        const seen = new Set(nodes);
        Array.from(scope.querySelectorAll('td, th')).forEach(function (cell) {
            if (seen.has(cell) || isToolCell(cell)) {
                return;
            }
            if (cell.querySelector('table')) {
                return;
            }
            const hasChildUnlockable = nodes.some(function (node) {
                return node !== cell && cell.contains(node);
            });
            if (hasChildUnlockable) {
                return;
            }
            seen.add(cell);
            nodes.push(cell);
        });
        return nodes;
    }

    function claimUnusedEmptyTarget(node) {
        if (!node || node.hasAttribute('data-neq-index') || isToolCell(node) || isInsideNewTableRow(node)) {
            return false;
        }
        if (isInsideTocRegion(node)) {
            return false;
        }
        const owning = getOwningCell(node);
        const cell = owning || (((node.tagName || '').toLowerCase() === 'td' || (node.tagName || '').toLowerCase() === 'th') ? node : null);
        if (cell && !shouldBindEmptyCellIndex(cell) && node.getAttribute('data-neq-checkbox') !== '1') {
            return false;
        }
        const parentCell = getOwningCell(node);
        if (parentCell && parentCell !== node && mappedIndexOfCell(parentCell) !== null) {
            return false;
        }
        const zone = getNodeZone(node);
        const pick = takeNextEmptyWordTarget(previousMappedIndex(node), zone);
        if (!pick) {
            return false;
        }
        assignedDocxIndexes.add(String(pick.index));
        node.setAttribute('data-neq-index', String(pick.index));
        node.setAttribute('data-neq-zone', pick.zone || zone);
        if (!node.hasAttribute('data-neq-original')) {
            node.setAttribute('data-neq-original', '');
        }
        return true;
    }

    function makeNodeEditable(node) {
        if (!node || isToolCell(node)) {
            return false;
        }
        if (node.closest && node.closest('.neq-table-toolbar, .neq-row-tool-cell, select, button, input.neq-checkbox')) {
            return false;
        }
        // Headers / labels / approval stamp cells must never become free-text fields.
        if (shouldKeepReadOnly(node)) {
            lockNodeReadOnly(node);
            return false;
        }
        if (!node.hasAttribute('data-neq-original')) {
            node.setAttribute('data-neq-original', nodeEditText(node));
        }
        // Table cells save via cell_edits — do not claim random empty Word indexes
        // (that was writing Instrument text into S. No. / body slots).
        if (!node.hasAttribute('data-neq-index') && nodeEditText(node) === '') {
            const tag = (node.tagName || '').toLowerCase();
            const cell = getOwningCell(node) || ((tag === 'td' || tag === 'th') ? node : null);
            // Only a real empty value cell may take an empty Word slot.
            // A blank line on the page must not take a slot from inside a table.
            if (cell && (shouldBindEmptyCellIndex(cell) || node.getAttribute('data-neq-checkbox') === '1')) {
                claimUnusedEmptyTarget(node);
            }
        }
        node.removeAttribute('data-neq-locked');
        node.setAttribute('contenteditable', 'true');
        node.setAttribute('spellcheck', 'false');
        node.removeEventListener('input', onMappedNodeInput);
        node.addEventListener('input', onMappedNodeInput);
        return true;
    }

    function isInsideNestedLayoutTable(cell) {
        if (!cell || !cell.closest) {
            return false;
        }
        const innerTable = cell.parentElement ? cell.parentElement.closest('table') : null;
        if (!innerTable) {
            return false;
        }
        const outerCell = innerTable.parentElement ? innerTable.parentElement.closest('td, th') : null;
        if (!outerCell) {
            return false;
        }
        const outerTable = outerCell.closest('table');
        if (!outerTable || outerTable === innerTable) {
            return false;
        }
        return outerTable.classList.contains('neq-check-matrix')
            || isSopDataMatrixTable(outerTable)
            || isSaveableDataMatrixTable(outerTable)
            || isComparisonTable(outerTable)
            || isCommentsOrDeviationsTable(outerTable);
    }

    function hideEmptyNestedLayoutTables(cell) {
        if (!cell || !cell.querySelectorAll) {
            return;
        }
        const directText = normalizeEditText(Array.from(cell.childNodes).map(function (node) {
            if (node.nodeType === 3) {
                return node.textContent || '';
            }
            if (!node.tagName || (node.tagName || '').toLowerCase() === 'table') {
                return '';
            }
            return node.innerText || node.textContent || '';
        }).join(' '));
        cell.querySelectorAll('table').forEach(function (inner) {
            const nestedText = normalizeEditText(inner.innerText || inner.textContent || '');
            if (nestedText && nestedText !== directText) {
                const holder = Array.from(cell.children).find(function (child) {
                    const tag = (child.tagName || '').toLowerCase();
                    return tag === 'p' || tag === 'span';
                });
                if (holder) {
                    if (!directText) {
                        holder.textContent = nestedText;
                    }
                } else {
                    const p = document.createElement('p');
                    p.textContent = nestedText;
                    cell.insertBefore(p, inner);
                }
            }
            inner.style.setProperty('display', 'none', 'important');
            inner.querySelectorAll('[contenteditable]').forEach(function (el) {
                el.removeAttribute('contenteditable');
            });
        });
    }

    // Unlock fill-in values only. Prefer editable paragraphs inside cells
    // so long OBJECTIVE / SCOPE lines stay typeable (not a non-editable inner <p>).
    function unlockPreviewNodes() {
        const scope = getEditScopeRoot();
        if (!scope) {
            return;
        }

        sanitizeCorruptedLabelAndHeaderCells(scope);
        stripCheckboxesFromDeviationCommentsTables(scope);

        // Pre-stamp matrix header indexes so isHeaderLikeRow locks them during unlock.
        scope.querySelectorAll('table').forEach(function (table) {
            table.removeAttribute('data-neq-header-idx');
            getCachedMatrixHeaderIndex(table);
        });

        let unlocked = 0;
        const blockSelector = 'p, li, h1, h2, h3, h4, h5, h6';

        scope.querySelectorAll('td, th').forEach(function (cell) {
            if (isToolCell(cell)) {
                return;
            }
            if (isInsideNestedLayoutTable(cell)) {
                lockNodeReadOnly(cell);
                return;
            }
            if (shouldKeepReadOnly(cell)) {
                lockNodeReadOnly(cell);
                return;
            }
            const ownerTable = cell.closest('table');
            const ownerIsDataGrid = !!(ownerTable && (
                ownerTable.classList.contains('neq-check-matrix')
                || isSopDataMatrixTable(ownerTable)
                || isSaveableDataMatrixTable(ownerTable)
                || isComparisonTable(ownerTable)
                || isCommentsOrDeviationsTable(ownerTable)
            ));
            // A layout table inside a data cell must not become a second edit box.
            if (cell.querySelector('table') && ownerIsDataGrid) {
                hideEmptyNestedLayoutTables(cell);
                cell.querySelectorAll('[contenteditable="true"]').forEach(function (el) {
                    if (el !== cell) {
                        el.removeAttribute('contenteditable');
                        el.removeEventListener('input', onMappedNodeInput);
                    }
                });
                if (makeNodeEditable(cell)) {
                    cell.style.setProperty('vertical-align', 'top', 'important');
                    cell.style.setProperty('text-align', 'center', 'important');
                    unlocked += 1;
                }
                return;
            }
            if (cell.querySelector('table')) {
                hideEmptyNestedLayoutTables(cell);
                const innerBlocks = Array.from(cell.children).filter(function (child) {
                    const tag = (child.tagName || '').toLowerCase();
                    return (tag === 'p' || tag === 'li' || /^h[1-6]$/.test(tag))
                        && !(child.closest && child.closest('table') !== cell.closest('table'));
                });
                if (innerBlocks.length) {
                    cell.removeAttribute('contenteditable');
                    innerBlocks.forEach(function (block) {
                        if (makeNodeEditable(block)) {
                            unlocked += 1;
                        }
                    });
                    return;
                }
                if (makeNodeEditable(cell)) {
                    unlocked += 1;
                }
                return;
            }

            const childBlocks = Array.from(cell.children).filter(function (child) {
                const tag = (child.tagName || '').toLowerCase();
                return tag === 'p' || tag === 'li' || /^h[1-6]$/.test(tag);
            });

            if (childBlocks.length) {
                cell.removeAttribute('contenteditable');
                cell.removeEventListener('input', onMappedNodeInput);
                childBlocks.forEach(function (block) {
                    if (makeNodeEditable(block)) {
                        unlocked += 1;
                    }
                });
                return;
            }

            // Empty / span-only value cells — edit the cell itself.
            cell.querySelectorAll('[contenteditable="true"]').forEach(function (child) {
                if (child === cell) {
                    return;
                }
                child.removeAttribute('contenteditable');
                child.removeEventListener('input', onMappedNodeInput);
            });
            if (makeNodeEditable(cell)) {
                unlocked += 1;
            }
        });

        scope.querySelectorAll(blockSelector).forEach(function (node) {
            if (isToolCell(node)) {
                return;
            }
            const parentCell = node.closest('td, th');
            if (parentCell && (isInsideNestedLayoutTable(parentCell) || parentCell.querySelector('table'))) {
                return;
            }
            if (parentCell && !parentCell.querySelector('table')) {
                // Already handled with the cell above.
                return;
            }
            if (makeNodeEditable(node)) {
                unlocked += 1;
            }
        });

        // Final pass: keep any leftover header/label nodes locked.
        scope.querySelectorAll('td, th, p, li, h1, h2, h3, h4, h5, h6').forEach(function (node) {
            if (isToolCell(node)) {
                return;
            }
            if (shouldKeepReadOnly(node)) {
                lockNodeReadOnly(node);
            }
        });

        unifyMatrixDataCellEditors();

        // Contents lines stay typeable: section, title, and page number.
        scope.querySelectorAll('[data-neq-toc="1"]').forEach(function (node) {
            const tag = (node.tagName || '').toLowerCase();
            if (tag === 'table') {
                node.querySelectorAll('p, li, td, th').forEach(function (inner) {
                    if (isToolCell(inner)) {
                        return;
                    }
                    if (!inner.hasAttribute('data-neq-original')) {
                        inner.setAttribute('data-neq-original', nodeEditText(inner));
                    }
                    inner.removeAttribute('data-neq-locked');
                    inner.setAttribute('contenteditable', 'true');
                });
                return;
            }
            if (!node.hasAttribute('data-neq-original')) {
                node.setAttribute('data-neq-original', nodeEditText(node));
            }
            node.removeAttribute('data-neq-locked');
            node.setAttribute('contenteditable', 'true');
        });

        console.info('[NEQ Edit] force-unlocked nodes:', unlocked,
            'contenteditable in preview:', scope.querySelectorAll('[contenteditable="true"]').length);

        unlockIdentityValueCells(scope);
    }

    // Ensure Effective Date / Document Title / Equipment blanks are always typeable.
    function unlockIdentityValueCells(scope) {
        if (!scope) {
            return;
        }
        scope.querySelectorAll('td, th').forEach(function (cell) {
            if (isToolCell(cell) || cell.querySelector('table')) {
                return;
            }
            const rowCells = getRowCells(cell);
            const col = rowCells.indexOf(cell);
            if (col <= 0) {
                return;
            }
            const label = normalizeEditText(rowCells[col - 1].innerText || rowCells[col - 1].textContent || '');
            if (!label) {
                return;
            }
            if (!/effective\s*date|document\s*title|document\s*no|equipment|facilit|reference\s*document|serial\s*no|in-?house\s*equipment|supplier|manufacturer|purchase\s*order|\bversion\b/i.test(label)) {
                return;
            }
            // Value cell: edit the whole cell (easier for blank Effective Date).
            cell.querySelectorAll('[contenteditable="true"]').forEach(function (child) {
                if (child === cell) {
                    return;
                }
                child.removeAttribute('contenteditable');
                child.removeEventListener('input', onMappedNodeInput);
            });
            makeNodeEditable(cell);
            if (nodeEditText(cell) === '' && !cell.hasAttribute('data-neq-index')) {
                claimUnusedEmptyTarget(cell);
            }
            cell.style.setProperty('min-height', '1.4em', 'important');
            cell.style.setProperty('cursor', 'text', 'important');
        });
    }

    function forceLetterheadValueCellsEditable(scope) {
        if (!scope) {
            return;
        }
        scope.querySelectorAll('td, th').forEach(function (cell) {
            if (isToolCell(cell) || isInsideOfficialLogoCell(cell) || cell.querySelector('img')) {
                return;
            }
            const rowCells = getRowCells(cell);
            const col = rowCells.indexOf(cell);
            if (col <= 0) {
                return;
            }
            const live = normalizeEditText(nodeEditText(cell));
            const ownLabel = /^equipment\s*name\b/i.test(live);
            const prevText = normalizeEditText(rowCells[col - 1].innerText || rowCells[col - 1].textContent || '');
            if (!ownLabel && !/document\s*no|reference\s*document|ref\.?\s*document|equipment|facilit|effective\s*date|document\s*title|\bversion\b/i.test(prevText)) {
                return;
            }
            if (!ownLabel && live && (isShortIdentityLabel(live) || isIdentityFormLabelText(live) || looksLikeFieldLabel(live))) {
                return;
            }
            cell.removeAttribute('data-neq-locked');
            cell.querySelectorAll('[data-neq-locked="1"]').forEach(function (child) {
                child.removeAttribute('data-neq-locked');
            });
            cell.querySelectorAll('[contenteditable]').forEach(function (child) {
                if (child !== cell) {
                    child.removeAttribute('contenteditable');
                }
            });
            cell.setAttribute('contenteditable', 'true');
            cell.setAttribute('spellcheck', 'false');
            cell.style.setProperty('min-height', '1.4em', 'important');
            cell.style.setProperty('cursor', 'text', 'important');
            if (!cell.hasAttribute('data-neq-original')) {
                cell.setAttribute('data-neq-original', live);
            }
            cell.removeEventListener('input', onMappedNodeInput);
            cell.addEventListener('input', onMappedNodeInput);
        });
    }

    function updateEditContentButtonState() {
        const btn = document.getElementById('btnFloatEditContent');
        if (!btn) {
            return;
        }
        if (isEditingEnabled) {
            btn.innerHTML = '<i class="fas fa-lock mr-1"></i> Lock Content';
            btn.classList.remove('btn-dark', 'btn-neq-edit', 'btn-success');
            btn.classList.add('btn-warning', 'btn-neq-lock');
            btn.style.backgroundColor = '#f6c23e';
            btn.style.borderColor = '#d4a017';
            btn.style.color = '#3d2e00';
            btn.title = 'Lock content (read-only again)';
        } else {
            btn.innerHTML = '<i class="fas fa-edit mr-1"></i> Edit Content';
            btn.classList.add('btn-success', 'btn-neq-edit');
            btn.classList.remove('btn-warning', 'btn-neq-lock', 'btn-dark');
            btn.style.backgroundColor = '#1cc88a';
            btn.style.borderColor = '#13855c';
            btn.style.color = '#ffffff';
            btn.title = 'Unlock all fields and values for typing';
        }
    }

    function onLetterheadCellClick(event) {
        if (!isEditingEnabled) {
            return;
        }

        const cell = event.currentTarget;
        if (isToolCell(cell)) {
            return;
        }

        // Cell itself is editable — let the browser place the caret (fields + values).
        if (cell.getAttribute('contenteditable') === 'true') {
            return;
        }

        const clickedLine = event.target.closest('p, h1, h2, h3, h4, h5, h6, li');
        if (clickedLine && cell.contains(clickedLine) && clickedLine.getAttribute('contenteditable') === 'true') {
            return;
        }

        const editable = findFillInForCell(cell);
        if (!editable) {
            return;
        }

        if (editable.contains(event.target) && event.target !== cell) {
            return;
        }

        event.preventDefault();
        editable.focus();
    }

    function getFormFieldTargets() {
        const form = document.getElementById('neqFillForm');
        const wrapper = form || getPreviewContentRoot();
        if (!wrapper) {
            return [];
        }

        return Array.from(wrapper.querySelectorAll('input, textarea, select')).filter(function (node) {
            return (node.type || '').toLowerCase() !== 'hidden';
        });
    }

    function setFormFieldsEditable(isEditable) {
        const form = document.getElementById('neqFillForm');
        if (form) {
            form.setAttribute('data-neq-form-locked', isEditable ? '0' : '1');
        }
        getFormFieldTargets().forEach(function (node) {
            if (node.getAttribute('data-neq-always-locked') === '1') {
                node.disabled = true;
                return;
            }
            const tag = (node.tagName || '').toLowerCase();
            const type = (node.type || '').toLowerCase();
            if (tag === 'select' || type === 'checkbox' || type === 'radio' || type === 'date' || type === 'file') {
                node.disabled = !isEditable;
                if (isEditable) {
                    node.removeAttribute('disabled');
                }
            } else {
                node.disabled = false;
                node.removeAttribute('disabled');
                node.readOnly = !isEditable;
                if (isEditable) {
                    node.removeAttribute('readonly');
                } else {
                    node.setAttribute('readonly', 'readonly');
                }
            }
        });
    }

    function disableEditing() {
        setFormFieldsEditable(false);
        previewShell.classList.remove('neq-edit-mode');
        isEditingEnabled = false;
        lastEditableFocus = null;
        lastStructureAnchor = null;
        clearInsertTargetHighlight();
        setFloatToolbarVisible(true);
        updateEditContentButtonState();
        updateApprovalActionButtons();
    }

    async function loadDocxTextTargets() {
        if (!currentTextTargetsUrl) {
            throw new Error('Text target mapping URL is missing.');
        }

        const response = await fetch(withCacheBust(currentTextTargetsUrl, Date.now()), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Accept': 'application/json', 'Cache-Control': 'no-cache' },
        });
        const payload = await parseJsonResponse(response);
        if (!response.ok) {
            throw new Error(payload.error || 'Unable to load document edit map.');
        }

        docxTextTargets = Array.isArray(payload.targets) ? payload.targets : [];
        return docxTextTargets;
    }

    function unifyMatrixDataCellEditors() {
        return;
    }

    async function enableEditing() {
        try {
            isEditingEnabled = true;

            if (!docxTextTargets) {
                await loadDocxTextTargets();
            }

            bindTableRowTools(false);
            stampTocRegion(getEditScopeRoot());
            rebuildTocProtectedIndexes(docxTextTargets || []);
            const result = mapDomNodesToDocxTargets(docxTextTargets || []);
            if (!result.mapped) {
                isEditingEnabled = false;
                throw new Error('Could not map editable fields to the Word document. Try viewing a clean original file.');
            }

            unlockPreviewNodes();
            unifyMatrixDataCellEditors();
            enhanceCheckboxWidgets();
            stripCheckboxesFromDeviationCommentsTables(getEditScopeRoot());
            stampCellOriginals();
            clearIdentityPlaceholderValueCells();
            repairLetterheadDocumentRow(getEditScopeRoot());
            forceLetterheadValueCellsEditable(getEditScopeRoot());
            bindLetterheadCellClicks(true);
            bindTableRowTools(true);
            bindApproverDropdowns();
            setFormFieldsEditable(true);
            const scope = getEditScopeRoot();
            if (scope) {
                scope.removeEventListener('input', onIdentityFieldInput, true);
                scope.addEventListener('input', onIdentityFieldInput, true);
            }
            previewShell.classList.add('neq-edit-mode');
            lastEditableFocus = null;
            lastStructureAnchor = null;
            clearInsertTargetHighlight();
            document.addEventListener('keydown', onEditableEnterKey, true);
            document.addEventListener('keydown', onEditableSpaceKey, true);
            document.addEventListener('focusin', onPreviewFocusIn, true);
            document.addEventListener('mouseup', onPreviewMouseUp, true);
            document.addEventListener('click', onPreviewClickSelect, true);
            protectedViewBanner.classList.add('d-none');
            editingModeBanner.classList.remove('d-none');
            setFloatToolbarVisible(true);
            updateFloatInsertHint(null);
            updateEditContentButtonState();
            previewError.classList.add('d-none');
            const editWrapper = previewArea ? previewArea.querySelector('.docx-wrapper') : null;
            if (editWrapper) {
                splitPreviewOntoA4Pages(editWrapper);
                stampVisiblePageNumbers(editWrapper);
            }
        } catch (error) {
            clearMappedEditAttrs();
            isEditingEnabled = false;
            setFloatToolbarVisible(true);
            updateEditContentButtonState();
            previewError.textContent = error.message || 'Unable to enable editing.';
            previewError.classList.remove('d-none');
        }
    }

    async function applyPythonImagePlacements(previewHtmlUrl) {
        const response = await fetch(previewHtmlUrl, { credentials: 'same-origin' });
        if (!response.ok) {
            const payload = await response.json().catch(function () {
                return {};
            });
            throw new Error(payload.error || 'Python image resolution failed.');
        }

        const data = await response.json();
        const placements = data.placements || [];

        console.info('[NEQ Preview] Python /docx-image-map placements:', placements.map(function (placement) {
            return {
                part: placement.part,
                embed_id: placement.embed_id,
                media_filename: placement.media_filename,
                has_crop: placement.has_crop,
            };
        }));

        if (data.messages && data.messages.length) {
            console.info('[NEQ Preview] Python messages:', data.messages);
        }

        await new Promise(function (resolve) {
            setTimeout(resolve, 0);
        });

        const logoCell = await waitForHeaderLogoCell(previewArea, 3000);
        if (!logoCell) {
            console.warn('[NEQ Preview] Header logo cell not found in docx-preview output.');
            return;
        }

        applyOfficialHeaderLogo(previewArea);
    }

    // Convert a Word/docx-preview length (pt, px, %) into a comparable ratio unit.
    function cssLengthToRatio(value) {
        const raw = String(value || '').trim().toLowerCase();
        if (!raw || raw === 'auto') {
            return 0;
        }
        const amount = parseFloat(raw);
        if (!isFinite(amount) || amount <= 0) {
            return 0;
        }
        if (raw.endsWith('%')) {
            return amount;
        }
        if (raw.endsWith('pt')) {
            return amount * (96 / 72);
        }
        if (raw.endsWith('in')) {
            return amount * 96;
        }
        if (raw.endsWith('cm')) {
            return amount * (96 / 2.54);
        }
        if (raw.endsWith('mm')) {
            return amount * (96 / 25.4);
        }
        if (raw.endsWith('pc')) {
            return amount * 16;
        }
        return amount;
    }

    function directTableColumns(table) {
        const group = table.querySelector(':scope > colgroup');
        if (!group) {
            return [];
        }
        return Array.from(group.children).filter(function (node) {
            return node.tagName === 'COL';
        });
    }

    function columnRatiosFromCells(table) {
        const rows = table.querySelectorAll(':scope > thead > tr, :scope > tbody > tr, :scope > tr');
        for (let r = 0; r < rows.length; r++) {
            const cells = Array.from(rows[r].children).filter(function (node) {
                return node.tagName === 'TD' || node.tagName === 'TH';
            });
            if (!cells.length) {
                continue;
            }
            const parts = [];
            let complete = true;
            cells.forEach(function (cell) {
                if (!complete) {
                    return;
                }
                const span = parseInt(cell.getAttribute('colspan') || '1', 10) || 1;
                const declared = cell.style.getPropertyValue('width') || cell.getAttribute('width') || '';
                const total = cssLengthToRatio(declared);
                if (total <= 0) {
                    complete = false;
                    return;
                }
                const each = total / span;
                for (let i = 0; i < span; i++) {
                    parts.push(each);
                }
            });
            if (complete && parts.length) {
                return parts;
            }
        }
        return [];
    }

    function ensureColumnElements(table, count) {
        const existing = directTableColumns(table);
        if (existing.length === count) {
            return existing;
        }
        const group = table.querySelector(':scope > colgroup');
        if (group) {
            group.remove();
        }
        const created = document.createElement('colgroup');
        for (let i = 0; i < count; i++) {
            created.appendChild(document.createElement('col'));
        }
        table.insertBefore(created, table.firstChild);
        return Array.from(created.children);
    }

    // Scale Word column widths onto the page. Do not drop them — equal/auto columns overlap.
    function applyWordColumnRatios(table) {
        let ratios = directTableColumns(table).map(function (col) {
            return cssLengthToRatio(col.style.getPropertyValue('width') || col.getAttribute('width') || '');
        });
        const columnsComplete = ratios.length > 0 && ratios.every(function (value) {
            return value > 0;
        });
        if (!columnsComplete) {
            const fromCells = columnRatiosFromCells(table);
            if (fromCells.length) {
                ratios = fromCells;
            }
        }
        if (!ratios.length || ratios.some(function (value) { return value <= 0; })) {
            return false;
        }
        const sum = ratios.reduce(function (total, value) {
            return total + value;
        }, 0);
        if (sum <= 0) {
            return false;
        }
        ensureColumnElements(table, ratios.length).forEach(function (col, index) {
            const percent = (ratios[index] / sum) * 100;
            col.style.setProperty('width', percent.toFixed(4) + '%', 'important');
            col.removeAttribute('width');
        });
        table.style.setProperty('width', '100%', 'important');
        table.style.setProperty('max-width', '100%', 'important');
        table.style.setProperty('table-layout', 'fixed', 'important');
        table.removeAttribute('width');
        table.querySelectorAll(':scope > thead > tr > td, :scope > thead > tr > th, :scope > tbody > tr > td, :scope > tbody > tr > th, :scope > tr > td, :scope > tr > th').forEach(function (cell) {
            cell.style.removeProperty('width');
            cell.style.removeProperty('min-width');
            cell.style.removeProperty('max-width');
            cell.removeAttribute('width');
        });
        return true;
    }

    // Soft-fit page tables. Never force fixed layout on metadata/identity tables —
    // that collapses colspan Document Title value cells in preview.
    function isPreviewIdentityTable(table) {
        if (!table) {
            return false;
        }
        // SOP list / verification grids also contain "Effective Date" — not letterhead.
        if (isSopDataMatrixTable(table)) {
            return false;
        }
        if (isMetadataFieldTable(table)) {
            return true;
        }
        if (table.closest && table.closest('header, .docx_header')) {
            return true;
        }
        const text = normalizeEditText(table.innerText || table.textContent).toLowerCase();
        if (/list\s*of\s*sop|title\s*of\s*sop|verification\s*of\s*draft\s*sop|sop\s*(no|number)/.test(text)) {
            return false;
        }
        return /document\s*title/.test(text)
            || /document\s*no/.test(text)
            || /\bversion\b/.test(text)
            || (/effective\s*date/.test(text) && (/document\s*title|document\s*no|reference\s*document|equipment|facilit/.test(text)));
    }

    function isInsideIdentityTable(table) {
        let parent = table.parentElement ? table.parentElement.closest('table') : null;
        while (parent) {
            if (isPreviewIdentityTable(parent)) {
                return true;
            }
            parent = parent.parentElement ? parent.parentElement.closest('table') : null;
        }
        return false;
    }

    function isLetterheadTable(table) {
        const text = (table && (table.innerText || table.textContent) || '').toLowerCase();
        return text.indexOf('lupin') !== -1 && (text.indexOf('document title') !== -1 || text.indexOf('attachment') !== -1);
    }

    // Pull each heading row's right border out to the Lupin row.
    function alignLetterheadRightEdge(table) {
        if (!table) {
            return;
        }
        const rows = Array.from(table.rows || []);
        let companyCell = null;
        rows.forEach(function (row) {
            Array.from(row.cells || []).forEach(function (cell) {
                if ((cell.innerText || '').toLowerCase().indexOf('lupin limited') !== -1) {
                    companyCell = cell;
                }
            });
        });
        if (!companyCell) {
            return;
        }
        const targetRight = companyCell.getBoundingClientRect().right;
        rows.forEach(function (row) {
            const cells = Array.from(row.cells || []).filter(function (cell) {
                return cell.getBoundingClientRect().width > 2;
            });
            if (!cells.length) {
                return;
            }
            let last = cells[cells.length - 1];
            while (
                cells.length > 1
                && last !== companyCell
                && !(last.innerText || '').replace(/\s/g, '')
                && last.getBoundingClientRect().width < 36
            ) {
                last.style.setProperty('width', '0px', 'important');
                last.style.setProperty('min-width', '0px', 'important');
                last.style.setProperty('max-width', '0px', 'important');
                last.style.setProperty('padding', '0', 'important');
                last.style.setProperty('border', '0', 'important');
                cells.pop();
                last = cells[cells.length - 1];
            }
            if (last === companyCell) {
                return;
            }
            const box = last.getBoundingClientRect();
            const gap = targetRight - box.right;
            if (gap > 1) {
                const next = Math.ceil(box.width + gap);
                last.style.setProperty('width', next + 'px', 'important');
                last.style.setProperty('min-width', next + 'px', 'important');
                last.style.setProperty('max-width', 'none', 'important');
                last.style.setProperty('box-sizing', 'border-box', 'important');
            }
        });
    }

    function pageContentWidth(section) {
        const style = window.getComputedStyle(section);
        const width = section.clientWidth
            - (parseFloat(style.paddingLeft) || 0)
            - (parseFloat(style.paddingRight) || 0);
        return Math.floor(width);
    }

    // Shrink only tables that stick out past the Word page margins.
    // Column ratios stay as in the document, so both side margins stay equal.
    function fixSplitListHeadingNumbers(root) {
        // A page break inside a numbered heading creates an empty heading.
        // That empty heading still consumes the next number, so 7.0 is left
        // alone and INSTALLATION CHECKS becomes 8.0.
        root.querySelectorAll('[class*="docx-num-"]').forEach(function (element) {
            if (!/\bdocx-num-\d+-0\b/.test(element.className || '')) {
                return;
            }
            // S. No. cells use the same level-0 marker. Hiding an empty one
            // removed 1, 2, 3 from the Training table.
            if (element.closest && element.closest('td, th')) {
                return;
            }
            const text = (element.textContent || '').replace(/\u00a0/g, ' ').trim();
            if (text !== '') {
                element.style.removeProperty('display');
                element.style.removeProperty('counter-increment');
                return;
            }
            element.style.setProperty('display', 'none', 'important');
            element.style.setProperty('counter-increment', 'none', 'important');
        });
    }

    function boldMatchingHeadingNumbers(root) {
        if (!root || !root.querySelectorAll) {
            return;
        }
        let styleTag = document.getElementById('neq-bold-heading-numbers');
        if (!styleTag) {
            styleTag = document.createElement('style');
            styleTag.id = 'neq-bold-heading-numbers';
        }
        styleTag.textContent = '.docx-preview-container p.neq-bold-num::before{font-weight:var(--neq-num-weight,700) !important;font-size:var(--neq-num-size,inherit) !important;font-family:var(--neq-num-family,inherit) !important;}';
        document.head.appendChild(styleTag);

        root.querySelectorAll('p[class*="docx-num-"]').forEach(function (paragraph) {
            paragraph.classList.remove('neq-bold-num');
            paragraph.style.removeProperty('--neq-num-weight');
            paragraph.style.removeProperty('--neq-num-size');
            paragraph.style.removeProperty('--neq-num-family');

            // 1.0 / 2.0 / 3.0 are decimal heading markers. Arrow bullets
            // (Wingdings) must keep their own font and left position.
            const before = window.getComputedStyle(paragraph, '::before');
            let marker = (before.content || '').trim();
            if (marker && marker !== 'none' && marker !== 'normal') {
                marker = marker.replace(/\\(?:9|a0)/gi, ' ').replace(/^["']+|["']+$/g, '').trim();
            } else {
                marker = '';
            }
            const compact = marker.replace(/\s+/g, '');
            // 7.1 / 8.2.1 are counters, not a plain "7.1" string. Arrow bullets
            // have no digits and must keep the Wingdings font at the left edge.
            const isNumber = /^\d+(?:\.\d+)*\.?$/.test(compact)
                || /counter\s*\(/i.test(marker);
            const isSymbol = marker !== '' && !/\d/.test(marker) && !/counter\s*\(/i.test(marker);
            if (isSymbol) {
                return;
            }

            const text = (paragraph.textContent || '').replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
            const levelMatch = String(paragraph.className || '').match(/docx-num-\d+-(\d+)/);
            const level = levelMatch ? parseInt(levelMatch[1], 10) : -1;
            let sample = null;
            const nodes = [paragraph].concat(Array.from(paragraph.querySelectorAll('span, b, strong')));
            nodes.forEach(function (el) {
                if (!(el.textContent || '').replace(/\u00a0/g, ' ').trim() && el !== paragraph) {
                    return;
                }
                const style = window.getComputedStyle(el);
                const weight = style.fontWeight;
                const numeric = parseInt(weight, 10);
                const isBold = weight === 'bold' || weight === 'bolder' || numeric >= 600;
                if (!isBold) {
                    return;
                }
                sample = el;
            });
            const levelZeroHeading = level === 0
                && text.length > 0
                && text.length <= 80
                && !/\.\s*$/.test(text);
            if (!isSymbol && (isNumber || level >= 1 || levelZeroHeading || sample)) {
                const style = sample ? window.getComputedStyle(sample) : window.getComputedStyle(paragraph);
                paragraph.classList.add('neq-bold-num');
                paragraph.style.setProperty('--neq-num-weight', '700');
                paragraph.style.setProperty('--neq-num-size', style.fontSize || '12pt');
                paragraph.style.setProperty('--neq-num-family', style.fontFamily || 'inherit');
            }
        });
    }

    function prependSectionNumber(paragraph, prefix) {
        const walker = document.createTreeWalker(paragraph, NodeFilter.SHOW_TEXT);
        let node = walker.nextNode();
        while (node) {
            if ((node.nodeValue || '').trim()) {
                node.nodeValue = prefix + node.nodeValue;
                return;
            }
            node = walker.nextNode();
        }
    }

    function decrementLeadingSectionNumber(paragraph, orphanNumber) {
        const walker = document.createTreeWalker(paragraph, NodeFilter.SHOW_TEXT);
        let node = walker.nextNode();
        while (node) {
            const raw = node.nodeValue || '';
            if (raw.trim()) {
                const match = raw.match(/^(\s*)(\d+)(\.\d+)/);
                if (!match) {
                    return;
                }
                const major = parseInt(match[2], 10);
                if (major <= orphanNumber) {
                    return;
                }
                node.nodeValue = match[1] + (major - 1) + match[3] + raw.slice(match[0].length);
                return;
            }
            node = walker.nextNode();
        }
    }

    /*
     * Do not resize Word rows or columns here.
     * Forcing height/width on letterhead cells (Reference Document No.,
     * Equipment name, Equipment ID, Facility) stretches the original table.
     * Wrapping is limited to the contenteditable box in CSS.
     */
    function wrapTableCellText(root) {
        if (!root || !root.querySelectorAll) {
            return;
        }
        root.querySelectorAll('td[contenteditable="true"], th[contenteditable="true"]').forEach(function (cell) {
            if (cell.closest('header, .docx_header, footer, .docx_footer')) {
                return;
            }
            cell.style.setProperty('white-space', 'pre-wrap', 'important');
            cell.style.setProperty('overflow-wrap', 'anywhere', 'important');
            cell.style.setProperty('word-break', 'break-word', 'important');
            cell.querySelectorAll('p, span').forEach(function (el) {
                el.style.setProperty('white-space', 'pre-wrap', 'important');
                el.style.setProperty('overflow-wrap', 'anywhere', 'important');
                el.style.setProperty('word-break', 'break-word', 'important');
            });
        });
    }

    /*
     * A long value must not make the table wider than the Word page.
     * Keep each column's share of the width and wrap inside the cell.
     * Letterhead rows are not resized here (that pulls Lupin Limited off the page).
     */
    function clampTablesToPage(root) {
        if (!root || !root.querySelectorAll) {
            return;
        }
        root.querySelectorAll('section.docx').forEach(function (section) {
            const limit = pageContentWidth(section);
            if (limit < 80) {
                return;
            }
            section.querySelectorAll('table').forEach(function (table) {
                if (table.closest('header, footer, .docx_header, .docx_footer')) {
                    return;
                }
                if (table.parentElement && table.parentElement.closest('td, th')) {
                    return;
                }
                const width = table.getBoundingClientRect().width;
                if (width > limit + 4) {
                    applyWordColumnRatios(table);
                    table.style.setProperty('width', '100%', 'important');
                    table.style.setProperty('max-width', '100%', 'important');
                    table.style.setProperty('min-width', '0', 'important');
                    table.style.setProperty('table-layout', 'fixed', 'important');
                }
                table.querySelectorAll('td, th').forEach(function (cell) {
                    if (cell.closest('table') !== table) {
                        return;
                    }
                    cell.style.setProperty('overflow-wrap', 'anywhere', 'important');
                    cell.style.setProperty('word-break', 'break-word', 'important');
                    cell.style.setProperty('min-width', '0', 'important');
                });
            });
        });
    }

    function fitPreviewTablesToPage(root) {
        if (!root) {
            return;
        }
        fixSplitListHeadingNumbers(root);
        dropAutoSerialNumbers(root);
        clampTablesToPage(root);
        root.querySelectorAll('header table, .docx_header table').forEach(function (table) {
            // Line up the letterhead's right border across rows.
            // Never push that border past the Word page — long text wraps instead.
            const section = table.closest('section.docx');
            const pageRight = section
                ? section.getBoundingClientRect().right - 8
                : Number.POSITIVE_INFINITY;
            const rows = Array.from(table.rows || []);
            table.querySelectorAll('td, th').forEach(function (cell) {
                if (cell.closest('table') !== table) {
                    return;
                }
                cell.style.setProperty('overflow-wrap', 'anywhere', 'important');
                cell.style.setProperty('word-break', 'break-word', 'important');
            });
            let edge = 0;
            rows.forEach(function (row) {
                const cells = Array.from(row.cells || []);
                if (!cells.length) {
                    return;
                }
                edge = Math.max(edge, Math.min(cells[cells.length - 1].getBoundingClientRect().right, pageRight));
            });
            rows.forEach(function (row) {
                const cells = Array.from(row.cells || []).filter(function (cell) {
                    return cell.getBoundingClientRect().width > 2;
                });
                if (!cells.length) {
                    return;
                }
                const last = cells[cells.length - 1];
                const box = last.getBoundingClientRect();
                const missing = edge - box.right;
                if (missing > 3) {
                    last.style.setProperty('box-sizing', 'border-box', 'important');
                    last.style.setProperty('width', Math.ceil(box.width + missing) + 'px', 'important');
                    last.style.setProperty('min-width', Math.ceil(box.width + missing) + 'px', 'important');
                    last.style.setProperty('max-width', 'none', 'important');
                }
            });
        });
        alignWideBodyTables(root);
        balanceSideMargins(root);
        restoreOriginalTableGaps(root);
        extendHeadingToBodyRightEdge(root);
        clampTablesToPage(root);
        wrapTableCellText(root);
    }

    function restoreOriginalTableGaps(root) {
        root.querySelectorAll('section.docx').forEach(function (section) {
            section.querySelectorAll('table').forEach(function (table) {
                if (table.closest('header, footer, .docx_header, .docx_footer')) {
                    return;
                }
                if (table.parentElement && table.parentElement.closest('td, th')) {
                    return;
                }
                const previous = table.previousElementSibling;
                if (previous && previous.tagName === 'P' && !(previous.innerText || '').replace(/\u00a0/g, ' ').trim()) {
                    previous.style.setProperty('display', 'block', 'important');
                    previous.style.setProperty('min-height', '14px', 'important');
                    previous.style.setProperty('margin', '10px 0', 'important');
                    return;
                }
                if (previous && previous.tagName === 'TABLE') {
                    table.style.setProperty('margin-top', '18px', 'important');
                }
            });
        });
    }

    function balanceSideMargins(root) {
        root.querySelectorAll('section.docx').forEach(function (section) {
            const page = section.getBoundingClientRect();
            const tables = Array.from(section.querySelectorAll('table')).filter(function (table) {
                if (table.parentElement && table.parentElement.closest('td, th')) {
                    return false;
                }
                return table.getBoundingClientRect().width > 200;
            });
            tables.forEach(function (table) {
                const box = table.getBoundingClientRect();
                const leftSpace = box.left - page.left;
                const rightSpace = page.right - box.right;
                const extra = leftSpace - rightSpace;
                if (extra <= 8) {
                    return;
                }
                const next = Math.floor(box.width - extra);
                if (next < 200) {
                    return;
                }
                table.style.setProperty('width', next + 'px', 'important');
                table.style.setProperty('max-width', next + 'px', 'important');
                table.style.setProperty('table-layout', 'auto', 'important');
                table.style.setProperty('box-sizing', 'border-box', 'important');
            });
        });
    }

    function alignWideBodyTables(root) {
        root.querySelectorAll('section.docx').forEach(function (section) {
            const tables = Array.from(section.querySelectorAll('table')).filter(function (table) {
                if (table.closest('header, footer, .docx_header, .docx_footer')) {
                    return false;
                }
                if (table.parentElement && table.parentElement.closest('td, th')) {
                    return false;
                }
                return table.getBoundingClientRect().width > 200;
            });
            if (tables.length < 2) {
                return;
            }
            let guideRight = null;
            tables.forEach(function (table) {
                const right = table.getBoundingClientRect().right;
                if (guideRight === null || right < guideRight) {
                    guideRight = right;
                }
            });
            tables.forEach(function (table) {
                const box = table.getBoundingClientRect();
                const overflow = box.right - guideRight;
                if (overflow <= 3) {
                    return;
                }
                const next = Math.floor(box.width - overflow);
                if (next < 200) {
                    return;
                }
                table.style.setProperty('width', next + 'px', 'important');
                table.style.setProperty('max-width', next + 'px', 'important');
                table.style.setProperty('table-layout', 'auto', 'important');
                table.style.setProperty('box-sizing', 'border-box', 'important');
            });
        });
    }

    function matchLetterheadToBodyTable(section) {
        const bodyTables = Array.from(section.querySelectorAll('table')).filter(function (table) {
            if (isLetterheadTable(table) || table.closest('header, footer, .docx_header, .docx_footer')) {
                return false;
            }
            if (table.parentElement && table.parentElement.closest('td, th')) {
                return false;
            }
            return table.getBoundingClientRect().width > 80;
        });
        if (!bodyTables.length) {
            return;
        }
        let widest = bodyTables[0];
        bodyTables.forEach(function (table) {
            if (table.getBoundingClientRect().width > widest.getBoundingClientRect().width) {
                widest = table;
            }
        });
        const target = widest.getBoundingClientRect();
        const widthPx = Math.round(target.width) + 'px';
        section.querySelectorAll('header, .docx_header').forEach(function (host) {
            host.style.setProperty('overflow', 'visible', 'important');
            host.style.setProperty('max-width', 'none', 'important');
            host.style.setProperty('width', 'auto', 'important');
            host.style.setProperty('min-width', widthPx, 'important');
        });
        section.querySelectorAll('header table, .docx_header table, table').forEach(function (table) {
            if (!isLetterheadTable(table) && !table.closest('header, .docx_header')) {
                return;
            }
            table.style.setProperty('width', widthPx, 'important');
            table.style.setProperty('min-width', widthPx, 'important');
            table.style.setProperty('max-width', 'none', 'important');
            table.style.setProperty('table-layout', 'auto', 'important');
            table.style.setProperty('box-sizing', 'border-box', 'important');
            const shifted = table.getBoundingClientRect();
            const gap = shifted.left - target.left;
            if (Math.abs(gap) > 2) {
                const margin = parseFloat(window.getComputedStyle(table).marginLeft) || 0;
                table.style.setProperty('margin-left', (margin - gap) + 'px', 'important');
            }
        });
    }

    // A line directly above a table must stay inside that table's left and right edges.
    function alignLeadInParagraphs(section) {
        section.querySelectorAll('table').forEach(function (table) {
            if (table.closest('header, footer, .docx_header, .docx_footer')) {
                return;
            }
            if (table.parentElement && table.parentElement.closest('td, th')) {
                return;
            }
            const node = table.previousElementSibling;
            if (!node || node.tagName !== 'P') {
                return;
            }
            const text = (node.innerText || '').replace(/\s+/g, ' ').trim();
            if (!text) {
                return;
            }
            const box = table.getBoundingClientRect();
            const line = node.getBoundingClientRect();
            if (box.width < 40) {
                return;
            }
            const leftGap = line.left - box.left;
            const rightOverflow = line.right - box.right;
            if (leftGap >= -1 && rightOverflow <= 2) {
                return;
            }
            const margin = parseFloat(window.getComputedStyle(node).marginLeft) || 0;
            node.style.setProperty('margin-left', (margin - leftGap) + 'px', 'important');
            node.style.setProperty('margin-right', '0px', 'important');
            node.style.setProperty('width', Math.floor(box.width) + 'px', 'important');
            node.style.setProperty('max-width', Math.floor(box.width) + 'px', 'important');
            node.style.setProperty('box-sizing', 'border-box', 'important');
        });
    }

    // Comments has no Word left indent, so it sits right of the table above.
    function alignInsetBodyTables(section) {
        const tables = Array.from(section.querySelectorAll('table')).filter(function (table) {
            if (table.closest('header, footer, .docx_header, .docx_footer')) {
                return false;
            }
            if (table.parentElement && table.parentElement.closest('td, th')) {
                return false;
            }
            return true;
        });
        if (tables.length < 2) {
            return;
        }
        let anchorLeft = null;
        tables.forEach(function (table) {
            const left = table.getBoundingClientRect().left;
            if (anchorLeft === null || left < anchorLeft) {
                anchorLeft = left;
            }
        });
        tables.forEach(function (table) {
            const box = table.getBoundingClientRect();
            const gap = box.left - anchorLeft;
            if (gap <= 2) {
                return;
            }
            const margin = parseFloat(window.getComputedStyle(table).marginLeft) || 0;
            table.style.setProperty('margin-left', (margin - gap) + 'px', 'important');
        });
    }

    function isIdentityLabelContinuation(text) {
        const t = normalizeEditText(text).toLowerCase();
        if (!t || t.length > 60) {
            return false;
        }
        // Wrapped second half of "Reference Document No. (Version No.)"
        if (/^no\.?\s*\(?\s*version(\s*no\.?)?\)?$/.test(t)
            || /^\(?\s*version\s*no\.?\s*\)?$/.test(t)
            || /^no\.?\s*\(version\s*no\.?\)$/.test(t)) {
            return true;
        }
        // Wrapped second half of "Equipment/ system name" or "Equipment / system ID No."
        if (/^system\s+name\b/.test(t)
            || /^system\s+id(\s*no\.?)?$/.test(t)
            || /^id\s*no\.?$/.test(t)
            || /^\/?\s*system\s+name\b/.test(t)
            || /^\/?\s*section\b/.test(t)) {
            return true;
        }
        return false;
    }

    function isIncompleteEquipmentLabel(text) {
        const t = normalizeEditText(text).toLowerCase();
        if (!t) {
            return false;
        }
        // Split label first half only: "Equipment/" or "Equipment /" (no system name/id yet).
        return /^equipment\s*\/?\s*$/.test(t);
    }

    function isIdentityPlaceholderValue(text) {
        const t = normalizeEditText(text);
        if (!t) {
            return true;
        }
        return isShortIdentityLabel(t)
            || isIdentityLabelContinuation(t)
            || isIncompleteEquipmentLabel(t);
    }

    function isLikelyRealDocumentNo(text) {
        const t = normalizeEditText(text);
        if (!t || isIdentityPlaceholderValue(t)) {
            return false;
        }
        const lower = t.toLowerCase();
        // Never treat neighbouring field labels or glued fragments as a document number.
        if (/^equipment\b/.test(lower)
            || /equipment\s*\/?\s*system\s*name/.test(lower)
            || /quipment\s*\/?\s*system\s*name/.test(lower)
            || /system\s*name/.test(lower)
            || /system\s*id/.test(lower)
            || /^facilit/.test(lower)
            || /facilit(?:y)?\s*\/?\s*section/.test(lower)
            || /^document\s*title\b/.test(lower)
            || /^list\s*of\s*sop/.test(lower)
            || /^title\s*of\s*sop/.test(lower)
            || /^sop\s*(no|number)/.test(lower)
            || /\bequipment\b.*\bsystem\b/.test(lower)
            || /\bfacilit\b.*\bsection\b/.test(lower)) {
            return false;
        }
        return t.length >= 2;
    }

    function textExtendsPastIdentityLabel(text) {
        const raw = normalizeCompareText(text);
        const canon = normalizeIdentitySaveLabel(raw);
        if (!raw || !canon || normalizeCompareText(canon) === raw) {
            return false;
        }
        const flatten = function (value) {
            return normalizeCompareText(value).toLowerCase()
                .replace(/[().]/g, ' ')
                .replace(/\s*\/\s*/g, ' ')
                .replace(/\bno\.?\b/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();
        };
        const flatRaw = flatten(raw);
        const flatCanon = flatten(canon);
        return !!flatCanon && flatRaw.indexOf(flatCanon + ' ') === 0;
    }

    function isShortIdentityLabel(text) {
        const t = normalizeEditText(text);
        if (!t || t.length > 80) {
            return false;
        }
        // "equipment system name test" is a typed value, not the field label.
        if (textExtendsPastIdentityLabel(t)) {
            return false;
        }
        if (isIdentityLabelContinuation(t) || isIncompleteEquipmentLabel(t)) {
            return true;
        }
        const lower = t.toLowerCase();
        return /^document\s*title\b/.test(lower)
            || /^document\s*no\b/.test(lower)
            || /^reference\s*document/.test(lower)
            || /^ref\.?\s*document/.test(lower)
            || /^equipment\s*\/?\s*system\s+name\b/.test(lower)
            || /^equipment\s*\/?\s*system\s*(id|id\s*no\.?)\b/.test(lower)
            || /^equipment\s*system\s+id\b/.test(lower)
            || /^equipment\s*\/\s*system\s+name\b/.test(lower)
            || /^equipment\s*name\b/.test(lower)
            || /^equipment\s*id\.?\b/.test(lower)
            || /^equipment\s*\/?\s*$/.test(lower)
            || /^facilit(y)?\s*\/?\s*section\b/.test(lower)
            || /^effective\s*date\b/.test(lower)
            || /^version\b/.test(lower);
    }

    function findWordValueAfterLabel(targets, labelRegex) {
        const list = Array.isArray(targets) ? targets : [];
        for (let i = 0; i < list.length; i++) {
            const label = normalizeEditText(list[i] && list[i].text);
            if (!label || label.length > 80) {
                continue;
            }
            if (!labelRegex.test(label.toLowerCase())) {
                continue;
            }
            for (let j = i + 1; j < Math.min(i + 8, list.length); j++) {
                const value = normalizeEditText(list[j] && list[j].text);
                if (!value) {
                    continue;
                }
                if (isIdentityPlaceholderValue(value)) {
                    continue;
                }
                if (/table\s+of\s+contents|list\s*of\s*sop/i.test(value)) {
                    continue;
                }
                if (/^\d+\.\d+(\.\d+)?\s+\S/.test(value)) {
                    continue;
                }
                if (/^(title\s*of\s*sop|sop\s*number|effective\s*date)\d*$/i.test(value)) {
                    continue;
                }
                if (/document\s*no|reference\s*document/i.test(label) && !isLikelyRealDocumentNo(value)) {
                    continue;
                }
                return value;
            }
        }
        return null;
    }

    function decodeXmlText(value) {
        return String(value || '')
            .replace(/&amp;/g, '&')
            .replace(/&lt;/g, '<')
            .replace(/&gt;/g, '>')
            .replace(/&quot;/g, '"')
            .replace(/&#39;/g, "'");
    }

    function extractTextFromWordTcXml(tcXml) {
        const parts = [];
        const re = /<w:t(?:\s[^>]*)?>([\s\S]*?)<\/w:t>/g;
        let match = re.exec(tcXml);
        while (match) {
            parts.push(decodeXmlText(match[1]));
            match = re.exec(tcXml);
        }
        return normalizeEditText(parts.join(''));
    }

    function savedFillTokens(text) {
        return String(text || '')
            .replace(/[.\u2026\u00b7]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim()
            .toLowerCase()
            .split(' ')
            .filter(Boolean);
    }

    function savedFillIsSameSentence(live, saved) {
        const shown = savedFillTokens(live);
        const filed = savedFillTokens(saved);
        if (shown.length < 6 || filed.length <= shown.length) {
            return false;
        }
        if (String(saved || '').length > String(live || '').length + 80) {
            return false;
        }
        const head = Math.min(6, shown.length);
        for (let i = 0; i < head; i++) {
            if (shown[i] !== filed[i]) {
                return false;
            }
        }
        let purePrefix = shown.length < filed.length;
        for (let i = 0; i < shown.length; i++) {
            if (shown[i] !== filed[i]) {
                purePrefix = false;
                break;
            }
        }
        if (purePrefix) {
            return false;
        }
        let cursor = 0;
        for (let i = 0; i < filed.length && cursor < shown.length; i++) {
            if (filed[i] === shown[cursor]) {
                cursor += 1;
            }
        }
        return cursor === shown.length;
    }

    function writeKeptText(node, value) {
        if (!node) {
            return;
        }
        const span = node.querySelector ? node.querySelector('span') : null;
        if (span) {
            span.textContent = value;
            const spans = node.querySelectorAll('span');
            for (let i = 1; i < spans.length; i++) {
                if (spans[i] !== span && spans[i].parentElement === span.parentElement) {
                    spans[i].textContent = '';
                }
            }
            return;
        }
        node.textContent = value;
    }

    function wordTableRowsFromXml(xml) {
        const rows = [];
        let index = 0;
        while (index < xml.length) {
            const start = xml.indexOf('<w:tr', index);
            if (start < 0) {
                break;
            }
            const marker = xml.charAt(start + 5);
            if (marker !== '>' && marker !== ' ' && marker !== '/') {
                index = start + 5;
                continue;
            }
            let depth = 0;
            let cursor = start;
            let end = -1;
            while (cursor < xml.length) {
                const open = xml.indexOf('<w:tr', cursor);
                const close = xml.indexOf('</w:tr>', cursor);
                if (close < 0) {
                    break;
                }
                const openMarker = open >= 0 ? xml.charAt(open + 5) : '';
                const opensRow = open >= 0 && open < close && (openMarker === '>' || openMarker === ' ' || openMarker === '/');
                if (opensRow) {
                    depth += 1;
                    cursor = open + 5;
                    continue;
                }
                depth -= 1;
                cursor = close + 7;
                if (depth === 0) {
                    end = cursor;
                    break;
                }
            }
            if (end < 0) {
                break;
            }
            rows.push(xml.slice(start, end));
            index = end;
        }
        return rows;
    }

    async function readSavedFillFromDocx(docxBlob, sharedZip) {
        const cache = { choices: {}, sentences: [] };
        if (!sharedZip && (!window.JSZip || !docxBlob)) {
            return cache;
        }
        const zip = sharedZip || await JSZip.loadAsync(docxBlob);
        const docXml = zip.file('word/document.xml');
        if (!docXml) {
            return cache;
        }
        const xml = await docXml.async('string');
        wordTableRowsFromXml(xml).forEach(function (rowXml) {
            const cells = [];
            const tcRe = /<w:tc[\s>][\s\S]*?<\/w:tc>/g;
            let tcMatch = tcRe.exec(rowXml);
            while (tcMatch) {
                cells.push(extractTextFromWordTcXml(tcMatch[0]));
                tcMatch = tcRe.exec(rowXml);
            }
            let annex = '';
            let attachment = false;
            cells.forEach(function (text) {
                const key = (String(text).match(/ANNEX_[A-Z0-9_]+/i) || [''])[0].toUpperCase();
                if (key) {
                    annex = key;
                }
                if (/ioq\s*attachment\s*\d+/i.test(text)) {
                    attachment = true;
                }
            });
            if (!annex || !attachment || !cells.length) {
                return;
            }
            const choice = String(cells[cells.length - 1] || '').trim();
            if (choice) {
                cache.choices[annex] = choice;
            }
        });
        const paraRe = /<w:p[\s>][\s\S]*?<\/w:p>/g;
        let paraMatch = paraRe.exec(xml);
        while (paraMatch) {
            const text = extractTextFromWordTcXml(paraMatch[0]);
            if (text && text.length > 24 && !/^\d+\.\d+\s+\S/.test(text)) {
                cache.sentences.push(text);
            }
            paraMatch = paraRe.exec(xml);
        }
        return cache;
    }

    function paintSavedFill(wrapper) {
        if (!wrapper || !savedFillCache) {
            return;
        }
        wrapper.querySelectorAll('table').forEach(function (table) {
            if (!isAttachmentListTable(table)) {
                return;
            }
            getDirectTableRows(table).forEach(function (row) {
                const cells = getRowCells(row);
                if (cells.length < 2) {
                    return;
                }
                let annex = '';
                cells.forEach(function (cell) {
                    const key = ((cell.innerText || cell.textContent || '').match(/ANNEX_[A-Z0-9_]+/i) || [''])[0].toUpperCase();
                    if (key) {
                        annex = key;
                    }
                });
                const choice = annex ? savedFillCache.choices[annex] : '';
                if (!choice) {
                    return;
                }
                const last = cells[cells.length - 1];
                const live = (last.innerText || last.textContent || '').replace(/\s+/g, ' ').trim();
                if (live || last.getAttribute('data-neq-user-edited') === '1' || last.querySelector('[data-neq-user-edited="1"]')) {
                    return;
                }
                if (typeof selectionInside === 'function' && selectionInside(last)) {
                    return;
                }
                const paragraph = last.querySelector('p') || last;
                writeKeptText(paragraph, choice);
            });
        });
        wrapper.querySelectorAll('section.docx > article > p').forEach(function (paragraph) {
            if (paragraph.closest('table, header, footer, .docx_header, .docx_footer')) {
                return;
            }
            if (paragraph.getAttribute('data-neq-user-edited') === '1') {
                return;
            }
            if (typeof selectionInside === 'function' && selectionInside(paragraph)) {
                return;
            }
            const live = (paragraph.innerText || paragraph.textContent || '').replace(/\s+/g, ' ').trim();
            if (!live || isLikelyTocEntryText(live, paragraph)) {
                return;
            }
            let match = '';
            savedFillCache.sentences.forEach(function (saved) {
                if (match) {
                    return;
                }
                if (savedFillIsSameSentence(live, saved)) {
                    match = saved;
                }
            });
            if (!match) {
                return;
            }
            writeKeptText(paragraph, match);
        });
    }

    // Read identity values directly from DOCX OOXML (same source as Download).
    async function extractIdentityValuesFromDocxBlob(docxBlob, sharedZip) {
        const result = {
            documentTitle: null,
            documentNo: null,
        };
        if (!sharedZip && (!window.JSZip || !docxBlob)) {
            return result;
        }

        try {
            const zip = sharedZip || await JSZip.loadAsync(docxBlob);
            const xmlParts = [];
            const docXml = zip.file('word/document.xml');
            if (docXml) {
                xmlParts.push(await docXml.async('string'));
            }
            const wordFolder = zip.folder('word');
            if (wordFolder) {
                const headerFiles = [];
                wordFolder.forEach(function (relativePath, file) {
                    if (!file.dir && /^header\d*\.xml$/i.test(relativePath)) {
                        headerFiles.push(file);
                    }
                });
                for (let i = 0; i < headerFiles.length; i++) {
                    xmlParts.push(await headerFiles[i].async('string'));
                }
            }

            const cellTexts = [];
            xmlParts.forEach(function (xml) {
                const tcRe = /<w:tc[\s>][\s\S]*?<\/w:tc>/g;
                let tcMatch = tcRe.exec(xml);
                while (tcMatch) {
                    const text = extractTextFromWordTcXml(tcMatch[0]);
                    if (text) {
                        cellTexts.push(text);
                    }
                    tcMatch = tcRe.exec(xml);
                }
            });

            function valueAfter(labelRegex, requireDocNo) {
                for (let i = 0; i < cellTexts.length; i++) {
                    const label = cellTexts[i];
                    if (label.length > 80 || !labelRegex.test(label.toLowerCase())) {
                        continue;
                    }
                    for (let j = i + 1; j < Math.min(i + 6, cellTexts.length); j++) {
                        const value = cellTexts[j];
                        if (!value || isIdentityPlaceholderValue(value)) {
                            continue;
                        }
                        if (/table\s+of\s+contents|list\s*of\s*sop/i.test(value) || /^\d+\.\d+/.test(value)) {
                            continue;
                        }
                        if (/installation\s+and\s+operational\s+qualification\s+protocol/i.test(value)) {
                            continue;
                        }
                        if (/^(title\s*of\s*sop|sop\s*number|effective\s*date)\d*$/i.test(value)) {
                            continue;
                        }
                        if (requireDocNo && !isLikelyRealDocumentNo(value)) {
                            continue;
                        }
                        return value;
                    }
                }
                return null;
            }

            result.documentTitle = valueAfter(/^document\s*title\b/, false);
            result.documentNo = valueAfter(/^document\s*no\b|^reference\s*document/, true);
            // Never keep a label fragment as the document number.
            if (result.documentNo && (!isLikelyRealDocumentNo(result.documentNo) || isIdentityPlaceholderValue(result.documentNo))) {
                result.documentNo = null;
            }
            if (result.documentTitle && isIdentityPlaceholderValue(result.documentTitle)) {
                result.documentTitle = null;
            }
            console.info('[NEQ Preview] OOXML identity values:', result);
        } catch (error) {
            console.warn('[NEQ Preview] OOXML identity extract failed:', error);
        }

        return result;
    }

    function writeRestoredCellValue(cell, value) {
        if (!cell || value === null || value === undefined) {
            return false;
        }
        value = String(value);
        // Never wipe typed / real content with an empty restore write.
        if (value === '') {
            return false;
        }
        // Do not overwrite a cell the user already edited in this session.
        if (cellHasUserIdentityEdit(cell)) {
            return false;
        }
        const live = nodeEditText(cell);
        if (live && !isIdentityPlaceholderValue(live) && live !== value) {
            return false;
        }

        // Prefer an empty nested cell when docx-preview wraps the value in an inner table.
        const nestedEmpty = Array.from(cell.querySelectorAll('td, th')).find(function (inner) {
            return !inner.querySelector('table') && nodeEditText(inner) === '';
        });
        if (nestedEmpty) {
            cell = nestedEmpty;
        }

        if (cell.querySelector('table') && !nestedEmpty) {
            return false;
        }

        // Force-visible write — docx-preview often leaves an empty shell paragraph.
        let paragraph = cell.querySelector('p');
        if (!paragraph) {
            paragraph = document.createElement('p');
            cell.appendChild(paragraph);
        }
        paragraph.textContent = value;
        paragraph.style.setProperty('display', 'block', 'important');
        paragraph.style.setProperty('visibility', 'visible', 'important');
        paragraph.style.setProperty('opacity', '1', 'important');
        paragraph.style.setProperty('color', '#000', 'important');
        paragraph.style.setProperty('min-height', '1em', 'important');

        cell.style.setProperty('visibility', 'visible', 'important');
        cell.style.setProperty('opacity', '1', 'important');
        cell.style.setProperty('color', '#000', 'important');
        cell.style.removeProperty('display');

        cell.setAttribute('data-neq-original', value);
        cell.setAttribute('data-neq-restored', '1');
        paragraph.setAttribute('data-neq-original', value);
        paragraph.setAttribute('data-neq-restored', '1');
        return true;
    }

    function cellHasUserIdentityEdit(cell) {
        if (!cell) {
            return false;
        }
        if (cell.getAttribute('data-neq-user-edited') === '1') {
            return true;
        }
        return !!(cell.querySelector && cell.querySelector('[data-neq-user-edited="1"]'));
    }

    function markIdentityUserEdit(node) {
        if (!node) {
            return;
        }
        node.setAttribute('data-neq-user-edited', '1');
        const cell = getOwningCell(node) || ((((node.tagName || '').toLowerCase() === 'td' || (node.tagName || '').toLowerCase() === 'th') ? node : null));
        if (cell) {
            cell.setAttribute('data-neq-user-edited', '1');
            // Also mark the outer SOP-grid cell when typing inside a nested layout table.
            const sopTable = findOwningSopGridTable(cell);
            if (sopTable) {
                const located = locateCellInSopGrid(sopTable, cell);
                if (located && located.outerCell) {
                    located.outerCell.setAttribute('data-neq-user-edited', '1');
                }
            }
        }
    }

    function onIdentityFieldInput(event) {
        const t = event.target;
        if (!t || !isEditingEnabled) {
            return;
        }
        const cell = t.closest ? t.closest('td, th') : null;
        if (!cell || isToolCell(cell)) {
            return;
        }
        // Mark every table cell the user types in so Save cannot miss the change.
        markIdentityUserEdit(t);
        const header = cell.closest('header, .docx_header');
        if (header) {
            const wrapper = header.closest('.docx-wrapper');
            if (wrapper) {
                syncLetterheadValuesOntoEveryPage(wrapper, header);
            }
        }
        scheduleNeqAutosave();
    }

    function fillEmptyValueAfterLabel(labelCell, value) {
        if (!labelCell || !value) {
            return false;
        }
        const rowCells = getRowCells(labelCell);
        const start = rowCells.indexOf(labelCell);
        if (start < 0) {
            return false;
        }

        // Prefer the next cell in the same row (Document Title value / colspan cell).
        // Overwrite empty cells AND label-wrap fragments / template placeholders only.
        for (let i = start + 1; i < rowCells.length; i++) {
            const cell = rowCells[i];
            if (cellHasUserIdentityEdit(cell)) {
                continue;
            }
            const live = nodeEditText(cell);
            if (live === '' || live === value || isIdentityPlaceholderValue(live)) {
                return writeRestoredCellValue(cell, value);
            }
        }

        // Some Word merges leave only the label cell in HTML — append value into label cell as 2nd line.
        if (rowCells.length === 1 || start === rowCells.length - 1) {
            if (cellHasUserIdentityEdit(labelCell)) {
                return false;
            }
            const second = document.createElement('p');
            second.textContent = value;
            second.setAttribute('data-neq-restored', '1');
            second.style.setProperty('color', '#000', 'important');
            labelCell.appendChild(second);
            labelCell.setAttribute('data-neq-restored', '1');
            return true;
        }

        return false;
    }

    // docx-preview drops some merged Document Title cells; refill from DOCX OOXML / targets.
    // NEVER wipe user-typed header values (that caused 1s flash-then-blank).
    function restoreMissingIdentityValues(targets, ooxmlValues) {
        // Once the user can type, do not mutate identity cells at all.
        if (isEditingEnabled) {
            return 0;
        }

        const root = getPreviewContentRoot() || previewArea;
        if (!root) {
            return 0;
        }

        let titleValue = (ooxmlValues && ooxmlValues.documentTitle)
            || findWordValueAfterLabel(targets, /^document\s*title\b/);
        let docNoValue = (ooxmlValues && ooxmlValues.documentNo)
            || findWordValueAfterLabel(targets, /^document\s*no\b|^reference\s*document/);

        if (titleValue && isIdentityPlaceholderValue(titleValue)) {
            titleValue = null;
        }
        if (docNoValue && !isLikelyRealDocumentNo(docNoValue)) {
            docNoValue = null;
        }

        // Nothing useful to fill â€” do not touch the DOM (avoids clearing typed values).
        if (!titleValue && !docNoValue) {
            return 0;
        }

        let restored = 0;

        root.querySelectorAll('section.docx table, table').forEach(function (table) {
            if (!isPreviewIdentityTable(table)) {
                return;
            }
            const tableText = normalizeEditText(table.innerText || table.textContent);
            getDirectTableRows(table).forEach(function (row) {
                const cells = Array.from(row.children).filter(function (el) {
                    const tag = (el.tagName || '').toLowerCase();
                    return (tag === 'td' || tag === 'th') && !isToolCell(el);
                });
                cells.forEach(function (cell, cellIdx) {
                    const text = nodeEditText(cell);
                    const lower = text.toLowerCase();
                    const next = cells[cellIdx + 1];
                    const nextText = nodeEditText(next);

                    if (next && cellHasUserIdentityEdit(next)) {
                        return;
                    }

                    // Merge label wrap into LABEL only â€” never clear the value cell to ''.
                    if (/^reference\s*document\b/.test(lower)
                        && !/no\.?\s*\(?\s*version/i.test(lower)
                        && next
                        && isIdentityLabelContinuation(nextText)) {
                        const mergedLabel = normalizeEditText(text + ' ' + nextText) || 'Reference Document No. (Version No.)';
                        writeRestoredCellValue(cell, mergedLabel);
                        cell.setAttribute('data-neq-original', mergedLabel);
                        if (docNoValue && (nextText === '' || isIdentityPlaceholderValue(nextText))) {
                            writeRestoredCellValue(next, docNoValue);
                        }
                        restored += 1;
                        return;
                    }

                    if (isIncompleteEquipmentLabel(text)
                        && next
                        && isIdentityLabelContinuation(nextText)
                        && /system\s+name|system\s+id/i.test(nextText)) {
                        const mergedLabel = normalizeEditText(
                            'Equipment/ ' + nextText.replace(/^\//, '').trim()
                        ) || 'Equipment/ system name';
                        writeRestoredCellValue(cell, mergedLabel);
                        cell.setAttribute('data-neq-original', mergedLabel);
                        restored += 1;
                        return;
                    }

                    if (!isShortIdentityLabel(text)) {
                        return;
                    }

                    if (/^document\s*title\b/.test(lower) && titleValue) {
                        if (nextText !== titleValue && (isIdentityPlaceholderValue(nextText) || tableText.indexOf(titleValue) === -1)) {
                            if (fillEmptyValueAfterLabel(cell, titleValue)) {
                                restored += 1;
                            }
                        }
                    }
                    if ((/^document\s*no\b/.test(lower) || /^reference\s*document/.test(lower)) && docNoValue) {
                        if (nextText !== docNoValue && isIdentityPlaceholderValue(nextText)) {
                            if (fillEmptyValueAfterLabel(cell, docNoValue)) {
                                restored += 1;
                            }
                        }
                    }
                });
            });
        });

        if (restored) {
            console.info('[NEQ Preview] Restored identity field values:', restored, {
                titleValue: titleValue,
                docNoValue: docNoValue,
            });
        }
        return restored;
    }

    function withCacheBust(url, bust) {
        if (!url || !bust) {
            return url;
        }
        const sep = url.indexOf('?') >= 0 ? '&' : '?';
        return url + sep + '_=' + encodeURIComponent(String(bust));
    }

    function disablePreviewLinkJumps(root) {
        if (!root) {
            return;
        }
        root.querySelectorAll('a').forEach(function (anchor) {
            const parent = anchor.parentNode;
            if (!parent) {
                return;
            }
            while (anchor.firstChild) {
                parent.insertBefore(anchor.firstChild, anchor);
            }
            parent.removeChild(anchor);
        });
    }

    function sectionBodyIsEmpty(section) {
        const article = pageArticle(section);
        const probe = article.cloneNode(true);
        probe.querySelectorAll('header, footer, .docx_header, .docx_footer, .neq-page-no').forEach(function (el) {
            el.remove();
        });
        const text = (probe.innerText || probe.textContent || '').replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
        const hasMedia = !!probe.querySelector('img, svg, canvas');
        const hasTable = !!probe.querySelector('table');
        if (hasMedia || hasTable) {
            return false;
        }
        if (!text || isStockTemplateNote(text)) {
            return true;
        }
        // A leftover list number ("7.7") with no words is still an empty page.
        const blocks = pageBodyParagraphs(section);
        if (!blocks.length) {
            return true;
        }
        return !blocks.some(function (el) {
            const blockText = plainBlockText(el);
            return blockText && !isBareListMarker(el) && !isStockTemplateNote(blockText);
        });
    }

    function dropEmptyPreviewPages(wrapper) {
        const pages = Array.from(wrapper.querySelectorAll(':scope > section.docx'));
        if (pages.length < 2) {
            return;
        }
        // An explicit page break sitting next to Word's last-rendered break
        // builds a page with no body. NEQ19 then shows a blank page 2 and the
        // deviations start on page 3.
        pages.forEach(function (section) {
            if (wrapper.querySelectorAll(':scope > section.docx').length < 2) {
                return;
            }
            if (sectionBodyIsEmpty(section)) {
                section.remove();
            }
        });
    }

    function stampVisiblePageNumbers(wrapper) {
        const pages = wrapper.querySelectorAll(':scope > section.docx');
        pages.forEach(function (section, index) {
            let badge = section.querySelector(':scope > .neq-page-no');
            if (!badge) {
                badge = document.createElement('div');
                badge.className = 'neq-page-no';
                section.appendChild(badge);
            }
            badge.textContent = 'Page ' + (index + 1);
        });
    }

    function pageBodyParagraphs(section) {
        const nodes = [];
        section.querySelectorAll('p, h1, h2, h3, h4, h5, h6').forEach(function (el) {
            if (el.closest('header, footer, .docx_header, .docx_footer')) {
                return;
            }
            if (el.closest('table')) {
                return;
            }
            if (el.classList && el.classList.contains('neq-page-no')) {
                return;
            }
            nodes.push(el);
        });
        return nodes;
    }

    function plainBlockText(el) {
        return (el.innerText || el.textContent || '').replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
    }

    function numberingMarkerClass(el) {
        const match = String(el.className || '').match(/docx-num-\d+-\d+/);
        return match ? match[0] : '';
    }

    function isStockTemplateNote(text) {
        return /the template may be appropriately adapted/i.test(String(text || ''));
    }

    function isBareListMarker(el) {
        const text = plainBlockText(el);
        if (/^\d+(?:\.\d+)+$/.test(text)) {
            return true;
        }
        // Word's page break sits inside the heading. Preview leaves the
        // list marker (7.7) on this page and the words on the next page.
        return numberingMarkerClass(el) !== '' && text === '';
    }

    function pageArticle(section) {
        return section.querySelector(':scope > article') || section;
    }

    function dropStackedDuplicateParagraphs(wrapper) {
        if (!wrapper || !wrapper.querySelectorAll) {
            return;
        }
        wrapper.querySelectorAll('section.docx').forEach(function (section) {
            const article = pageArticle(section);
            const blocks = articleBlocks(article);
            let run = [];
            function flush() {
                if (run.length < 2) {
                    run = [];
                    return;
                }
                run.forEach(function (node) {
                    node.remove();
                });
                run = [];
            }
            blocks.forEach(function (block) {
                if (!block || block.tagName === 'TABLE' || (block.classList && block.classList.contains('neq-table-wrap'))) {
                    flush();
                    return;
                }
                if (block.closest && block.closest('table, header, footer, .docx_header, .docx_footer')) {
                    flush();
                    return;
                }
                const text = (block.innerText || block.textContent || '').replace(/\s+/g, ' ').trim();
                if (!text || text.length < 4 || /^\d+(?:\.\d+)+\s+\S/.test(text)) {
                    flush();
                    return;
                }
                const prev = run.length ? (run[0].innerText || run[0].textContent || '').replace(/\s+/g, ' ').trim() : '';
                if (run.length && prev === text) {
                    run.push(block);
                    return;
                }
                flush();
                run = [block];
            });
            flush();
        });
    }

    function healSplitSectionHeadings(wrapper) {
        const pages = Array.from(wrapper.querySelectorAll(':scope > section.docx'));
        pages.forEach(function (page, index) {
            const next = pages[index + 1];
            if (!next) {
                return;
            }
            const blocks = pageBodyParagraphs(page);
            let orphan = null;
            for (let i = blocks.length - 1; i >= 0; i--) {
                if (isBareListMarker(blocks[i])) {
                    orphan = blocks[i];
                    break;
                }
                if (plainBlockText(blocks[i])) {
                    break;
                }
            }
            if (!orphan) {
                return;
            }
            const continuation = pageBodyParagraphs(next).find(function (el) {
                return plainBlockText(el);
            });
            if (!continuation || continuation === orphan) {
                return;
            }
            const orphanNum = numberingMarkerClass(orphan);
            const continuationNum = numberingMarkerClass(continuation);
            if (continuationNum && orphanNum && continuationNum !== orphanNum) {
                return;
            }
            // The words already have the same list number on the next page.
            // Keep that heading. Drop only the empty marker, otherwise it is
            // hidden and the next section is numbered 1.0 instead of 2.0.
            if (continuationNum && continuationNum === orphanNum) {
                orphan.remove();
                return;
            }
            while (continuation.firstChild) {
                orphan.appendChild(continuation.firstChild);
            }
            orphan.style.removeProperty('display');
            orphan.style.removeProperty('counter-increment');
            pageArticle(next).insertBefore(orphan, continuation);
            continuation.remove();
        });
    }

    function revealClippedListText(root) {
        if (!root || !root.querySelectorAll) {
            return;
        }
        root.querySelectorAll('p[class*="docx-num-"]').forEach(function (paragraph) {
            if (paragraph.closest('header, footer, .docx_header, .docx_footer, table')) {
                return;
            }
            const style = window.getComputedStyle(paragraph);
            const indent = parseFloat(style.textIndent) || 0;
            const marginLeft = parseFloat(style.marginLeft) || 0;
            if (indent >= -8 || marginLeft >= Math.abs(indent) - 2) {
                return;
            }
            paragraph.style.setProperty('margin-left', Math.abs(indent) + 'px', 'important');
        });
    }

    function equalizeComponentDataRowHeights(root) {
        if (!root || !root.querySelectorAll) {
            return;
        }
        root.querySelectorAll('table').forEach(function (table) {
            if (!isComponentSubsystemMatrixTable(table)) {
                return;
            }
            getDirectTableRows(table).forEach(function (row) {
                const cells = getRowCells(row);
                if (!cells.length) {
                    return;
                }
                const text = (row.innerText || row.textContent || '').replace(/\s+/g, ' ').trim();
                if (/verified\s+by\s+sign/i.test(text)) {
                    return;
                }
                if (/^(main\s*body|mechanical\s*components?|electrical\s*components?)\b/i.test(text)) {
                    return;
                }
                if (isDataMatrixSectionBannerRow(row, cells)) {
                    return;
                }
                // Leave the Word row height. Clearing it made the locked preview shorter
                // than the same table after unlock.
            });
        });
    }

    function alignLetterheadValuesLeft(root) {
        if (!root || !root.querySelectorAll) {
            return;
        }
        root.querySelectorAll('header table, .docx_header table').forEach(function (table) {
            table.classList.remove('neq-check-matrix');
            table.querySelectorAll('td, th').forEach(function (cell) {
                const text = normalizeEditText(cell.innerText || cell.textContent || '');
                if (!text) {
                    return;
                }
                if (isShortIdentityLabel(text) || looksLikeFieldLabel(text)
                    || /annex|lupin|attachment|comparison\s+of\s+urs/i.test(text)) {
                    return;
                }
                cell.style.setProperty('text-align', 'left', 'important');
                cell.querySelectorAll('p, span, div').forEach(function (el) {
                    el.style.setProperty('text-align', 'left', 'important');
                });
            });
        });
    }

    function stripIdentityLabelCheckboxes(root) {
        if (!root || !root.querySelectorAll) {
            return;
        }
        const labelRe = /document\s*no|document\s*title|equipment\s*\/?\s*system|facilit(?:y)?\s*\/\s*section|effective\s*date|reference\s*document/i;
        const glyphRe = /[\u2610\u2611\u2612\u2713\u2714\u25a1\u25a0]/g;
        root.querySelectorAll('td, th').forEach(function (cell) {
            const text = cell.innerText || cell.textContent || '';
            if (!labelRe.test(text)) {
                return;
            }
            cell.querySelectorAll('input.neq-checkbox, .neq-checkbox-remove').forEach(function (el) {
                el.remove();
            });
            const walker = document.createTreeWalker(cell, NodeFilter.SHOW_TEXT);
            const nodes = [];
            while (walker.nextNode()) {
                nodes.push(walker.currentNode);
            }
            nodes.forEach(function (tn) {
                if (tn.nodeValue && glyphRe.test(tn.nodeValue)) {
                    glyphRe.lastIndex = 0;
                    tn.nodeValue = tn.nodeValue.replace(glyphRe, '');
                }
                glyphRe.lastIndex = 0;
            });
        });
    }

    function stripMisplacedAnnexCheckboxes(root) {
        if (!root || !root.querySelectorAll) {
            return;
        }
        const glyphOnly = /^[\s\u2610\u2611\u2612\u2713\u2714\u2705\u25a1\u25a0]*$/;
        root.querySelectorAll('td, th').forEach(function (cell) {
            const header = getColumnHeaderTextForCell(cell);
            if (!header) {
                return;
            }
            if (cell.getAttribute('data-neq-applicable-col') === '1') {
                return;
            }
            if (!/annex(?:ure)?\s*no/i.test(header) && !/attachment\s*(?:no\.?|type|number)/i.test(header)) {
                return;
            }
            cell.querySelectorAll('input.neq-checkbox').forEach(function (cb) {
                cb.remove();
            });
            cell.querySelectorAll('p, span').forEach(function (el) {
                const text = (el.innerText || el.textContent || '').replace(/\u00a0/g, ' ');
                if (glyphOnly.test(text) && text.trim()) {
                    el.style.setProperty('display', 'none', 'important');
                }
            });
        });
    }

    function dropForeignHeadingRows(wrapper) {
        if (!wrapper || !wrapper.querySelectorAll) {
            return;
        }
        wrapper.querySelectorAll('header table, .docx_header table').forEach(function (table) {
            getDirectTableRows(table).forEach(function (row) {
                const text = plainBlockText(row).toLowerCase();
                if (!text) {
                    return;
                }
                // Keep the real letterhead: logo, company name, annex/attachment title,
                // and the identity labels. Hiding these rows removes the Lupin logo,
                // because it sits in the first row and spans the title rows.
                if (row.querySelector('img, svg, object, canvas, [data-neq-official-logo]')) {
                    return;
                }
                if (/document\s*(no|title|number)|reference\s*document|equipment|facilit|effective\s*date|\bversion\b/.test(text)) {
                    return;
                }
                if (/\blupin\b|\bannex\b|attachment|\bioq\b|qualification/.test(text)) {
                    return;
                }
                row.style.setProperty('display', 'none', 'important');
            });
        });
    }

    // The letterhead is a little narrower than the body table under it, so its
    // right border stops short. Keep the left edge and pull the right edge out
    // to the table the user is lining up against.
    function extendHeadingToBodyRightEdge(root) {
        if (!root || !root.querySelectorAll) {
            return;
        }
        root.querySelectorAll('section.docx').forEach(function (section) {
            const bodyTables = Array.from(section.querySelectorAll('table')).filter(function (table) {
                if (table.closest('header, footer, .docx_header, .docx_footer')) {
                    return false;
                }
                if (table.parentElement && table.parentElement.closest('td, th')) {
                    return false;
                }
                return table.getBoundingClientRect().width > 80;
            });
            if (!bodyTables.length) {
                return;
            }
            let guide = bodyTables.find(function (table) {
                return isComponentSubsystemMatrixTable(table);
            }) || bodyTables[0];
            const targetRight = Math.min(
                guide.getBoundingClientRect().right,
                section.getBoundingClientRect().right - 8
            );
            section.querySelectorAll('header table, .docx_header table').forEach(function (table) {
                if (table.parentElement && table.parentElement.closest('td, th')) {
                    return;
                }
                const host = table.closest('header, .docx_header');
                if (host) {
                    host.style.setProperty('overflow', 'visible', 'important');
                    host.style.setProperty('max-width', 'none', 'important');
                }
                const box = table.getBoundingClientRect();
                const gap = targetRight - box.right;
                if (gap <= 1) {
                    return;
                }
                const next = Math.ceil(box.width + gap);
                table.style.setProperty('width', next + 'px', 'important');
                table.style.setProperty('min-width', next + 'px', 'important');
                table.style.setProperty('max-width', 'none', 'important');
                table.style.setProperty('box-sizing', 'border-box', 'important');
                const cols = table.querySelectorAll(':scope > colgroup > col');
                if (cols.length) {
                    const lastCol = cols[cols.length - 1];
                    const raw = (lastCol.style.width || '').trim();
                    const declared = /px$/i.test(raw) ? parseFloat(raw) : 0;
                    if (declared > 0) {
                        lastCol.style.setProperty('width', Math.ceil(declared + gap) + 'px', 'important');
                    }
                }
                Array.from(table.rows || []).forEach(function (row) {
                    const cells = Array.from(row.cells || []).filter(function (cell) {
                        return cell.getBoundingClientRect().width > 2;
                    });
                    if (!cells.length) {
                        return;
                    }
                    const last = cells[cells.length - 1];
                    const cellBox = last.getBoundingClientRect();
                    const missing = targetRight - cellBox.right;
                    if (missing <= 1) {
                        return;
                    }
                    last.style.setProperty('box-sizing', 'border-box', 'important');
                    last.style.setProperty('width', Math.ceil(cellBox.width + missing) + 'px', 'important');
                    last.style.setProperty('min-width', Math.ceil(cellBox.width + missing) + 'px', 'important');
                    last.style.setProperty('max-width', 'none', 'important');
                });
            });
        });
    }

    function mmToPx(mm) {
        return (mm * 96) / 25.4;
    }

    function directTableRows(table) {
        return Array.from(table.querySelectorAll(':scope > tr, :scope > thead > tr, :scope > tbody > tr, :scope > tfoot > tr'));
    }

    function flowInsideContentBox(el, marginName) {
        if (!el) {
            return 0;
        }
        const style = window.getComputedStyle(el);
        if (style.display === 'none' || style.position === 'absolute' || style.position === 'fixed') {
            return 0;
        }
        const height = el.getBoundingClientRect().height || 0;
        const margin = parseFloat(style[marginName]) || 0;
        return Math.max(0, height + margin);
    }

    function pageContentBudget(section) {
        const cs = window.getComputedStyle(section);
        const padBottom = parseFloat(cs.paddingBottom) || 0;
        let pageH = parseFloat(cs.minHeight);
        if (!pageH || pageH < 200) {
            pageH = mmToPx(279.4);
        }
        const article = pageArticle(section);
        const sectionTop = section.getBoundingClientRect().top;
        const articleTop = article.getBoundingClientRect().top;
        let bottom = sectionTop + pageH - padBottom;
        const footer = section.querySelector(':scope > footer, :scope > .docx_footer');
        const footerFlow = flowInsideContentBox(footer, 'marginBottom');
        bottom -= footerFlow;
        // The letterhead is already above the article. Subtracting it again
        // ended the page while the sheet was still empty, so every table jumped
        // to the next page.
        const budget = bottom - articleTop - 6;
        return Math.max(48, budget);
    }

    function tableInside(block) {
        if (!block) {
            return null;
        }
        if (block.tagName === 'TABLE') {
            return block;
        }
        if (block.classList && block.classList.contains('neq-table-wrap')) {
            return block.querySelector(':scope > table');
        }
        return null;
    }

    function findSplitHost(article, key) {
        if (!article || !key) {
            return null;
        }
        const tables = article.querySelectorAll('table[data-neq-table="' + key + '"]');
        for (let i = tables.length - 1; i >= 0; i--) {
            if (tables[i].getAttribute('data-neq-page-split') !== '1') {
                return tables[i];
            }
        }
        return tables.length ? tables[tables.length - 1] : null;
    }

    /*
     * Adding a row used to split an already split table, which left a second
     * copy on the next page. Fold those copies back into one table first.
     */
    function selectionInside(node) {
        const sel = window.getSelection();
        if (!sel || !sel.rangeCount || !node) {
            return false;
        }
        const anchor = sel.anchorNode;
        return !!(anchor && node.contains(anchor));
    }

    function mergeFollowPages(wrapper) {
        const sections = Array.from(wrapper.querySelectorAll(':scope > section.docx'));
        for (let i = sections.length - 1; i >= 1; i--) {
            const section = sections[i];
            if (section.getAttribute('data-neq-page-follow') !== '1') {
                continue;
            }
            // The page being typed must stay put. Merging it back moves the cursor.
            if (selectionInside(section)) {
                continue;
            }
            const prevArticle = pageArticle(sections[i - 1]);
            articleBlocks(pageArticle(section)).forEach(function (block) {
                const table = tableInside(block);
                if (table && table.getAttribute('data-neq-page-split') === '1') {
                    const host = findSplitHost(prevArticle, table.getAttribute('data-neq-table'));
                    if (host) {
                        const dest = host.querySelector(':scope > tbody') || host;
                        directTableRows(table).forEach(function (row) {
                            if (row.getAttribute('data-neq-repeat-header') === '1') {
                                return;
                            }
                            dest.appendChild(row);
                        });
                        block.remove();
                        return;
                    }
                    table.removeAttribute('data-neq-page-split');
                }
                prevArticle.appendChild(block);
            });
            section.remove();
        }
    }

    function articleBlocks(article) {
        return Array.from(article.children).filter(function (el) {
            return el.nodeType === 1 && !(el.classList && el.classList.contains('neq-page-no'));
        });
    }

    function sectionHeaderHost(section) {
        return section.querySelector(':scope > header, :scope > .docx_header');
    }

    function headerLetterheadText(header) {
        return (header && (header.innerText || header.textContent) || '').replace(/\s+/g, ' ').trim();
    }

    function letterheadLeafCell(cell) {
        if (!cell) {
            return null;
        }
        const nested = Array.from(cell.querySelectorAll('td, th')).filter(function (inner) {
            return !inner.querySelector('table');
        });
        if (!nested.length) {
            return cell;
        }
        const withText = nested.find(function (inner) {
            return normalizeCompareText(nodeEditText(inner));
        });
        return withText || nested[0];
    }

    function eachLetterheadValueCell(header, callback) {
        if (!header || !header.querySelectorAll) {
            return;
        }
        const tables = Array.from(header.querySelectorAll('table')).filter(function (table) {
            const parent = table.parentElement;
            return !(parent && parent.closest && parent.closest('td, th'));
        });
        tables.forEach(function (table) {
            getDirectTableRows(table).forEach(function (row) {
                const cells = getRowCells(row);
                for (let i = 0; i < cells.length - 1; i++) {
                    let labelText = nodeEditText(cells[i]);
                    if (isIdentityLabelContinuation(labelText)) {
                        continue;
                    }
                    if (!isIdentityFormLabelText(labelText) && !isShortIdentityLabel(labelText) && !isIncompleteEquipmentLabel(labelText)) {
                        continue;
                    }
                    let valueCell = cells[i + 1];
                    const nextText = valueCell ? nodeEditText(valueCell) : '';
                    if (valueCell && (isIdentityLabelContinuation(nextText) || isIncompleteEquipmentLabel(nextText)) && cells[i + 2]) {
                        if (/^document\b/i.test(labelText) && /title/i.test(nextText)) {
                            labelText = 'Document Title';
                        } else if (/^document\b/i.test(labelText) || /^reference\s*document\b|^ref\.?\s*document\b/i.test(labelText)) {
                            labelText = 'Reference Document No. (Version No.)';
                        } else if (/^equipment\b/i.test(labelText) || isIncompleteEquipmentLabel(labelText)) {
                            labelText = /id/i.test(nextText) ? 'Equipment/ system ID No.' : 'Equipment/ system name';
                        }
                        valueCell = cells[i + 2];
                        if (valueCell && isIdentityLabelContinuation(nodeEditText(valueCell)) && cells[i + 3]) {
                            valueCell = cells[i + 3];
                        }
                    }
                    const canon = normalizeIdentitySaveLabel(labelText);
                    if (!canon || !valueCell) {
                        continue;
                    }
                    callback(canon, valueCell);
                }
            });
        });
    }

    function letterheadValueMap(header) {
        const map = {};
        eachLetterheadValueCell(header, function (canon, valueCell) {
            const value = normalizeCompareText(nodeEditText(valueCell));
            if (!value || isIdentityPlaceholderValue(value) || isIdentityFormLabelText(value)) {
                return;
            }
            if (!map[canon]) {
                map[canon] = value;
            }
        });
        return map;
    }

    function writeLetterheadMirror(cell, value, force) {
        const leaf = letterheadLeafCell(cell);
        if (!leaf || !value) {
            return;
        }
        if (document.activeElement && (leaf === document.activeElement || leaf.contains(document.activeElement) || (cell.contains && cell.contains(document.activeElement)))) {
            return;
        }
        const live = normalizeCompareText(nodeEditText(leaf));
        const next = normalizeCompareText(value);
        if (!next || live === next) {
            return;
        }
        const mirrored = leaf.getAttribute('data-neq-letterhead-mirror') === '1';
        if (!force && live && !isIdentityPlaceholderValue(live) && !mirrored) {
            return;
        }
        const target = leaf.querySelector('p') || leaf;
        if (target.querySelector && target.querySelector('table, select')) {
            return;
        }
        setNodePlainText(target, value);
        leaf.setAttribute('data-neq-letterhead-mirror', '1');
    }

    /*
     * The letterhead is one Word header. Page 1 keeps the typed values.
     * Later pages are drawn again with the labels and empty value cells.
     * Copy Document No., Title, Equipment, and Facility onto every page.
     */
    function syncLetterheadValuesOntoEveryPage(wrapper, preferredHeader) {
        if (!wrapper || !wrapper.querySelectorAll) {
            return;
        }
        const headers = [];
        wrapper.querySelectorAll(':scope > section.docx').forEach(function (section) {
            const header = sectionHeaderHost(section);
            if (header) {
                headers.push(header);
            }
        });
        if (headers.length < 2) {
            return;
        }
        let source = preferredHeader && headers.indexOf(preferredHeader) >= 0 ? preferredHeader : null;
        if (!source) {
            let bestCount = 0;
            headers.forEach(function (header) {
                const count = Object.keys(letterheadValueMap(header)).length;
                if (count > bestCount) {
                    bestCount = count;
                    source = header;
                }
            });
        }
        if (!source) {
            return;
        }
        const values = letterheadValueMap(source);
        const force = !!preferredHeader;
        headers.forEach(function (header) {
            if (header === source) {
                return;
            }
            eachLetterheadValueCell(header, function (canon, valueCell) {
                if (values[canon]) {
                    writeLetterheadMirror(valueCell, values[canon], force);
                }
            });
        });
    }

    /*
     * Word stores the letterhead on the first section only. The pages after
     * that render with an empty top. Copy that letterhead onto every sheet.
     */
    function copyLetterheadOntoEveryPage(wrapper) {
        const pages = Array.from(wrapper.querySelectorAll(':scope > section.docx'));
        let source = null;
        let sourceLen = 0;
        pages.forEach(function (section) {
            const header = sectionHeaderHost(section);
            if (!header || header.getAttribute('data-neq-header-copy') === '1') {
                return;
            }
            const text = headerLetterheadText(header);
            if (text.length > sourceLen) {
                source = header;
                sourceLen = text.length;
            }
        });
        if (source && sourceLen >= 12) {
            pages.forEach(function (section) {
                const header = sectionHeaderHost(section);
                if (headerLetterheadText(header).length >= 12) {
                    return;
                }
                const clone = source.cloneNode(true);
                clone.setAttribute('data-neq-header-copy', '1');
                clone.querySelectorAll('[data-neq-table], [data-neq-row]').forEach(function (el) {
                    el.removeAttribute('data-neq-table');
                    el.removeAttribute('data-neq-row');
                });
                if (header) {
                    header.replaceWith(clone);
                } else {
                    const article = pageArticle(section);
                    if (article && article !== section) {
                        section.insertBefore(clone, article);
                    } else {
                        section.insertBefore(clone, section.firstChild);
                    }
                }
            });
        }
        syncLetterheadValuesOntoEveryPage(wrapper);
    }

    function insertFollowPage(section) {
        const next = section.cloneNode(true);
        next.setAttribute('data-neq-page-follow', '1');
        next.style.setProperty('height', 'auto', 'important');
        const pageH = window.getComputedStyle(section).minHeight;
        if (pageH && pageH !== 'auto' && pageH !== '0px') {
            next.style.setProperty('min-height', pageH);
        }
        next.style.setProperty('overflow', 'visible', 'important');
        const article = pageArticle(next);
        articleBlocks(article).forEach(function (el) {
            el.remove();
        });
        next.querySelectorAll(':scope > .neq-page-no').forEach(function (el) {
            el.remove();
        });
        next.querySelectorAll('header [data-neq-table], header [data-neq-row], .docx_header [data-neq-table], .docx_header [data-neq-row], footer [data-neq-table], footer [data-neq-row], .docx_footer [data-neq-table], .docx_footer [data-neq-row]').forEach(function (el) {
            el.removeAttribute('data-neq-table');
            el.removeAttribute('data-neq-row');
        });
        section.parentNode.insertBefore(next, section.nextSibling);
        return next;
    }

    function splitWordArticlesOntoPages(wrapper) {
        if (!wrapper) {
            return;
        }
        // A Word page break stays inside one section as a second article.
        // Pinning that section to one sheet hid the letterhead page and
        // clipped Comments and the approval table. Each article is a page.
        Array.from(wrapper.querySelectorAll(':scope > section.docx')).forEach(function (section) {
            const articles = Array.from(section.querySelectorAll(':scope > article'));
            if (articles.length < 2) {
                return;
            }
            for (let i = articles.length - 1; i >= 1; i--) {
                const article = articles[i];
                if (!article.parentNode) {
                    continue;
                }
                const next = insertFollowPage(section);
                next.removeAttribute('data-neq-page-follow');
                Array.from(next.querySelectorAll(':scope > article')).slice(1).forEach(function (extra) {
                    extra.remove();
                });
                const host = pageArticle(next);
                while (article.firstChild) {
                    host.appendChild(article.firstChild);
                }
                article.remove();
            }
        });
    }

    function looksLikeRepeatedHeader(row) {
        if (!row) {
            return false;
        }
        if (row.querySelector('th')) {
            return true;
        }
        const text = (row.innerText || row.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
        return text.length > 0 && text.length < 180
            && /sr\.?\s*no|s\.?\s*no|test recommended|acceptance|result|revision\s*no|change|date/.test(text);
    }

    function wordPageHeight(section) {
        const saved = parseFloat(section.getAttribute('data-neq-page-h') || '');
        if (saved > 200) {
            return saved;
        }
        const measured = parseFloat(window.getComputedStyle(section).minHeight);
        const height = measured > 200 ? measured : mmToPx(279.4);
        section.setAttribute('data-neq-page-h', String(Math.round(height)));
        return height;
    }

    function pageBodyLimit(section) {
        const padBottom = parseFloat(window.getComputedStyle(section).paddingBottom) || 0;
        // Keep the "Page N" footer clear. A row that would be sliced by that
        // band moves to the next page, including the yes/no letter on the
        // last attachment row.
        const footerBand = 108;
        return wordPageHeight(section) - Math.max(padBottom, footerBand);
    }

    function firstOverflowRow(table, section, limit) {
        const rows = directTableRows(table);
        const top = section.getBoundingClientRect().top;
        for (let i = 0; i < rows.length; i++) {
            const box = rows[i].getBoundingClientRect();
            if (box.height < 1) {
                continue;
            }
            if ((box.bottom - top) > limit - 2) {
                return i;
            }
        }
        return -1;
    }

    function isSectionHeadingBlock(block) {
        if (!block || block.tagName === 'TABLE') {
            return false;
        }
        if (block.classList && block.classList.contains('neq-table-wrap')) {
            return false;
        }
        const text = (block.innerText || block.textContent || '').replace(/\s+/g, ' ').trim();
        if (!text || text.length > 90) {
            return false;
        }
        // A contents line also starts with "18.0". It is not a body heading,
        // and moving it would push the list onto the next page.
        if (isLikelyTocEntryText(text, block) || (block.getAttribute && block.getAttribute('data-neq-toc') === '1')) {
            return false;
        }
        return /^\d+(?:\.\d+)+\s+\S/.test(text);
    }

    function moveTableTail(section, table, splitAt, following) {
        const next = insertFollowPage(section);
        const article = pageArticle(next);
        const clone = table.cloneNode(true);
        copySelectValues(table, clone);
        clone.setAttribute('data-neq-page-split', '1');
        const origRows = directTableRows(table);
        const cloneRows = directTableRows(clone);
        const rowSnapshots = origRows.map(function (row) {
            return getRowCells(row).map(function (cell) {
                return (cell.innerText || cell.textContent || '').replace(/\s+/g, ' ').trim();
            });
        });
        for (let i = splitAt; i < origRows.length; i++) {
            if (blockHoldsCaret(origRows[i]) && cloneRows[i] && cloneRows[i].parentNode) {
                cloneRows[i].parentNode.replaceChild(origRows[i], cloneRows[i]);
            } else {
                origRows[i].remove();
            }
        }
        for (let i = 0; i < splitAt && i < cloneRows.length; i++) {
            cloneRows[i].remove();
        }
        article.appendChild(clone);
        // A split can leave the yes/no letter behind. Put the original cell
        // text back when the copy is empty.
        const keptRows = directTableRows(clone);
        keptRows.forEach(function (row, index) {
            const snap = rowSnapshots[splitAt + index] || [];
            const copied = getRowCells(row);
            copied.forEach(function (cell, cellIndex) {
                const want = snap[cellIndex] || '';
                const have = (cell.innerText || cell.textContent || '').replace(/\s+/g, '').trim();
                if (want && !have) {
                    const paragraph = cell.querySelector('p') || cell;
                    const span = paragraph.querySelector ? paragraph.querySelector('span') : null;
                    if (span) {
                        span.textContent = want;
                    } else {
                        paragraph.textContent = want;
                    }
                }
            });
        });
        following.forEach(function (el) {
            article.appendChild(el);
        });
    }

    function moveBlocksToNextPage(section, blocks) {
        if (!blocks.length) {
            return;
        }
        const next = insertFollowPage(section);
        const article = pageArticle(next);
        blocks.forEach(function (el) {
            article.appendChild(el);
        });
    }

    /*
     * Word still draws an empty paragraph as one line. The browser drops that
     * line, so the cover shrinks and TABLE OF CONTENTS starts on page 1.
     * Attachment 14 uses those blank lines to fill page 1; the list is page 2.
     */
    function holdBlankLineHeight(wrapper) {
        wrapper.querySelectorAll('section.docx > article > p').forEach(function (paragraph) {
            if (paragraph.closest('table')) {
                return;
            }
            const text = (paragraph.innerText || paragraph.textContent || '').replace(/\u00a0/g, ' ').trim();
            if (text) {
                return;
            }
            // A paragraph the user added stays only while it has words.
            // Do not prop an empty one up into a blank line.
            if (paragraph.getAttribute('data-neq-new-para') === '1' || paragraph.getAttribute('data-neq-user-cleared') === '1') {
                return;
            }
            const style = window.getComputedStyle(paragraph);
            const fontPx = parseFloat(style.fontSize) || 14.6;
            const line = Math.max(fontPx * 1.15, 12);
            if ((paragraph.getBoundingClientRect().height || 0) >= line * 0.75) {
                return;
            }
            paragraph.style.setProperty('min-height', line + 'px', 'important');
            const after = parseFloat(style.marginBottom) || 0;
            if (after < 6 && fontPx < 16) {
                paragraph.style.setProperty('margin-bottom', '10pt', 'important');
            }
        });
    }

    function pageIsContentsPage(section) {
        const blocks = articleBlocks(pageArticle(section));
        let heading = false;
        let entries = 0;
        blocks.forEach(function (block) {
            const text = (block.innerText || block.textContent || '').replace(/\s+/g, ' ').trim();
            if (/^table\s+of\s+contents$/i.test(text)) {
                heading = true;
            }
            if (isLikelyTocEntryText(text, block)) {
                entries += 1;
            }
        });
        return heading || entries >= 4;
    }

    function blockIsContentsEntry(block) {
        if (!block || (block.tagName || '').toUpperCase() === 'TABLE') {
            return false;
        }
        const text = (block.innerText || block.textContent || '').replace(/\s+/g, ' ').trim();
        if (/^table\s+of\s+contents$/i.test(text)) {
            return true;
        }
        return isLikelyTocEntryText(text, block);
    }

    /*
     * Word keeps a contents page number on the title line. A long title was
     * wrapping, so "10" and "11" dropped onto the next line.
     */
    function pinTocEntryOnOneLine(wrapper) {
        if (!wrapper) {
            return;
        }
        wrapper.querySelectorAll(':scope > section.docx').forEach(function (section) {
            if (!pageIsContentsPage(section)) {
                return;
            }
            articleBlocks(pageArticle(section)).forEach(function (block) {
                if (!blockIsContentsEntry(block)) {
                    return;
                }
                const text = (block.innerText || block.textContent || '').replace(/\s+/g, ' ').trim();
                if (!/\s\d{1,3}$/.test(text)) {
                    return;
                }
                block.style.setProperty('white-space', 'nowrap', 'important');
                block.querySelectorAll('a, span').forEach(function (el) {
                    el.style.setProperty('white-space', 'nowrap', 'important');
                });
            });
        });
    }

    function isTocHeadingBlock(block) {
        const text = (block.innerText || block.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
        return text === 'table of contents' || text === 'contents';
    }

    function isTocTableBlock(block) {
        if (!block || block.tagName !== 'TABLE') {
            return false;
        }
        if (block.getAttribute('data-neq-toc') === '1') {
            return true;
        }
        let hits = 0;
        directTableRows(block).forEach(function (row) {
            const text = (row.innerText || row.textContent || '').replace(/\s+/g, ' ').trim();
            if (/^\d+(?:\.\d+)+\s+\S/.test(text)) {
                hits += 1;
            }
        });
        return hits >= 4;
    }

    /*
     * Keep each sheet at the Word page height. Content that no longer fits
     * moves to the next page. A block already taller than one page stays put
     * so the letterhead is not copied until the view fills with empty sheets.
     */
    function blockStartsAtTop(start) {
        return start < 48;
    }

    function firstPaintedBodyBlock(article) {
        const blocks = articleBlocks(article);
        for (let i = 0; i < blocks.length; i++) {
            const box = blocks[i].getBoundingClientRect();
            if (box.height < 1 && box.width < 1) {
                continue;
            }
            return blocks[i];
        }
        return null;
    }

    /*
     * The letterhead lives in the page header. docx-preview pulls that header
     * up by a negative margin equal to its min-height, and the sheet is a
     * fixed-height flex column. A filled letterhead (wrapped title, equipment,
     * facility) is taller than that band, so flex shrinks the header back and
     * the table paints over the first body line — 1.0 OBJECTIVE, or a paragraph
     * added under the same header. The article is stacked above the header, so
     * the body text is what shows on top of the border.
     * Grow the header to the real table bottom plus a gap, and do not let the
     * page shrink it again.
     */
    function keepSectionBodyBelowLetterhead(section) {
        const header = section.querySelector(':scope > header, :scope > .docx_header');
        const article = pageArticle(section);
        if (!header || !article || article === section) {
            return;
        }
        const table = header.querySelector('table');
        header.style.setProperty('flex-shrink', '0', 'important');
        header.style.setProperty('height', 'auto', 'important');
        header.style.setProperty('overflow', 'visible', 'important');
        if (!header.hasAttribute('data-neq-base-min')) {
            const baseMin = parseFloat(window.getComputedStyle(header).minHeight) || 0;
            header.setAttribute('data-neq-base-min', String(baseMin));
        }
        const baseMin = parseFloat(header.getAttribute('data-neq-base-min')) || 0;
        header.style.setProperty('min-height', baseMin + 'px', 'important');
        if (!article.hasAttribute('data-neq-base-pad')) {
            const base = parseFloat(window.getComputedStyle(article).paddingTop) || 0;
            article.setAttribute('data-neq-base-pad', String(base));
        }
        const basePad = parseFloat(article.getAttribute('data-neq-base-pad')) || 0;
        article.style.setProperty('padding-top', basePad + 'px', 'important');

        let markerBottom = header.getBoundingClientRect().bottom;
        if (table) {
            markerBottom = Math.max(markerBottom, table.getBoundingClientRect().bottom);
        }
        Array.from(header.children).forEach(function (child) {
            const box = child.getBoundingClientRect();
            if (box.height > 1) {
                markerBottom = Math.max(markerBottom, box.bottom);
            }
        });
        const gap = 22;
        const headerTop = header.getBoundingClientRect().top;
        const needed = Math.ceil(markerBottom - headerTop + gap);
        if (needed > baseMin + 0.5 && needed < baseMin + 640) {
            header.style.setProperty('min-height', needed + 'px', 'important');
        }

        const first = firstPaintedBodyBlock(article);
        if (!first) {
            return;
        }
        const marginTop = parseFloat(window.getComputedStyle(first).marginTop) || 0;
        if (marginTop < 0) {
            first.style.setProperty('margin-top', '0', 'important');
        }
        const short = markerBottom + gap - first.getBoundingClientRect().top;
        if (short > 1) {
            article.style.setProperty('padding-top', (basePad + short) + 'px', 'important');
        }
    }

    let typingCaretBlock = null;

    function revealAttachmentCellText(wrapper) {
        if (!wrapper || !wrapper.querySelectorAll) {
            return;
        }
        wrapper.querySelectorAll('table').forEach(function (table) {
            if (!isAttachmentListTable(table)) {
                return;
            }
            table.querySelectorAll('td, th').forEach(function (cell) {
                if (cell.closest('table') !== table) {
                    return;
                }
                cell.style.setProperty('overflow', 'visible', 'important');
                cell.querySelectorAll('p, span').forEach(function (el) {
                    const text = (el.textContent || '').replace(/\s+/g, '').trim();
                    if (!text) {
                        return;
                    }
                    el.style.setProperty('position', 'static', 'important');
                    el.style.setProperty('overflow', 'visible', 'important');
                    if (el.getAttribute('data-neq-cb-hidden') === '1') {
                        el.removeAttribute('data-neq-cb-hidden');
                    }
                    const style = window.getComputedStyle(el);
                    if (style.display === 'none' || style.visibility === 'hidden' || parseFloat(style.fontSize) < 8) {
                        el.style.setProperty('display', 'inline', 'important');
                        el.style.setProperty('visibility', 'visible', 'important');
                        el.style.setProperty('font-size', '11pt', 'important');
                        el.style.setProperty('color', '#000', 'important');
                    }
                });
            });
        });
    }

    function pinSheetsToWordPage(wrapper) {
        wrapper.querySelectorAll(':scope > section.docx').forEach(function (section) {
            const pageLock = wordPageHeight(section);
            const keepsCaret = !!(typingCaretBlock && section.contains(typingCaretBlock));
            // The sheet stays the Word page height. Overflow rows are moved
            // before this runs, so the page itself does not grow.
            section.style.setProperty('height', pageLock + 'px', 'important');
            section.style.setProperty('max-height', pageLock + 'px', 'important');
            section.style.setProperty('min-height', pageLock + 'px', 'important');
            section.style.setProperty('overflow', keepsCaret ? 'visible' : 'hidden', 'important');
        });
    }

    function previewScrollHost() {
        const shell = document.getElementById('neqPreviewSection');
        if (shell) {
            const body = shell.querySelector(':scope > .card-body');
            if (body) {
                return body;
            }
        }
        return document.scrollingElement || document.documentElement;
    }

    function blockHoldsCaret(block) {
        const sel = window.getSelection();
        if (!sel || !sel.rangeCount || !block) {
            return false;
        }
        const node = sel.anchorNode || sel.focusNode;
        return !!(node && block.contains(node));
    }

    /* Overflow may move to the next sheet. The paragraph being typed stays. */
    function moveOverflowKeepingCaret(section, blocks, from) {
        let start = from;
        for (let i = from; i < blocks.length; i++) {
            if (blockHoldsCaret(blocks[i])) {
                start = i + 1;
                break;
            }
        }
        if (start >= blocks.length) {
            return false;
        }
        moveBlocksToNextPage(section, blocks.slice(start));
        return true;
    }

    function caretStillInBlock(block) {
        const sel = window.getSelection();
        if (!sel || !sel.rangeCount || !block) {
            return false;
        }
        const node = sel.anchorNode;
        return !!(node && block.contains(node));
    }

    function captureCaretPoint() {
        const sel = window.getSelection();
        if (!sel || !sel.rangeCount) {
            return null;
        }
        const range = sel.getRangeAt(0);
        let node = range.startContainer;
        const el = node && node.nodeType === 3 ? node.parentElement : node;
        if (!el || !el.closest || !previewShell || !previewShell.contains(el)) {
            return null;
        }
        const editable = el.closest('[contenteditable="true"]');
        if (!editable) {
            return null;
        }
        const block = el.closest('p, li, h1, h2, h3, h4, h5, h6, td, th') || editable;
        return {
            block: block,
            editable: editable,
            offset: measureCaretOffset(block, range.startContainer, range.startOffset)
        };
    }

    function addWholeCaretUnits(node, countBox) {
        if (!node) {
            return;
        }
        if (node.nodeType === 3) {
            countBox.value += node.textContent.length;
            return;
        }
        if (node.nodeName === 'BR') {
            countBox.value += 1;
            return;
        }
        for (let i = 0; i < node.childNodes.length; i++) {
            addWholeCaretUnits(node.childNodes[i], countBox);
        }
    }

    function measureCaretOffset(root, endNode, endOffset) {
        const countBox = { value: 0 };
        let found = false;
        function walk(node) {
            if (found || !node) {
                return;
            }
            if (node === endNode && node.nodeType === 3) {
                countBox.value += Math.min(endOffset, node.textContent.length);
                found = true;
                return;
            }
            if (node === endNode) {
                for (let i = 0; i < endOffset && i < node.childNodes.length; i++) {
                    addWholeCaretUnits(node.childNodes[i], countBox);
                }
                found = true;
                return;
            }
            if (node.nodeType === 3) {
                countBox.value += node.textContent.length;
                return;
            }
            if (node.nodeName === 'BR') {
                countBox.value += 1;
                return;
            }
            for (let i = 0; i < node.childNodes.length; i++) {
                walk(node.childNodes[i]);
                if (found) {
                    return;
                }
            }
        }
        walk(root);
        return countBox.value;
    }

    function caretOffsetInBlock(block) {
        const sel = window.getSelection();
        if (!sel || !sel.rangeCount || !block) {
            return null;
        }
        const range = sel.getRangeAt(0);
        if (!block.contains(range.startContainer)) {
            return null;
        }
        return measureCaretOffset(block, range.startContainer, range.startOffset);
    }

    function rangeAtCaretUnit(block, offset) {
        let left = offset;
        function walk(node) {
            if (node.nodeType === 3) {
                if (left <= node.textContent.length) {
                    return { node: node, offset: left };
                }
                left -= node.textContent.length;
                return null;
            }
            if (node.nodeName === 'BR') {
                if (left <= 0) {
                    return { before: node };
                }
                left -= 1;
                if (left <= 0) {
                    return { after: node };
                }
                return null;
            }
            for (let i = 0; i < node.childNodes.length; i++) {
                const hit = walk(node.childNodes[i]);
                if (hit) {
                    return hit;
                }
            }
            return null;
        }
        const hit = walk(block);
        const range = document.createRange();
        if (hit && hit.node) {
            range.setStart(hit.node, Math.min(hit.offset, hit.node.textContent.length));
        } else if (hit && hit.before && hit.before.parentNode) {
            range.setStartBefore(hit.before);
        } else if (hit && hit.after && hit.after.parentNode) {
            range.setStartAfter(hit.after);
        } else {
            range.selectNodeContents(block);
            range.collapse(false);
        }
        range.collapse(true);
        return range;
    }

    function restoreCaretPoint(saved) {
        if (!saved || !saved.block || !saved.block.isConnected || !saved.editable || !saved.editable.isConnected) {
            return;
        }
        const live = caretOffsetInBlock(saved.block);
        if (live === saved.offset) {
            return;
        }
        // A key pressed after this snapshot is newer. Putting the cursor back
        // would drop it into the middle of the word while typing quickly.
        if (live != null && live > saved.offset) {
            return;
        }
        const range = rangeAtCaretUnit(saved.block, saved.offset);
        try {
            saved.editable.focus({ preventScroll: true });
        } catch (error) {
            saved.editable.focus();
        }
        const sel = window.getSelection();
        if (!sel) {
            return;
        }
        sel.removeAllRanges();
        sel.addRange(range);
    }

    function keepElementOnThisPage(element, run) {
        const host = previewScrollHost();
        const anchor = (element && host)
            ? element.getBoundingClientRect().top - host.getBoundingClientRect().top
            : null;
        if (host) {
            host.style.setProperty('overflow-anchor', 'none');
        }
        try {
            run();
        } finally {
            if (host) {
                host.style.removeProperty('overflow-anchor');
            }
        }
        function putBack() {
            if (!host || anchor == null || !element || !element.isConnected) {
                return;
            }
            const now = element.getBoundingClientRect().top - host.getBoundingClientRect().top;
            const delta = now - anchor;
            if (Math.abs(delta) > 1) {
                host.scrollTop += delta;
            }
        }
        putBack();
        window.requestAnimationFrame(putBack);
        window.setTimeout(putBack, 0);
        window.setTimeout(putBack, 60);
    }

    function captureTypingAnchor() {
        const sel = window.getSelection();
        if (!sel || !sel.rangeCount) {
            return null;
        }
        const range = sel.getRangeAt(0);
        let node = range.startContainer;
        if (node && node.nodeType === 3) {
            node = node.parentElement;
        }
        if (!node || !node.closest) {
            return null;
        }
        const section = node.closest('section.docx');
        const host = previewScrollHost();
        if (!section || !host || !host.contains(node)) {
            return null;
        }
        const rects = range.getClientRects();
        const rect = rects.length ? rects[0] : node.getBoundingClientRect();
        const hostTop = host.getBoundingClientRect().top;
        return {
            host: host,
            node: node,
            section: section,
            caretOffset: rect.top - hostTop,
            sectionOffset: section.getBoundingClientRect().top - hostTop
        };
    }

    function restoreTypingAnchor(anchor) {
        if (!anchor || !anchor.host) {
            return;
        }
        const host = anchor.host;
        const hostTop = host.getBoundingClientRect().top;
        let top = null;
        let target = null;
        const caretStillOnSheet = anchor.node && anchor.node.isConnected
            && anchor.section && anchor.section.isConnected && anchor.section.contains(anchor.node);
        if (caretStillOnSheet || (anchor.node && anchor.node.isConnected)) {
            const sel = window.getSelection();
            if (sel && sel.rangeCount && anchor.node.contains(sel.anchorNode || anchor.node)) {
                const rects = sel.getRangeAt(0).getClientRects();
                if (rects.length) {
                    top = rects[0].top;
                }
            }
            if (top == null) {
                top = anchor.node.getBoundingClientRect().top;
            }
            target = anchor.caretOffset;
        } else if (anchor.section && anchor.section.isConnected) {
            top = anchor.section.getBoundingClientRect().top;
            target = anchor.sectionOffset;
        }
        if (top == null || target == null) {
            return;
        }
        const delta = top - hostTop - target;
        if (Math.abs(delta) < 2) {
            return;
        }
        host.scrollTop += delta;
    }

    function isBlankFlowBlock(block) {
        if (!block || tableInside(block)) {
            return false;
        }
        if (block.querySelector && block.querySelector('img, svg, canvas, table')) {
            return false;
        }
        const text = (block.innerText || block.textContent || '').replace(/\u00a0/g, ' ').trim();
        return !text || isStockTemplateNote(text);
    }

    function stripEdgeBlankBlocks(section, edge) {
        let blocks = articleBlocks(pageArticle(section));
        if (edge === 'trailing') {
            blocks = blocks.slice().reverse();
        }
        for (let i = 0; i < blocks.length; i++) {
            if (!isBlankFlowBlock(blocks[i])) {
                break;
            }
            const height = blocks[i].getBoundingClientRect().height || 0;
            if (edge === 'leading' || height > 24 || height < 1) {
                blocks[i].remove();
                continue;
            }
            break;
        }
    }

    function pageFreeRoom(section) {
        const limit = pageBodyLimit(section);
        const top = section.getBoundingClientRect().top;
        let bottom = 0;
        const header = section.querySelector(':scope > header, :scope > .docx_header');
        if (header) {
            bottom = Math.max(bottom, header.getBoundingClientRect().bottom - top);
        }
        articleBlocks(pageArticle(section)).forEach(function (block) {
            if (isBlankFlowBlock(block)) {
                return;
            }
            const box = block.getBoundingClientRect();
            if (box.height < 1) {
                return;
            }
            bottom = Math.max(bottom, box.bottom - top);
        });
        return limit - bottom;
    }

    function equalizeExistingRowLines(wrapper) {
        if (!wrapper || !wrapper.querySelectorAll) {
            return;
        }
        wrapper.querySelectorAll('section.docx > article table').forEach(function (table) {
            table.style.setProperty('border-collapse', 'collapse', 'important');
            table.style.setProperty('border-spacing', '0', 'important');
            const cells = Array.from(table.querySelectorAll('td, th')).filter(function (cell) {
                return cell.closest('table') === table;
            });
            const counts = {};
            cells.forEach(function (cell) {
                const style = window.getComputedStyle(cell);
                ['top', 'bottom'].forEach(function (side) {
                    const width = parseFloat(style['border-' + side + '-width']) || 0;
                    if (width < 0.2 || width > 4) {
                        return;
                    }
                    const key = String(Math.round(width));
                    counts[key] = (counts[key] || 0) + 1;
                });
            });
            let chosen = '';
            let best = 0;
            Object.keys(counts).forEach(function (key) {
                if (counts[key] > best) {
                    best = counts[key];
                    chosen = key;
                }
            });
            if (!chosen) {
                return;
            }
            const line = chosen + 'px';
            cells.forEach(function (cell) {
                const style = window.getComputedStyle(cell);
                ['top', 'bottom'].forEach(function (side) {
                    const width = parseFloat(style['border-' + side + '-width']) || 0;
                    if (width <= 0) {
                        return;
                    }
                    cell.style.setProperty('border-' + side + '-width', line, 'important');
                    cell.style.setProperty('border-' + side + '-style', 'solid', 'important');
                });
            });
        });
    }

    function unwrapGluedPrefixes(text) {
        let best = normalizeEditText(text || '');
        if (best.length < 24) {
            return text == null ? '' : String(text);
        }
        let changed = true;
        while (changed) {
            changed = false;
            const limit = Math.floor(best.length / 2);
            for (let cut = 12; cut <= limit; cut++) {
                const head = best.slice(0, cut);
                if (head.indexOf(' ') === -1) {
                    continue;
                }
                const tail = best.slice(cut);
                if (tail.indexOf(head) === 0 && tail.length > head.length) {
                    best = tail;
                    changed = true;
                    break;
                }
            }
        }
        return best;
    }

    function rowSnapshotText(row) {
        return getRowCells(row).map(function (cell) {
            return normalizeEditText(cellLiveText(cell) || nodeEditText(cell) || cell.innerText || '');
        }).join('\n');
    }

    // A page split used to leave a second copy of the same Word row on the next
    // sheet. Autosave then wrote that copy into the blank rows underneath.
    function dropDuplicateSplitRows(root) {
        if (!root || !root.querySelectorAll) {
            return;
        }
        const seen = new Map();
        Array.from(root.querySelectorAll('table')).forEach(function (table) {
            if (table.parentElement && table.parentElement.closest && table.parentElement.closest('td, th')) {
                return;
            }
            // The letterhead is copied onto every page on purpose. Those copies
            // share the first page's row index, and must not be deleted.
            if (table.closest('header, .docx_header, footer, .docx_footer')) {
                return;
            }
            const key = table.getAttribute('data-neq-table');
            if (key === null || key === '') {
                return;
            }
            getDirectTableRows(table).forEach(function (row) {
                if (row.getAttribute('data-neq-new-row') === '1' || row.getAttribute('data-neq-repeat-header') === '1') {
                    return;
                }
                const rowKey = row.getAttribute('data-neq-row');
                if (rowKey === null || rowKey === '') {
                    return;
                }
                const id = key + ':' + rowKey;
                const snap = rowSnapshotText(row);
                const first = seen.get(id);
                if (!first) {
                    seen.set(id, { row: row, snap: snap });
                    return;
                }
                // Same Word row copied onto the next page. The copy often has
                // the typed values and an empty signature, so the text does
                // not match. Keep the fuller row and drop the copy. Saving
                // both wrote the last calibration row into the blank rows below.
                let keep = snap.length >= first.snap.length ? row : first.row;
                let drop = keep === row ? first.row : row;
                if (selectionInside(drop) && !selectionInside(keep)) {
                    const swap = keep;
                    keep = drop;
                    drop = swap;
                }
                if (!drop.parentNode) {
                    return;
                }
                drop.remove();
                seen.set(id, { row: keep, snap: rowSnapshotText(keep) });
            });
        });
    }

    function joinSplitTablesOnSamePage(wrapper) {
        if (!wrapper) {
            return;
        }
        wrapper.querySelectorAll(':scope > section.docx').forEach(function (section) {
            const article = pageArticle(section);
            let guard = 0;
            while (guard < 12) {
                guard += 1;
                const blocks = articleBlocks(article);
                let joined = false;
                for (let i = 0; i < blocks.length; i++) {
                    const table = tableInside(blocks[i]);
                    const key = table && table.getAttribute('data-neq-table');
                    if (!key) {
                        continue;
                    }
                    let next = i + 1;
                    while (next < blocks.length && isBlankFlowBlock(blocks[next])) {
                        next += 1;
                    }
                    const nextTable = next < blocks.length ? tableInside(blocks[next]) : null;
                    if (!nextTable || nextTable.getAttribute('data-neq-table') !== key) {
                        continue;
                    }
                    // Two pieces of the same table on one page draw two borders
                    // where they meet, so the line between those rows looks bold.
                    const dest = table.querySelector(':scope > tbody') || table;
                    directTableRows(nextTable).forEach(function (row) {
                        dest.appendChild(row);
                    });
                    const holder = blocks[next];
                    nextTable.remove();
                    if (holder !== nextTable && !tableInside(holder)) {
                        holder.remove();
                    }
                    joined = true;
                    break;
                }
                if (!joined) {
                    break;
                }
            }
        });
    }

    function pullTableRowsThatFit(section, block, table) {
        const rows = directTableRows(table);
        if (!rows.length) {
            return false;
        }
        const limit = pageBodyLimit(section);
        const top = section.getBoundingClientRect().top;
        let used = top;
        const header = section.querySelector(':scope > header, :scope > .docx_header');
        if (header) {
            used = Math.max(used, header.getBoundingClientRect().bottom);
        }
        articleBlocks(pageArticle(section)).forEach(function (item) {
            if (isBlankFlowBlock(item)) {
                return;
            }
            used = Math.max(used, item.getBoundingClientRect().bottom);
        });
        let count = 0;
        let cursor = used;
        for (let i = 0; i < rows.length; i++) {
            const height = Math.max(rows[i].getBoundingClientRect().height || 0, 20);
            if (cursor + height > top + limit - 8) {
                break;
            }
            cursor += height;
            count += 1;
        }
        if (count < 1) {
            return false;
        }
        pageArticle(section).appendChild(block);
        const overflowAt = firstOverflowRow(table, section, limit);
        if (overflowAt === 0) {
            const next = section.nextElementSibling;
            const dest = next ? pageArticle(next) : null;
            if (dest) {
                const first = dest.firstElementChild;
                if (first) {
                    dest.insertBefore(block, first);
                } else {
                    dest.appendChild(block);
                }
            }
            return false;
        }
        if (overflowAt > 0) {
            moveTableTail(section, table, overflowAt, []);
        }
        return true;
    }

    function fillSpareRoomFromNextPage(wrapper) {
        let guard = 0;
        let movedAny = true;
        while (movedAny && guard < 40) {
            guard += 1;
            movedAny = false;
            const sections = Array.from(wrapper.querySelectorAll(':scope > section.docx'));
            for (let i = 0; i < sections.length - 1; i++) {
                const section = sections[i];
                const next = sections[i + 1];
                const editingNext = typeof isEditingEnabled !== 'undefined' && isEditingEnabled && selectionInside(next);
                if (!editingNext) {
                    stripEdgeBlankBlocks(section, 'trailing');
                    stripEdgeBlankBlocks(next, 'leading');
                }
                let inner = 0;
                while (inner < 60) {
                    inner += 1;
                    const room = pageFreeRoom(section);
                    const nextBlocks = articleBlocks(pageArticle(next));
                    if (!nextBlocks.length || room < 28) {
                        break;
                    }
                    const block = nextBlocks[0];
                    const blockH = block.getBoundingClientRect().height || 0;
                    const table = tableInside(block);
                    if (!table && isBlankFlowBlock(block)) {
                        block.remove();
                        movedAny = true;
                        continue;
                    }
                    if (blockH > 1 && blockH <= room - 4) {
                        // The line being typed stays put. A contents line still
                        // comes back while this page has room.
                        if (editingNext && !blockIsContentsEntry(block)) {
                            break;
                        }
                        // The contents page ends at the list. "1.0 OBJECTIVE"
                        // starts the next page, even when this page still has room.
                        if (pageIsContentsPage(section) && !blockIsContentsEntry(block)) {
                            break;
                        }
                        // A heading stays with the paragraph under it. Do not
                        // pull "1.0 OBJECTIVE" onto this page and leave the
                        // sentence on the next one.
                        if (isSectionHeadingBlock(block)) {
                            const follow = nextBlocks[1];
                            const followH = follow ? (follow.getBoundingClientRect().height || 0) : 0;
                            if (!follow || followH < 1 || followH > room - blockH - 8) {
                                break;
                            }
                        }
                        pageArticle(section).appendChild(block);
                        movedAny = true;
                        continue;
                    }
                    if (table && pullTableRowsThatFit(section, block, table)) {
                        movedAny = true;
                        continue;
                    }
                    break;
                }
            }
        }
    }

    function pushRowsPastPageEnd(wrapper) {
        const sections = Array.from(wrapper.querySelectorAll(':scope > section.docx'));
        sections.forEach(function (section) {
            let guard = 0;
            while (guard < 20) {
                guard += 1;
                const limit = pageBodyLimit(section);
                const blocks = articleBlocks(pageArticle(section));
                let table = null;
                let splitAt = -1;
                let blockIndex = -1;
                for (let b = 0; b < blocks.length; b++) {
                    const candidate = tableInside(blocks[b]);
                    if (!candidate) {
                        continue;
                    }
                    const at = firstOverflowRow(candidate, section, limit);
                    if (at >= 0) {
                        table = candidate;
                        splitAt = at;
                        blockIndex = b;
                        break;
                    }
                }
                if (!table) {
                    break;
                }
                if (splitAt === 0) {
                    const rows = directTableRows(table);
                    const rowH = rows[0] ? rows[0].getBoundingClientRect().height : 0;
                    const start = table.getBoundingClientRect().top - section.getBoundingClientRect().top;
                    const rowCanMoveWhole = rowH > 1 && rowH <= (pageBodyLimit(section) - 36) && start > 24;
                    if (!rowCanMoveWhole && rows.length > 1) {
                        const following = blocks.slice(blockIndex + 1);
                        moveTableTail(section, table, 1, following);
                        continue;
                    }
                    if (!rowCanMoveWhole && blockIndex + 1 < blocks.length) {
                        moveBlocksToNextPage(section, blocks.slice(blockIndex + 1));
                        continue;
                    }
                    // One row already taller than the page, and nothing after it.
                    // Moving it again would only add blank sheets.
                    if (!rowCanMoveWhole) {
                        break;
                    }
                    let from = blockIndex;
                    if (from > 0 && isSectionHeadingBlock(blocks[from - 1])) {
                        from = from - 1;
                    }
                    moveBlocksToNextPage(section, blocks.slice(from));
                    continue;
                }
                const following = blocks.slice(blockIndex + 1);
                const next = section.nextElementSibling;
                const sameTable = next && next.getAttribute && next.getAttribute('data-neq-page-follow') === '1'
                    ? (function () {
                        const blocksOnNext = articleBlocks(pageArticle(next));
                        for (let n = 0; n < blocksOnNext.length; n++) {
                            const other = tableInside(blocksOnNext[n]);
                            if (other && other.getAttribute('data-neq-table') === table.getAttribute('data-neq-table')) {
                                return other;
                            }
                        }
                        return null;
                    })()
                    : null;
                if (!sameTable) {
                    moveTableTail(section, table, splitAt, following);
                    continue;
                }
                const dest = sameTable.querySelector(':scope > tbody') || sameTable;
                directTableRows(table).slice(splitAt).forEach(function (row) {
                    dest.appendChild(row);
                });
                following.forEach(function (el) {
                    pageArticle(next).appendChild(el);
                });
            }
        });
    }

    function splitPreviewOntoA4Pages(wrapper) {
        if (!wrapper) {
            return;
        }
        wrapper.querySelectorAll(':scope > section.docx').forEach(function (section) {
            section.style.setProperty('height', 'auto', 'important');
            section.style.setProperty('max-height', 'none', 'important');
            section.style.setProperty('overflow', 'visible', 'important');
        });
        mergeFollowPages(wrapper);
        splitWordArticlesOntoPages(wrapper);
        copyLetterheadOntoEveryPage(wrapper);
        if (typeof stampNeqTables === 'function') {
            stampNeqTables();
        }
        let guard = 0;
        let index = 0;
        while (index < wrapper.querySelectorAll(':scope > section.docx').length && guard < 48) {
            guard += 1;
            const section = wrapper.querySelectorAll(':scope > section.docx')[index];
            keepSectionBodyBelowLetterhead(section);
            const article = pageArticle(section);
            const limit = pageBodyLimit(section);
            const blocks = articleBlocks(article);
            const top = section.getBoundingClientRect().top;
            const beforeHeight = section.getBoundingClientRect().height;
            let moved = false;
            for (let b = 0; b < blocks.length; b++) {
                const block = blocks[b];
                const table = tableInside(block);
                const box = (table || block).getBoundingClientRect();
                const start = box.top - top;
                const end = box.bottom - top;
                if (end <= limit + 2) {
                    continue;
                }
                if (table && isTocTableBlock(table) && box.height <= limit && start > 24) {
                    let from = b;
                    if (b > 0 && isTocHeadingBlock(blocks[b - 1])) {
                        from = b - 1;
                    }
                    moved = moveOverflowKeepingCaret(section, blocks, from);
                    break;
                }
                if (table) {
                    const splitAt = firstOverflowRow(table, section, limit);
                    if (splitAt > 0) {
                        // Rows that still fit stay on this page, under the heading.
                        moveTableTail(section, table, splitAt, blocks.slice(b + 1));
                        moved = true;
                        break;
                    }
                    // The first row is already at the top and still crosses the
                    // page (a parts-name cell with many lines). Keep that row.
                    // Move every row under it, and the blocks after the table,
                    // so the approval table is not clipped off the sheet.
                    if (splitAt === 0) {
                        const rows = directTableRows(table);
                        const rowH = rows[0] ? rows[0].getBoundingClientRect().height : 0;
                        const rowCanMoveWhole = rowH > 1 && rowH <= (limit - 36) && start > 24;
                        if (!rowCanMoveWhole && rows.length > 1) {
                            moveTableTail(section, table, 1, blocks.slice(b + 1));
                            moved = true;
                            break;
                        }
                        if (!rowCanMoveWhole && b + 1 < blocks.length) {
                            moved = moveOverflowKeepingCaret(section, blocks, b + 1);
                            break;
                        }
                    }
                    if ((splitAt === 0 || (splitAt < 0 && end > limit)) && start > 24) {
                        // The row does not fit on this page. Move the whole table.
                        // Keep the section heading with it.
                        let from = b;
                        if (b > 0 && isSectionHeadingBlock(blocks[b - 1])) {
                            from = b - 1;
                        }
                        moved = moveOverflowKeepingCaret(section, blocks, from);
                        break;
                    }
                    continue;
                }
                const blockHeight = end - start;
                if (blockHeight > limit - 8 && blockStartsAtTop(start)) {
                    moved = moveOverflowKeepingCaret(section, blocks, b + 1);
                    break;
                }
                if (start > 24) {
                    moved = moveOverflowKeepingCaret(section, blocks, b);
                    break;
                }
            }
            if (moved && section.getBoundingClientRect().height < beforeHeight - 1) {
                continue;
            }
            index += 1;
        }
        // A row that still fits goes back under the heading. A heading left
        // alone at the bottom of a page moves with its table.
        fillSpareRoomFromNextPage(wrapper);
        // Do not leave the last added row cut off on this page.
        pushRowsPastPageEnd(wrapper);
        // "1.0 OBJECTIVE" must stay with the paragraph under it, on the next page.
        keepHeadingsWithFollowing(wrapper);
        // A sheet left with only the letterhead is not a page. Drop it so the
        // next page's content moves up. No blank page stays in the preview.
        dropEmptyPreviewPages(wrapper);
        syncLetterheadValuesOntoEveryPage(wrapper);
        // A table that was split and then pulled back onto one page must be one
        // table again, so the line between those rows is not a double border.
        joinSplitTablesOnSamePage(wrapper);
        dropDuplicateSplitRows(wrapper);
        equalizeExistingRowLines(wrapper);
        revealAttachmentCellText(wrapper);
        paintSavedFill(wrapper);
        const previewRoot = wrapper.closest ? wrapper.closest('#neqPreviewMount, .docx-preview-container') : null;
        ensureAttachmentNaCheckboxes(previewRoot || wrapper);
        pinSheetsToWordPage(wrapper);
        pinTocEntryOnOneLine(wrapper);
        paintApprovalPageHeading(wrapper);
        if (typeof isEditingEnabled !== 'undefined' && isEditingEnabled) {
            bindTableRowTools(true);
        }
        restorePinnedApproverSelects(wrapper);
        healTrainingDepartments(wrapper);
    }

    function paintApprovalPageHeading(root) {
        if (!root || !root.querySelectorAll) {
            return;
        }
        root.querySelectorAll('p, h1, h2, h3, h4').forEach(function (el) {
            if (el.closest && el.closest('table, header, footer, .docx_header, .docx_footer')) {
                return;
            }
            const text = (el.innerText || el.textContent || '').replace(/\s+/g, ' ').trim();
            if (!/^approval\s*page$/i.test(text)) {
                return;
            }
            el.style.setProperty('background', '#E0E0E0', 'important');
            el.style.setProperty('font-weight', '700', 'important');
            el.style.setProperty('padding', '3px 6px', 'important');
            el.style.setProperty('display', 'block', 'important');
        });
    }

    function keepHeadingsWithFollowing(wrapper) {
        const sections = Array.from(wrapper.querySelectorAll(':scope > section.docx'));
        sections.forEach(function (section, index) {
            const next = sections[index + 1];
            if (!next) {
                return;
            }
            const blocks = articleBlocks(pageArticle(section)).filter(function (block) {
                return !isBlankFlowBlock(block);
            });
            if (!blocks.length || !isSectionHeadingBlock(blocks[blocks.length - 1])) {
                return;
            }
            const heading = blocks[blocks.length - 1];
            if (pageIsContentsPage(section) || blockIsContentsEntry(heading)) {
                return;
            }
            const article = pageArticle(next);
            const first = article.firstElementChild;
            if (first) {
                article.insertBefore(heading, first);
            } else {
                article.appendChild(heading);
            }
        });
    }

    function paragraphTextIsEmpty(node) {
        const text = (node.innerText || node.textContent || '').replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
        return text === '' || text === '•';
    }

    function paragraphWasCleared(node) {
        if (!node || (node.closest && node.closest('td, th, header, footer, .docx_header, .docx_footer'))) {
            return false;
        }
        if (!paragraphTextIsEmpty(node)) {
            return false;
        }
        if (node.getAttribute('data-neq-new-para') === '1') {
            return true;
        }
        return !!normalizeEditText(node.getAttribute('data-neq-original') || '');
    }

    function removeClearedParagraph(node) {
        const previous = node.previousElementSibling;
        node.remove();
        if (previous && previous.getAttribute && previous.getAttribute('contenteditable') === 'true' && previous.focus) {
            try {
                previous.focus({ preventScroll: true });
            } catch (e) {
                previous.focus();
            }
        }
    }

    function bindPreviewPageGrowth() {
        return;
    }

    function dropBodySentencesFromPageHeaders(wrapper) {
        const seen = new Set();
        wrapper.querySelectorAll('section.docx > article p, section.docx > article li').forEach(function (node) {
            const text = (node.innerText || '').replace(/\s+/g, ' ').trim().toLowerCase();
            if (text.length >= 40) {
                seen.add(text);
            }
        });
        wrapper.querySelectorAll('header p, .docx_header p').forEach(function (node) {
            const text = (node.innerText || '').replace(/\s+/g, ' ').trim().toLowerCase();
            if (text.length < 40) {
                return;
            }
            if (seen.has(text)) {
                node.remove();
            }
        });
    }

    function preparePreviewDomForA4Split(wrapper) {
        if (!wrapper) {
            return;
        }
        wrapper.querySelectorAll(':scope > section.docx').forEach(function (section) {
            section.style.setProperty('height', 'auto', 'important');
            section.style.setProperty('overflow', 'visible', 'important');
        });
        dropStackedDuplicateParagraphs(wrapper);
        healSplitSectionHeadings(wrapper);
        dropEmptyPreviewPages(wrapper);
        joinSplitTablesOnSamePage(wrapper);
        dropDuplicateSplitRows(wrapper);
        equalizeExistingRowLines(wrapper);
        revealClippedListText(wrapper);
        boldMatchingHeadingNumbers(wrapper);
        dropForeignHeadingRows(wrapper);
        dropBodySentencesFromPageHeaders(wrapper);
        stripMisplacedAnnexCheckboxes(wrapper);
        stripIdentityLabelCheckboxes(wrapper);
        equalizeComponentDataRowHeights(wrapper);
        extendHeadingToBodyRightEdge(wrapper);
        alignLetterheadValuesLeft(wrapper);
        applyOfficialHeaderLogo(wrapper);
        holdImportedRowHeights(wrapper);
        holdBlankLineHeight(wrapper);
    }

    function reflowA4Pages(root) {
        const wrapper = root && root.querySelector ? root.querySelector('.docx-wrapper') : null;
        if (!wrapper) {
            return;
        }
        preparePreviewDomForA4Split(wrapper);
        splitPreviewOntoA4Pages(wrapper);
        stampVisiblePageNumbers(wrapper);
        bindPreviewPageGrowth(wrapper);
    }

    /*
     * docx-preview puts Word's row height on the tr only. An empty cell then
     * collapses, so lock view is shorter than the file. NEQ2 (attachment 14)
     * also has a few rows saved at 3pt; those match the normal rows in that table.
     * Runs for every imported docx, in lock and unlock.
     */
    function holdImportedRowHeights(root) {
        if (!root || !root.querySelectorAll) {
            return;
        }
        root.querySelectorAll('table').forEach(function (table) {
            if (table.closest('header, footer, .docx_header, .docx_footer')) {
                return;
            }
            if (table.parentElement && table.parentElement.closest('td, th')) {
                return;
            }
            const rows = Array.from(table.rows || []).filter(function (row) {
                return row.closest('table') === table;
            });
            const parsed = rows.map(function (row) {
                const value = parseFloat(row.style.height);
                return isFinite(value) ? value : 0;
            });
            const normal = parsed.filter(function (value) {
                return value >= 12;
            }).sort(function (a, b) {
                return a - b;
            });
            const fallback = normal.length ? normal[Math.floor(normal.length / 2)] : 16;
            rows.forEach(function (row, index) {
                const value = parsed[index];
                if (!(value > 0)) {
                    return;
                }
                const heightPt = value >= 12 ? value : fallback;
                const css = heightPt.toFixed(2) + 'pt';
                Array.from(row.cells || []).forEach(function (cell) {
                    cell.style.setProperty('min-height', css, 'important');
                });
            });
        });
    }

    async function renderClientPreview(fileUrl, previewOptions) {
        if (!window.docx) {
            throw new Error('DOCX preview library is unavailable.');
        }

        const opts = previewOptions || {};
        const fetchUrl = withCacheBust(fileUrl, opts.cacheBust);
        const response = await fetch(fetchUrl, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Cache-Control': 'no-cache' },
        });
        if (!response.ok) {
            throw new Error('Unable to load the document file.');
        }

        const arrayBuffer = await response.arrayBuffer();
        currentDocxBlob = new Blob([arrayBuffer], {
            type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        });

        previewArea.style.visibility = 'hidden';
        await window.docx.renderAsync(
            currentDocxBlob,
            previewArea,
            styleContainer,
            {
                className: 'docx',
                inWrapper: true,
                ignoreWidth: false,
                ignoreHeight: false,
                ignoreFonts: false,
                breakPages: true,
                ignoreLastRenderedPageBreak: false,
                experimental: true,
                trimXmlDeclaration: true,
                useBase64URL: true,
                renderHeaders: true,
                renderFooters: true,
                renderFootnotes: true,
                renderEndnotes: true,
                renderAltChunks: true,
            }
        );

        fitPreviewTablesToPage(previewArea);
        disablePreviewLinkJumps(previewArea);
        reflowA4Pages(previewArea);
        await fixPreviewImages(previewArea, currentDocxBlob);

        if (!opts.skipIdentityRestore) {
            try {
                const ooxmlValues = await extractIdentityValuesFromDocxBlob(currentDocxBlob);
                restoreMissingIdentityValues(docxTextTargets || [], ooxmlValues);
            } catch (restoreError) {
                console.warn('[NEQ Preview] identity restore failed:', restoreError);
            }
        }

        try {
            savedFillCache = await readSavedFillFromDocx(currentDocxBlob);
            const filledWrapper = previewArea.querySelector('.docx-wrapper');
            if (filledWrapper) {
                paintSavedFill(filledWrapper);
            }
        } catch (fillError) {
            console.warn('[NEQ Preview] saved fill restore failed:', fillError);
        }

        const pageWrapper = previewArea.querySelector('.docx-wrapper');
        if (pageWrapper) {
            splitPreviewOntoA4Pages(pageWrapper);
            stampVisiblePageNumbers(pageWrapper);
        }

        hasActivePreview = !!previewArea.querySelector('.docx-wrapper');
        if (hasActivePreview) {
            showProtectedViewBanner();
        }
    }

    let neqPreviewHome = null;

    function setNeqChromeHidden(hidden) {
        // Only hide list + page hero. Keep sidebar + topbar like Protocol Collection.
        const nodes = [
            document.getElementById('neqListSection'),
            document.querySelector('.page-hero'),
        ];
        nodes.forEach(function (el) {
            if (!el) {
                return;
            }
            if (hidden) {
                el.setAttribute('data-neq-was-hidden', '1');
                el.style.setProperty('display', 'none', 'important');
                el.style.setProperty('visibility', 'hidden', 'important');
            } else if (el.getAttribute('data-neq-was-hidden') === '1') {
                el.removeAttribute('data-neq-was-hidden');
                el.style.removeProperty('display');
                el.style.removeProperty('visibility');
            }
        });
    }

    function getNeqPreviewHost() {
        return document.querySelector('main.app-page')
            || document.getElementById('content')
            || document.getElementById('content-wrapper')
            || document.body;
    }

    function applyPreviewOverlayStyles(preview) {
        if (!preview) {
            return;
        }
        const host = getNeqPreviewHost();
        if (host && host !== document.body) {
            host.style.setProperty('position', 'relative', 'important');
            host.style.setProperty('min-height', 'calc(100vh - 110px)', 'important');
            host.setAttribute('data-neq-preview-host', '1');
        }
        preview.classList.add('neq-preview-overlay');
        preview.style.setProperty('position', 'absolute', 'important');
        preview.style.setProperty('top', '0', 'important');
        preview.style.setProperty('left', '0', 'important');
        preview.style.setProperty('right', '0', 'important');
        preview.style.setProperty('bottom', '0', 'important');
        preview.style.setProperty('width', '100%', 'important');
        preview.style.setProperty('height', '100%', 'important');
        preview.style.setProperty('min-height', 'calc(100vh - 110px)', 'important');
        preview.style.setProperty('max-width', 'none', 'important');
        preview.style.setProperty('margin', '0', 'important');
        preview.style.setProperty('z-index', '20', 'important');
        preview.style.setProperty('display', 'flex', 'important');
        preview.style.setProperty('flex-direction', 'column', 'important');
        preview.style.setProperty('overflow', 'hidden', 'important');
        preview.style.setProperty('background', '#f8f9fc', 'important');
        preview.style.setProperty('border-radius', '0', 'important');
        preview.style.setProperty('border', 'none', 'important');
        const body = preview.querySelector(':scope > .card-body') || preview.querySelector('.card-body');
        if (body) {
            body.style.setProperty('flex', '1 1 auto', 'important');
            body.style.setProperty('overflow', 'auto', 'important');
        }
    }

    function clearPreviewOverlayStyles(preview) {
        if (!preview) {
            return;
        }
        preview.classList.remove('neq-preview-overlay');
        [
            'position', 'top', 'left', 'right', 'bottom', 'width', 'height', 'min-height', 'max-width',
            'margin', 'z-index', 'display', 'flex-direction', 'overflow', 'background',
            'border-radius', 'border',
        ].forEach(function (prop) {
            preview.style.removeProperty(prop);
        });
        const body = preview.querySelector(':scope > .card-body') || preview.querySelector('.card-body');
        if (body) {
            body.style.removeProperty('flex');
            body.style.removeProperty('overflow');
        }
        const host = document.querySelector('[data-neq-preview-host="1"]');
        if (host) {
            host.removeAttribute('data-neq-preview-host');
            host.style.removeProperty('position');
            host.style.removeProperty('min-height');
        }
    }

    function enterDocumentFocusMode() {
        document.documentElement.classList.add('neq-doc-focus');
        document.body.classList.add('neq-doc-focus');

        setNeqChromeHidden(true);

        const preview = document.getElementById('neqPreviewSection');
        const host = getNeqPreviewHost();
        if (preview) {
            if (!neqPreviewHome) {
                neqPreviewHome = {
                    parent: preview.parentNode,
                    next: preview.nextSibling,
                };
            }
            // Keep preview inside main content (sidebar stays visible).
            if (host && preview.parentElement !== host) {
                host.appendChild(preview);
            }
            applyPreviewOverlayStyles(preview);
        }

        const backBtn = document.getElementById('btnNeqBackToList');
        if (backBtn) {
            backBtn.classList.remove('d-none');
            backBtn.classList.remove('btn-outline-secondary');
            backBtn.classList.add('btn-primary');
        }
        ensureFloatToolbarOnBody();
        applyFloatToolbarFixedStyles();
        window.scrollTo(0, 0);
    }

    function exitDocumentFocusMode() {
        document.documentElement.classList.remove('neq-doc-focus');
        document.body.classList.remove('neq-doc-focus');
        document.body.style.removeProperty('overflow');

        setNeqChromeHidden(false);

        const preview = document.getElementById('neqPreviewSection');
        if (preview) {
            clearPreviewOverlayStyles(preview);
            if (neqPreviewHome && neqPreviewHome.parent) {
                if (neqPreviewHome.next && neqPreviewHome.next.parentNode === neqPreviewHome.parent) {
                    neqPreviewHome.parent.insertBefore(preview, neqPreviewHome.next);
                } else {
                    neqPreviewHome.parent.appendChild(preview);
                }
            }
        }

        const backBtn = document.getElementById('btnNeqBackToList');
        if (backBtn) {
            backBtn.classList.add('d-none');
            backBtn.classList.remove('btn-primary');
            backBtn.classList.add('btn-outline-secondary');
        }
        setFloatToolbarVisible(false);
        showPlaceholder();
        document.querySelectorAll('.js-preview-btn').forEach(function (btn) {
            btn.classList.remove('active');
        });
        const listSection = document.getElementById('neqListSection');
        if (listSection && typeof listSection.scrollIntoView === 'function') {
            listSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function escapeNeqHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function neqFriendlyRoleFromKey(roleKey, fallbackText) {
        const cleanFallback = normalizeEditText(fallbackText || '');
        if (cleanFallback && !/^name(?:\s*[\/&]\s*designation)?$/i.test(cleanFallback) && !/^role$/i.test(cleanFallback)) {
            return cleanFallback;
        }
        const key = String(roleKey || '').toLowerCase();
        const map = {
            post_prepared_by_name: 'Prepared By',
            post_checked_by_engineering_name: 'Checked By (Engineering)',
            post_checked_by_production_name: 'Checked By (Production)',
            post_checked_by_quality_name: 'Checked By (Quality Assurance)',
            post_checked_by_validation_name: 'Checked By (Validation)',
            post_approved_by_quality_name: 'Approved By (Quality Assurance)',
            approved_by_user_name: 'Approved By (User)',
        };
        if (map[key]) {
            return map[key];
        }
        if (/prepared_by/.test(key)) return 'Prepared By';
        if (/checked_by/.test(key)) return 'Checked By';
        if (/reviewed_by/.test(key)) return 'Reviewed By';
        if (/approved_by/.test(key)) return 'Approved By';
        return cleanFallback || 'Approver';
    }

    function buildNeqUserSelectElement(cssClass, selectedUserId) {
        const sel = document.createElement('select');
        sel.className = cssClass || 'docx-input neq-approver-select';
        const blank = document.createElement('option');
        blank.value = '';
        blank.textContent = '-- Select User --';
        sel.appendChild(blank);
        (neqApproverUsers || []).forEach(function (user) {
            const opt = document.createElement('option');
            opt.value = String(user.id);
            const display = approverNameCellText(user, true) || user.label || ('User #' + user.id);
            opt.textContent = display;
            opt.setAttribute('data-name', display);
            opt.setAttribute('data-designation', user.designation || '');
            sel.appendChild(opt);
        });
        if (selectedUserId && Number(selectedUserId) > 0) {
            sel.value = String(selectedUserId);
        }
        return sel;
    }

    function neqHeaderTableColumnsInfo(appTableIndex) {
        const fragments = Array.from(previewArea.querySelectorAll('table[data-table-index="' + String(appTableIndex) + '"]'));
        const info = {
            sectionTitle: '',
            roleCol: -1,
            nameCol: -1,
            departmentCol: -1,
            signatureCol: -1,
            dateCol: -1,
            nameCombined: false,
            columnCount: 0,
        };
        fragments.forEach(function (frag) {
            getDirectTableRows(frag).forEach(function (r) {
                const cells = Array.from(r.children).filter(function (el) {
                    return el.tagName === 'TD' || el.tagName === 'TH';
                });
                if (!cells.length) return;
                if (cells.length === 1 || Number(cells[0].getAttribute('colspan') || 1) >= 2) {
                    const bText = normalizeEditText(cells[0].innerText || cells[0].textContent || '');
                    if (!info.sectionTitle && /^(prepared|checked|approved|reviewed|performed|compiled|complied|recorded)\s*by\b/i.test(bText)) {
                        info.sectionTitle = bText.replace(/:\s*$/, '');
                    }
                    return;
                }
                const headerTexts = cells.map(function (c) {
                    return normalizeEditText(c.innerText || c.textContent || '').toLowerCase();
                });
                const hasNameOrSig = headerTexts.some(function (t) {
                    return /\bname\b/.test(t) || /\bsign/.test(t) || /\bdate\b/.test(t);
                });
                if (!hasNameOrSig || info.nameCol >= 0) {
                    return;
                }
                info.columnCount = Math.max(info.columnCount, cells.length);
                headerTexts.forEach(function (t, idx) {
                    if (info.nameCol < 0 && /\bname\b/.test(t) && !/equipment|document|system|file/.test(t)) {
                        info.nameCol = idx;
                        if (/designation/.test(t)) {
                            info.nameCombined = true;
                        }
                    } else if (info.departmentCol < 0 && /(department|designation)/.test(t) && !/\bname\b/.test(t)) {
                        info.departmentCol = idx;
                    } else if (/sign/.test(t)) {
                        if (info.signatureCol < 0) info.signatureCol = idx;
                        if (/\bdate\b/.test(t) && info.dateCol < 0) info.dateCol = idx;
                    } else if (/\bdate\b/.test(t) && !/effective/.test(t)) {
                        if (info.dateCol < 0) info.dateCol = idx;
                    } else if (/role|dept/.test(t) && info.roleCol < 0) {
                        info.roleCol = idx;
                    }
                });
                if (info.nameCol > 0 && info.roleCol < 0 && info.departmentCol !== 0) {
                    info.roleCol = 0;
                }
                if (info.signatureCol >= 0 && info.dateCol < 0) {
                    info.dateCol = info.signatureCol;
                }
            });
        });
        return info;
    }

    function syncLetterheadCellsFromSummary() {
        if (!previewArea) return;
        const card = previewArea.querySelector('.docx-summary-header-card');
        if (!card) return;
        card.querySelectorAll('input[data-letterhead-mirror]').forEach(function (inp) {
            const key = inp.getAttribute('data-letterhead-mirror');
            const val = String(inp.value || '').trim();
            if (!key) return;
            previewArea.querySelectorAll('.docx-page-official-header [data-letterhead-field="' + key + '"]').forEach(function (cell) {
                cell.textContent = val;
            });
        });
    }

    function buildNeqSummaryHeaderCard(wrapper, previewFields, savedFields, refDocNo) {
        const coverFields = (previewFields || []).filter(function (f) {
            return f && f.location && String(f.location).indexOf('header_') === 0;
        });
        if (!coverFields.length) {
            return;
        }
        const byType = {};
        coverFields.forEach(function (f) {
            const cf = String(f.cover_field || '').toLowerCase();
            if (cf && !byType[cf]) byType[cf] = f;
        });
        const docField = byType.document_no || byType.reference_protocol_no || coverFields.find(function (f) {
            return /document\s*no|protocol\s*no/i.test(String(f.label || ''));
        });
        const refProtoField = (byType.reference_protocol_no && docField && byType.reference_protocol_no.location !== docField.location)
            ? byType.reference_protocol_no
            : null;
        const eqNameField = byType.equipment_name;
        const eqIdField = byType.equipment_id;
        const facilityField = byType.facility_section;
        const titleField = byType.document_title;
        const effDateField = byType.effective_date;

        const fieldVal = function (f, fallback) {
            if (!f || !f.location) return fallback || '';
            if (savedFields && Object.prototype.hasOwnProperty.call(savedFields, f.location)) {
                return String(savedFields[f.location] || '');
            }
            return String(f.value || fallback || '');
        };

        const docTitle = fieldVal(titleField, currentFileName || 'New Equipment Qualification');
        const usedLocations = new Set();
        const extraCells = [];
        function makeCoverCell(f, title, placeholder, defaultVal) {
            if (!f || !f.location || usedLocations.has(f.location)) return '';
            usedLocations.add(f.location);
            const loc = f.location;
            const val = fieldVal(f, defaultVal || '');
            const cf = f.cover_field || '';
            return '<td style="width:26%;">'
                + '<label class="docx-summary-field-label" for="neq_sum_' + escapeNeqHtml(loc) + '">' + escapeNeqHtml(title) + '</label>'
                + '<input type="text" id="neq_sum_' + escapeNeqHtml(loc) + '" name="fields[' + escapeNeqHtml(loc) + ']" '
                + 'class="docx-input" data-letterhead-mirror="' + escapeNeqHtml(cf) + '" '
                + 'placeholder="' + escapeNeqHtml(placeholder || 'Enter value...') + '" value="' + escapeNeqHtml(val) + '">'
                + '</td>';
        }

        const c1 = makeCoverCell(docField, docField ? (docField.label || 'Document Number') : 'Document Number', 'Enter document number...', refDocNo || '');
        const c2 = makeCoverCell(eqNameField, 'Equipment Name', 'Equipment name...', '');
        const c3 = makeCoverCell(eqIdField, 'Equipment ID', 'Equipment ID...', '');
        [refProtoField, facilityField, effDateField].forEach(function (ef) {
            if (ef && !usedLocations.has(ef.location)) {
                usedLocations.add(ef.location);
                extraCells.push(
                    '<td><label class="docx-summary-field-label">' + escapeNeqHtml(ef.label || ef.cover_field || 'Field') + '</label>'
                    + '<input type="text" name="fields[' + escapeNeqHtml(ef.location) + ']" class="docx-input" '
                    + 'data-letterhead-mirror="' + escapeNeqHtml(ef.cover_field || '') + '" value="' + escapeNeqHtml(fieldVal(ef, '')) + '"></td>'
                );
            }
        });
        if (titleField && !usedLocations.has(titleField.location)) {
            usedLocations.add(titleField.location);
            extraCells.push(
                '<td><label class="docx-summary-field-label">Document Title</label>'
                + '<input type="text" name="fields[' + escapeNeqHtml(titleField.location) + ']" class="docx-input neq-summary-title-input" '
                + 'data-letterhead-mirror="document_title" value="' + escapeNeqHtml(docTitle) + '"></td>'
            );
        }

        const card = document.createElement('div');
        card.className = 'docx-summary-header-card';
        card.innerHTML = ''
            + '<table class="docx-table header-table" style="margin-bottom:0;">'
            + '<tr>'
            + '<td rowspan="' + (extraCells.length ? '3' : '2') + '" style="width:96px;text-align:center;vertical-align:middle !important;background:#ffffff !important;">'
            + '<img src="' + escapeNeqHtml(LUPIN_LOGO_URL) + '" alt="Lupin" style="height:44px;width:auto;" onerror="this.style.display=\'none\'">'
            + '<div style="font-size:11px;font-weight:700;margin-top:4px;color:#047857;">LUPIN</div>'
            + '</td>'
            + '<td colspan="3" style="text-align:center;font-weight:700;font-size:15px;padding:10px !important;background:#f1f5f9 !important;color:#0f172a !important;" id="neqSummaryBannerTitle">'
            + escapeNeqHtml(String(docTitle || 'NEW EQUIPMENT QUALIFICATION').toUpperCase())
            + '</td>'
            + '</tr>'
            + '<tr>' + (c1 || '<td></td>') + (c2 || '<td></td>') + (c3 || '<td></td>') + '</tr>'
            + (extraCells.length ? ('<tr>' + extraCells.slice(0, 3).join('') + (extraCells.length === 1 ? '<td colspan="2"></td>' : (extraCells.length === 2 ? '<td></td>' : '')) + '</tr>') : '')
            + '</table>';
        wrapper.appendChild(card);

        card.addEventListener('input', function (event) {
            syncLetterheadCellsFromSummary();
            if (event.target && event.target.classList && event.target.classList.contains('neq-summary-title-input')) {
                const banner = document.getElementById('neqSummaryBannerTitle');
                if (banner) {
                    banner.textContent = String(event.target.value || currentFileName || 'NEW EQUIPMENT QUALIFICATION').toUpperCase();
                }
            }
        });
    }

    function createNeqClonedRowFromTemplate(templateRow, appTableIndex, valuesArray, opIndex) {
        const clone = templateRow.cloneNode(true);
        clone.removeAttribute('id');
        clone.removeAttribute('data-row-index');
        clone.classList.remove('neq-selected-table-row');
        clone.setAttribute('data-neq-inserted-row', '1');
        clone.setAttribute('data-neq-inserted-op-idx', String(opIndex));
        clone.setAttribute('data-neq-table-idx', String(appTableIndex));
        clone.querySelectorAll('th').forEach(function (th) {
            const td = document.createElement('td');
            Array.from(th.attributes).forEach(function (attr) {
                td.setAttribute(attr.name, attr.value);
            });
            td.className = 'docx-cell editable';
            th.parentNode.replaceChild(td, th);
        });
        const cells = Array.from(clone.children).filter(function (el) {
            return el.tagName === 'TD' || el.tagName === 'TH';
        });
        cells.forEach(function (cell, colIdx) {
            cell.removeAttribute('data-field');
            cell.innerHTML = '';
            const val = Array.isArray(valuesArray) && valuesArray[colIdx] !== undefined ? String(valuesArray[colIdx]) : '';
            cell.className = 'docx-cell editable';
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'docx-input neq-added-row-input';
            input.placeholder = colIdx === 0 ? 'No.' : 'Enter value...';
            input.value = val;
            input.setAttribute('data-neq-added-col', String(colIdx));
            input.setAttribute('data-neq-added-op-idx', String(opIndex));
            input.addEventListener('input', function () {
                const curIdx = Number(clone.getAttribute('data-neq-inserted-op-idx'));
                if (neqTableOpsLog[curIdx] && Array.isArray(neqTableOpsLog[curIdx].values)) {
                    neqTableOpsLog[curIdx].values[colIdx] = input.value;
                }
                scheduleNeqAutosave();
            });
            cell.appendChild(input);
        });
        return clone;
    }

    function findTargetTemplateRowInDoc(appTableIndex, rowIdx) {
        const frags = Array.from(previewArea.querySelectorAll('table[data-table-index="' + String(appTableIndex) + '"]'));
        for (let i = 0; i < frags.length; i++) {
            const found = frags[i].querySelector('tr[data-row-index="' + String(rowIdx) + '"]');
            if (found) return found;
        }
        return null;
    }

    function replayNeqTableOps() {
        if (!previewArea || !Array.isArray(neqTableOpsLog) || !neqTableOpsLog.length) {
            return;
        }
        neqTableOpsLog.forEach(function (op, opIndex) {
            if (!op || !op.op) return;
            const appTable = Number(op.table);
            if (!Number.isInteger(appTable) || appTable < 0) return;
            if (op.op === 'delete') {
                const row = findTargetTemplateRowInDoc(appTable, Number(op.row));
                if (row && row.parentNode) {
                    row.parentNode.removeChild(row);
                }
                return;
            }
            if (op.op === 'insert') {
                const frags = Array.from(previewArea.querySelectorAll('table[data-table-index="' + String(appTable) + '"]'));
                if (!frags.length) return;
                let afterEl = findTargetTemplateRowInDoc(appTable, Number(op.after_row));
                if (!afterEl) {
                    const lastFrag = frags[frags.length - 1];
                    const rows = getDirectTableRows(lastFrag);
                    afterEl = rows.length ? rows[rows.length - 1] : null;
                }
                if (!afterEl || !afterEl.parentNode) return;
                // Advance past previously replayed rows inserted after the same anchor.
                while (afterEl.nextElementSibling && afterEl.nextElementSibling.getAttribute('data-neq-inserted-row') === '1') {
                    afterEl = afterEl.nextElementSibling;
                }
                if (!Array.isArray(op.values)) {
                    op.values = [];
                }
                const clone = createNeqClonedRowFromTemplate(afterEl, appTable, op.values, opIndex);
                afterEl.parentNode.insertBefore(clone, afterEl.nextSibling);
            }
        });
    }

    function hydrateCachedApprovers(previewFields, headerTableCount, paraTargetMap, savedApprovers) {
        if (!previewArea) return;
        const allowSelect = currentUserCanSave();
        const serverApprovals = approvalsForCurrentList();
        const draftApproversList = Array.isArray(savedApprovers) ? savedApprovers : [];
        const slots = new Map();

        function addSlot(appTable, rowIdx, nameCol, approverRole, sigDateLoc) {
            if (!Number.isInteger(appTable) || !Number.isInteger(rowIdx) || !Number.isInteger(nameCol)) return;
            const k = appTable + ':' + rowIdx + ':' + nameCol;
            if (!slots.has(k)) {
                slots.set(k, {
                    appTable: appTable,
                    rowIdx: rowIdx,
                    nameCol: nameCol,
                    approverRole: approverRole || '',
                    sigDateLoc: sigDateLoc || '',
                });
            }
        }

        (previewFields || []).forEach(function (f) {
            if (!f || (f.type !== 'approver' && f.widget !== 'approver')) return;
            const m = String(f.location || '').match(/^table_(\d+)_row_(\d+)_col_(\d+)$/);
            if (m) {
                addSlot(Number(m[1]), Number(m[2]), Number(m[3]), f.approver_role || f.label || '', f.signature_date_location || '');
            }
        });

        previewArea.querySelectorAll('select.approver-field[data-field-location]').forEach(function (sel) {
            const m = String(sel.getAttribute('data-field-location') || '').match(/^table_(\d+)_row_(\d+)_col_(\d+)$/);
            if (m) {
                addSlot(Number(m[1]), Number(m[2]), Number(m[3]), sel.getAttribute('data-approver-role') || '', sel.getAttribute('data-signature-date-location') || '');
            }
        });

        previewArea.querySelectorAll('td.docx-approver-readonly[data-field]').forEach(function (td) {
            const m = String(td.getAttribute('data-field') || '').match(/^table_(\d+)_row_(\d+)_col_(\d+)$/);
            if (m) {
                addSlot(Number(m[1]), Number(m[2]), Number(m[3]), '', '');
            }
        });

        const tableInfoCache = new Map();
        slots.forEach(function (slot) {
            const rowEl = findTargetTemplateRowInDoc(slot.appTable, slot.rowIdx);
            if (!rowEl) return;
            const tableEl = rowEl.closest('table');
            const neqTable = slot.appTable + Number(headerTableCount || 1);
            if (tableEl) {
                tableEl.setAttribute('data-neq-is-signatory', '1');
                tableEl.setAttribute('data-neq-table', String(neqTable));
            }
            rowEl.setAttribute('data-neq-row', String(slot.rowIdx));

            if (!tableInfoCache.has(slot.appTable)) {
                tableInfoCache.set(slot.appTable, neqHeaderTableColumnsInfo(slot.appTable));
            }
            const tInfo = tableInfoCache.get(slot.appTable);
            const cells = Array.from(rowEl.children).filter(function (el) {
                return el.tagName === 'TD' || el.tagName === 'TH';
            });
            const nameCell = cells[slot.nameCol] || null;
            if (!nameCell) return;

            let sigCol = tInfo.signatureCol >= 0 ? tInfo.signatureCol : -1;
            let dateCol = tInfo.dateCol >= 0 ? tInfo.dateCol : -1;
            const sigLocMatch = String(slot.sigDateLoc || '').match(/^table_\d+_row_\d+_col_(\d+)$/);
            if (sigLocMatch) {
                const parsedSigDateCol = Number(sigLocMatch[1]);
                if (sigCol < 0 && dateCol < 0) {
                    sigCol = parsedSigDateCol;
                    dateCol = parsedSigDateCol;
                } else if (dateCol < 0) {
                    dateCol = parsedSigDateCol;
                }
            }
            if (sigCol < 0) {
                sigCol = (slot.nameCol + 1 < cells.length) ? (slot.nameCol + 1) : slot.nameCol;
            }
            if (dateCol < 0) {
                dateCol = (sigCol + 1 < cells.length && tInfo.dateCol !== tInfo.signatureCol) ? (sigCol + 1) : sigCol;
            }
            sigCol = Math.min(sigCol, cells.length - 1);
            dateCol = Math.min(dateCol, cells.length - 1);

            let deptCol = (tInfo.departmentCol >= 0 && tInfo.departmentCol !== slot.nameCol && tInfo.departmentCol < cells.length)
                ? tInfo.departmentCol
                : null;
            if (tInfo.nameCombined) deptCol = null;

            const roleCell = (tInfo.roleCol >= 0 && tInfo.roleCol !== slot.nameCol && cells[tInfo.roleCol]) ? cells[tInfo.roleCol] : null;
            const roleCellText = roleCell ? normalizeEditText(roleCell.innerText || roleCell.textContent || '') : '';
            const roleLabel = neqFriendlyRoleFromKey(slot.approverRole, roleCellText || tInfo.sectionTitle);

            const sigCell = cells[sigCol] && cells[sigCol] !== nameCell ? cells[sigCol] : null;
            const dateCell = cells[dateCol] && cells[dateCol] !== nameCell ? cells[dateCol] : null;
            const deptCell = (deptCol !== null && cells[deptCol] && cells[deptCol] !== nameCell) ? cells[deptCol] : null;

            const savedStep = serverApprovals.find(function (a) {
                return Number(a.table) === neqTable && Number(a.row) === slot.rowIdx;
            }) || findSavedApprovalFlexible(neqTable, slot.rowIdx, nameCell);
            const draftEntry = draftApproversList.find(function (a) {
                return Number(a.table) === neqTable && Number(a.row) === slot.rowIdx;
            });
            const preselectedUserId = (savedStep && savedStep.user_id)
                ? Number(savedStep.user_id)
                : ((draftEntry && draftEntry.user_id) ? Number(draftEntry.user_id) : 0);

            if (savedStep && (savedStep.approved_at || savedStep.rejected_at)) {
                nameCell.innerHTML = '';
                if (sigCell) sigCell.innerHTML = '';
                if (dateCell && dateCell !== sigCell) dateCell.innerHTML = '';
                paintApprovalStampCells(
                    nameCell,
                    sigCell,
                    dateCell,
                    savedStep.name_text || '',
                    savedStep.signature_text || (savedStep.rejected_at ? 'Rejected' : 'Approved'),
                    savedStep.date_text || savedStep.approved_at || savedStep.rejected_at || '',
                    deptCell,
                    savedStep.department_text || ''
                );
                nameCell.classList.add('neq-approver-cell');
                return;
            }

            if (sigCell) {
                sigCell.innerHTML = '<span class="neq-stamp-pending">—</span>';
                sigCell.className = 'docx-cell neq-stamp-cell';
            }
            if (dateCell && dateCell !== sigCell) {
                dateCell.innerHTML = '<span class="neq-stamp-pending">—</span>';
                dateCell.className = 'docx-cell neq-stamp-cell';
            }
            if (deptCell) {
                deptCell.className = 'docx-cell neq-stamp-cell';
            }

            if (!allowSelect) {
                nameCell.className = 'docx-cell locked neq-approver-cell';
                const assignedLabel = (savedStep && savedStep.name_text)
                    || (preselectedUserId > 0 ? ((neqApproverUsers.find(function (u) { return Number(u.id) === preselectedUserId; }) || {}).label || '') : '');
                nameCell.innerHTML = '';
                nameCell.textContent = assignedLabel || 'Pending selection';
                if (deptCell && preselectedUserId > 0) {
                    const u = neqApproverUsers.find(function (item) { return Number(item.id) === preselectedUserId; });
                    if (u && u.designation) deptCell.textContent = u.designation;
                }
                return;
            }

            nameCell.innerHTML = '';
            nameCell.className = 'docx-cell editable neq-approver-cell';
            const sel = buildNeqUserSelectElement('docx-input neq-approver-select', preselectedUserId);
            sel.setAttribute('data-neq-approver-slot', '1');
            sel.dataset.neqTable = String(neqTable);
            sel.dataset.neqRow = String(slot.rowIdx);
            sel.dataset.neqRole = roleLabel;
            sel.dataset.neqNameCol = String(slot.nameCol);
            sel.dataset.neqDepartmentCol = deptCol !== null ? String(deptCol) : '';
            sel.dataset.neqNameCombined = (tInfo.nameCombined || deptCol === null) ? '1' : '0';
            sel.dataset.neqSignatureCol = String(sigCol);
            sel.dataset.neqDateCol = String(dateCol);
            nameCell.appendChild(sel);

            if (deptCell) {
                paintApproverDepartmentFromSelect(deptCell, sel);
            }
            sel.addEventListener('change', function () {
                if (deptCell) {
                    paintApproverDepartmentFromSelect(deptCell, sel);
                }
                updateApprovalActionButtons();
                scheduleNeqAutosave();
            });
        });

        // Handle Attachment 01 "Validation /Quality Assurance:" paragraph selector.
        const qaParaField = (previewFields || []).find(function (f) {
            return f && /^para_\d+$/.test(String(f.location || '')) && /validation\s*\/?\s*quality\s*assurance/i.test(String(f.label || ''));
        });
        if (qaParaField) {
            const pMatch = String(qaParaField.location).match(/^para_(\d+)$/);
            const pIdx = pMatch ? Number(pMatch[1]) : null;
            const targetParaIdx = (pIdx !== null && paraTargetMap && paraTargetMap[String(pIdx)] !== undefined)
                ? Number(paraTargetMap[String(pIdx)])
                : pIdx;
            const qaInput = previewArea.querySelector('input[name="fields[' + qaParaField.location + ']"]');
            const savedQa = serverApprovals.find(function (a) {
                return Number(a.table) >= 900000 || /validation\s*\/?\s*quality\s*assurance/i.test(String(a.role || ''));
            });
            const draftQa = draftApproversList.find(function (a) {
                return !!a.qa_stamp || Number(a.table) >= 900000;
            });
            const qaUid = (savedQa && savedQa.user_id) ? Number(savedQa.user_id) : ((draftQa && draftQa.user_id) ? Number(draftQa.user_id) : 0);
            if (qaInput && qaInput.parentNode) {
                if (savedQa && (savedQa.approved_at || savedQa.rejected_at)) {
                    const span = document.createElement('span');
                    span.className = 'neq-qa-stamp-line font-weight-bold text-success';
                    span.textContent = ' ' + [savedQa.name_text, savedQa.signature_text || 'Approved', savedQa.date_text].filter(Boolean).join(' / ');
                    qaInput.parentNode.replaceChild(span, qaInput);
                } else if (allowSelect) {
                    const qaSel = buildNeqUserSelectElement('docx-input docx-input-inline neq-approver-select neq-qa-approver-select', qaUid);
                    qaSel.dataset.neqParaTargetIndex = targetParaIdx !== null ? String(targetParaIdx) : '';
                    qaInput.parentNode.replaceChild(qaSel, qaInput);
                    qaSel.addEventListener('change', function () {
                        updateApprovalActionButtons();
                        scheduleNeqAutosave();
                    });
                } else {
                    const span = document.createElement('span');
                    span.className = 'text-muted';
                    span.textContent = (savedQa && savedQa.name_text) ? (' ' + savedQa.name_text) : ' —';
                    qaInput.parentNode.replaceChild(span, qaInput);
                }
            }
        }
    }

    function captureInitialOriginalsAndLabels(previewFields, savedEditsPayload) {
        const form = document.getElementById('neqFillForm');
        if (!form) return;
        const fieldMetaMap = {};
        (previewFields || []).forEach(function (f) {
            if (f && f.location) {
                fieldMetaMap[String(f.location)] = f;
            }
        });
        const existingOriginals = (savedEditsPayload && typeof savedEditsPayload.field_originals === 'object' && savedEditsPayload.field_originals)
            ? savedEditsPayload.field_originals
            : {};
        const existingLabels = (savedEditsPayload && typeof savedEditsPayload.field_labels === 'object' && savedEditsPayload.field_labels)
            ? savedEditsPayload.field_labels
            : {};

        neqFieldOriginals = {};
        neqFieldLabels = {};
        form.querySelectorAll('input[name^="fields["], textarea[name^="fields["], select[name^="fields["]').forEach(function (el) {
            const m = String(el.getAttribute('name') || '').match(/^fields\[([^\]]+)\]$/);
            if (!m) return;
            const loc = m[1];
            const meta = fieldMetaMap[loc] || null;
            if (Object.prototype.hasOwnProperty.call(existingOriginals, loc)) {
                neqFieldOriginals[loc] = String(existingOriginals[loc] == null ? '' : existingOriginals[loc]);
            } else if (meta && Object.prototype.hasOwnProperty.call(meta, 'value')) {
                neqFieldOriginals[loc] = String(meta.value == null ? '' : meta.value);
            } else if ((el.type || '').toLowerCase() === 'checkbox') {
                neqFieldOriginals[loc] = el.checked ? '1' : '0';
            } else if ((el.type || '').toLowerCase() === 'radio') {
                if (!Object.prototype.hasOwnProperty.call(neqFieldOriginals, loc)) {
                    neqFieldOriginals[loc] = '';
                }
                if (el.checked) {
                    neqFieldOriginals[loc] = String(el.value || '');
                }
            } else {
                neqFieldOriginals[loc] = String(el.value || '');
            }
            if (existingLabels[loc]) {
                neqFieldLabels[loc] = String(existingLabels[loc]);
            } else if (meta && meta.label) {
                neqFieldLabels[loc] = String(meta.label);
            } else if (el.getAttribute('placeholder')) {
                neqFieldLabels[loc] = String(el.getAttribute('placeholder'));
            }
        });
    }

    function hydrateSavedFillFields(savedEditsPayload) {
        const form = document.getElementById('neqFillForm');
        if (!form || !savedEditsPayload) return;
        const fields = (savedEditsPayload.fields && typeof savedEditsPayload.fields === 'object') ? savedEditsPayload.fields : {};
        Object.keys(fields).forEach(function (loc) {
            const val = fields[loc] == null ? '' : String(fields[loc]);
            const matches = form.querySelectorAll('[name="fields[' + CSS.escape(loc) + ']"]');
            matches.forEach(function (el) {
                const type = (el.type || '').toLowerCase();
                if (type === 'checkbox') {
                    el.checked = (val === '1' || val.toLowerCase() === 'true' || val.toLowerCase() === 'yes' || val.toLowerCase() === 'on');
                } else if (type === 'radio') {
                    el.checked = (String(el.value || '').toLowerCase() === val.toLowerCase());
                } else {
                    el.value = val;
                }
            });
        });

        const addedParas = Array.isArray(savedEditsPayload.added_paragraphs) ? savedEditsPayload.added_paragraphs : [];
        addedParas.forEach(function (item) {
            const txt = typeof item === 'string' ? item : String((item && item.text) || '');
            if (txt.trim() !== '') {
                appendNeqAddedParagraphBlock(txt, false);
            }
        });

        syncLetterheadCellsFromSummary();
        const titleInp = form.querySelector('.neq-summary-title-input');
        const banner = document.getElementById('neqSummaryBannerTitle');
        if (titleInp && banner && titleInp.value.trim() !== '') {
            banner.textContent = titleInp.value.trim().toUpperCase();
        }
    }

    function appendNeqAddedParagraphBlock(initialText, shouldFocus) {
        if (!previewArea) return null;
        const pages = previewArea.querySelectorAll('.docx-a4-page .docx-page-body');
        const targetBody = pages.length ? pages[pages.length - 1] : (previewArea.querySelector('.docx-preview') || previewArea);
        if (!targetBody) return null;

        const block = document.createElement('div');
        block.className = 'neq-added-para-block';
        const label = document.createElement('div');
        label.className = 'small font-weight-bold text-warning mb-1';
        label.innerHTML = '<i class="fas fa-paragraph mr-1"></i> Added Paragraph';
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'btn btn-xs btn-link text-danger float-right p-0';
        removeBtn.innerHTML = '&times; Remove';
        removeBtn.addEventListener('click', function () {
            if (!isEditingEnabled) return;
            block.remove();
            scheduleNeqAutosave();
        });
        label.appendChild(removeBtn);

        const textarea = document.createElement('textarea');
        textarea.className = 'docx-input neq-added-paragraph-input';
        textarea.rows = 2;
        textarea.placeholder = 'Enter additional paragraph / note...';
        textarea.value = String(initialText || '');
        textarea.addEventListener('input', function () {
            scheduleNeqAutosave();
        });
        block.appendChild(label);
        block.appendChild(textarea);
        targetBody.appendChild(block);

        if (shouldFocus) {
            textarea.focus();
            block.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        return block;
    }

    function buildNeqPageNavigator() {
        const nav = document.getElementById('neqPageNavigator');
        const sel = document.getElementById('neqPageJumpSelect');
        if (!nav || !sel || !previewArea) return;
        const pages = Array.from(previewArea.querySelectorAll('.docx-a4-page'));
        sel.innerHTML = '';
        if (!pages.length) {
            nav.classList.add('d-none');
            return;
        }
        pages.forEach(function (pg, idx) {
            pg.setAttribute('data-neq-page-num', String(idx + 1));
            const opt = document.createElement('option');
            opt.value = String(idx + 1);
            opt.textContent = 'Page ' + (idx + 1) + ' of ' + pages.length;
            sel.appendChild(opt);
        });
        nav.classList.remove('d-none');
    }

    function jumpToNeqPage(pageNumber) {
        if (!previewArea) return;
        const pages = Array.from(previewArea.querySelectorAll('.docx-a4-page'));
        if (!pages.length) return;
        const clamped = Math.max(1, Math.min(pages.length, Number(pageNumber) || 1));
        const target = pages[clamped - 1];
        const sel = document.getElementById('neqPageJumpSelect');
        if (sel) sel.value = String(clamped);
        if (target && typeof target.scrollIntoView === 'function') {
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function isNeqDataTableEligible(table) {
        if (!table) return false;
        if (table.classList.contains('header-table')) return false;
        if (table.getAttribute('data-neq-is-signatory') === '1') return false;
        if (table.closest('.docx-page-official-header, .docx-page-official-footer, .docx-summary-header-card')) return false;
        const idx = table.getAttribute('data-table-index');
        return idx !== null && idx !== '' && /^\d+$/.test(idx);
    }

    function selectNeqTableRowForTools(row) {
        if (!previewArea) return;
        previewArea.querySelectorAll('tr.neq-selected-table-row').forEach(function (tr) {
            tr.classList.remove('neq-selected-table-row');
        });
        const badge = document.getElementById('neqRowSelectionBadge');
        if (!row) {
            neqSelectedRowInfo = null;
            if (badge) badge.classList.add('d-none');
            return;
        }
        const table = row.closest('table');
        if (!isNeqDataTableEligible(table)) {
            neqSelectedRowInfo = null;
            if (badge) badge.classList.add('d-none');
            return;
        }
        row.classList.add('neq-selected-table-row');
        const appTable = Number(table.getAttribute('data-table-index'));
        const isInserted = row.getAttribute('data-neq-inserted-row') === '1';
        const opIndex = isInserted ? Number(row.getAttribute('data-neq-inserted-op-idx')) : null;
        const rowIndex = row.hasAttribute('data-row-index') ? Number(row.getAttribute('data-row-index')) : null;
        neqSelectedRowInfo = {
            tableEl: table,
            appTableIndex: appTable,
            rowEl: row,
            rowIndex: rowIndex,
            isInserted: isInserted,
            opIndex: opIndex,
        };
        if (badge) {
            const label = isInserted ? 'Added Row' : ('Row #' + (Number(rowIndex || 0) + 1));
            badge.textContent = label + ' selected';
            badge.classList.remove('d-none');
        }
    }

    function bindNeqTableRowControls() {
        if (!previewArea) return;
        previewArea.querySelectorAll('table.docx-table').forEach(function (table) {
            if (!isNeqDataTableEligible(table)) return;
            table.addEventListener('click', function (event) {
                const tr = event.target && event.target.closest ? event.target.closest('tr') : null;
                if (tr && tr.closest('table') === table) {
                    selectNeqTableRowForTools(tr);
                }
            });
        });
    }

    function addNeqTableRow() {
        if (!hasActivePreview || !isEditingEnabled || !currentUserCanSave()) return;
        let info = neqSelectedRowInfo;
        if (!info || !info.rowEl || !info.rowEl.isConnected) {
            const firstEligible = previewArea.querySelector('table.docx-table[data-table-index]:not(.header-table):not([data-neq-is-signatory="1"])');
            if (!firstEligible) {
                previewError.textContent = 'Click inside a data table row first, then click Add Row.';
                previewError.classList.remove('alert-success', 'd-none');
                previewError.classList.add('alert-warning');
                return;
            }
            const rows = getDirectTableRows(firstEligible);
            const fallbackRow = rows.length ? rows[rows.length - 1] : null;
            if (!fallbackRow) return;
            selectNeqTableRowForTools(fallbackRow);
            info = neqSelectedRowInfo;
        }

        const appTable = info.appTableIndex;
        const rowEl = info.rowEl;
        const cellCount = Array.from(rowEl.children).filter(function (el) {
            return el.tagName === 'TD' || el.tagName === 'TH';
        }).length;

        let afterRow = Number.isInteger(info.rowIndex) ? info.rowIndex : null;
        if (afterRow === null) {
            // If inserting after another inserted row, walk back to its parent original row index.
            let scan = rowEl.previousElementSibling;
            while (scan && !scan.hasAttribute('data-row-index')) {
                scan = scan.previousElementSibling;
            }
            afterRow = scan && scan.hasAttribute('data-row-index') ? Number(scan.getAttribute('data-row-index')) : 0;
        }

        const values = new Array(cellCount).fill('');
        const opIdx = neqTableOpsLog.length;
        neqTableOpsLog.push({
            op: 'insert',
            table: appTable,
            after_row: afterRow,
            values: values,
        });

        const clone = createNeqClonedRowFromTemplate(rowEl, appTable, values, opIdx);
        rowEl.parentNode.insertBefore(clone, rowEl.nextSibling);
        selectNeqTableRowForTools(clone);
        const firstInput = clone.querySelector('input, textarea');
        if (firstInput) firstInput.focus();
        scheduleNeqAutosave();
    }

    function deleteNeqTableRow() {
        if (!hasActivePreview || !isEditingEnabled || !currentUserCanSave()) return;
        const info = neqSelectedRowInfo;
        if (!info || !info.rowEl || !info.rowEl.isConnected) {
            previewError.textContent = 'Click a table row first, then click Delete Row.';
            previewError.classList.remove('alert-success', 'd-none');
            previewError.classList.add('alert-warning');
            return;
        }
        const rowEl = info.rowEl;
        const tableEl = info.tableEl;
        const allRows = getDirectTableRows(tableEl);
        if (allRows.length <= 2 && rowEl === allRows[0]) {
            previewError.textContent = 'The table header row cannot be deleted.';
            previewError.classList.remove('alert-success', 'd-none');
            previewError.classList.add('alert-warning');
            return;
        }
        const hasTh = !!rowEl.querySelector('th.docx-table-header');
        if (hasTh) {
            previewError.textContent = 'The table header row cannot be deleted.';
            previewError.classList.remove('alert-success', 'd-none');
            previewError.classList.add('alert-warning');
            return;
        }

        if (info.isInserted && Number.isInteger(info.opIndex)) {
            neqTableOpsLog.splice(info.opIndex, 1);
            rowEl.remove();
            // Re-index remaining inserted row op indices after splice.
            previewArea.querySelectorAll('tr[data-neq-inserted-row="1"]').forEach(function (tr) {
                const cur = Number(tr.getAttribute('data-neq-inserted-op-idx'));
                if (cur > info.opIndex) {
                    const updated = cur - 1;
                    tr.setAttribute('data-neq-inserted-op-idx', String(updated));
                    tr.querySelectorAll('[data-neq-added-op-idx]').forEach(function (inp) {
                        inp.setAttribute('data-neq-added-op-idx', String(updated));
                    });
                }
            });
        } else if (Number.isInteger(info.rowIndex)) {
            neqTableOpsLog.push({
                op: 'delete',
                table: info.appTableIndex,
                row: info.rowIndex,
            });
            rowEl.remove();
        }
        selectNeqTableRowForTools(null);
        scheduleNeqAutosave();
    }

    function collectFillFormPayload() {
        const form = document.getElementById('neqFillForm');
        const fields = {};
        if (form) {
            const radioNames = new Set();
            form.querySelectorAll('input[name^="fields["], textarea[name^="fields["], select[name^="fields["]').forEach(function (el) {
                const m = String(el.getAttribute('name') || '').match(/^fields\[([^\]]+)\]$/);
                if (!m) return;
                const loc = m[1];
                const type = (el.type || '').toLowerCase();
                if (type === 'checkbox') {
                    fields[loc] = el.checked ? '1' : '0';
                } else if (type === 'radio') {
                    radioNames.add(loc);
                    if (el.checked) {
                        fields[loc] = String(el.value || '');
                    } else if (!Object.prototype.hasOwnProperty.call(fields, loc)) {
                        fields[loc] = '';
                    }
                } else {
                    fields[loc] = String(el.value == null ? '' : el.value);
                }
            });
        }
        const addedParagraphs = [];
        if (form) {
            form.querySelectorAll('.neq-added-paragraph-input').forEach(function (ta) {
                const txt = String(ta.value || '').trim();
                if (txt !== '') {
                    addedParagraphs.push(txt);
                }
            });
        }
        return {
            fields: fields,
            field_originals: neqFieldOriginals,
            field_labels: neqFieldLabels,
            table_ops: neqTableOpsLog,
            added_paragraphs: addedParagraphs,
            approvers: collectApprovers(),
        };
    }

    async function renderPreview(fileUrl, fileName, recordId, saveUrl, previewHtmlUrl, triggerButton, textTargetsUrl, downloadUrl, previewOptions) {
        if (!previewArea) {
            return;
        }

        resetPreviewState();
        enterDocumentFocusMode();
        hidePreviewMessages();
        previewLoading.classList.remove('d-none');
        previewArea.innerHTML = '';
        styleContainer.innerHTML = '';
        previewFileName.textContent = fileName || 'Document preview';

        currentRecordId = recordId;
        currentFileName = fileName;
        currentSaveUrl = saveUrl;
        currentTextTargetsUrl = textTargetsUrl || null;
        if (triggerButton) {
            currentListId = triggerButton.dataset.listId || currentListId;
            currentApproveUrl = triggerButton.dataset.approveUrl || currentApproveUrl;
            currentSendForApprovalUrl = triggerButton.dataset.sendForApprovalUrl || currentSendForApprovalUrl;
            currentSendBackUrl = triggerButton.dataset.sendBackUrl || currentSendBackUrl;
            currentRejectUrl = triggerButton.dataset.rejectUrl || currentRejectUrl;
            currentUploaderId = triggerButton.dataset.uploaderId || currentUploaderId;
        }
        updateDownloadLinks(downloadUrl || null);

        document.querySelectorAll('.js-preview-btn').forEach(function (btn) {
            btn.classList.remove('active');
        });
        if (triggerButton) {
            triggerButton.classList.add('active');
        }

        const opts = previewOptions || {};
        docxTextTargets = null;

        try {
            await renderClientPreview(fileUrl, {
                cacheBust: opts.cacheBust !== false ? (opts.cacheBust || Date.now()) : null,
                skipIdentityRestore: !!opts.skipIdentityRestore,
            });

            if (currentTextTargetsUrl) {
                try {
                    await loadDocxTextTargets();
                    mapDomNodesToDocxTargets(docxTextTargets || []);
                } catch (mapError) {
                    console.warn('[NEQ Edit Map] preload failed:', mapError);
                }
            }

            enterDocumentFocusMode();
            if (hasActivePreview) {
                setFloatToolbarVisible(true);
            }
            try {
                stampNeqTables();
                bindApproverDropdowns();
            } catch (approverBindError) {
                console.warn('[NEQ Approvers] bind failed:', approverBindError);
            }
        } catch (error) {
            previewError.textContent = error.message || 'Failed to render document preview.';
            previewError.classList.remove('alert-success');
            previewError.classList.add('alert-warning');
            previewError.classList.remove('d-none');
            exitDocumentFocusMode();
        } finally {
            if (previewArea) {
                previewArea.style.visibility = '';
            }
            previewLoading.classList.add('d-none');
            updateApprovalActionButtons();
        }
    }

    function collectTextEdits(wrapper) {
        // Build the Save payload. Python writes each {index, text} into that Word slot.
        // Only non-table fill-in nodes are saved here; table values use collectCellEdits.
        const seenIndexes = new Set();
        const edits = [];

        function pushEdit(index, text, original, zone) {
            if (!Number.isInteger(index) || index < 0 || seenIndexes.has(index)) {
                return;
            }
            const saveText = (isLikelyTocEntryText(text, null) || isLikelyTocEntryText(original, null))
                ? String(text)
                : prepareTextForSave(text, original);
            if (normalizeCompareText(saveText) === normalizeCompareText(original)) {
                return;
            }
            seenIndexes.add(index);
            edits.push({
                index: index,
                text: saveText,
                original_text: original,
                zone: zone || 'body',
            });
        }

        getUnlockableNodes().forEach(function (node) {
            if (isInsideNewTableRow(node)) {
                return;
            }
            const stampedZone = node.getAttribute('data-neq-zone') || 'body';
            const liveZone = getNodeZone(node);
            // Middle-of-page text must not be saved into the page heading.
            if (liveZone === 'body' && (stampedZone === 'header' || stampedZone === 'footer')) {
                return;
            }
            if ((liveZone === 'header' || liveZone === 'footer') && stampedZone === 'body') {
                return;
            }
            if (tocProtectedIndexes.has(String(node.getAttribute('data-neq-index'))) && !isInsideTocRegion(node)) {
                return;
            }
            if (node.closest && node.closest('td, th')) {
                return;
            }
            if (node.getAttribute('data-neq-new-para') === '1') {
                return;
            }
            const indexAttr = node.getAttribute('data-neq-index');
            if (indexAttr === null || indexAttr === '') {
                return;
            }
            const index = Number(indexAttr);
            const text = nodeEditText(node);
            const original = node.getAttribute('data-neq-original') || '';
            if (normalizeCompareText(text) === normalizeCompareText(original)) {
                return;
            }
            // A body line is saved only after the user types in that line.
            // A leaked prefix ("test" on every heading) must not be written
            // into the next Word slot when a new table row shifts the indexes.
            if (node.getAttribute('data-neq-user-edited') !== '1') {
                return;
            }
            // Numbered heading preview vs Word text without the number â€” not an edit.
            if (textMatchesWordTarget(text, original)
                && normalizeCompareText(stripAutoListPrefix(text)) === normalizeCompareText(stripAutoListPrefix(original) || original)) {
                return;
            }

            // Checkbox hosts: do NOT send paragraph text rewrites when only the tick changed.
            // Full-line rewrite was destroying trailing Word checkbox drawings + formatting.
            if (node.getAttribute('data-neq-checkbox') === '1'
                || (node.querySelector && node.querySelector('input.neq-checkbox'))) {
                const liveLabel = typeof stripCheckboxGlyphsFromText === 'function'
                    ? stripCheckboxGlyphsFromText(text)
                    : text;
                const origLabel = typeof stripCheckboxGlyphsFromText === 'function'
                    ? stripCheckboxGlyphsFromText(original)
                    : original;
                if (normalizeCompareText(liveLabel) === normalizeCompareText(origLabel)) {
                    return;
                }
            }

            // Persist body/prose edits (incl. lines ending with ":"). Keep TOC / short form labels out.
            if (!shouldPersistEditedContent(node, original, text)) {
                // Short label with typed suffix on a body line: still save the full edited line.
                const extra = extractTypedValueFromLabel(original, text);
                if (!extra) {
                    return;
                }
            }

            pushEdit(index, text, original, node.getAttribute('data-neq-zone') || 'body');
        });

        return edits;
    }

    function collectTocPageEdits(scope) {
        const edits = [];
        if (!scope) {
            return edits;
        }
        stampTocRegion(scope);
        const tocLinePattern = /^(\d+(?:\.\d+)+)\s+(.+?)(?:[.\u2026·\s]+)(\d{1,3})$/;
        function pushTocLine(node) {
            const tag = (node.tagName || '').toLowerCase();
            if (tag === 'table') {
                const lines = node.querySelectorAll('p');
                if (lines.length) {
                    lines.forEach(pushTocLine);
                    return;
                }
                node.querySelectorAll('td, th').forEach(pushTocLine);
                return;
            }
            const text = normalizeEditText(nodeEditText(node) || node.innerText || '');
            const original = normalizeEditText(node.getAttribute('data-neq-original') || '');
            const live = text.match(tocLinePattern);
            if (!live) {
                return;
            }
            const prior = original.match(tocLinePattern);
            if (prior && prior[1] === live[1]
                && normalizeCompareText(prior[2]) === normalizeCompareText(live[2])
                && prior[3] === live[3]) {
                return;
            }
            if (!prior && node.getAttribute('data-neq-user-edited') !== '1') {
                return;
            }
            const indexAttr = node.getAttribute('data-neq-index');
            const indexNum = indexAttr !== null && indexAttr !== '' ? Number(indexAttr) : 0;
            edits.push({
                toc_page: true,
                text: text,
                original_text: original,
                section: live[1],
                title: (live[2] || '').replace(/[.\u2026·]+/g, ' ').replace(/\s+/g, ' ').trim(),
                page: live[3],
                index: Number.isInteger(indexNum) && indexNum >= 0 ? indexNum : 0,
                zone: 'body',
            });
        }
        scope.querySelectorAll('[data-neq-toc="1"]').forEach(function (node) {
            if ((node.tagName || '').toLowerCase() === 'table' && node.querySelector('[data-neq-toc="1"]')) {
                return;
            }
            pushTocLine(node);
        });
        return edits;
    }

    function tocLineParts(text) {
        const clean = normalizeEditText(String(text || '').replace(/[.\u2026·]{2,}/g, ' '));
        const match = clean.match(/^(\d+(?:\.\d+)+)\s+(.+?)(?:\s+(\d{1,3}))?$/);
        if (!match) {
            return null;
        }
        return {
            text: clean,
            section: match[1],
            title: match[2],
            page: match[3] || '',
        };
    }

    function chooseTocTable(scope) {
        // The page wrapper is also a table. Pick the inner list, the one with the section rows.
        let best = null;
        let bestHits = 0;
        scope.querySelectorAll('table').forEach(function (candidate) {
            let hits = 0;
            getDirectTableRows(candidate).forEach(function (row) {
                const rowText = normalizeEditText(row.innerText || row.textContent || '');
                if (/^\d+\.\d+/.test(rowText) || isLikelyTocEntryText(rowText, row)) {
                    hits += 1;
                }
            });
            if (hits > bestHits) {
                best = candidate;
                bestHits = hits;
            }
        });
        return bestHits >= 4 ? best : null;
    }

    function collectTocLines(scope) {
        // The contents list on screen is the list that must be saved, in this order.
        const lines = [];
        if (!scope) {
            return lines;
        }
        stampTocRegion(scope);
        const table = chooseTocTable(scope);
        if (!table) {
            return lines;
        }
        const seen = new Set();
        function pushLine(raw) {
            String(raw || '').split(/\n+/).forEach(function (piece) {
                const parts = tocLineParts(piece);
                if (!parts) {
                    return;
                }
                const key = (parts.section + ' ' + parts.title).toLowerCase();
                if (seen.has(key)) {
                    return;
                }
                seen.add(key);
                lines.push(parts);
            });
        }
        table.querySelectorAll('p, li').forEach(function (node) {
            if (node.closest('table') !== table) {
                return;
            }
            pushLine(nodeEditText(node) || node.innerText || '');
        });
        if (lines.length < 4) {
            getDirectTableRows(table).forEach(function (row) {
                pushLine(row.innerText || row.textContent || '');
            });
        }
        return lines;
    }

    // IOQ SCOPE condition lines â€” keep widgets even after Word drawing/box was stripped.
    const SCOPE_CONDITION_LABELS = [
        'new/ first installation',
        'major modification/ change done after installation',
        'shifted from one location (where installed originally) to another location',
        'in use equipment/system/utility',
    ];

    function isScopeConditionLabel(text) {
        const t = stripCheckboxGlyphsFromText(text).toLowerCase();
        if (!t) {
            return false;
        }
        return SCOPE_CONDITION_LABELS.some(function (label) {
            return t === label || t.indexOf(label) === 0;
        });
    }

    function isChecklistCandidate(node) {
        if (!node || isToolCell(node) || isNeqApprovalLockCell(node) || isInsideTocRegion(node)) {
            return false;
        }
        const hostTable = node.closest ? node.closest('table') : null;
        // Deviation / Comments / MOC Parts grids are plain text fills — never inject checkboxes.
        // 17.0 attachments are text too. The NA column is typed, not a checkbox.
        if (hostTable && (isCommentsOrDeviationsTable(hostTable) || isMocPartsMatrixTable(hostTable) || isAttachmentListTable(hostTable) || isContactSurfaceTable(hostTable))) {
            return false;
        }
        if (node.closest && node.closest('header, footer, .docx_header, .docx_footer')) {
            return false;
        }
        const titleProbe = String(node.innerText || node.textContent || '');
        if (/installation and operational qualification protocol/i.test(titleProbe)) {
            return false;
        }
        if (node.getAttribute('data-neq-checkbox') === '1' || node.getAttribute('data-neq-applicable-col') === '1') {
            return true;
        }
        if (node.querySelector && node.querySelector('table')) {
            return false;
        }
        const tag = (node.tagName || '').toLowerCase();
        const cell = (tag === 'td' || tag === 'th') ? node : getOwningCell(node);
        if (cell && isHeaderLikeRow(cell.parentElement)) {
            return false;
        }
        const cellText = cell ? String(cell.innerText || cell.textContent || '') : '';
        if (/document\s*no|document\s*title|equipment\s*\/?\s*system|facilit(?:y)?\s*\/\s*section|effective\s*date|reference\s*document/i.test(cellText)) {
            return false;
        }
        // Never turn Parts Name / Acceptance Criteria / Actual MOC columns into checklists.
        if (cell) {
            const header = getColumnHeaderTextForCell(cell);
            if (header && /parts?\s*name|acceptance\s*criteria|actual\s*moc|certificate\s*no|sign\s*and\s*date/i.test(header)) {
                return false;
            }
            // Annexure No. and Attachment Type are names, not Yes/NA boxes.
            if (header && (/annex(ure)?\s*no/i.test(header) || /attachment\s*(no\.?|type|number)/i.test(header))) {
                return false;
            }
            // S. No. is a serial, never a checkbox.
            if (isSerialOrAnnexNoColumnCell(cell) && !(header && isApplicableChoiceHeader(header))) {
                return false;
            }
        }
        if (cell && isYesNaApplicableColumnCell(cell)) {
            if (isHeaderLikeRow(cell.parentElement)) {
                return false;
            }
            const rawCell = String(cell.innerText || cell.textContent || '').trim();
            if (!rawCell || rawCell.length <= 24 || /^(yes|n\/?a|â˜|â˜‘|â–¡)/i.test(rawCell)
                || /[â˜‘âœ“â˜â–¡\u2610\u2611\u25a1]/.test(rawCell)) {
                return true;
            }
        }
        const raw = String(node.innerText || node.textContent || '');
        const t = raw.trim();
        if (!t || t.length > 320) {
            return false;
        }
        // Known SCOPE condition labels (box may already be missing in DOCX).
        if (isScopeConditionLabel(t)) {
            return true;
        }
        // Leading or trailing Word checkbox marks (SCOPE list style).
        if (/^[â˜‘âœ“â˜â–¡\u2610\u2611\u25a1]/.test(t) || /[â˜‘âœ“â˜â–¡\u2610\u2611\u25a1]\s*$/.test(t)) {
            return true;
        }
        // Wingdings / Symbol private-use checkbox glyphs Word often emits.
        if (/[\uF0A7\uF0A8\uF0FE\uF0FD\uF070]/.test(t) || /[\uF000-\uF0FF]\s*$/.test(t)) {
            return true;
        }
        return false;
    }

    function stripCheckboxesFromDeviationCommentsTables(scope) {
        if (!scope) {
            return;
        }
        scope.querySelectorAll('table').forEach(function (table) {
            if (!isCommentsOrDeviationsTable(table) && !isMocPartsMatrixTable(table) && !isContactSurfaceTable(table)) {
                return;
            }
            table.querySelectorAll('input.neq-checkbox').forEach(function (cb) {
                const host = cb.parentElement;
                cb.remove();
                if (!host) {
                    return;
                }
                host.removeAttribute('data-neq-checkbox');
                host.removeAttribute('data-neq-checked');
                host.removeAttribute('data-neq-checkbox-dirty');
                host.removeAttribute('data-neq-checked-original');
                host.removeAttribute('data-neq-cb-table');
                host.removeAttribute('data-neq-cb-row');
                host.removeAttribute('data-neq-cb-col');
            });
        });
    }

    function hideWordCheckboxArtifacts(node) {
        if (!node || !node.querySelectorAll) {
            return;
        }
        node.querySelectorAll('img, svg, canvas, object').forEach(function (el) {
            el.setAttribute('data-neq-cb-hidden', '1');
        });
        // docx-preview often emits tiny absolute/relative boxes for Word checkbox drawings.
        Array.from(node.querySelectorAll('*')).forEach(function (el) {
            if (el.classList && el.classList.contains('neq-checkbox')) {
                return;
            }
            if (el.getAttribute('data-neq-cb-hidden') === '1') {
                return;
            }
            let style = null;
            try {
                style = window.getComputedStyle(el);
            } catch (e) {
                return;
            }
            if (!style) {
                return;
            }
            const pos = style.position;
            if (pos !== 'absolute' && pos !== 'relative' && pos !== 'fixed') {
                return;
            }
            const w = el.offsetWidth || parseFloat(style.width) || 0;
            const h = el.offsetHeight || parseFloat(style.height) || 0;
            if (w > 0 && w <= 32 && h > 0 && h <= 32) {
                el.setAttribute('data-neq-cb-hidden', '1');
            }
        });
        // Strip visible checkbox glyphs from text so only the HTML input remains.
        const glyphRe = /[\u2610\u2611â˜‘âœ“â˜â–¡\u25a1\uF0A7\uF0A8\uF0FE\uF0FD\uF070]/g;
        const walker = document.createTreeWalker(node, NodeFilter.SHOW_TEXT, null);
        const textNodes = [];
        while (walker.nextNode()) {
            textNodes.push(walker.currentNode);
        }
        textNodes.forEach(function (tn) {
            if (tn.parentElement && tn.parentElement.classList
                && tn.parentElement.classList.contains('neq-choice-mark')) {
                return;
            }
            if (!tn.nodeValue || !glyphRe.test(tn.nodeValue)) {
                glyphRe.lastIndex = 0;
                return;
            }
            glyphRe.lastIndex = 0;
            tn.nodeValue = tn.nodeValue.replace(glyphRe, '');
        });
    }

    function stripCheckboxGlyphsFromText(value) {
        return String(value || '')
            .replace(/[\u2610\u2611â˜‘âœ“â˜â–¡\u25a1\uF0A7\uF0A8\uF0FE\uF0FD\uF070\uF0FC]/g, '')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function findDocxTargetIndexForCheckbox(node) {
        if (!node || !Array.isArray(docxTextTargets) || !docxTextTargets.length) {
            return null;
        }
        const existing = Number(node.getAttribute('data-neq-index'));
        if (Number.isInteger(existing) && existing >= 0) {
            return existing;
        }
        const original = normalizeCompareText(node.getAttribute('data-neq-original') || '');
        const live = normalizeCompareText(nodeEditText(node));
        const originalStripped = stripCheckboxGlyphsFromText(original);
        const liveStripped = stripCheckboxGlyphsFromText(live);
        const zone = getNodeZone(node);
        const matches = [];
        docxTextTargets.forEach(function (target) {
            if ((target.zone || 'body') !== zone) {
                return;
            }
            const t = normalizeCompareText(target.text || '');
            const ts = stripCheckboxGlyphsFromText(t);
            if (!t && !ts) {
                return;
            }
            // A bare ☐/☑ strips to nothing. Matching that empty string
            // bound every NA box to the first tick in the file, so Attachment 7
            // showed a tick after unlock even though the saved cell is empty.
            const liveKind = checkboxGlyphKind(live || original);
            if (!originalStripped && !liveStripped && liveKind) {
                if (!ts && checkboxGlyphKind(t) === liveKind) {
                    matches.push(Number(target.index));
                }
                return;
            }
            if (t === original || t === live
                || (originalStripped && ts && (ts === originalStripped || ts.indexOf(originalStripped) === 0 || originalStripped.indexOf(ts) === 0))
                || (liveStripped && ts && (ts === liveStripped || ts.indexOf(liveStripped) === 0 || liveStripped.indexOf(ts) === 0))) {
                matches.push(Number(target.index));
            }
        });
        const unique = matches.filter(function (idx, i, arr) {
            return Number.isInteger(idx) && idx >= 0 && arr.indexOf(idx) === i;
        });
        if (unique.length === 1) {
            return unique[0];
        }
        // Prefer unused index when multiple fuzzy matches.
        const unused = unique.filter(function (idx) {
            return !assignedDocxIndexes.has(String(idx));
        });
        if (unused.length === 1) {
            return unused[0];
        }
        return unique.length ? unique[0] : null;
    }

    function ensureCheckboxHostIndex(node) {
        if (!node) {
            return null;
        }
        let index = Number(node.getAttribute('data-neq-index'));
        if (Number.isInteger(index) && index >= 0) {
            return index;
        }
        index = findDocxTargetIndexForCheckbox(node);
        if (index === null) {
            // Last resort: claim an empty slot only for true checklist hosts.
            if (claimUnusedEmptyTarget(node)) {
                index = Number(node.getAttribute('data-neq-index'));
                return Number.isInteger(index) ? index : null;
            }
            return null;
        }
        assignedDocxIndexes.add(String(index));
        node.setAttribute('data-neq-index', String(index));
        if (!node.hasAttribute('data-neq-zone')) {
            node.setAttribute('data-neq-zone', getNodeZone(node));
        }
        if (!node.hasAttribute('data-neq-original')) {
            const target = (docxTextTargets || []).find(function (t) {
                return Number(t.index) === index;
            });
            node.setAttribute(
                'data-neq-original',
                target ? String(target.text || '') : nodeEditText(node)
            );
        }
        return index;
    }

    function isProtectedApprovalTailText(text) {
        const t = normalizeEditText(text || '').toLowerCase();
        if (!t) {
            return false;
        }
        if (/training\s*imparted/.test(t) && /\byes\b/.test(t) && /\bno\b/.test(t)) {
            return true;
        }
        if (/validation\s*\/?\s*quality\s*assurance/.test(t)) {
            return true;
        }
        return /^\(?\s*sign\s*\/?\s*date\s*\)?$/.test(t);
    }

    function isTrainingImpartedChoiceText(text) {
        const t = normalizeEditText(text || '').toLowerCase();
        return /training\s*imparted/.test(t) && /\byes\b/.test(t) && /\bno\b/.test(t);
    }

    function trainingImpartedYesIsTicked(text) {
        return /yes\s*[\u2611\u2612]/i.test(String(text || ''));
    }

    function paintTrainingImpartedChoice(node, yesChecked, touched) {
        if (!node) {
            return;
        }
        const yesMark = !touched ? '\u2610' : (yesChecked ? '\u2611' : '\u2610');
        const noMark = !touched ? '\u2610' : (yesChecked ? '\u2610' : '\u2611');
        let mark = node.querySelector('.neq-choice-mark');
        if (!mark) {
            mark = document.createElement('span');
            mark.className = 'neq-choice-mark';
            node.appendChild(mark);
        }
        mark.textContent = 'Training imparted : Yes ' + yesMark + '   No ' + noMark;
        Array.from(node.childNodes).forEach(function (child) {
            if (child === mark) {
                return;
            }
            if (child.nodeType === 1 && (child.classList.contains('neq-checkbox')
                || (child.querySelector && child.querySelector('input.neq-checkbox')))) {
                return;
            }
            if (child.nodeType === 3 || child.nodeType === 1) {
                if (child.nodeType === 1 && child.classList && child.classList.contains('neq-checkbox')) {
                    return;
                }
                node.removeChild(child);
            }
        });
        const cb = node.querySelector('input.neq-checkbox');
        if (cb && cb.parentNode === node) {
            node.insertBefore(cb, mark);
        }
    }

    function isCheckboxGlyphOnly(text) {
        const raw = String(text || '');
        if (!raw.trim()) {
            return false;
        }
        return stripCheckboxGlyphsFromText(raw) === '';
    }

    function syncTickMark(host, checked) {
        if (!host) {
            return;
        }
        let mark = null;
        Array.from(host.children).forEach(function (child) {
            if (!mark && child.classList && child.classList.contains('neq-tick-mark')) {
                mark = child;
            }
        });
        if (!mark) {
            mark = document.createElement('span');
            mark.className = 'neq-tick-mark';
            mark.setAttribute('contenteditable', 'false');
            mark.setAttribute('aria-hidden', 'true');
            const box = host.querySelector('input.neq-checkbox');
            if (box) {
                host.insertBefore(mark, box);
            } else {
                host.insertBefore(mark, host.firstChild);
            }
        }
        mark.textContent = checked ? '\u2611' : '\u2610';
    }

    function checkboxGlyphKind(text) {
        const raw = String(text || '');
        if (/[\u2611\u2612\u2713\u2714\u2705]/.test(raw)) {
            return 'on';
        }
        if (/[\u2610\u25a1\u25a0]/.test(raw)) {
            return 'off';
        }
        return '';
    }

    function applicableCellIsTicked(cell) {
        if (!cell) {
            return false;
        }
        // The lock-mode mark is the saved glyph. A mapped data-neq-original can
        // carry a neighbor row's tick (Attachment 7 was picking up Attachment 6).
        const mark = cell.querySelector('.neq-tick-mark');
        if (mark && checkboxGlyphKind(mark.textContent || '')) {
            return checkboxGlyphKind(mark.textContent || '') === 'on';
        }
        let word = '';
        if (cell.ownerDocument && typeof document.createTreeWalker === 'function') {
            const walker = document.createTreeWalker(cell, NodeFilter.SHOW_TEXT, null);
            while (walker.nextNode()) {
                const parent = walker.currentNode.parentElement;
                if (parent && parent.classList && parent.classList.contains('neq-tick-mark')) {
                    continue;
                }
                word += walker.currentNode.nodeValue || '';
            }
        } else {
            word = cell.textContent || '';
        }
        const wordKind = checkboxGlyphKind(word);
        if (wordKind) {
            return wordKind === 'on';
        }
        if (cell.getAttribute('data-neq-tick-from-file') === '1') {
            return cell.getAttribute('data-neq-checked') === '1';
        }
        return false;
    }

    function annexAnchorFromRow(row) {
        if (!row) {
            return '';
        }
        const cells = getRowCells(row);
        for (let i = 0; i < cells.length; i++) {
            const text = normalizeEditText(cells[i].innerText || cells[i].textContent || '');
            if (/^annex[_-]/i.test(text)) {
                return text;
            }
        }
        return '';
    }

    function rememberApplicableAddress(cell) {
        const table = cell.closest ? cell.closest('table') : null;
        const row = cell.parentElement;
        if (table && table.hasAttribute('data-neq-table')) {
            cell.setAttribute('data-neq-cb-table', table.getAttribute('data-neq-table'));
        }
        if (row && row.hasAttribute('data-neq-row')) {
            cell.setAttribute('data-neq-cb-row', row.getAttribute('data-neq-row'));
        }
        if (cell.hasAttribute('data-neq-col')) {
            cell.setAttribute('data-neq-cb-col', cell.getAttribute('data-neq-col'));
        }
        const anchor = annexAnchorFromRow(row);
        if (anchor) {
            cell.setAttribute('data-neq-row-anchor', anchor);
        }
        const inner = cell.querySelector('[data-neq-index]');
        if (inner && !cell.hasAttribute('data-neq-index')) {
            const orig = inner.getAttribute('data-neq-original') || '';
            if (!normalizeEditText(stripCheckboxGlyphsFromText(orig))) {
                cell.setAttribute('data-neq-index', inner.getAttribute('data-neq-index'));
                if (!cell.hasAttribute('data-neq-original')) {
                    cell.setAttribute('data-neq-original', orig || '\u2610');
                }
            }
        }
    }

    function clearCheckboxGlyphText(cell) {
        if (!cell) {
            return;
        }
        const glyphRe = /[\u2610\u2611\u2612\u2713\u2714\u2705\u25a1\u25a0]/g;
        const walker = document.createTreeWalker(cell, NodeFilter.SHOW_TEXT, null);
        const nodes = [];
        while (walker.nextNode()) {
            nodes.push(walker.currentNode);
        }
        nodes.forEach(function (tn) {
            if (tn.parentElement && tn.parentElement.classList
                && tn.parentElement.classList.contains('neq-tick-mark')) {
                return;
            }
            if (!tn.nodeValue || !glyphRe.test(tn.nodeValue)) {
                glyphRe.lastIndex = 0;
                return;
            }
            glyphRe.lastIndex = 0;
            tn.nodeValue = tn.nodeValue.replace(glyphRe, '');
        });
    }

    function placeApplicableCheckbox(cell) {
        if (!cell) {
            return;
        }
        const live = cell.querySelector('input.neq-checkbox');
        const dirty = cell.getAttribute('data-neq-checkbox-dirty') === '1';
        const ticked = dirty
            ? (live ? !!live.checked : cell.getAttribute('data-neq-checked') === '1')
            : applicableCellIsTicked(cell);
        if (!dirty) {
            cell.setAttribute('data-neq-tick-from-file', '1');
        }
        cell.setAttribute('data-neq-checked', ticked ? '1' : '0');
        cell.querySelectorAll('input.neq-checkbox, .neq-tick-mark').forEach(function (old) {
            old.remove();
        });
        cell.setAttribute('data-neq-applicable-col', '1');
        cell.setAttribute('data-neq-checkbox', '1');
        rememberApplicableAddress(cell);
        clearCheckboxGlyphText(cell);
        if (typeof isEditingEnabled !== 'undefined' && !isEditingEnabled) {
            cell.setAttribute('data-neq-checked', ticked ? '1' : '0');
            if (!cell.hasAttribute('data-neq-checked-original')) {
                cell.setAttribute('data-neq-checked-original', ticked ? '1' : '0');
            }
            syncTickMark(cell, ticked);
            return;
        }
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.className = 'neq-checkbox';
        cb.title = 'Check / uncheck';
        cb.checked = ticked;
        cell.setAttribute('data-neq-checked', ticked ? '1' : '0');
        if (!cell.hasAttribute('data-neq-checked-original')) {
            cell.setAttribute('data-neq-checked-original', ticked ? '1' : '0');
        }
        cb.addEventListener('click', function (event) {
            event.stopPropagation();
        });
        cb.addEventListener('mousedown', function (event) {
            event.stopPropagation();
        });
        cb.addEventListener('change', function () {
            cell.setAttribute('data-neq-checked', cb.checked ? '1' : '0');
            cell.setAttribute('data-neq-checkbox-dirty', '1');
        });
        cell.insertBefore(cb, cell.firstChild);
    }

    function removeStrayCheckboxes(scope) {
        if (!scope || !scope.querySelectorAll) {
            return;
        }
        scope.querySelectorAll('input.neq-checkbox').forEach(function (cb) {
            const host = cb.parentElement;
            if (!host) {
                cb.remove();
                return;
            }
            if (host.closest && host.closest('header, footer, .docx_header, .docx_footer')) {
                cb.remove();
                host.querySelectorAll('.neq-tick-mark').forEach(function (mark) { mark.remove(); });
                host.removeAttribute('data-neq-checkbox');
                return;
            }
            const cell = (host.tagName && /^(td|th)$/i.test(host.tagName))
                ? host
                : (host.closest ? host.closest('td, th') : null);
            if (cell && cell.getAttribute('data-neq-applicable-col') === '1') {
                return;
            }
            const text = host.innerText || host.textContent || '';
            if (isScopeConditionLabel(text) || isTrainingImpartedChoiceText(text)) {
                return;
            }
            if (/installation and operational qualification protocol/i.test(text)) {
                cb.remove();
                host.querySelectorAll('.neq-tick-mark').forEach(function (mark) { mark.remove(); });
                host.removeAttribute('data-neq-checkbox');
                return;
            }
            if (cell && isSerialOrAnnexNoColumnCell(cell)) {
                cb.remove();
                host.querySelectorAll('.neq-tick-mark').forEach(function (mark) { mark.remove(); });
                host.removeAttribute('data-neq-checkbox');
                cell.removeAttribute('data-neq-checkbox');
                return;
            }
            const words = stripCheckboxGlyphsFromText(text);
            if (!words) {
                cb.remove();
                host.querySelectorAll('.neq-tick-mark').forEach(function (mark) { mark.remove(); });
                host.removeAttribute('data-neq-checkbox');
            }
        });
    }

    function ensureAttachmentNaCheckboxes(scope) {
        // Checkbox widgets on 17.0 attachments are paused. The NA column is text.
        if (!scope || !scope.querySelectorAll) {
            return;
        }
        scope.querySelectorAll('table').forEach(function (table) {
            if (!isAttachmentListTable(table)) {
                return;
            }
            table.querySelectorAll('input.neq-checkbox, .neq-tick-mark').forEach(function (el) {
                el.remove();
            });
            table.querySelectorAll('td, th').forEach(function (cell) {
                if (cell.closest('table') !== table) {
                    return;
                }
                cell.removeAttribute('data-neq-applicable-col');
                if (cell.getAttribute('data-neq-checkbox') === '1' && !normalizeEditText(stripCheckboxGlyphsFromText(cell.innerText || cell.textContent || ''))) {
                    cell.removeAttribute('data-neq-checkbox');
                    cell.removeAttribute('data-neq-checked');
                    cell.removeAttribute('data-neq-checkbox-dirty');
                }
            });
        });
    }

    function enhanceCheckboxWidgets() {
        const scope = getEditScopeRoot();
        if (!scope) {
            return;
        }
        markApplicableColumnHosts(scope);
        let added = 0;
        Array.from(scope.querySelectorAll('p, li, td, th')).forEach(function (node) {
            if (!isChecklistCandidate(node) || isToolCell(node) || isInsideTocRegion(node)) {
                return;
            }
            if (node.querySelector && node.querySelector('input.neq-checkbox')) {
                return;
            }
            // Prefer leaf text nodes; skip outer cells that only wrap paragraphs.
            // The attachments NA column must keep its box on the cell itself.
            // A box inside the nested table docx-preview adds is hidden.
            const tag = (node.tagName || '').toLowerCase();
            if ((tag === 'td' || tag === 'th') && node.getAttribute('data-neq-applicable-col') === '1') {
                // The attachments column gets one box from placeApplicableCheckbox.
                return;
            } else if ((tag === 'p' || tag === 'li') && node.closest && node.closest('td[data-neq-applicable-col="1"], th[data-neq-applicable-col="1"]')) {
                return;
            } else if ((tag === 'td' || tag === 'th') && node.querySelector('p, li')) {
                return;
            }

            const raw = String(node.innerText || '');
            const trainingChoice = isTrainingImpartedChoiceText(raw);
            const checked = trainingChoice
                ? trainingImpartedYesIsTicked(raw)
                : (/[â˜‘âœ“\u2611]/.test(raw) || node.getAttribute('data-neq-checked') === '1');

            // Capture original Word text BEFORE stripping glyphs (needed for index remap).
            if (!node.hasAttribute('data-neq-original')) {
                node.setAttribute('data-neq-original', nodeEditText(node) || raw);
            }

            hideWordCheckboxArtifacts(node);

            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.className = 'neq-checkbox';
            cb.checked = checked;
            cb.title = 'Check / uncheck';
            cb.addEventListener('click', function (e) {
                e.stopPropagation();
            });
            cb.addEventListener('mousedown', function (e) {
                e.stopPropagation();
            });
            cb.addEventListener('change', function () {
                node.setAttribute('data-neq-checked', cb.checked ? '1' : '0');
                node.setAttribute('data-neq-checkbox-dirty', '1');
                if (node.getAttribute('data-neq-training-choice') === '1') {
                    node.setAttribute('data-neq-choice-touched', '1');
                    paintTrainingImpartedChoice(node, cb.checked, true);
                }
                const tickWrapper = previewArea ? previewArea.querySelector('.docx-wrapper') : null;
                if (!tickWrapper) {
                    return;
                }
                keepElementOnThisPage(cb, function () {
                    splitPreviewOntoA4Pages(tickWrapper);
                    stampVisiblePageNumbers(tickWrapper);
                });
            });

            // Always keep the interactive checkbox at the start of the line.
            node.insertBefore(cb, node.firstChild);

            node.setAttribute('data-neq-checkbox', '1');
            node.setAttribute('data-neq-checked', checked ? '1' : '0');
            if (trainingChoice) {
                node.setAttribute('data-neq-training-choice', '1');
                node.setAttribute('data-neq-original', 'Training imparted : Yes No');
                paintTrainingImpartedChoice(node, checked, trainingImpartedYesIsTicked(raw) || /no\s*[\u2611\u2612]/i.test(raw));
            }
            if (!node.hasAttribute('data-neq-checked-original')) {
                node.setAttribute('data-neq-checked-original', checked ? '1' : '0');
            }
            stampNeqTables();
            // Cell address ONLY for real Yes/NA td/th hosts â€” never for <p>/<li> inside
            // layout tables (that wrongly became table:0,row:0,col:0 â†’ Document No. cell).
            node.removeAttribute('data-neq-cb-table');
            node.removeAttribute('data-neq-cb-row');
            node.removeAttribute('data-neq-cb-col');
            const hostCell = (tag === 'td' || tag === 'th') ? node : (node.closest ? node.closest('td, th') : null);
            if (hostCell && isYesNaApplicableColumnCell(hostCell)) {
                const table = hostCell.closest('table');
                if (table && table.hasAttribute('data-neq-table')) {
                    node.setAttribute('data-neq-cb-table', table.getAttribute('data-neq-table'));
                }
                const row = hostCell.parentElement;
                if (row && row.hasAttribute('data-neq-row')) {
                    node.setAttribute('data-neq-cb-row', row.getAttribute('data-neq-row'));
                }
                if (hostCell.hasAttribute('data-neq-col')) {
                    node.setAttribute('data-neq-cb-col', hostCell.getAttribute('data-neq-col'));
                }
            }
            // Keep line text editable too (mapped index comes from text-target map).
            makeNodeEditable(node);
            ensureCheckboxHostIndex(node);
            added += 1;
        });
        scope.querySelectorAll('input.neq-checkbox').forEach(function (cb) {
            const cell = cb.closest ? cb.closest('td, th') : null;
            if (!cell) {
                return;
            }
            const header = getColumnHeaderTextForCell(cell);
            if (cell.getAttribute('data-neq-applicable-col') === '1') {
                return;
            }
            if (!header) {
                return;
            }
            if (/annex(ure)?\s*no/i.test(header) || /attachment\s*(no\.?|type|number)/i.test(header)) {
                const host = cb.parentElement;
                cb.remove();
                if (host) {
                    host.removeAttribute('data-neq-checkbox');
                    host.removeAttribute('data-neq-checked');
                    host.removeAttribute('data-neq-checkbox-dirty');
                    host.removeAttribute('data-neq-checked-original');
                }
            }
        });
        stripMisplacedAnnexCheckboxes(scope);
        ensureAttachmentNaCheckboxes(scope);
        console.info('[NEQ Edit] checkbox widgets added:', added);
    }

    function collectCheckboxOps() {
        const ops = [];
        const seen = new Set();
        const scope = getEditScopeRoot();
        if (!scope) {
            return ops;
        }
        stampNeqTables();
        scope.querySelectorAll('[data-neq-checkbox="1"]').forEach(function (node) {
            const hostTable = node.closest ? node.closest('table') : null;
            if (hostTable && isCommentsOrDeviationsTable(hostTable)) {
                return;
            }
            if (node.closest && node.closest('header, footer, .docx_header, .docx_footer')) {
                return;
            }
            const cb = node.querySelector('input.neq-checkbox');
            const checked = cb ? !!cb.checked : node.getAttribute('data-neq-checked') === '1';
            const original = node.getAttribute('data-neq-checked-original') === '1';
            const tag = (node.tagName || '').toLowerCase();
            const hostCell = (tag === 'td' || tag === 'th') ? node : (node.closest ? node.closest('td, th') : null);
            if (hostCell && hostCell.getAttribute('data-neq-applicable-col') === '1' && hostCell !== node) {
                return;
            }
            if (hostCell && hostCell.getAttribute('data-neq-applicable-col') === '1') {
                if (hostCell !== node) {
                    return;
                }
                // Attachment rows save as text, not as a checkbox op.
                if (rowIsAttachmentListRow(hostCell.parentElement) || isAttachmentListTable(hostCell.closest('table'))) {
                    return;
                }
                const anchor = annexAnchorFromRow(hostCell.parentElement);
                const keyId = (String(anchor).match(/annex(?:[_-][a-z0-9]+)+/i) || [''])[0].toLowerCase();
                if (!keyId) {
                    return;
                }
                const key = 'a:' + keyId;
                if (seen.has(key)) {
                    return;
                }
                seen.add(key);
                ops.push({
                    checked: checked,
                    original_text: checked ? '\u2611' : '\u2610',
                    row_anchor: keyId,
                });
                return;
            }
            if (hostCell && isSerialOrAnnexNoColumnCell(hostCell)) {
                return;
            }
            const isBodyChecklist = !hostCell && ((tag === 'p' || tag === 'li') || isScopeConditionLabel(nodeEditText(node)));
            let table = NaN;
            let row = NaN;
            let col = NaN;
            if (!isBodyChecklist && node.hasAttribute('data-neq-cb-table')
                && node.hasAttribute('data-neq-cb-row') && node.hasAttribute('data-neq-cb-col')) {
                table = Number(node.getAttribute('data-neq-cb-table'));
                row = Number(node.getAttribute('data-neq-cb-row'));
                col = Number(node.getAttribute('data-neq-cb-col'));
            }
            if (!isBodyChecklist && hostCell && isYesNaApplicableColumnCell(hostCell)) {
                const tEl = hostCell.closest('table');
                if (tEl && tEl.hasAttribute('data-neq-table')) {
                    table = Number(tEl.getAttribute('data-neq-table'));
                }
                if (hostCell.parentElement && hostCell.parentElement.hasAttribute('data-neq-row')) {
                    row = Number(hostCell.parentElement.getAttribute('data-neq-row'));
                }
                if (hostCell.hasAttribute('data-neq-col')) {
                    col = Number(hostCell.getAttribute('data-neq-col'));
                }
            }
            if (!isBodyChecklist && checked === original && node.getAttribute('data-neq-checkbox-dirty') !== '1') {
                return;
            }

            const index = ensureCheckboxHostIndex(node);
            const hasIndex = Number.isInteger(index) && index >= 0;
            const hasCell = Number.isInteger(table) && table >= 0
                && Number.isInteger(row) && row >= 0
                && Number.isInteger(col) && col >= 0;
            if (!hasIndex && !hasCell) {
                console.warn('[NEQ Save] checkbox skipped — no index/cell address', nodeEditText(node));
                return;
            }

            const key = hasCell ? ('c:' + table + ':' + row + ':' + col) : ('i:' + index);
            if (seen.has(key)) {
                return;
            }
            seen.add(key);
            const op = {
                checked: checked,
                original_text: node.getAttribute('data-neq-original') || nodeEditText(node) || '',
            };
            if (hasCell) {
                op.table = table;
                op.row = row;
                op.col = col;
                op.abs_row = 1;
            }
            if (hasIndex && !(index === 0 && isCheckboxGlyphOnly(node.getAttribute('data-neq-original') || ''))) {
                op.index = index;
            }
            if (!hasCell && op.index == null) {
                return;
            }
            ops.push(op);
        });
        return ops;
    }

    function ensureFloatToolbarOnBody() {
        if (!neqFloatToolbar) {
            return;
        }
        // Layout parents can break position:fixed; pin toolbar on <body>.
        if (neqFloatToolbar.parentElement !== document.body) {
            document.body.appendChild(neqFloatToolbar);
        }
    }

    function applyFloatToolbarFixedStyles() {
        if (!neqFloatToolbar) {
            return;
        }
        neqFloatToolbar.style.setProperty('position', 'fixed', 'important');
        neqFloatToolbar.style.setProperty('top', '96px', 'important');
        neqFloatToolbar.style.setProperty('right', '20px', 'important');
        neqFloatToolbar.style.setProperty('bottom', 'auto', 'important');
        neqFloatToolbar.style.setProperty('left', 'auto', 'important');
        neqFloatToolbar.style.setProperty('transform', 'none', 'important');
        neqFloatToolbar.style.setProperty('z-index', '2000', 'important');
        neqFloatToolbar.style.setProperty('display', 'flex', 'important');
        neqFloatToolbar.style.setProperty('margin', '0', 'important');
    }

    function setFloatToolbarVisible(visible) {
        if (!neqFloatToolbar) {
            return;
        }
        ensureFloatToolbarOnBody();
        if (visible) {
            neqFloatToolbar.classList.remove('d-none');
            applyFloatToolbarFixedStyles();
        } else {
            neqFloatToolbar.classList.add('d-none');
            neqFloatToolbar.style.setProperty('display', 'none', 'important');
        }
    }

    // Move off page flow immediately so it never sits at document bottom.
    ensureFloatToolbarOnBody();

    function clearInsertTargetHighlight() {
        if (!previewShell) {
            return;
        }
        previewShell.querySelectorAll('.neq-insert-target').forEach(function (el) {
            el.classList.remove('neq-insert-target');
        });
    }

    function updateFloatInsertHint(node) {
        if (!neqFloatInsertHint) {
            return;
        }
        if (!node) {
            neqFloatInsertHint.textContent = 'Click a paragraph or cell to select it';
            return;
        }
        let snippet = normalizeCompareText(node.innerText || node.textContent || '');
        if (snippet.length > 56) {
            snippet = snippet.slice(0, 53) + 'â€¦';
        }
        neqFloatInsertHint.textContent = snippet
            ? ('Insert after: ' + snippet)
            : 'Insert after selected empty cell';
    }

    function findNearestEditable(fromNode) {
        if (!fromNode || !previewShell) {
            return null;
        }
        let node = fromNode.nodeType === Node.TEXT_NODE ? fromNode.parentElement : fromNode;
        if (!node || !node.closest || !previewShell.contains(node)) {
            return null;
        }
        const editable = node.closest('[contenteditable="true"]');
        if (editable && previewShell.contains(editable) && !isToolCell(editable)) {
            return editable;
        }
        return null;
    }

    // Prefer the real contenteditable surface (cell or paragraph), not a non-editable inner <p>.
    function resolveClickBlock(fromNode) {
        if (!fromNode || !previewShell) {
            return null;
        }
        let node = fromNode.nodeType === Node.TEXT_NODE ? fromNode.parentElement : fromNode;
        if (!node || !node.closest || !previewShell.contains(node)) {
            return null;
        }
        if (node.closest('#neqFloatToolbar, .neq-table-toolbar, .neq-row-tool-cell, button, select, input')) {
            return null;
        }

        const editable = findNearestEditable(node);
        if (editable) {
            return editable;
        }

        const block = node.closest('p, li, h1, h2, h3, h4, h5, h6');
        if (block && previewShell.contains(block) && !isToolCell(block)) {
            const parentEditable = block.closest('[contenteditable="true"]');
            if (parentEditable && previewShell.contains(parentEditable)) {
                return parentEditable;
            }
            return block;
        }
        const cell = node.closest('td, th');
        if (cell && previewShell.contains(cell) && !isToolCell(cell)) {
            const innerEditable = cell.querySelector('[contenteditable="true"]');
            return innerEditable || cell;
        }
        return null;
    }

    function focusEditableNode(editable) {
        if (!editable || typeof editable.focus !== 'function') {
            return;
        }
        try {
            editable.focus({ preventScroll: true });
        } catch (e) {
            editable.focus();
        }
    }

    function setInsertTarget(block) {
        if (!block || !previewShell.contains(block)) {
            return;
        }

        let editable = block.getAttribute('contenteditable') === 'true'
            ? block
            : findNearestEditable(block);
        if (!editable && block.querySelector) {
            editable = block.querySelector('[contenteditable="true"]');
        }
        if (!editable && block.parentElement) {
            editable = findNearestEditable(block.parentElement);
        }

        const highlight = editable || block;
        clearInsertTargetHighlight();
        highlight.classList.add('neq-insert-target');
        lastStructureAnchor = highlight;

        if (editable && previewShell.contains(editable)) {
            lastEditableFocus = editable;
            focusEditableNode(editable);
        }
        updateFloatInsertHint(highlight);
    }

    function rememberStructureFocus(fromNode) {
        const editable = findNearestEditable(fromNode);
        if (editable) {
            setInsertTarget(editable);
            return;
        }
        const block = resolveClickBlock(fromNode);
        if (block) {
            setInsertTarget(block);
            return;
        }
        if (!fromNode || !previewShell || !previewShell.contains(fromNode)) {
            return;
        }
        let node = fromNode.nodeType === Node.TEXT_NODE ? fromNode.parentElement : fromNode;
        if (!node || !node.closest) {
            return;
        }
        const fallback = node.closest('[contenteditable="true"]');
        if (fallback && previewShell.contains(fallback)) {
            setInsertTarget(fallback);
        }
    }

    function onPreviewFocusIn(event) {
        if (!isEditingEnabled) {
            return;
        }
        rememberStructureFocus(event.target);
    }

    function onPreviewMouseUp(event) {
        if (!isEditingEnabled || !event.target || !previewShell.contains(event.target)) {
            return;
        }
        rememberEsignCell(event.target);
        rememberStructureFocus(event.target);
    }

    function onPreviewClickSelect(event) {
        if (!isEditingEnabled || !event.target) {
            return;
        }
        if (event.target.closest && event.target.closest('#neqFloatToolbar, .neq-structure-toolbar, .neq-table-toolbar')) {
            return;
        }
        if (!previewShell.contains(event.target)) {
            return;
        }
        rememberStructureFocus(event.target);
    }

    function getFocusedEditableNode() {
        const sel = window.getSelection && window.getSelection();
        let node = sel && sel.anchorNode ? sel.anchorNode : null;
        if (node && node.nodeType === Node.TEXT_NODE) {
            node = node.parentElement;
        }
        if (node && node.closest) {
            const live = node.closest('[contenteditable="true"]');
            if (live && previewShell.contains(live)) {
                lastEditableFocus = live;
                return live;
            }
        }
        if (
            lastEditableFocus
            && previewShell.contains(lastEditableFocus)
            && lastEditableFocus.getAttribute('contenteditable') === 'true'
        ) {
            return lastEditableFocus;
        }
        return null;
    }

    function resolveStructureHost() {
        // Always prefer the explicitly selected insert target (highlighted).
        if (lastStructureAnchor && previewShell.contains(lastStructureAnchor)) {
            return lastStructureAnchor;
        }
        const focus = getFocusedEditableNode();
        if (focus && previewShell.contains(focus)) {
            return focus.closest('p, li, h1, h2, h3, h4, h5, h6, td, th') || focus;
        }
        return null;
    }

    function wordTargetIsInsideTable(idx) {
        const target = (docxTextTargets || []).find(function (item) {
            return String(item.index) === String(idx);
        });
        return !!(target && target.in_table);
    }

    function resolveAfterIndex(host) {
        if (!host) {
            return null;
        }
        const hostInTable = !!(host.closest && host.closest('td, th'));
        function accept(idx) {
            if (idx === null || idx === '') {
                return false;
            }
            // A paragraph added on the page must not anchor inside the check table.
            if (!hostInTable && wordTargetIsInsideTable(idx)) {
                return false;
            }
            return true;
        }
        let current = host;
        while (current && previewShell.contains(current)) {
            const idx = current.getAttribute && current.getAttribute('data-neq-index');
            if (accept(idx)) {
                return String(idx);
            }
            if (current === previewShell) {
                break;
            }
            current = current.parentElement;
        }
        let walk = host;
        while (walk && walk !== previewShell) {
            let prev = walk.previousElementSibling;
            while (prev) {
                const mapped = prev.hasAttribute && prev.hasAttribute('data-neq-index')
                    ? prev
                    : (prev.querySelector ? prev.querySelector('[data-neq-index]') : null);
                if (mapped && accept(mapped.getAttribute('data-neq-index'))) {
                    return mapped.getAttribute('data-neq-index');
                }
                prev = prev.previousElementSibling;
            }
            walk = walk.parentElement;
        }
        return null;
    }

    function insertStructureBlock(asBullet) {
        if (!hasActivePreview || !isEditingEnabled || !currentUserCanSave()) {
            return;
        }
        hidePreviewMessages();
        const host = resolveStructureHost();
        if (!host) {
            previewError.textContent = 'Click the line where the new paragraph should go, then click Add Paragraph.';
            previewError.classList.remove('alert-success');
            previewError.classList.add('alert-warning');
            previewError.classList.remove('d-none');
            return;
        }

        const line = host.closest
            ? (host.closest('p, li, h1, h2, h3, h4, h5, h6') || host)
            : host;
        const para = document.createElement(asBullet ? 'li' : 'p');
        para.setAttribute('contenteditable', 'true');
        para.setAttribute('spellcheck', 'false');
        para.setAttribute('data-neq-new-para', '1');
        para.setAttribute('data-neq-user-typed', '1');
        para.setAttribute('data-neq-user-edited', '1');
        if (asBullet) {
            para.setAttribute('data-neq-list', '1');
        }
        const afterIndex = resolveAfterIndex(line);
        if (afterIndex !== null && afterIndex !== '') {
            para.setAttribute('data-neq-after-index', String(afterIndex));
        }
        const afterText = (line.innerText || line.textContent || '').replace(/\s+/g, ' ').trim();
        if (afterText) {
            para.setAttribute('data-neq-after-text', afterText.slice(0, 180));
        }
        if (asBullet) {
            para.textContent = '\u2022 ';
        }

        const cell = line.closest ? line.closest('td, th') : null;
        const lineIsCell = (line.tagName || '').toLowerCase() === 'td' || (line.tagName || '').toLowerCase() === 'th';
        if (lineIsCell) {
            line.appendChild(para);
        } else if (line.parentNode) {
            line.parentNode.insertBefore(para, line.nextSibling);
        } else if (cell) {
            cell.appendChild(para);
        } else {
            return;
        }

        if (typeof onMappedNodeInput === 'function') {
            para.addEventListener('input', onMappedNodeInput);
        }
        setInsertTarget(para);
        try {
            para.focus();
        } catch (e) {
            // The new line is already in the page.
        }
        scheduleNeqAutosave();
    }

    function collectStructureOps() {
        const ops = [];
        const root = getPreviewContentRoot();
        if (!root) {
            return ops;
        }
        root.querySelectorAll('[data-neq-new-para="1"]').forEach(function (node) {
            let text = nodeEditText(node).replace(/^â€¢\s*/, '').trim();
            if (!text) {
                return;
            }
            const insideCell = !!(node.closest && node.closest('td, th'));
            const inToc = isInsideTocRegion(node);
            const storedAfterText = normalizeEditText(node.getAttribute('data-neq-after-text') || '');
            const anchor = previousBodyAnchor(node);
            let afterIndex = Number(node.getAttribute('data-neq-after-index'));
            if (!storedAfterText && anchor && anchor.index !== null && anchor.index !== '' && !wordTargetIsInsideTable(anchor.index)) {
                afterIndex = Number(anchor.index);
            }
            if (!Number.isInteger(afterIndex) || afterIndex < 0) {
                return;
            }
            const alreadyThere = normalizeEditText(node.getAttribute('data-neq-saved-text') || '') === normalizeEditText(text)
                || normalizeEditText(previousLineText(node)) === normalizeEditText(text);
            if (alreadyThere) {
                return;
            }
            ops.push({
                op: node.getAttribute('data-neq-list') === '1' ? 'insert_bullet' : 'insert_paragraph',
                after_index: afterIndex,
                text: text,
                list: node.getAttribute('data-neq-list') === '1',
                // A paragraph added on the page must not be written into a table cell.
                // A contents line stays under the contents line above it.
                outside_table: (insideCell || inToc) ? 0 : 1,
                toc_insert: inToc ? 1 : 0,
                previous_text: node.getAttribute('data-neq-saved-text') || '',
                after_text: storedAfterText || (anchor && anchor.text ? anchor.text : (inToc ? previousLineText(node) : '')),
            });
        });
        // A line typed on the page with no Word slot of its own. Save it where it
        // sits, not into the next empty cell of the installation table.
        root.querySelectorAll('p[contenteditable="true"], li[contenteditable="true"]').forEach(function (node) {
            if (node.getAttribute('data-neq-new-para') === '1') {
                return;
            }
            if (node.closest && node.closest('td, th, header, footer, .docx_header, .docx_footer')) {
                return;
            }
            // An existing contents line is updated in place. Do not add a second copy.
            if (isInsideTocRegion(node)) {
                return;
            }
            if (node.hasAttribute('data-neq-index')) {
                return;
            }
            let text = nodeEditText(node).trim();
            if (!text) {
                return;
            }
            // Already stored in the file. Inserting it again stacked the same
            // 7.7 sentence on every later save, and a copy in the page header
            // then repeated it at the top of every page.
            if (node.getAttribute('data-neq-user-typed') !== '1') {
                return;
            }
            const anchor = previousBodyAnchor(node);
            let afterIndex = Number(resolveAfterIndex(node));
            if (anchor && anchor.index !== null && anchor.index !== '' && !wordTargetIsInsideTable(anchor.index)) {
                afterIndex = Number(anchor.index);
            }
            if (!Number.isInteger(afterIndex) || afterIndex < 0) {
                return;
            }
            if (normalizeEditText(previousLineText(node)) === normalizeEditText(text)
                || normalizeEditText(node.getAttribute('data-neq-saved-text') || '') === normalizeEditText(text)) {
                return;
            }
            ops.push({
                op: 'insert_paragraph',
                after_index: afterIndex,
                text: text,
                list: false,
                outside_table: 1,
                previous_text: node.getAttribute('data-neq-saved-text') || '',
                after_text: anchor && anchor.text ? anchor.text : '',
            });
        });
        return ops;
    }

    function onEditableSpaceKey(event) {
        if (!isEditingEnabled || event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }
        if (event.key !== ' ' && event.code !== 'Space') {
            return;
        }
        const target = event.target;
        if (!target || !target.closest) {
            return;
        }
        const editable = target.closest('[contenteditable="true"]');
        if (!editable || !previewShell.contains(editable)) {
            return;
        }
        // The preview sheet scrolls. Space is the browser's page-down key, so a
        // space in this paragraph jumped the view onto the next page.
        event.preventDefault();
        event.stopPropagation();
        const inserted = document.queryCommandSupported
            && document.queryCommandSupported('insertText')
            && document.execCommand('insertText', false, ' ');
        if (inserted) {
            return;
        }
        const sel = window.getSelection();
        if (!sel || !sel.rangeCount) {
            return;
        }
        const range = sel.getRangeAt(0);
        if (!editable.contains(range.startContainer)) {
            return;
        }
        range.deleteContents();
        const text = document.createTextNode(' ');
        range.insertNode(text);
        range.setStartAfter(text);
        range.collapse(true);
        sel.removeAllRanges();
        sel.addRange(range);
        editable.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertText', data: ' ' }));
    }

    function onEditableEnterKey(event) {
        if (!isEditingEnabled || event.key !== 'Enter' || event.shiftKey || event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }
        const target = event.target;
        if (!target || !target.closest) {
            return;
        }
        const editable = target.closest('[contenteditable="true"]');
        if (!editable || !previewShell.contains(editable)) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        const sel = window.getSelection();
        if (!sel || !sel.rangeCount) {
            return;
        }
        const range = sel.getRangeAt(0);
        if (!editable.contains(range.startContainer)) {
            return;
        }
        range.deleteContents();
        const br = document.createElement('br');
        range.insertNode(br);
        // A break at the end of a cell is invisible unless another break follows it.
        // Without that, the next letter is typed back on the same line.
        if (!br.nextSibling) {
            br.parentNode.insertBefore(document.createElement('br'), br.nextSibling);
        }
        const after = document.createRange();
        after.setStartAfter(br);
        after.collapse(true);
        sel.removeAllRanges();
        sel.addRange(after);
        editable.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertLineBreak' }));
    }

    function parseJsonResponse(response) {
        return response.text().then(function (raw) {
            if (!raw) {
                return {};
            }

            try {
                return JSON.parse(raw);
            } catch (error) {
                const snippet = raw.slice(0, 120).replace(/\s+/g, ' ');
                throw new Error('Server returned an invalid response: ' + snippet);
            }
        });
    }

    function bindPreviewButton(button) {
        if (!button || button.dataset.neqBound === '1') {
            return;
        }
        button.dataset.neqBound = '1';
        button.addEventListener('click', function () {
            renderPreview(
                button.dataset.fileUrl,
                button.dataset.name,
                button.dataset.id,
                button.dataset.saveUrl,
                button.dataset.previewHtmlUrl,
                button,
                button.dataset.textTargetsUrl,
                button.dataset.downloadUrl,
                { cacheBust: button.dataset.neqVersion || null }
            );
        });
    }

    const NEQ_UPLOADS_PER_PAGE = 10;
    let neqUploadsPage = 1;

    function filterUploadRows(resetPage) {
        if (!uploadsTbody) {
            return;
        }
        if (resetPage !== false) {
            neqUploadsPage = 1;
        }
        const searchInput = document.getElementById('neqUploadSearch');
        const docNoInput = document.getElementById('neqUploadDocNo');
        const fromDateInput = document.getElementById('neqUploadFromDate');
        const toDateInput = document.getElementById('neqUploadToDate');
        const statusInput = document.getElementById('neqUploadStatus');
        const emptyRow = document.getElementById('neqUploadEmptyFilter');
        const query = String(searchInput && searchInput.value ? searchInput.value : '').trim().toLowerCase();
        const docNoQuery = String(docNoInput && docNoInput.value ? docNoInput.value : '').trim().toLowerCase();
        const fromDate = String(fromDateInput && fromDateInput.value ? fromDateInput.value : '').trim();
        const toDate = String(toDateInput && toDateInput.value ? toDateInput.value : '').trim();
        const statusQuery = String(statusInput && statusInput.value ? statusInput.value : '').trim().toLowerCase();
        const matchedRows = [];

        Array.from(uploadsTbody.querySelectorAll('tr[data-list-id]')).forEach(function (row) {
            const id = String(row.getAttribute('data-id') || '').toLowerCase();
            const code = String(row.getAttribute('data-neq-code') || '').toLowerCase();
            const name = String(row.getAttribute('data-name') || '').toLowerCase();
            const refDoc = String(row.getAttribute('data-ref-doc') || '').toLowerCase();
            const status = String(row.getAttribute('data-status') || '').toLowerCase();
            const uploadedDate = String(row.getAttribute('data-uploaded-date') || '');
            const matchesSearch = !query
                || id.indexOf(query) !== -1
                || code.indexOf(query) !== -1
                || name.indexOf(query) !== -1
                || refDoc.indexOf(query) !== -1;
            const matchesDocNo = !docNoQuery || refDoc.indexOf(docNoQuery) !== -1;
            const matchesFromDate = !fromDate || uploadedDate >= fromDate;
            const matchesToDate = !toDate || uploadedDate <= toDate;
            const matchesStatus = !statusQuery || status === statusQuery;
            const match = matchesSearch && matchesDocNo && matchesFromDate && matchesToDate && matchesStatus;
            row.style.display = 'none';
            row.setAttribute('data-neq-match', match ? '1' : '0');
            if (match) {
                matchedRows.push(row);
            }
        });

        const total = matchedRows.length;
        const totalPages = Math.max(1, Math.ceil(total / NEQ_UPLOADS_PER_PAGE));
        if (neqUploadsPage > totalPages) {
            neqUploadsPage = totalPages;
        }
        if (neqUploadsPage < 1) {
            neqUploadsPage = 1;
        }
        const start = total === 0 ? 0 : ((neqUploadsPage - 1) * NEQ_UPLOADS_PER_PAGE);
        const end = total === 0 ? 0 : Math.min(start + NEQ_UPLOADS_PER_PAGE, total);

        matchedRows.forEach(function (row, index) {
            row.style.display = (index >= start && index < end) ? '' : 'none';
        });

        if (emptyRow) {
            emptyRow.classList.toggle('d-none', total > 0);
        }
        renderNeqUploadsPagination(total, start, end, totalPages);
    }

    function renderNeqUploadsPagination(total, start, end, totalPages) {
        const info = document.getElementById('neqUploadsPageInfo');
        const pager = document.getElementById('neqUploadsPager');
        const wrap = document.getElementById('neqUploadsPagination');
        if (info) {
            info.textContent = total === 0
                ? 'Showing 0 to 0 of 0 documents'
                : ('Showing ' + (start + 1) + ' to ' + end + ' of ' + total + ' documents');
        }
        if (!pager) {
            return;
        }
        pager.innerHTML = '';
        if (wrap) {
            wrap.classList.toggle('d-none', total === 0);
        }
        if (totalPages <= 1) {
            return;
        }

        function addPageItem(label, page, disabled, active) {
            const li = document.createElement('li');
            li.className = 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'page-link';
            btn.textContent = label;
            if (!disabled && !active) {
                btn.addEventListener('click', function () {
                    neqUploadsPage = page;
                    filterUploadRows(false);
                });
            }
            li.appendChild(btn);
            pager.appendChild(li);
        }

        addPageItem('Previous', neqUploadsPage - 1, neqUploadsPage <= 1, false);
        for (let page = 1; page <= totalPages; page += 1) {
            addPageItem(String(page), page, false, page === neqUploadsPage);
        }
        addPageItem('Next', neqUploadsPage + 1, neqUploadsPage >= totalPages, false);
    }

    function workflowBadgeMeta(status) {
        const map = {
            pending: { label: 'Pending Approval', cls: 'badge-info' },
            returned: { label: 'Sent Back', cls: 'badge-warning' },
            rejected: { label: 'Rejected', cls: 'badge-danger' },
            approved: { label: 'Approved', cls: 'badge-success' },
            edited: { label: 'Edited', cls: 'badge-warning' },
            original: { label: 'Original', cls: 'badge-secondary' },
        };
        return map[status] || map.original;
    }

    function applyWorkflowPayload(row, payload) {
        const approvalStatus = payload.approval_status || row.getAttribute('data-approval-status') || 'draft';
        const fullyApproved = !!payload.is_fully_approved;
        const listStatus = ['pending', 'returned', 'rejected', 'approved'].indexOf(approvalStatus) !== -1
            ? approvalStatus
            : (payload.has_edited_copy ? 'edited' : 'original');
        row.setAttribute('data-status', listStatus);
        row.setAttribute('data-approval-status', approvalStatus);
        row.setAttribute('data-fully-approved', fullyApproved ? '1' : '0');
        const status = row.querySelector('.js-neq-status');
        if (status) {
            const meta = workflowBadgeMeta(listStatus);
            status.textContent = meta.label;
            status.className = 'badge js-neq-status ' + meta.cls;
        }
        const editedDownload = row.querySelector('.js-neq-edited-download');
        if (editedDownload) {
            const downloadHref = payload.edited_download_url || payload.download_url || null;
            if (fullyApproved && downloadHref) {
                editedDownload.href = downloadHref;
                editedDownload.classList.remove('d-none');
            } else {
                editedDownload.href = '#';
                editedDownload.classList.add('d-none');
            }
        }
    }

    function updateImportedDocumentRow(payload) {
        if (!uploadsTbody || !payload.list_id) {
            return document.querySelector('.js-preview-btn.active');
        }
        const row = uploadsTbody.querySelector('tr[data-list-id="' + payload.list_id + '"]');
        if (!row) {
            return document.querySelector('.js-preview-btn.active');
        }
        const isAutosaveOnly = !!payload.autosave && payload.docx_written === false;
        if (!isAutosaveOnly) {
            applyWorkflowPayload(row, payload);
        }
        const lastSaved = row.querySelector('.js-neq-last-saved');
        if (lastSaved && payload.saved_at) {
            lastSaved.textContent = 'Last saved ' + payload.saved_at;
            lastSaved.classList.remove('d-none');
        }
        if (Object.prototype.hasOwnProperty.call(payload, 'reference_document_no')) {
            const ref = String(payload.reference_document_no || '').trim();
            row.setAttribute('data-ref-doc', ref);
            const refCell = row.querySelector('.js-neq-ref-doc');
            if (refCell) {
                refCell.textContent = ref !== '' ? ref : '—';
            }
        }
        const button = row.querySelector('.js-preview-btn');
        if (button && !isAutosaveOnly) {
            button.dataset.id = String(payload.record_id || button.dataset.id);
            button.dataset.name = payload.display_name || payload.filename || button.dataset.name;
            button.dataset.fileUrl = payload.file_url || button.dataset.fileUrl;
            button.dataset.previewHtmlUrl = payload.preview_html_url || button.dataset.previewHtmlUrl;
            button.dataset.textTargetsUrl = payload.text_targets_url || button.dataset.textTargetsUrl;
            button.dataset.saveUrl = payload.save_url || button.dataset.saveUrl;
            if (payload.file_url || payload.saved_at || payload.approval_status) {
                button.dataset.neqVersion = String(Date.now());
            }
            if (payload.edited_download_url || payload.download_url) {
                button.dataset.downloadUrl = payload.edited_download_url || payload.download_url;
            }
            if (payload.approve_url) {
                button.dataset.approveUrl = payload.approve_url;
            }
            if (payload.send_for_approval_url) {
                button.dataset.sendForApprovalUrl = payload.send_for_approval_url;
            }
            if (payload.send_back_url) {
                button.dataset.sendBackUrl = payload.send_back_url;
            }
            if (payload.reject_url) {
                button.dataset.rejectUrl = payload.reject_url;
            }
        }
        if (payload.list_id && payload.approvals) {
            neqApprovals[String(payload.list_id)] = payload.approvals;
        }
        if (!isAutosaveOnly) {
            updateApprovalActionButtons();
            filterUploadRows(false);
        }
        return button;
    }

    function clearUnsaveableDirtyMarkers(scope) {
        if (!scope) {
            return;
        }
        // Strip accidental "ed" glue from section titles / body chrome (ed Deviations…).
        scope.querySelectorAll('p, li, h1, h2, h3, h4, h5, h6, td, th').forEach(function (node) {
            if (isToolCell(node)) {
                return;
            }
            const live = normalizeEditText(node.innerText || node.textContent || '');
            if (!/^ed(?=[A-Za-z])/i.test(live)) {
                return;
            }
            const stripped = live.replace(/^ed(?=[A-Za-z])/i, '');
            if (!stripped) {
                return;
            }
            // Only auto-fix when the remainder looks like a known section/header phrase.
            if (!(/deviation|corrective|comment|observation|acceptance|power\s*\/?\s*electrical|identification/i.test(stripped)
                || isColumnHeaderText(stripped) || isKnownLabelText(stripped))) {
                return;
            }
            const target = ((node.tagName || '').toLowerCase() === 'td' || (node.tagName || '').toLowerCase() === 'th')
                ? (node.querySelector('p, li, h1, h2, h3, h4, h5, h6') || node)
                : node;
            target.textContent = stripped;
            node.removeAttribute('data-neq-user-edited');
            if (target !== node) {
                target.removeAttribute('data-neq-user-edited');
            }
            if (node.hasAttribute('data-neq-original')) {
                node.setAttribute('data-neq-original', stripAccidentalEdPollution(node.getAttribute('data-neq-original') || '', ''));
            }
        });
        // Header / label dirty markers are not saveable body fills — clear them so Save is not blocked.
        scope.querySelectorAll('[data-neq-user-edited="1"]').forEach(function (node) {
            if (isToolCell(node)) {
                node.removeAttribute('data-neq-user-edited');
                return;
            }
            const cell = getOwningCell(node) || (
                ((node.tagName || '').toLowerCase() === 'td' || (node.tagName || '').toLowerCase() === 'th') ? node : null
            );
            if (!cell) {
                // Non-table chrome that is not a mapped fill — drop false dirty.
                if (!node.hasAttribute('data-neq-index') || shouldKeepReadOnly(node) || !isFillInField(node)) {
                    node.removeAttribute('data-neq-user-edited');
                }
                return;
            }
            if (isHeaderLikeRow(cell.parentElement) || !isFillInField(cell) || shouldKeepReadOnly(cell)) {
                node.removeAttribute('data-neq-user-edited');
                if (cell !== node) {
                    cell.removeAttribute('data-neq-user-edited');
                }
            }
        });
    }

    function countCollectableDirtyCells(scope) {
        if (!scope) {
            return 0;
        }
        let count = 0;
        scope.querySelectorAll('[data-neq-user-edited="1"]').forEach(function (node) {
            if (isToolCell(node) || isInsideTocRegion(node)) {
                return;
            }
            const cell = getOwningCell(node) || (
                ((node.tagName || '').toLowerCase() === 'td' || (node.tagName || '').toLowerCase() === 'th') ? node : null
            );
            if (!cell) {
                if (node.hasAttribute('data-neq-index') && isFillInField(node) && !shouldKeepReadOnly(node)) {
                    count += 1;
                }
                return;
            }
            if (isHeaderLikeRow(cell.parentElement) || !isFillInField(cell) || shouldKeepReadOnly(cell)) {
                return;
            }
            count += 1;
        });
        return count;
    }

    function rememberSavedBodyText(scope) {
        if (!scope || !scope.querySelectorAll) {
            return;
        }
        // The next autosave must treat this sentence as already saved.
        // Otherwise a later word typed into the next dotted blank is rejected,
        // because the file no longer matches the original dotted sentence.
        scope.querySelectorAll('[data-neq-user-edited="1"][data-neq-index]').forEach(function (node) {
            if (node.closest && node.closest('td, th')) {
                return;
            }
            const text = nodeEditText(node);
            if (!text) {
                return;
            }
            node.setAttribute('data-neq-original', text);
        });
    }

    function syncSavedCellOriginals(scope) {
        if (!scope || !scope.querySelectorAll) {
            return;
        }
        scope.querySelectorAll('[data-neq-user-edited="1"]').forEach(function (node) {
            const text = nodeEditText(node);
            if (node.hasAttribute('data-neq-original')) {
                node.setAttribute('data-neq-original', text);
            }
            const cell = (node.closest && node.closest('td, th')) || null;
            if (cell && cell !== node && cell.hasAttribute('data-neq-original')) {
                cell.setAttribute('data-neq-original', nodeEditText(cell));
            }
            node.removeAttribute('data-neq-user-edited');
        });
        scope.querySelectorAll('[data-neq-checkbox-dirty="1"]').forEach(function (node) {
            const checked = node.getAttribute('data-neq-checked') === '1' ? '1' : '0';
            node.setAttribute('data-neq-checked-original', checked);
            node.removeAttribute('data-neq-checkbox-dirty');
        });
    }

    async function saveEditedDocument(esignPassword, options) {
        options = options || {};
        const autosave = !!options.autosave;
        if (neqDraftSaving) {
            neqSaveQueued = (!autosave || neqSaveQueued === 'manual') ? 'manual' : 'auto';
            return false;
        }
        if (!hasActivePreview || !currentSaveUrl || !currentUserCanSave()) {
            return true;
        }

        const fillPayload = collectFillFormPayload();
        neqDraftSaving = true;
        if (autosave) {
            setNeqDraftStatus('Saving…', 'saving');
        } else {
            if (btnSaveAs) {
                btnSaveAs.disabled = true;
            }
            hidePreviewMessages();
        }

        try {
            const response = await fetch(currentSaveUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(Object.assign({}, fillPayload, {
                    autosave: autosave ? 1 : 0,
                    password: autosave ? '' : (esignPassword || ''),
                })),
            });

            const payload = await parseJsonResponse(response);
            if (!response.ok || !payload.success) {
                throw new Error(payload.message || 'Failed to save draft.');
            }

            if (payload.approve_url) {
                currentApproveUrl = payload.approve_url;
            }
            if (payload.send_for_approval_url) {
                currentSendForApprovalUrl = payload.send_for_approval_url;
            }
            if (payload.send_back_url) {
                currentSendBackUrl = payload.send_back_url;
            }
            if (payload.reject_url) {
                currentRejectUrl = payload.reject_url;
            }
            if (payload.save_url) {
                currentSaveUrl = payload.save_url;
            }
            if (payload.record_id) {
                currentRecordId = payload.record_id;
            }
            if (payload.list_id && payload.approvals) {
                neqApprovals[String(payload.list_id)] = payload.approvals;
                currentListId = payload.list_id;
            }

            if (autosave) {
                if (payload.list_id) {
                    updateImportedDocumentRow({
                        list_id: payload.list_id,
                        reference_document_no: Object.prototype.hasOwnProperty.call(payload, 'reference_document_no')
                            ? payload.reference_document_no
                            : undefined,
                        autosave: true,
                        docx_written: false,
                        saved_at: payload.saved_at,
                    });
                }
                setNeqDraftStatus('Draft saved' + (payload.saved_at ? (' ' + payload.saved_at) : ''), 'ok');
                return true;
            }

            updateImportedDocumentRow(payload);
            setNeqDraftStatus('Saved' + (payload.saved_at ? (' ' + payload.saved_at) : ''), 'ok');
            showSaveSuccess(payload.message || 'Draft saved. You can keep editing, or Send for Approval when all approvers are selected.');
            return true;
        } catch (error) {
            if (autosave) {
                setNeqDraftStatus((error && error.message) || 'Draft save failed.', 'error');
            } else {
                previewError.textContent = (error && error.message) || 'Failed to save draft.';
                previewError.classList.remove('alert-success', 'd-none');
                previewError.classList.add('alert-warning');
            }
            return false;
        } finally {
            neqDraftSaving = false;
            if (btnSaveAs) {
                btnSaveAs.disabled = false;
            }
            const nextSave = neqSaveQueued;
            neqSaveQueued = null;
            if (nextSave === 'manual') {
                saveEditedDocument('', { autosave: false });
            } else if (nextSave === 'auto') {
                saveEditedDocument('', { autosave: true });
            }
        }
    }

    async function waitUntilDraftSaveIdle() {
        let spins = 0;
        while (neqDraftSaving && spins < 80) {
            await new Promise(function (resolve) { setTimeout(resolve, 100); });
            spins += 1;
        }
    }

    async function sendDocumentForApproval(esignPassword) {
        if (!hasActivePreview || !currentSendForApprovalUrl || !currentUserCanSendForApproval()) {
            previewError.textContent = 'Select all approvers before sending for approval.';
            previewError.classList.remove('alert-success', 'd-none');
            previewError.classList.add('alert-warning');
            return;
        }

        if (neqDraftTimer) {
            clearTimeout(neqDraftTimer);
            neqDraftTimer = null;
        }
        await waitUntilDraftSaveIdle();

        const fillPayload = collectFillFormPayload();
        if (btnSendForApproval) {
            btnSendForApproval.disabled = true;
        }
        hidePreviewMessages();

        try {
            const response = await fetch(currentSendForApprovalUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(Object.assign({}, fillPayload, {
                    password: esignPassword || '',
                })),
            });

            const payload = await parseJsonResponse(response);
            if (!response.ok || !payload.success) {
                throw new Error(payload.message || 'Failed to send for approval.');
            }

            if (payload.send_for_approval_url) {
                currentSendForApprovalUrl = payload.send_for_approval_url;
            }
            if (payload.approve_url) {
                currentApproveUrl = payload.approve_url;
            }
            if (payload.send_back_url) {
                currentSendBackUrl = payload.send_back_url;
            }
            if (payload.reject_url) {
                currentRejectUrl = payload.reject_url;
            }
            if (payload.list_id && payload.approvals) {
                neqApprovals[String(payload.list_id)] = payload.approvals;
                currentListId = payload.list_id;
            }

            updateImportedDocumentRow(payload);
            disableEditing();
            showProtectedViewBanner();
            updateApprovalActionButtons();
            showSaveSuccess(payload.message || 'Sent for approval. Editing is locked until Send Back.');
        } catch (error) {
            previewError.textContent = (error && error.message) || 'Failed to send for approval.';
            previewError.classList.remove('alert-success', 'd-none');
            previewError.classList.add('alert-warning');
        } finally {
            updateApprovalActionButtons();
        }
    }

    document.querySelectorAll('.js-preview-btn').forEach(bindPreviewButton);

    const btnNeqBackToList = document.getElementById('btnNeqBackToList');
    if (btnNeqBackToList) {
        btnNeqBackToList.addEventListener('click', function () {
            exitDocumentFocusMode();
        });
    }

    const neqUploadSearch = document.getElementById('neqUploadSearch');
    const neqUploadDocNo = document.getElementById('neqUploadDocNo');
    const neqUploadFilterBtn = document.getElementById('neqUploadFilterBtn');
    const neqUploadResetBtn = document.getElementById('neqUploadResetBtn');
    const loggedInEmail = @json(optional(auth()->user())->email);
    function clearSearchAutofill() {
        if (!neqUploadSearch) {
            return;
        }
        const current = String(neqUploadSearch.value || '').trim().toLowerCase();
        const email = String(loggedInEmail || '').trim().toLowerCase();
        if ((email && current === email) || current.indexOf('@') !== -1) {
            neqUploadSearch.value = '';
        }
    }
    if (neqUploadSearch) {
        neqUploadSearch.value = '';
        window.setTimeout(clearSearchAutofill, 50);
        window.setTimeout(clearSearchAutofill, 400);
        neqUploadSearch.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                filterUploadRows(true);
            }
        });
    }
    if (neqUploadDocNo) {
        neqUploadDocNo.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                filterUploadRows(true);
            }
        });
    }
    if (neqUploadFilterBtn) {
        neqUploadFilterBtn.addEventListener('click', function () {
            filterUploadRows(true);
        });
    }
    if (neqUploadResetBtn) {
        neqUploadResetBtn.addEventListener('click', function () {
            const searchInput = document.getElementById('neqUploadSearch');
            const docNoInput = document.getElementById('neqUploadDocNo');
            const statusInput = document.getElementById('neqUploadStatus');
            const fromDateInput = document.getElementById('neqUploadFromDate');
            const toDateInput = document.getElementById('neqUploadToDate');
            if (searchInput) {
                searchInput.value = '';
            }
            if (docNoInput) {
                docNoInput.value = '';
            }
            if (statusInput) {
                statusInput.value = '';
            }
            if (fromDateInput) {
                fromDateInput.value = '';
            }
            if (toDateInput) {
                toDateInput.value = '';
            }
            filterUploadRows(true);
        });
    }

    // Apply 10-per-page pagination on first paint (keeps filters working).
    filterUploadRows(true);

    const btnFloatEditContent = document.getElementById('btnFloatEditContent');
    if (btnFloatEditContent) {
        btnFloatEditContent.addEventListener('mousedown', function (event) {
            event.preventDefault();
        });
        btnFloatEditContent.addEventListener('click', async function () {
            if (!hasActivePreview || !currentUserCanSave()) {
                return;
            }
            // Toggle: unlocked → lock; locked → unlock + edit.
            if (isEditingEnabled) {
                disableEditing();
                showProtectedViewBanner();
                if (previewError) {
                    previewError.textContent = 'Content locked — click Edit Content to unlock again.';
                    previewError.classList.remove('alert-success');
                    previewError.classList.add('alert-warning');
                    previewError.classList.remove('d-none');
                    window.setTimeout(function () {
                        previewError.classList.add('d-none');
                    }, 2500);
                }
                return;
            }
            await enableEditing();
            if (isEditingEnabled && previewError) {
                previewError.textContent = 'Content unlocked — edit Effective Date, blanks, and checkboxes.';
                previewError.classList.remove('alert-warning');
                previewError.classList.add('alert-success');
                previewError.classList.remove('d-none');
                window.setTimeout(function () {
                    previewError.classList.add('d-none');
                }, 2500);
            }
        });
    }

    let lastFocusedFillField = null;
    if (previewShell) {
        previewShell.addEventListener('focusin', function (event) {
            const t = event.target;
            if (t && t.matches && t.matches('input.docx-input, textarea.docx-input, textarea.neq-added-paragraph-input')
                && !t.classList.contains('neq-summary-readonly') && !t.readOnly && !t.disabled) {
                lastFocusedFillField = t;
            }
        });
    }

    function stampEsignOnSelection() {
        if (!isEditingEnabled) {
            previewError.textContent = 'Turn on Edit Content before using E-Sign.';
            previewError.classList.remove('alert-success', 'd-none');
            previewError.classList.add('alert-warning');
            return;
        }
        const form = document.getElementById('neqFillForm');
        const active = document.activeElement;
        let target = null;
        if (form && active && form.contains(active) && active.matches && active.matches('input.docx-input, textarea.docx-input, textarea.neq-added-paragraph-input')
            && !active.classList.contains('neq-summary-readonly') && !active.readOnly && !active.disabled) {
            target = active;
        } else if (lastFocusedFillField && lastFocusedFillField.isConnected && !lastFocusedFillField.readOnly && !lastFocusedFillField.disabled) {
            target = lastFocusedFillField;
        }
        if (!target) {
            previewError.textContent = 'Click inside a fillable text field first, then click E-Sign.';
            previewError.classList.remove('alert-success', 'd-none');
            previewError.classList.add('alert-warning');
            return;
        }
        const now = new Date();
        const pad = function (n) { return String(n).padStart(2, '0'); };
        let hours = now.getHours();
        const ampm = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12 || 12;
        const when = pad(now.getDate()) + '-' + pad(now.getMonth() + 1) + '-' + now.getFullYear()
            + ' ' + pad(hours) + ':' + pad(now.getMinutes()) + ' ' + ampm;
        target.value = neqCurrentUserName + ' ' + when;
        try {
            target.dispatchEvent(new Event('input', { bubbles: true }));
        } catch (e) {
            /* older browsers */
        }
        previewError.textContent = 'Signed as ' + neqCurrentUserName + '.';
        if (neqDraftTimer) {
            clearTimeout(neqDraftTimer);
            neqDraftTimer = null;
        }
        saveEditedDocument('', { autosave: true });
        previewError.classList.remove('alert-warning', 'd-none');
        previewError.classList.add('alert-success');
    }

    const btnEsignStamp = document.getElementById('btnEsignStamp');
    if (btnEsignStamp) {
        btnEsignStamp.addEventListener('mousedown', function (event) {
            event.preventDefault();
        });
        btnEsignStamp.addEventListener('click', function () {
            stampEsignOnSelection();
        });
    }

    if (btnSaveAs) {
        btnSaveAs.addEventListener('mousedown', function (event) {
            event.preventDefault();
        });
        btnSaveAs.addEventListener('click', function () {
            if (!hasActivePreview || !currentSaveUrl || !currentUserCanSave()) {
                return;
            }

            if (neqDraftTimer) {
                clearTimeout(neqDraftTimer);
                neqDraftTimer = null;
            }
            saveEditedDocument('', { autosave: false });
        });
    }

    const btnNeqPrevPage = document.getElementById('btnNeqPrevPage');
    const btnNeqNextPage = document.getElementById('btnNeqNextPage');
    const neqPageJumpSelect = document.getElementById('neqPageJumpSelect');
    if (btnNeqPrevPage) {
        btnNeqPrevPage.addEventListener('click', function () {
            const cur = neqPageJumpSelect ? Number(neqPageJumpSelect.value || 1) : 1;
            jumpToNeqPage(cur - 1);
        });
    }
    if (btnNeqNextPage) {
        btnNeqNextPage.addEventListener('click', function () {
            const cur = neqPageJumpSelect ? Number(neqPageJumpSelect.value || 1) : 1;
            jumpToNeqPage(cur + 1);
        });
    }
    if (neqPageJumpSelect) {
        neqPageJumpSelect.addEventListener('change', function () {
            jumpToNeqPage(this.value);
        });
    }

    if (previewShell) {
        previewShell.addEventListener('input', function (event) {
            const t = event.target;
            if (!t || !isEditingEnabled) {
                return;
            }
            if (t.closest && t.closest('.neq-table-toolbar, #neqFloatToolbar')) {
                return;
            }
            scheduleNeqAutosave();
        }, true);
        previewShell.addEventListener('change', function (event) {
            const t = event.target;
            if (!t || !t.matches) {
                return;
            }
            if (t.matches('select, input[type="checkbox"], input[type="radio"], input[type="datetime-local"], input[type="date"], input[type="time"]')) {
                scheduleNeqAutosave();
            }
        }, true);
    }
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden' && isEditingEnabled && currentSaveUrl) {
            if (neqDraftTimer) {
                clearTimeout(neqDraftTimer);
                neqDraftTimer = null;
            }
            saveEditedDocument('', { autosave: true });
        }
    });

    if (btnSendForApproval) {
        btnSendForApproval.addEventListener('mousedown', function (event) {
            event.preventDefault();
        });
        btnSendForApproval.addEventListener('click', function () {
            if (!hasActivePreview || !currentUserCanSave()) {
                return;
            }
            if (!allApproversSelected()) {
                previewError.textContent = 'Select all approvers before sending for approval.';
                previewError.classList.remove('alert-success');
                previewError.classList.add('alert-warning');
                previewError.classList.remove('d-none');
                return;
            }
            if (typeof window.runWithEsign === 'function') {
                window.runWithEsign(function (password) {
                    sendDocumentForApproval(password);
                });
                return;
            }
            sendDocumentForApproval('');
        });
    }

    const btnAddParagraph = document.getElementById('btnAddParagraph');
    // Keep caret/selection when clicking fixed right toolbar.
    function bindStructureToolbarButton(btn, asBullet) {
        if (!btn) {
            return;
        }
        btn.addEventListener('mousedown', function (event) {
            event.preventDefault();
        });
        btn.addEventListener('click', function () {
            insertStructureBlock(asBullet);
        });
    }
    bindStructureToolbarButton(btnAddParagraph, false);

    async function approveCurrentDocument(esignPassword) {
        if (!hasActivePreview || !currentApproveUrl) {
            return;
        }
        hidePreviewMessages();
        document.querySelectorAll('.js-neq-approve-btn').forEach(function (btn) {
            btn.disabled = true;
        });
        try {
            const response = await fetch(currentApproveUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ password: esignPassword }),
            });
            const payload = await parseJsonResponse(response);
            if (!response.ok || !payload.success) {
                throw new Error(payload.message || 'Failed to approve document.');
            }
            if (payload.list_id && payload.approvals) {
                neqApprovals[String(payload.list_id)] = payload.approvals;
                currentListId = payload.list_id;
            }
            if (payload.approve_url) {
                currentApproveUrl = payload.approve_url;
            }
            if (payload.send_for_approval_url) {
                currentSendForApprovalUrl = payload.send_for_approval_url;
            }
            if (payload.send_back_url) {
                currentSendBackUrl = payload.send_back_url;
            }
            if (payload.reject_url) {
                currentRejectUrl = payload.reject_url;
            }
            hideNeqDecisionBox();
            const savedButton = updateImportedDocumentRow(payload);
            const activeButton = savedButton || document.querySelector('.js-preview-btn.active');
            if (payload.file_url) {
                await renderPreview(
                    payload.file_url,
                    payload.filename || currentFileName,
                    payload.record_id || currentRecordId,
                    payload.save_url || currentSaveUrl,
                    payload.preview_html_url || null,
                    activeButton || null,
                    payload.text_targets_url || null,
                    payload.download_url || null,
                    { cacheBust: Date.now() }
                );
            }
            showSaveSuccess(payload.message || 'Approved. Signature and date were filled.');
        } catch (error) {
            previewError.textContent = error.message || 'Failed to approve document.';
            previewError.classList.remove('alert-success');
            previewError.classList.add('alert-warning');
            previewError.classList.remove('d-none');
        } finally {
            document.querySelectorAll('.js-neq-approve-btn').forEach(function (btn) {
                btn.disabled = false;
            });
        }
    }

    async function submitNeqDecision(type, esignPassword, remark) {
        const url = type === 'reject' ? currentRejectUrl : currentSendBackUrl;
        if (!hasActivePreview || !url) {
            return;
        }
        hidePreviewMessages();
        document.querySelectorAll('.js-neq-send-back-btn, .js-neq-reject-btn, .js-neq-approve-btn').forEach(function (btn) {
            btn.disabled = true;
        });
        const confirmBtn = document.getElementById('neqDecisionConfirm');
        if (confirmBtn) {
            confirmBtn.disabled = true;
        }
        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    password: esignPassword,
                    remark: remark,
                }),
            });
            const payload = await parseJsonResponse(response);
            if (!response.ok || !payload.success) {
                throw new Error(payload.message || 'Failed to submit decision.');
            }
            if (payload.list_id && payload.approvals) {
                neqApprovals[String(payload.list_id)] = payload.approvals;
                currentListId = payload.list_id;
            }
            if (payload.approve_url) {
                currentApproveUrl = payload.approve_url;
            }
            if (payload.send_for_approval_url) {
                currentSendForApprovalUrl = payload.send_for_approval_url;
            }
            if (payload.send_back_url) {
                currentSendBackUrl = payload.send_back_url;
            }
            if (payload.reject_url) {
                currentRejectUrl = payload.reject_url;
            }
            hideNeqDecisionBox();
            const savedButton = updateImportedDocumentRow(payload);
            const activeButton = savedButton || document.querySelector('.js-preview-btn.active');
            if (payload.file_url) {
                await renderPreview(
                    payload.file_url,
                    payload.filename || currentFileName,
                    payload.record_id || currentRecordId,
                    payload.save_url || currentSaveUrl,
                    payload.preview_html_url || null,
                    activeButton || null,
                    payload.text_targets_url || null,
                    payload.download_url || null,
                    { cacheBust: Date.now() }
                );
            }
            showSaveSuccess(payload.message || (type === 'reject' ? 'Rejected.' : 'Sent back for correction.'));
        } catch (error) {
            previewError.textContent = error.message || 'Failed to submit decision.';
            previewError.classList.remove('alert-success');
            previewError.classList.add('alert-warning');
            previewError.classList.remove('d-none');
        } finally {
            document.querySelectorAll('.js-neq-send-back-btn, .js-neq-reject-btn, .js-neq-approve-btn').forEach(function (btn) {
                btn.disabled = false;
            });
            if (confirmBtn) {
                confirmBtn.disabled = false;
            }
        }
    }

    document.querySelectorAll('.js-neq-approve-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!hasActivePreview || !currentApproveUrl) {
                return;
            }
            hideNeqDecisionBox();
            if (typeof window.runWithEsign === 'function') {
                window.runWithEsign(function (password) {
                    approveCurrentDocument(password);
                });
                return;
            }
            approveCurrentDocument();
        });
    });

    document.querySelectorAll('.js-neq-send-back-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!hasActivePreview || !currentSendBackUrl) {
                return;
            }
            showNeqDecisionBox('sendBack');
        });
    });

    document.querySelectorAll('.js-neq-reject-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!hasActivePreview || !currentRejectUrl) {
                return;
            }
            showNeqDecisionBox('reject');
        });
    });

    const neqDecisionConfirm = document.getElementById('neqDecisionConfirm');
    if (neqDecisionConfirm) {
        neqDecisionConfirm.addEventListener('click', function () {
            const type = currentDecisionType;
            const remarkEl = document.getElementById('neqDecisionRemark');
            const hint = document.getElementById('neqDecisionHint');
            const remark = remarkEl ? String(remarkEl.value || '').trim() : '';
            if (!type || !remark) {
                if (remarkEl) {
                    remarkEl.classList.add('is-invalid');
                    remarkEl.focus();
                }
                if (hint) {
                    hint.classList.remove('d-none');
                }
                return;
            }
            if (remarkEl) {
                remarkEl.classList.remove('is-invalid');
            }
            if (hint) {
                hint.classList.add('d-none');
            }
            if (typeof window.runWithEsign === 'function') {
                window.runWithEsign(function (password) {
                    submitNeqDecision(type, password, remark);
                });
                return;
            }
            submitNeqDecision(type, '', remark);
        });
    }
});
</script>
@endsection
