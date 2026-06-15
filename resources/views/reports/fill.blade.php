@extends('layouts.app')

@section('content')
<div class="container">
  <h2>Fill: {{ $template->name }}</h2>

  @if($errors->any())
    <div class="alert alert-danger">
      <ul class="mb-0">
        @foreach($errors->all() as $error)
          <li>{{ $error }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  @if(session('status'))
    <div class="alert alert-success">
      {{ session('status') }}
    </div>
  @endif

  @php
    $toolbarCssVersion = @filemtime(public_path('css/template-editor-toolbar.css')) ?: time();
    $toolbarJsVersion = @filemtime(public_path('js/template-editor-toolbar.js')) ?: time();
  @endphp
  <link rel="stylesheet" href="{{ asset('css/template-editor-toolbar.css') }}?v={{ $toolbarCssVersion }}">
  <script src="{{ asset('js/template-editor-toolbar.js') }}?v={{ $toolbarJsVersion }}" defer></script>

  <div id="floatingReportFillToolbar" class="template-editor-toolbar" data-floating-toolbar="1">
    <button type="button" class="btn btn-outline-primary" onclick="addRowFill()">Add Row</button>
    <button type="button" class="btn btn-outline-primary" onclick="addColFill()">Add Column</button>
    <button type="button" class="btn btn-outline-danger" onclick="delRowFill()">Delete Row</button>
    <button type="button" class="btn btn-outline-danger" onclick="delColFill()">Delete Column</button>
    <span class="toolbar-hint">Click any table cell first, then use buttons.</span>
  </div>

  <form method="POST" action="{{ route('reports.store',$template) }}" enctype="multipart/form-data" class="esign-required">
    @csrf
    <input type="hidden" name="template_id" value="{{ $template->id }}">

    @if(!empty($requiresPreapprovedFile))
      <div class="card mb-3">
        <div class="card-header">Pre-Approved Document (Required)</div>
        <div class="card-body">
          <div class="alert alert-info mb-3">
            Step 1: Upload the pre-approved DOC/DOCX/PDF file, then continue the same workflow process.
          </div>
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label">Pre-Approved File</label>
              <input type="file"
                     class="form-control"
                     name="preapproved_doc_file"
                     accept=".doc,.docx,.pdf"
                     required>
            </div>
          </div>
        </div>
      </div>
    @endif

    <div id="formBody">{!! $bodyHtml !!}</div>
    <button class="btn btn-success mt-3" type="submit">Submit & Save</button>
  </form>
</div>

<script>
document.querySelectorAll('#formBody [contenteditable="true"]').forEach(el => el.contentEditable="false");

let selectedCell = null;
document.addEventListener('click', (e) => {
  const cell = e.target.closest('#formBody td,#formBody th');
  if (!cell) return;
  selectedCell = cell;
  document.querySelectorAll('#formBody td,#formBody th').forEach(c => c.style.outline = '');
  cell.style.outline = '2px solid #0d6efd';
});

function table() { return selectedCell ? selectedCell.closest('table') : null; }

function addRowFill() {
  const t = table(); if (!t) return alert('Select a cell');
  const rows = t.querySelectorAll('tr');
  const clone = rows[rows.length - 1].cloneNode(true);
  clone.querySelectorAll('input,textarea').forEach(el => el.type === 'checkbox' ? el.checked = false : el.value = '');
  clone.querySelectorAll('select').forEach(sel => sel.selectedIndex = 0);
  // Remove any existing done-by labels in cloned row
  clone.querySelectorAll('.done-by-label').forEach(el => el.remove());
  t.appendChild(clone);
  // Re-attach listeners for newly added row
  attachDoneByListeners(clone);
}

function delRowFill() {
  if (!selectedCell) return alert('Select a row');
  const row = selectedCell.closest('tr');
  const t = row.closest('table');
  if (t.querySelectorAll('tr').length <= 2) return alert('Cannot delete last row');
  row.remove(); selectedCell = null;
}

function addColFill() {
  const t = table(); if (!t) return alert('Select a cell');
  const idx = selectedCell.cellIndex;
  t.querySelectorAll('tr').forEach(tr => {
    const isHeader = tr.querySelectorAll('th').length > 0;
    const cell = document.createElement(isHeader ? 'th' : 'td');
    if (isHeader) {
      cell.textContent = 'NEW COLUMN';
    } else {
      const uniq = 'col_' + Date.now() + '_' + Math.floor(Math.random() * 1000);
      cell.innerHTML = `<input class="input" name="${uniq}">`;
    }
    if (tr.children[idx]) tr.children[idx].after(cell);
    else tr.appendChild(cell);
  });
}

function delColFill() {
  const t = table(); if (!t) return alert('Select a cell');
  const idx = selectedCell.cellIndex;
  const cols = t.querySelector('tr')?.children?.length ?? 0;
  if (cols <= 1) return alert('Cannot delete last column');
  t.querySelectorAll('tr').forEach(tr => tr.children[idx]?.remove());
  selectedCell = null;
}

// ─── DONE BY ENGINEERING: Dynamic user + date on checkbox ───────────────────

const CURRENT_USER = "{{ trim((auth()->user()->first_name ?? '') . ' ' . (auth()->user()->last_name ?? '')) ?: (auth()->user()->name ?? 'Unknown') }}";

function formatDate(date) {
  const d = String(date.getDate()).padStart(2, '0');
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const y = date.getFullYear();
  return `${d}/${m}/${y}`;
}

function handleDoneByCheckbox(checkbox) {
  const td = checkbox.closest('td');
  if (!td) return;

  // ✅ Find the sibling text input in the same TD (check_electrical_done_by etc.)
  const textInput = td.querySelector('input[type="text"], input:not([type="checkbox"]):not([type="hidden"])');

  if (checkbox.checked) {
    const label_text = `${CURRENT_USER} - ${formatDate(new Date())}`;
    if (textInput) {
      textInput.value = label_text;
      textInput.readOnly = true;  // prevent manual editing
      textInput.style.background = '#f0f9ff';
      textInput.style.fontWeight = '500';
      textInput.style.color = '#0369a1';
    }
  } else {
    // ✅ On uncheck: clear the text input
    if (textInput) {
      textInput.value = '';
      textInput.readOnly = false;
      textInput.style.background = '';
      textInput.style.fontWeight = '';
      textInput.style.color = '';
    }
  }
}

function attachDoneByListeners(scope = document) {
  scope.querySelectorAll('input[type="checkbox"].done-by').forEach(cb => {
    if (cb.dataset.doneByBound) return;
    cb.dataset.doneByBound = 'true';

    cb.addEventListener('change', function () {
      handleDoneByCheckbox(this);
    });

    // On page load: if already checked, fill immediately
    if (cb.checked) {
      handleDoneByCheckbox(cb);
    }
  });
}

document.addEventListener('DOMContentLoaded', function () {
  attachDoneByListeners();
  addHvacAttachmentUploadInputs();
});

function addHvacAttachmentUploadInputs() {
  for (let i = 1; i <= 10; i++) {
    const markerInput = document.querySelector(`#formBody input[name="attachment_no_${i}"]`);
    if (!markerInput) continue;

    const cell = markerInput.closest('td');
    if (!cell) continue;

    const row = markerInput.closest('tr');
    const labelCell = row && row.children.length > 1 ? row.children[1] : null;
    const labelText = labelCell ? labelCell.textContent.trim() : `Attachment ${i}`;

    const hiddenName = document.createElement('input');
    hiddenName.type = 'hidden';
    hiddenName.name = `attachment_name_${i}`;
    hiddenName.value = labelText;

    const fileInput = document.createElement('input');
    fileInput.type = 'file';
    fileInput.name = `attachment_file_${i}`;
    fileInput.accept = '.pdf,.jpg,.jpeg,.png,.doc,.docx';
    fileInput.className = markerInput.className || 'form-control';
    fileInput.style.width = '100%';

    cell.innerHTML = '';
    cell.appendChild(fileInput);
    cell.appendChild(hiddenName);
  }
}
</script>

<script>
document.addEventListener("change", function(e) {

    // ✅ works for ALL templates
    if (e.target.matches('select[name="document_id"]')) {

        let selected = e.target.selectedOptions[0];
        if (!selected) return;

        let facility = selected.getAttribute("data-facility") || '';
        let eqName = selected.getAttribute("data-equipment-name") || '';
        let eqId = selected.getAttribute("data-equipment-id") || '';
        let approverId = selected.getAttribute("data-approver-id") || '';

        // ✅ find nearest container
        let container = e.target.closest('table, div, section');

        if (!container) return;

        let facilityInput = container.querySelector('[name="facility_section"]');
        let eqNameInput = container.querySelector('[name="equipment_name"], [name="equipment_system_name"]');
        let eqIdInput = container.querySelector('[name="equipment_id"], [name="equipment_system_id"]');

        if (facilityInput) facilityInput.value = facility;
        if (eqNameInput) eqNameInput.value = eqName;
        if (eqIdInput) eqIdInput.value = eqId;

        // Auto-fill pre approver (USER/VALIDATION) from equipment mapping when available.
        if (approverId) {
            let approverSelect = container.querySelector('[name="approved_by_user_name"]');
            if (!approverSelect) {
                approverSelect = document.querySelector('[name="approved_by_user_name"]');
            }

            if (approverSelect) {
                approverSelect.value = approverId;
                approverSelect.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
    }

});
</script>
@endsection
