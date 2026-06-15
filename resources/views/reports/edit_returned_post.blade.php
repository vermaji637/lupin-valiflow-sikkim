@extends('layouts.app')

@section('content')
<div class="container">

    <h3>Edit & Resubmit (Post Approval)</h3>

    <div class="alert alert-warning">
        You can update form fields and upload files.
        Table structure cannot be modified.
    </div>

    <form method="POST"
          action="{{ route('reports.resubmitReturnedPost',$report) }}"
          enctype="multipart/form-data">
        @csrf

        <div id="formBody">
            {!! $bodyHtml !!}
        </div>

        <button type="submit" class="btn btn-success mt-3">
            Resubmit For Approval
        </button>
    </form>

</div>
@endsection