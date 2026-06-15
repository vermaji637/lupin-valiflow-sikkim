@once
@php
  $toolbarCssVersion = @filemtime(public_path('css/template-editor-toolbar.css')) ?: time();
  $toolbarJsVersion = @filemtime(public_path('js/template-editor-toolbar.js')) ?: time();
@endphp
<link rel="stylesheet" href="{{ asset('css/template-editor-toolbar.css') }}?v={{ $toolbarCssVersion }}">
<script src="{{ asset('js/template-editor-toolbar.js') }}?v={{ $toolbarJsVersion }}" defer></script>
@endonce

<div
  id="floatingTemplateToolbar"
  class="template-editor-toolbar"
  data-floating-toolbar="1"
>
  <button type="button" class="btn btn-outline-secondary" onclick="enableEdit()">Enable Text Edit</button>
  <button type="button" class="btn btn-outline-secondary" onclick="disableEdit()">Lock Text</button>

  <button type="button" class="btn btn-outline-primary" onclick="addRow()">Add Row</button>
  <button type="button" class="btn btn-outline-primary" onclick="addCol()">Add Column</button>

  <button type="button" class="btn btn-outline-danger" onclick="delRow()">Delete Row</button>
  <button type="button" class="btn btn-outline-danger" onclick="delCol()">Delete Column</button>

  <span class="toolbar-hint">Click any table cell first, then use buttons.</span>
</div>
