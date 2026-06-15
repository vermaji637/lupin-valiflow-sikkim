@extends('layouts.app')

@section('content')
<a class="" href="{{ route('download.pdf', $report->id) }}">    
    <button  class="btn btn-primary pull-right" style=" float:right; margin-bottom:10px">
        Download PDF
    </button>
</a>

<div class="container">
  <h2>Report #{{ $report->id }} - {{ $template->name }}</h2>
  <div class="border p-3">{!! $bodyHtml !!}</div>
</div>

@php 
$currentStep = $report->approvalSteps
    ->where('step_order', $report->current_step_order)
    ->first();

$isMyTurn = $currentStep && $currentStep->user_id == auth()->id();
//dd($isMyTurn );
@endphp

@if($isMyTurn && $report->status === 'pending')
    <div class="mt-4 p-3 border rounded bg-light">
        <h5>Approval Action</h5>

       

         <form method="POST" action="{{ route('reports.approve', $report) }}">
        @csrf
        <button class="btn btn-success">Approve</button>
    </form>

    <form method="POST" action="{{ route('reports.reject', $report) }}">
        @csrf
        <input name="remark" required class="form-control" placeholder="Reject remark">
        <button class="btn btn-danger mt-2">Reject</button>
    </form>
    </div>
@endif


@endsection