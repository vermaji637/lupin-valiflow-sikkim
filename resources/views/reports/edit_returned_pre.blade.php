@extends('layouts.app')

@section('content')
<div class="container">
  <h2>Returned Report #{{ $report->id }} (Fix & Re-submit)</h2>

  @php
    $toolbarCssVersion = @filemtime(public_path('css/template-editor-toolbar.css')) ?: time();
    $toolbarJsVersion = @filemtime(public_path('js/template-editor-toolbar.js')) ?: time();
  @endphp
  <link rel="stylesheet" href="{{ asset('css/template-editor-toolbar.css') }}?v={{ $toolbarCssVersion }}">
  <script src="{{ asset('js/template-editor-toolbar.js') }}?v={{ $toolbarJsVersion }}" defer></script>

  <div id="floatingReturnedPreToolbar" class="template-editor-toolbar" data-floating-toolbar="1">
    <button type="button" class="btn btn-outline-primary js-add-row">Add Row</button>
    <button type="button" class="btn btn-outline-primary js-add-col">Add Column</button>
    <button type="button" class="btn btn-outline-danger js-del-row">Delete Row</button>
    <button type="button" class="btn btn-outline-danger js-del-col">Delete Column</button>
    <span class="toolbar-hint">Click any table cell first, then use buttons.</span>
  </div>

  <form method="POST" action="{{ route('reports.resubmitReturnedPre',$report) }}" enctype="multipart/form-data">
    @csrf

    <div id="formBody">{!! $bodyHtml !!}</div>

    <button class="btn btn-success mt-3" type="submit">Re-submit for PRE Approval</button>
  </form>
</div>
@endsection

@push('scripts')
<script>
(function () {

  let selectedCell = null;

  document.addEventListener('click', (e) => {
    const cell = e.target.closest('#formBody td, #formBody th');
    if (!cell) return;

    selectedCell = cell;
    document.querySelectorAll('#formBody td,#formBody th').forEach(c => c.style.outline = '');
    cell.style.outline = '2px solid #0d6efd';
  });

  function table(){ return selectedCell ? selectedCell.closest('table') : null; }

  function clearRowInputs(row) {
    row.querySelectorAll('input, textarea, select').forEach(el => {
      const tag = el.tagName.toLowerCase();
      const type = (el.getAttribute('type') || '').toLowerCase();

      if (tag === 'select') { el.selectedIndex = 0; return; }
      if (type === 'checkbox') { el.checked = false; return; }
      if (type === 'file') { el.value = ''; return; }
      el.value = '';
    });
  }

  function addRowFill(){
    const t = table(); if(!t) return alert('Select a cell');
    const rows = t.querySelectorAll('tr');
    const clone = rows[rows.length-1].cloneNode(true);
    clearRowInputs(clone);
    clone.querySelectorAll('[id]').forEach(el => el.removeAttribute('id'));
    t.appendChild(clone);
  }

  function delRowFill(){
    if(!selectedCell) return alert('Select a row');
    const row = selectedCell.closest('tr');
    const t = row.closest('table');
    if(t.querySelectorAll('tr').length <= 2) return alert('Cannot delete last row');
    row.remove(); selectedCell=null;
  }

  function addColFill(){
    const t = table(); if(!t) return alert('Select a cell');
    const idx = selectedCell.cellIndex;

    t.querySelectorAll('tr').forEach((tr,trIndex)=>{
      const isHeader = trIndex===0 || tr.querySelectorAll('th').length>0;
      const cell = document.createElement(isHeader?'th':'td');
      if(isHeader){
        cell.textContent='NEW COLUMN';
      } else {
        const uniq='col_'+Date.now()+'_'+Math.floor(Math.random()*1000);
        cell.innerHTML=`<input class="input" name="${uniq}" style="width:100%;">`;
      }
      if(tr.children[idx]) tr.children[idx].after(cell);
      else tr.appendChild(cell);
    });
  }

  function delColFill(){
    const t=table(); if(!t) return alert('Select a cell');
    const idx = selectedCell.cellIndex;
    const cols = t.querySelector('tr')?.children?.length ?? 0;
    if(cols<=1) return alert('Cannot delete last column');
    t.querySelectorAll('tr').forEach(tr => tr.children[idx]?.remove());
    selectedCell=null;
  }

  document.addEventListener('click', (e) => {
    if (e.target.closest('.js-add-row')) return addRowFill();
    if (e.target.closest('.js-del-row')) return delRowFill();
    if (e.target.closest('.js-add-col')) return addColFill();
    if (e.target.closest('.js-del-col')) return delColFill();
  });

})();
</script>
@endpush
