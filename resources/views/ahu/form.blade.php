@extends('layouts.app')



@push('styles')
<style>
  

  .toolbar{position:sticky; top:0; background:#fff; padding:10px 0; border-bottom:1px solid #eee; margin-bottom:16px; display:flex; align-items:center; gap:8px; z-index: 5;}
  .toolbar button{padding:8px 12px; border:1px solid #ccc; background:#f6f6f6; border-radius:8px; cursor:pointer}
  .toolbar .hint{font-size:12px; color:var(--muted)}

  .editable{outline:2px dashed #cbd5e1; padding:16px; border-radius:12px; background:var(--bg);}

  .grid{display:grid; grid-template-columns: 1fr 1fr; gap:12px;}
  table{border-collapse:collapse; width:100%; margin:12px 0;}
  th, td{border:1px solid #ddd; padding:8px; vertical-align:top;}
  th{background:#f2f2f2; text-align:left;}
  .noborder td{border:none; padding:4px 0}

  .center{text-align:center}

  .input, .input-sm, .input-lg, .input-num, .input-date { 
    width:100%; border:none; padding:4px 6px; background:transparent; font:inherit; color:inherit; outline:none;
  }
  .input-sm{max-width:220px}
  .input-lg{font-weight:600}
  .input-num{max-width:140px}
  .input-date{max-width:160px}
  textarea.input{min-height:56px; border:1px solid #ccc; border-radius:8px; padding:8px}

  .footnote{margin-top:40px; font-size:12px; color:var(--muted)}

  .btn-custom {
      padding: 10px 20px !important;
      margin: 20px 0 !important;
      background: #007bff !important;
      color: white !important;
      border: none !important;
      border-radius: 5px !important;
      cursor: pointer !important;
      font-size: 14px !important;
  }

  .btn-custom:hover {
      background: #0056b3;
  }

  @media print {
    .toolbar, .editable { display:none !important; }
    body { margin: 0.5in; }
    a[href]:after{content:""}
  }
</style>
@endpush

@section('content')
<div class="container">

  <h2>{{ $template->title }}</h2>

  @if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
  @endif

  @php
    $mode = $mode ?? 'user';
    $isView = ($mode === 'view');
  @endphp
<div class="container-fluid">
  @if(!$isView)
  <div id="content" class="editable" contenteditable="false">
    <form method="POST" action="{{ route('forms.submit', $template->form_key) }}">
      @csrf
  @endif

    @include('ahu.partials.render-blocks', [
      'blocks' => data_get($schema,'form.blocks',[]),
      'mode' => $mode,
      'schema' => $schema,
      'documents' => $documents ?? [],
      'users' => $users ?? [],
      'values' => $values ?? []
    ])

    @if(!$isView)
      <button type="submit" class="btn btn-primary mt-3">Submit</button>
    @endif

  @if(!$isView)
</div>
    </form>
  @endif
</div>
</div>
@endsection
