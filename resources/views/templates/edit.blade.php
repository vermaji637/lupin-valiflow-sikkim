@extends('layouts.app')

@section('content')
<div class="container">
  <h2>Edit Template</h2>

  @if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
  @endif

  <form method="POST" action="{{ route('templates.update',$template) }}" onsubmit="beforeSave()">
    @csrf
    @method('PUT')

    <div class="mb-3">
      <label class="form-label">Template Name</label>
      <input class="form-control" name="name" value="{{ $template->name }}" required>
    </div>

    <input type="hidden" name="template_type" value="{{ $template->template_type }}">

    <input type="hidden" name="body_html" id="body_html">

    @include('templates.partials.editor_toolbar')

    <div id="editor" class="border p-3" style="min-height:300px;">
      {!! $template->body_html !!}
    </div>

    <button class="btn btn-primary mt-3" type="submit">Update Template</button>
  </form>
</div>

@include('templates.partials.editor_js')
@endsection
