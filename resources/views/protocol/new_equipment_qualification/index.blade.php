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
        font-size: 11pt;
        line-height: 1.5;
        color: #000;
        max-width: 850px;
        margin: 0 auto;
        padding: 40px;
        background: #fff;
        box-shadow: 0 0 8px rgba(0, 0, 0, 0.1);
        min-height: 280px;
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

    .docx-preview-container table {
        border-collapse: collapse;
        margin: 12px 0;
    }

    .docx-preview-container table td,
    .docx-preview-container table th {
        border: 1px solid #000;
        padding: 6px 10px;
        vertical-align: top;
        font-size: 11pt;
        word-wrap: break-word;
    }

    .docx-preview-container table th {
        background: #f0f0f0;
        font-weight: bold;
    }

    .docx-preview-container p {
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

    .neq-edit-mode .docx-preview-container [contenteditable="true"] {
        outline: 1px dashed #4e73df;
        outline-offset: 1px;
        min-height: 1em;
        cursor: text;
    }

    .neq-edit-mode .docx-preview-container [contenteditable="true"]:focus {
        background: rgba(78, 115, 223, 0.06);
    }

    .js-preview-btn.active {
        box-shadow: inset 0 0 0 2px rgba(255, 255, 255, 0.35);
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
                <button type="button" class="btn btn-primary btn-compact" id="btnImportDocx">
                    <i class="fas fa-file-upload mr-1"></i>
                    Import New Equipment Qualification
                </button>
            </div>
        </div>
    </section>

    @include('common.alert')

    <form id="uploadForm"
          action="{{ route('protocol.new-equipment-qualification.upload') }}"
          method="POST"
          enctype="multipart/form-data"
          class="d-none esign-required">
        @csrf
        <input type="hidden" name="password">
        <input type="file" name="docx_file" id="docxFileInput" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
    </form>

    <div class="card mb-4">
        <div class="card-header">
            <div class="section-title">Imported Documents</div>
        </div>
        <div class="card-body p-0">
            @if($uploads->isEmpty())
                <div class="p-4">
                    <div class="alert alert-info mb-0">No documents imported yet.</div>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-striped mb-0">
                        <thead>
                            <tr>
                                <th>File Name</th>
                                <th>Uploaded On</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($uploads as $upload)
                                <tr>
                                    <td>{{ $upload->original_filename }}</td>
                                    <td>{{ optional($upload->created_at)->format('d-m-Y h:i A') }}</td>
                                    <td class="text-end">
                                        <button type="button"
                                                class="btn btn-sm btn-primary js-preview-btn"
                                                data-id="{{ $upload->id }}"
                                                data-name="{{ $upload->original_filename }}"
                                                data-file-url="{{ route('protocol.new-equipment-qualification.file', $upload) }}"
                                                data-preview-html-url="{{ route('protocol.new-equipment-qualification.preview-html', $upload) }}"
                                                data-save-url="{{ route('protocol.new-equipment-qualification.save-as', $upload) }}">
                                            View
                                        </button>
                                        <a class="btn btn-sm btn-light"
                                           href="{{ route('protocol.new-equipment-qualification.download', $upload) }}">
                                            Save
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <div class="section-title mb-0">Document Preview</div>
            <span class="small text-muted" id="previewFileName">Select a file from the list and click View to preview</span>
        </div>
        <div class="card-body">
            <div id="protectedViewBanner" class="neq-protected-banner d-none">
                <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between">
                    <div class="mb-2 mb-md-0">
                        <strong>PROTECTED VIEW</strong> — This document is in read-only mode.
                    </div>
                    <button type="button" class="btn btn-sm btn-dark" id="btnEnableEditing">
                        Enable Editing
                    </button>
                </div>
            </div>

            <div id="editingModeBanner" class="neq-editing-banner d-none">
                <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between">
                    <div class="mb-2 mb-md-0">
                        <strong>Editing enabled</strong> — Click any paragraph or table cell to modify content.
                    </div>
                    <button type="button" class="btn btn-sm btn-primary" id="btnSaveAs">
                        <i class="fas fa-save mr-1"></i>
                        Save As
                    </button>
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
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script src="https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/docx-preview@0.3.7/dist/docx-preview.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const uploadForm = document.getElementById('uploadForm');
    const fileInput = document.getElementById('docxFileInput');
    const importBtn = document.getElementById('btnImportDocx');
    const previewArea = document.getElementById('docxPreviewArea');
    const styleContainer = document.getElementById('docxStyleContainer');
    const previewShell = document.getElementById('previewShell');
    const previewLoading = document.getElementById('previewLoading');
    const previewError = document.getElementById('previewError');
    const previewFileName = document.getElementById('previewFileName');
    const protectedViewBanner = document.getElementById('protectedViewBanner');
    const editingModeBanner = document.getElementById('editingModeBanner');
    const btnEnableEditing = document.getElementById('btnEnableEditing');
    const btnSaveAs = document.getElementById('btnSaveAs');
    const csrfToken = document.querySelector('#uploadForm input[name="_token"]')?.value || '';
    const previewPlaceholder = 'Select a file from the list above and click <strong>View</strong> to preview.';

    let currentDocxBlob = null;
    let currentRecordId = null;
    let currentFileName = null;
    let currentSaveUrl = null;
    let isEditingEnabled = false;
    let hasActivePreview = false;

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
        previewShell.classList.remove('neq-edit-mode');
        isEditingEnabled = false;
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

    function findHeaderLogoCell(previewRoot) {
        const docRoot = getPreviewDocumentRoot(previewRoot);
        const firstTable = docRoot.querySelector('table');
        if (!firstTable) {
            return null;
        }

        const firstRow = firstTable.querySelector('tr');
        if (!firstRow) {
            return null;
        }

        return firstRow.querySelector('td, th');
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

        function addCell(cell) {
            if (!cell || seen.has(cell) || cells.length >= limit) {
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

    async function fixPreviewImages(previewRoot, docxBlob) {
        if (!window.JSZip || !docxBlob || !previewRoot) {
            console.warn('[NEQ Preview] fixPreviewImages skipped — missing JSZip, blob, or preview root.');
            return;
        }

        try {
            const zip = await JSZip.loadAsync(docxBlob);
            const imageIndex = await buildDocxImageIndex(zip);
            const placements = imageIndex.placements.slice();
            const usedPlacements = new Set();

            await new Promise(function (resolve) {
                setTimeout(resolve, 250);
            });

            const images = Array.from(previewRoot.querySelectorAll('img'));
            console.info('[NEQ Preview] <img> tags in rendered HTML:', images.length);

            images.forEach(function (img) {
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
                console.info('[NEQ Preview] All embedded images accounted for.');
                return;
            }

            const targetCells = findEmptyImageCells(previewRoot, remainingPlacements.length);
            console.info('[NEQ Preview] Empty image candidate cells found:', targetCells.length);

            remainingPlacements.forEach(function (placement, index) {
                const dataUrl = imageIndex.mediaDataUrls[placement.mediaPath]
                    || imageIndex.mediaDataUrls[placement.mediaFileName];

                if (!dataUrl) {
                    console.warn('[NEQ Preview] Missing binary for placement:', placement.mediaFileName);
                    return;
                }

                let cell = null;
                if (placement.part.indexOf('header') === 0) {
                    cell = findHeaderLogoCell(previewRoot);
                }

                if (!cell) {
                    cell = targetCells[index];
                }

                if (!cell) {
                    console.warn('[NEQ Preview] No empty cell available for placement:', placement.mediaFileName, 'in', placement.part);
                    return;
                }

                injectImageIntoCell(cell, dataUrl, placement);
                usedPlacements.add(placement.mediaFileName);
                console.info('[NEQ Preview] Injected', placement.mediaFileName, 'into table cell from', placement.part);
            });

            if (!usedPlacements.size && Object.keys(imageIndex.mediaDataUrls).length) {
                const fallbackCell = findHeaderLogoCell(previewRoot);
                const fallbackPath = Object.keys(imageIndex.mediaDataUrls).find(function (key) {
                    return key.indexOf('word/media/') === 0;
                });

                if (fallbackCell && fallbackPath) {
                    injectImageIntoCell(
                        fallbackCell,
                        imageIndex.mediaDataUrls[fallbackPath],
                        { mediaFileName: fallbackPath.split('/').pop(), part: 'fallback' }
                    );
                    console.info('[NEQ Preview] Fallback injected', fallbackPath, 'into header logo cell.');
                }
            }
        } catch (error) {
            console.warn('[NEQ Preview] fixPreviewImages failed:', error);
        }
    }

    function getPreviewContentRoot() {
        return previewArea.querySelector('.docx-wrapper')
            || previewArea.querySelector('.neq-server-preview')
            || null;
    }

    function getEditableTargets() {
        const wrapper = getPreviewContentRoot();
        if (!wrapper) {
            return [];
        }

        return Array.from(wrapper.querySelectorAll('td, th, p, h1, h2, h3, h4, h5, h6, li'));
    }

    function disableEditing() {
        getEditableTargets().forEach(function (node) {
            node.removeAttribute('contenteditable');
        });
        previewShell.classList.remove('neq-edit-mode');
        isEditingEnabled = false;
    }

    function enableEditing() {
        getEditableTargets().forEach(function (node) {
            node.setAttribute('contenteditable', 'true');
            node.setAttribute('spellcheck', 'false');
        });
        previewShell.classList.add('neq-edit-mode');
        isEditingEnabled = true;
        protectedViewBanner.classList.add('d-none');
        editingModeBanner.classList.remove('d-none');
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

        const headerPlacement = placements.find(function (placement) {
            return placement.target === 'header_logo_cell';
        });

        if (!headerPlacement || !headerPlacement.data_url) {
            console.warn('[NEQ Preview] No header logo placement returned from Python.');
            return;
        }

        await new Promise(function (resolve) {
            setTimeout(resolve, 500);
        });

        const logoCell = await waitForHeaderLogoCell(previewArea, 3000);
        if (!logoCell) {
            console.warn('[NEQ Preview] Header logo cell not found in docx-preview output.');
            return;
        }

        const host = findLogoImageHost(logoCell);
        console.info('[NEQ Preview] Logo injection target:', {
            cellTag: logoCell.tagName,
            hostTag: host === logoCell ? 'td-direct' : host.tagName,
            hasImgBefore: !!logoCell.querySelector('img'),
            hostEmpty: host !== logoCell && host.childElementCount === 0,
        });

        injectImageIntoCell(logoCell, headerPlacement.data_url, {
            mediaFileName: headerPlacement.media_filename,
            part: headerPlacement.part,
        });

        console.info(
            '[NEQ Preview] Applied cropped logo from Python:',
            headerPlacement.part,
            headerPlacement.embed_id,
            headerPlacement.data_url.substring(0, 40) + '...'
        );
    }

    async function renderClientPreview(fileUrl) {
        if (!window.docx) {
            throw new Error('DOCX preview library is unavailable.');
        }

        const response = await fetch(fileUrl, { credentials: 'same-origin' });
        if (!response.ok) {
            throw new Error('Unable to load the document file.');
        }

        const arrayBuffer = await response.arrayBuffer();
        currentDocxBlob = new Blob([arrayBuffer], {
            type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        });

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
                ignoreLastRenderedPageBreak: true,
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

        await new Promise(function (resolve) {
            setTimeout(resolve, 500);
        });

        await fixPreviewImages(previewArea, currentDocxBlob);

        hasActivePreview = !!previewArea.querySelector('.docx-wrapper');
        if (hasActivePreview) {
            showProtectedViewBanner();
        }

        previewArea.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    async function renderPreview(fileUrl, fileName, recordId, saveUrl, previewHtmlUrl, triggerButton) {
        if (!previewArea) {
            return;
        }

        resetPreviewState();
        previewError.classList.add('d-none');
        previewLoading.classList.remove('d-none');
        previewArea.innerHTML = '';
        styleContainer.innerHTML = '';
        previewFileName.textContent = fileName || 'Document preview';

        currentRecordId = recordId;
        currentFileName = fileName;
        currentSaveUrl = saveUrl;

        document.querySelectorAll('.js-preview-btn').forEach(function (btn) {
            btn.classList.remove('active');
        });
        if (triggerButton) {
            triggerButton.classList.add('active');
        }

        try {
            await renderClientPreview(fileUrl);

            if (previewHtmlUrl) {
                try {
                    await applyPythonImagePlacements(previewHtmlUrl);
                } catch (pythonError) {
                    console.warn('[NEQ Preview] Python image resolution failed:', pythonError);
                }
            }
        } catch (error) {
            previewError.textContent = error.message || 'Failed to render document preview.';
            previewError.classList.remove('d-none');
            showPlaceholder();
        } finally {
            previewLoading.classList.add('d-none');
        }
    }

    async function saveEditedDocument(esignPassword) {
        if (!hasActivePreview || !isEditingEnabled || !currentSaveUrl) {
            return;
        }

        const wrapper = getPreviewContentRoot();
        if (!wrapper) {
            previewError.textContent = 'No editable preview content found.';
            previewError.classList.remove('d-none');
            return;
        }

        disableEditing();
        btnSaveAs.disabled = true;
        previewError.classList.add('d-none');

        const editedHtml = wrapper.innerHTML;
        const editedFilename = currentFileName
            ? currentFileName.replace(/\.docx$/i, '') + '_edited.docx'
            : 'edited_document.docx';

        try {
            const response = await fetch(currentSaveUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    html: editedHtml,
                    filename: editedFilename,
                    password: esignPassword,
                }),
            });

            const payload = await response.json();
            if (!response.ok || !payload.success) {
                throw new Error(payload.message || 'Failed to save edited document.');
            }

            const link = document.createElement('a');
            link.href = payload.download_url;
            link.download = payload.filename || editedFilename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            window.location.reload();
        } catch (error) {
            previewError.textContent = error.message || 'Failed to save edited document.';
            previewError.classList.remove('d-none');
            enableEditing();
        } finally {
            btnSaveAs.disabled = false;
        }
    }

    document.querySelectorAll('.js-preview-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            renderPreview(
                button.dataset.fileUrl,
                button.dataset.name,
                button.dataset.id,
                button.dataset.saveUrl,
                button.dataset.previewHtmlUrl,
                button
            );
        });
    });

    if (btnEnableEditing) {
        btnEnableEditing.addEventListener('click', function () {
            if (!hasActivePreview) {
                return;
            }
            enableEditing();
        });
    }

    if (btnSaveAs) {
        btnSaveAs.addEventListener('click', function () {
            if (!hasActivePreview || !isEditingEnabled || !currentSaveUrl) {
                return;
            }

            if (typeof window.runWithEsign === 'function') {
                window.runWithEsign(function (password) {
                    saveEditedDocument(password);
                });
                return;
            }

            saveEditedDocument();
        });
    }
});
</script>
@endsection
