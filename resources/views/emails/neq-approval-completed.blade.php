<p>Hello {{ $recipientName }},</p>

<p>Document <strong>{{ $documentTitle }}</strong> has been <strong>fully approved</strong>.</p>

<p>
    <strong>Module:</strong> New Equipment Qualification<br>
    @if(!empty($documentNumber))
        <strong>Reference Document No.:</strong> {{ $documentNumber }}<br>
    @endif
    @if(!empty($displayCode))
        <strong>Document ID:</strong> {{ $displayCode }}<br>
    @endif
</p>

<p>You can view the approved document here:</p>
<p><a href="{{ $url }}">{{ $url }}</a></p>

<p>Thank you,<br>ValiFlow</p>
