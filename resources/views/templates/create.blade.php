@extends('layouts.app')

@section('content')
<div class="container">
  <h2>Create Template</h2>

  <form method="POST" action="{{ route('templates.store') }}" enctype="multipart/form-data" onsubmit="beforeSave()">
    @csrf

    <div class="mb-3">
      <label class="form-label">Template Name</label>
      <input class="form-control" name="name" required>
    </div>

    <input type="hidden" name="template_type" value="{{ $type }}">

    <input type="hidden" name="body_html" id="body_html">

    @include('templates.partials.editor_toolbar')

    <div id="editor" class="border p-3" style="min-height:300px;">
      {!! $starter !!}
    </div>

    <button class="btn btn-primary mt-3" type="submit">Save Template</button>
  </form>
</div>

@include('templates.partials.editor_js')
@endsection
