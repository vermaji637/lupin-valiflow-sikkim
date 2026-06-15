{{-- resources/views/admin/templates/designer.blade.php --}}
@extends('layouts.app')

@section('content')
<div class="container">
  <h2>Template Designer: {{ $template->form_key }}</h2>

  @if(session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
  @if(session('error')) <div class="alert alert-danger">{{ session('error') }}</div> @endif

  <form method="POST" action="{{ route('admin.templates.save', $template->form_key) }}">
    @csrf

    {{-- IMPORTANT: keep schema_json in DOM; JS will update it --}}
    <textarea name="schema_json" id="schema_json" class="form-control" style="display:none;">
{!! json_encode($schema, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) !!}
    </textarea>

    <div class="mb-3 d-flex gap-2">
      <button type="button" class="btn btn-secondary" onclick="toggleJson();return false;">Show/Hide JSON</button>
      <button type="button" class="btn btn-outline-warning" onclick="clearDraft();return false;">Clear Draft</button>
      <button type="submit" class="btn btn-primary" onclick="beforeSubmit();">Save Template</button>
      <a class="btn btn-success" href="{{ route('forms.show', $template->form_key) }}" target="_blank">Open Protocol Form</a>
    </div>

    <pre id="json_box" style="display:none; border:1px solid #ddd; padding:10px; max-height:350px; overflow:auto;"></pre>

    <hr/>

    <div id="designer_root">
      @include('ahu.partials.render-blocks', [
        'blocks' => data_get($schema,'form.blocks',[]),
        'mode' => 'designer',
        'schema' => $schema,
        'documents' => $documents,
        'users' => $users,
        'values' => []
      ])
    </div>

  </form>
</div>
@endsection

@push('scripts')
<script>
/* =========================================================
   DRAFT PERSISTENCE (fix: refresh should not reset JSON)
========================================================= */
const TEMPLATE_KEY = @json($template->form_key);
const DRAFT_KEY = "designer_schema_draft_" + TEMPLATE_KEY;

function getSchemaText() {
  const el = document.getElementById('schema_json');
  return el ? el.value : '{}';
}
function setSchemaText(txt) {
  const el = document.getElementById('schema_json');
  if (el) el.value = txt;
}
function getSchemaObj() {
  return JSON.parse(getSchemaText() || '{}');
}
function saveSchemaObj(schemaObj) {
  const txt = JSON.stringify(schemaObj, null, 2);
  setSchemaText(txt);

  // keep JSON viewer updated
  const box = document.getElementById('json_box');
  if (box && box.style.display !== 'none') box.textContent = txt;

  // store draft (THIS FIXES refresh reset)
  localStorage.setItem(DRAFT_KEY, txt);
}

window.toggleJson = function() {
  const box = document.getElementById('json_box');
  if (!box) return;
  box.style.display = (box.style.display === 'none') ? 'block' : 'none';
  box.textContent = getSchemaText();
};

window.clearDraft = function() {
  if (confirm("Clear local draft? It will revert to DB JSON after refresh.")) {
    localStorage.removeItem(DRAFT_KEY);
    location.reload();
  }
};

window.beforeSubmit = function() {
  // On successful submit you may want to clear draft (optional)
  // localStorage.removeItem(DRAFT_KEY);
};

/* Restore draft on load */
document.addEventListener('DOMContentLoaded', () => {
  const draft = localStorage.getItem(DRAFT_KEY);
  if (draft && draft.trim()) {
    setSchemaText(draft);
    // re-render with draft JSON (no reload needed)
    renderAllFromJson();
  } else {
    // ensure json_box displays latest if opened
    const box = document.getElementById('json_box');
    if (box && box.style.display !== 'none') box.textContent = getSchemaText();
  }
});

/* =========================================================
   JSON Path setter (for editable-text)
========================================================= */
function setByPath(obj, path, value) {
  const parts = path.split('.');
  let cur = obj;
  for (let i = 0; i < parts.length - 1; i++) {
    const key = parts[i];
    if (cur[key] === undefined) return false;
    cur = cur[key];
  }
  cur[parts[parts.length - 1]] = value;
  return true;
}

function ensureBlocks(schema) {
  schema.form = schema.form || {};
  schema.form.blocks = schema.form.blocks || [];
}

function findBlockIndexByKey(schema, key) {
  ensureBlocks(schema);
  return schema.form.blocks.findIndex(b => b && b.key === key);
}

function uid(prefix='x') {
  return `${prefix}_${Date.now()}_${Math.floor(Math.random()*1000)}`;
}

function escapeHtml(str) {
  return String(str ?? '')
    .replace(/&/g,'&amp;')
    .replace(/</g,'&lt;')
    .replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;')
    .replace(/'/g,'&#039;');
}

/* =========================================================
   Inline editable-text saving (NO reload)
========================================================= */
document.addEventListener('input', function(e) {
  if (!e.target.classList.contains('editable-text')) return;
  const path = e.target.getAttribute('data-path');
  if (!path) return;

  const schemaObj = getSchemaObj();
  setByPath(schemaObj, path, e.target.innerText);
  saveSchemaObj(schemaObj);
});

/* =========================================================
   RENDER: re-render whole designer from JSON (NO reload)
========================================================= */
window.renderAllFromJson = function() {
  const root = document.getElementById('designer_root');
  if (!root) return;

  // simple approach: reload only the blocks HTML by rebuilding on server would need AJAX,
  // but we can do client-side minimal re-render for table/repeat/list.
  // Here we do NOT rebuild headings/paragraph containers; we just update dynamic pieces.
  // So: after load or draft restore, we update visible JSON only.
  // (Your block HTML already exists from Blade. Draft restore mainly affects operations)
};

/* =========================================================
   BLOCK ops (delete/move/add) - these change structure => easiest refresh UI section.
   Since you said reload breaks JSON, we DO NOT do location.reload.
   We update draft + then do full page refresh BUT keep draft, so it shows new structure.
========================================================= */
function softReloadKeepingDraft() {
  // page reload is OK because draft is restored
  location.reload();
}

window.designerDeleteBlock = function(blockKey) {
  const schema = getSchemaObj();
  ensureBlocks(schema);

  const idx = findBlockIndexByKey(schema, blockKey);
  if (idx < 0) return alert('Block not found: ' + blockKey);

  schema.form.blocks.splice(idx, 1);
  saveSchemaObj(schema);
  softReloadKeepingDraft();
};

window.designerMoveBlock = function(blockKey, dir) {
  const schema = getSchemaObj();
  ensureBlocks(schema);

  const idx = findBlockIndexByKey(schema, blockKey);
  if (idx < 0) return alert('Block not found: ' + blockKey);

  const newIdx = idx + dir;
  if (newIdx < 0 || newIdx >= schema.form.blocks.length) return;

  const tmp = schema.form.blocks[idx];
  schema.form.blocks[idx] = schema.form.blocks[newIdx];
  schema.form.blocks[newIdx] = tmp;

  saveSchemaObj(schema);
  softReloadKeepingDraft();
};

window.designerAddParagraph = function() {
  const schema = getSchemaObj();
  ensureBlocks(schema);
  schema.form.blocks.push({
    type: "paragraph",
    key: uid("para"),
    text: "New paragraph..."
  });
  saveSchemaObj(schema);
  softReloadKeepingDraft();
};

window.designerAddHeading = function() {
  const schema = getSchemaObj();
  ensureBlocks(schema);
  schema.form.blocks.push({
    type: "heading",
    key: uid("heading"),
    level: 3,
    text: "New Heading"
  });
  saveSchemaObj(schema);
  softReloadKeepingDraft();
};

window.designerAddTable = function() {
  const schema = getSchemaObj();
  ensureBlocks(schema);
  schema.form.blocks.push({
    type: "table",
    key: uid("table"),
    rows: [
      { type: "header", cells: [ {type:"th", text:"Column 1"}, {type:"th", text:"Column 2"} ] },
      { cells: [ {type:"text", text:""}, {type:"text", text:""} ] }
    ]
  });
  console.log('Added table block. Current blocks:', schema);
  saveSchemaObj(schema);
  softReloadKeepingDraft();
};

window.designerAddRepeatTable = function() {
  const schema = getSchemaObj();
  ensureBlocks(schema);
  const rk = uid("repeat_table");
  schema.form.blocks.push({
    type: "repeat_table",
    key: rk,
    table_id: rk,
    add_rows_control: { type: "number", id: uid("rowCount"), default: 1, min: 1 },
    columns: ["Column 1", "Column 2"],
    row_schema: {
      col_1: { type: "text", class: "input", default: "" },
      col_2: { type: "text", class: "input", default: "" }
    },
    min_rows: 1,
    default_rows: [{ col_1: "", col_2: "" }]
  });
  saveSchemaObj(schema);
  softReloadKeepingDraft();
};

/* =========================================================
   TABLE operations (type: table) - update UI in-place
========================================================= */
function getTableBlock(schema, blockKey) {
  const idx = findBlockIndexByKey(schema, blockKey);
  if (idx < 0) return [null, -1];
  const block = schema.form.blocks[idx];
  if (!block || block.type !== 'table') return [null, idx];
  return [block, idx];
}

function renderTable(blockKey) {
  const tableEl = document.querySelector(`[data-table-key="${blockKey}"]`);
  if (!tableEl) return;

  const schema = getSchemaObj();
  const [block, idx] = getTableBlock(schema, blockKey);
  if (!block) return;

  const basePath = `form.blocks.${idx}`;
  const rows = block.rows || [];

  let html = `<table class="table table-bordered">`;
  rows.forEach((row, ri) => {
    if ((row.type||'') === 'header') {
      html += `<tr>`;
      (row.cells||[]).forEach((cell, ci) => {
        const path = `${basePath}.rows.${ri}.cells.${ci}.text`;
        html += `<th><span class="editable-text" contenteditable="true" data-path="${path}">${escapeHtml(cell.text||'')}</span></th>`;
      });
      html += `</tr>`;
    } else {
      html += `<tr>`;
      (row.cells||[]).forEach((cell, ci) => {
        const path = `${basePath}.rows.${ri}.cells.${ci}.text`;
        html += `<td><span class="editable-text" contenteditable="true" data-path="${path}">${escapeHtml(cell.text||'')}</span></td>`;
      });
      html += `</tr>`;
    }
  });
  html += `</table>`;

  tableEl.innerHTML = html;
}

window.designerTableAddRow = function(blockKey) {
  const schema = getSchemaObj();
  const [block] = getTableBlock(schema, blockKey);
  if (!block) return alert('Not a table block: ' + blockKey);

  block.rows = block.rows || [];
  const header = block.rows.find(r => (r.type || '') === 'header');
  const colCount = (header && header.cells ? header.cells.length : 0) || (block.rows[0]?.cells?.length || 1);

  block.rows.push({ cells: Array.from({length: colCount}, () => ({ type:"text", text:"" })) });
  saveSchemaObj(schema);
  renderTable(blockKey);
};

window.designerTableAddColumn = function(blockKey) {
  const label = prompt('New column label?');
  if (!label) return;

  const schema = getSchemaObj();
  const [block] = getTableBlock(schema, blockKey);
  if (!block) return alert('Not a table block');

  block.rows = block.rows || [];
  let header = block.rows.find(r => (r.type || '') === 'header');
  if (!header) {
    header = { type:'header', cells:[] };
    block.rows.unshift(header);
  }
  header.cells = header.cells || [];
  header.cells.push({ type:'th', text: label });

  for (const r of block.rows) {
    if ((r.type || '') === 'header') continue;
    r.cells = r.cells || [];
    r.cells.push({ type:'text', text:'' });
  }

  saveSchemaObj(schema);
  renderTable(blockKey);
};

window.designerTableDeleteRow = function(blockKey) {
  const rowIndexStr = prompt('Delete which row index? (0-based. Header usually 0)');
  if (rowIndexStr === null) return;
  const rowIndex = parseInt(rowIndexStr, 10);
  if (Number.isNaN(rowIndex)) return alert('Invalid row index');

  const schema = getSchemaObj();
  const [block] = getTableBlock(schema, blockKey);
  if (!block) return alert('Not a table block');

  block.rows = block.rows || [];
  if (rowIndex < 0 || rowIndex >= block.rows.length) return alert('Row out of range');

  block.rows.splice(rowIndex, 1);
  saveSchemaObj(schema);
  renderTable(blockKey);
};

window.designerTableDeleteColumn = function(blockKey) {
  const colIndexStr = prompt('Delete which column index? (0-based)');
  if (colIndexStr === null) return;
  const colIndex = parseInt(colIndexStr, 10);
  if (Number.isNaN(colIndex)) return alert('Invalid column index');

  const schema = getSchemaObj();
  const [block] = getTableBlock(schema, blockKey);
  if (!block) return alert('Not a table block');

  block.rows = block.rows || [];
  for (const r of block.rows) {
    r.cells = r.cells || [];
    if (colIndex >= 0 && colIndex < r.cells.length) r.cells.splice(colIndex, 1);
  }

  saveSchemaObj(schema);
  renderTable(blockKey);
};

/* =========================================================
   REPEAT TABLE ops (repeat_table) - update UI in-place
========================================================= */
function slugFieldName(label) {
  return String(label || '')
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g,'');
}

function getRepeatBlock(schema, blockKey) {
  const idx = findBlockIndexByKey(schema, blockKey);
  if (idx < 0) return [null, -1];
  const block = schema.form.blocks[idx];
  if (!block || block.type !== 'repeat_table') return [null, idx];
  return [block, idx];
}

function renderRepeatTable(blockKey) {
  const wrap = document.querySelector(`[data-repeat-key="${blockKey}"]`);
  if (!wrap) return;

  const schema = getSchemaObj();
  const [block, idx] = getRepeatBlock(schema, blockKey);
  if (!block) return;

  const basePath = `form.blocks.${idx}`;
  const columns = block.columns || [];
  const rowSchema = block.row_schema || {};
  const schemaKeys = Object.keys(rowSchema);
  const defaultRows = block.default_rows || [];

  let html = `<table class="table table-bordered">`;
  html += `<thead><tr>`;
  columns.forEach((label, ci) => {
    const path = `${basePath}.columns.${ci}`;
    html += `<th><span class="editable-text" contenteditable="true" data-path="${path}">${escapeHtml(label)}</span></th>`;
  });
  html += `</tr></thead><tbody>`;

  if (!defaultRows.length) {
    html += `<tr><td colspan="${Math.max(columns.length,1)}" class="text-muted">No default rows. Use + Row.</td></tr>`;
  } else {
    defaultRows.forEach((r, ri) => {
      html += `<tr>`;
      schemaKeys.forEach((fieldKey) => {
        const path = `${basePath}.default_rows.${ri}.${fieldKey}`;
        html += `<td><span class="editable-text" contenteditable="true" data-path="${path}">${escapeHtml(r[fieldKey] ?? '')}</span></td>`;
      });
      html += `</tr>`;
    });
  }

  html += `</tbody></table>`;
  wrap.innerHTML = html;
}

window.designerRepeatAddDefaultRow = function(blockKey) {
  const schema = getSchemaObj();
  const [block] = getRepeatBlock(schema, blockKey);
  if (!block) return alert('Not a repeat_table: ' + blockKey);

  block.row_schema = block.row_schema || {};
  block.default_rows = block.default_rows || [];

  const newRow = {};
  for (const k of Object.keys(block.row_schema)) newRow[k] = "";
  block.default_rows.push(newRow);

  saveSchemaObj(schema);
  renderRepeatTable(blockKey);
};

window.designerRepeatDeleteDefaultRow = function(blockKey) {
  const idxStr = prompt('Delete which default row index? (0-based)');
  if (idxStr === null) return;
  const rowIndex = parseInt(idxStr, 10);
  if (Number.isNaN(rowIndex)) return alert('Invalid index');

  const schema = getSchemaObj();
  const [block] = getRepeatBlock(schema, blockKey);
  if (!block) return alert('Not a repeat_table');

  block.default_rows = block.default_rows || [];
  if (rowIndex < 0 || rowIndex >= block.default_rows.length) return alert('Out of range');

  block.default_rows.splice(rowIndex, 1);
  saveSchemaObj(schema);
  renderRepeatTable(blockKey);
};

window.designerRepeatAddColumn = function(blockKey) {
  const label = prompt('Column label?');
  if (!label) return;

  const schema = getSchemaObj();
  const [block] = getRepeatBlock(schema, blockKey);
  if (!block) return alert('Not a repeat_table');

  block.columns = block.columns || [];
  block.row_schema = block.row_schema || {};
  block.default_rows = block.default_rows || [];

  const field = slugFieldName(label) || uid('col');
  const fieldKey = (block.row_schema[field]) ? `${field}_${Date.now()}` : field;

  block.columns.push(label);
  block.row_schema[fieldKey] = { type:"text", class:"input", default:"" };

  for (const r of block.default_rows) r[fieldKey] = r[fieldKey] ?? "";

  saveSchemaObj(schema);
  renderRepeatTable(blockKey);
};

window.designerRepeatDeleteColumn = function(blockKey) {
  const colIndexStr = prompt('Delete which column index? (0-based)');
  if (colIndexStr === null) return;
  const colIndex = parseInt(colIndexStr, 10);
  if (Number.isNaN(colIndex)) return alert('Invalid index');

  const schema = getSchemaObj();
  const [block] = getRepeatBlock(schema, blockKey);
  if (!block) return alert('Not a repeat_table');

  block.columns = block.columns || [];
  block.row_schema = block.row_schema || {};
  block.default_rows = block.default_rows || [];

  const schemaKeys = Object.keys(block.row_schema);
  const keyToRemove = schemaKeys[colIndex];
  if (keyToRemove === undefined) return alert('No schema key for this column');

  block.columns.splice(colIndex, 1);
  delete block.row_schema[keyToRemove];
  for (const r of block.default_rows) delete r[keyToRemove];

  saveSchemaObj(schema);
  renderRepeatTable(blockKey);
};

/* =========================================================
   LIST ops - update UI in-place
========================================================= */
function getListBlock(schema, blockKey) {
  const idx = findBlockIndexByKey(schema, blockKey);
  if (idx < 0) return [null, -1];
  const block = schema.form.blocks[idx];
  if (!block || block.type !== 'list') return [null, idx];
  return [block, idx];
}

function renderList(blockKey) {
  const wrap = document.querySelector(`[data-list-key="${blockKey}"]`);
  if (!wrap) return;

  const schema = getSchemaObj();
  const [block, idx] = getListBlock(schema, blockKey);
  if (!block) return;

  const basePath = `form.blocks.${idx}`;
  const style = block.style || 'ul';
  const tag = (style === 'ol' || style === 'ol_fields') ? 'ol' : 'ul';
  const items = block.items || [];

  let html = `<${tag} class="mb-2">`;
  items.forEach((item, ii) => {
    const path = `${basePath}.items.${ii}`;
    html += `<li>
      <span class="editable-text" contenteditable="true" data-path="${path}">${escapeHtml(item)}</span>
      <button type="button" class="btn btn-sm btn-outline-danger ms-2"
        onclick="designerListDeleteItem('${blockKey}', ${ii});return false;">✕</button>
    </li>`;
  });
  html += `</${tag}>`;

  html += `<button type="button" class="btn btn-sm btn-outline-primary"
    onclick="designerListAddItem('${blockKey}');return false;">+ Add Item</button>`;

  wrap.innerHTML = html;
}

window.designerListAddItem = function(blockKey) {
  const schema = getSchemaObj();
  const [block] = getListBlock(schema, blockKey);
 // if (!block) return alert('Not a list: ' + blockKey);

  block.items = block.items || [];
  block.items.push("New List Item");

  saveSchemaObj(schema);
  renderList(blockKey);
};

window.designerListDeleteItem = function(blockKey, itemIndex) {
  const schema = getSchemaObj();
  const [block] = getListBlock(schema, blockKey);
  if (!block) return alert('Not a list');

  block.items = block.items || [];
  if (itemIndex < 0 || itemIndex >= block.items.length) return;

  block.items.splice(itemIndex, 1);
  saveSchemaObj(schema);
  renderList(blockKey);
};

function getBlocks(schema){
  schema.form = schema.form || {};
  schema.form.blocks = schema.form.blocks || [];
  return schema.form.blocks;
}

window.designerDeleteBlockByIndex = function(i){
  const schema = getSchemaObj();
  const blocks = getBlocks(schema);
  if (!blocks[i]) return alert("Block not found at index: " + i);
  blocks.splice(i, 1);
  saveSchemaObj(schema);
  location.reload();
};

window.designerMoveBlockByIndex = function(i, dir){
  const schema = getSchemaObj();
  const blocks = getBlocks(schema);
  const j = i + dir;
  if (i < 0 || j < 0 || i >= blocks.length || j >= blocks.length) return;
  [blocks[i], blocks[j]] = [blocks[j], blocks[i]];
  saveSchemaObj(schema);
  location.reload();
};

// ---------- TABLE ----------
window.designerTableAddRowByIndex = function(i){
  const schema = getSchemaObj();
  const blocks = getBlocks(schema);
  const block = blocks[i];
  if (!block || block.type !== "table") return alert("Not a table at index: " + i);

  block.rows = block.rows || [];
  const header = block.rows.find(r => (r.type || "") === "header");
  const colCount = (header && header.cells ? header.cells.length : (block.rows[0]?.cells?.length || 1));

  block.rows.push({ cells: Array.from({length: colCount}, () => ({ type:"text", text:"" })) });
  saveSchemaObj(schema);
  location.reload();
};

window.designerTableAddColumnByIndex = function(i){
  const label = prompt("New column label?");
  if (!label) return;

  const schema = getSchemaObj();
  const blocks = getBlocks(schema);
  const block = blocks[i];
  if (!block || block.type !== "table") return alert("Not a table at index: " + i);

  block.rows = block.rows || [];
  let header = block.rows.find(r => (r.type || "") === "header");
  if (!header) { header = { type:"header", cells:[] }; block.rows.unshift(header); }
  header.cells = header.cells || [];
  header.cells.push({ type:"th", text: label });

  for (const r of block.rows) {
    if ((r.type || "") === "header") continue;
    r.cells = r.cells || [];
    r.cells.push({ type:"text", text:"" });
  }

  saveSchemaObj(schema);
  location.reload();
};

window.designerTableDeleteRowByIndex = function(i){
  const rowIndexStr = prompt("Delete which row index? (0-based, header usually 0)");
  if (rowIndexStr === null) return;
  const rowIndex = parseInt(rowIndexStr, 10);
  if (Number.isNaN(rowIndex)) return alert("Invalid row index");

  const schema = getSchemaObj();
  const block = getBlocks(schema)[i];
  if (!block || block.type !== "table") return alert("Not a table at index: " + i);

  block.rows = block.rows || [];
  if (rowIndex < 0 || rowIndex >= block.rows.length) return alert("Row out of range");
  block.rows.splice(rowIndex, 1);

  saveSchemaObj(schema);
  location.reload();
};

window.designerTableDeleteColumnByIndex = function(i){
  const colIndexStr = prompt("Delete which column index? (0-based)");
  if (colIndexStr === null) return;
  const colIndex = parseInt(colIndexStr, 10);
  if (Number.isNaN(colIndex)) return alert("Invalid column index");

  const schema = getSchemaObj();
  const block = getBlocks(schema)[i];
  if (!block || block.type !== "table") return alert("Not a table at index: " + i);

  block.rows = block.rows || [];
  for (const r of block.rows) {
    r.cells = r.cells || [];
    if (colIndex >= 0 && colIndex < r.cells.length) r.cells.splice(colIndex, 1);
  }

  saveSchemaObj(schema);
  location.reload();
};

// ---------- REPEAT TABLE ----------
function repeatSchemaKeys(block){
  return Object.keys(block.row_schema || {});
}

window.designerRepeatAddDefaultRowByIndex = function(i){
  const schema = getSchemaObj();
  const block = getBlocks(schema)[i];
  if (!block || block.type !== "repeat_table") return alert("Not repeat_table at index: " + i);

  block.row_schema = block.row_schema || {};
  block.default_rows = block.default_rows || [];

  const newRow = {};
  for (const k of Object.keys(block.row_schema)) newRow[k] = "";
  block.default_rows.push(newRow);

  saveSchemaObj(schema);
  location.reload();
};

function slugFieldName(label){
  return (label || "")
    .toLowerCase().trim()
    .replace(/[^a-z0-9]+/g, "_")
    .replace(/^_+|_+$/g, "");
}

window.designerRepeatAddColumnByIndex = function(i){
  const label = prompt("Column label?");
  if (!label) return;

  const schema = getSchemaObj();
  const block = getBlocks(schema)[i];
  if (!block || block.type !== "repeat_table") return alert("Not repeat_table at index: " + i);

  block.columns = block.columns || [];
  block.row_schema = block.row_schema || {};
  block.default_rows = block.default_rows || [];

  const field = slugFieldName(label) || ("col_" + Date.now());
  const fieldKey = block.row_schema[field] ? `${field}_${Date.now()}` : field;

  block.columns.push(label);
  block.row_schema[fieldKey] = { type:"text", class:"input", default:"" };

  for (const r of block.default_rows) r[fieldKey] = r[fieldKey] ?? "";

  saveSchemaObj(schema);
  location.reload();
};

window.designerRepeatDeleteDefaultRowByIndex = function(i){
  const rowIndexStr = prompt("Delete which row index? (0-based)");
  if (rowIndexStr === null) return;
  const rowIndex = parseInt(rowIndexStr, 10);
  if (Number.isNaN(rowIndex)) return alert("Invalid row index");

  const schema = getSchemaObj();
  const block = getBlocks(schema)[i];
  if (!block || block.type !== "repeat_table") return alert("Not repeat_table at index: " + i);

  block.default_rows = block.default_rows || [];
  if (rowIndex < 0 || rowIndex >= block.default_rows.length) return alert("Row out of range");
  block.default_rows.splice(rowIndex, 1);

  saveSchemaObj(schema);
  location.reload();
};

window.designerRepeatDeleteColumnByIndex = function(i){
  const colIndexStr = prompt("Delete which column index? (0-based)");
  if (colIndexStr === null) return;
  const colIndex = parseInt(colIndexStr, 10);
  if (Number.isNaN(colIndex)) return alert("Invalid column index");

  const schema = getSchemaObj();
  const block = getBlocks(schema)[i];
  if (!block || block.type !== "repeat_table") return alert("Not repeat_table at index: " + i);

  block.columns = block.columns || [];
  block.row_schema = block.row_schema || {};
  block.default_rows = block.default_rows || [];

  const keys = Object.keys(block.row_schema);
  const keyToRemove = keys[colIndex];
  if (keyToRemove === undefined) return alert("No schema key found for this column");

  block.columns.splice(colIndex, 1);
  delete block.row_schema[keyToRemove];
  for (const r of block.default_rows) delete r[keyToRemove];

  saveSchemaObj(schema);
  location.reload();
};
function getSchema() {
  return window.DESIGNER_SCHEMA; // ✅ change if needed
}

function setSchema(s) {
  window.DESIGNER_SCHEMA = s;
}

function deepClone(obj) {
  return JSON.parse(JSON.stringify(obj));
}

/**
 * Walk nodes inside a table row and:
 * - rename all field names to be unique for new row
 * - clear defaults
 */
function bumpRowFieldNames(row, newIndex) {
  row.cells.forEach(cell => {

    // ✅ HERE
    if (cell.field && cell.field.name) {
      const cleaned = cell.field.name.replace(/_r\d+$/i, "");
      cell.field.name = `${cleaned}_r${newIndex}`;
    }

  });
}

function walkNode(node, newIndex) {
  if (!node || typeof node !== "object") return;

  if (node.type === "field") {
    const old = node.name || "";
    const cleaned = old.replace(/_r\d+$/i, ""); // remove old row suffix if exists
    node.name = `${cleaned}_r${newIndex}`;

    if ("default" in node) node.default = "";
    return;
  }

  if (node.type === "group" && Array.isArray(node.content)) {
    node.content.forEach((child) => walkNode(child, newIndex));
  }
}

/**
 * ✅ MAIN FIX
 * - Adds new row EXACTLY like last data row
 * - Keeps Signature + Date, Name & Designation etc.
 */
function designerTableAddRow(blockKey) {
  const schema = getSchema();
  if (!schema || !schema.form || !Array.isArray(schema.form.blocks)) return;

  const blocks = schema.form.blocks;
  const bi = blocks.findIndex(b => (b.key || "") === blockKey && b.type === "table");
  if (bi === -1) return;

  const block = blocks[bi];
  block.rows = block.rows || [];

  // only non-header rows are data rows
  const dataRows = block.rows.filter(r => (r.type || "") !== "header");
  if (dataRows.length === 0) {
    alert("No row exists to clone. Add one row first.");
    return;
  }

  // clone last data row
  const lastRow = dataRows[dataRows.length - 1];
  const newRow = deepClone(lastRow);

  // new row index
  const newIndex = dataRows.length + 1;

  // rename field names inside cloned row
  bumpRowFieldNames(newRow, newIndex);

  // push to block rows
  block.rows.push(newRow);
  blocks[bi] = block;
  schema.form.blocks = blocks;

  setSchema(schema);

  // re-render preview
  if (typeof window.renderDesigner === "function") {
    window.renderDesigner();
  }}

  
</script>
@endpush
