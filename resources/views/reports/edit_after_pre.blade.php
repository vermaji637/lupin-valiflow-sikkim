@extends('layouts.app')

@section('content')
@php
     @endphp
<div class="container">
  <h2>Edit After Pre-Approval: Report #{{ $report->id }}</h2>

  <form method="POST" action="{{ route('reports.submitToPost', $report) }}" enctype="multipart/form-data">
    @csrf

    <div id="formBody">
      {!! $bodyHtml !!}
    </div>

    <button class="btn btn-primary mt-3" type="submit">Submit For Post Approval</button>
  </form>
</div>

<script>
(function () {
  // Keep contenteditable false
  document.querySelectorAll('#formBody [contenteditable="true"]').forEach(el => el.contentEditable="false");
})();
</script>
@endsection