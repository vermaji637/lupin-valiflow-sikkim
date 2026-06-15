@extends('layouts.app')
@section('content')
<div style="text-align:center; padding:80px 20px; max-width:480px; margin:0 auto;">

    <div style="font-size:52px; margin-bottom:20px;">⏳</div>

    <h2 style="font-size:22px; font-weight:600; margin-bottom:10px; color:#1a1a1a;">
        Processing is taking longer than usual
    </h2>

    <p style="color:#666; font-size:15px; line-height:1.6; margin-bottom:32px;">
        {{ $message ?? 'The document is being processed. Please wait a moment and try again.' }}
    </p>

    <a href="{{ url()->previous() }}"
       style="display:inline-block; padding:10px 24px; background:#1a73e8;
              color:white; border-radius:6px; text-decoration:none;
              font-size:14px; margin-right:10px;">
        Go Back
    </a>

    <button onclick="location.reload()"
            style="padding:10px 24px; background:#f1f3f4; color:#333;
                   border:none; border-radius:6px; cursor:pointer; font-size:14px;">
        Try Again
    </button>

</div>
@endsection
