<p>Hello {{ $recipientName }},</p>

<p>{{ $bodyText }}</p>

<p>
    <strong>Module:</strong> New Equipment Qualification<br>
    @if(!empty($documentNumber))
        <strong>Reference Document No.:</strong> {{ $documentNumber }}<br>
    @endif
    @if(!empty($displayCode))
        <strong>Document ID:</strong> {{ $displayCode }}<br>
    @endif
    @if(!empty($roleLabel))
        <strong>Your role:</strong> {{ $roleLabel }}<br>
    @endif
</p>

<p>Please sign in to ValiFlow to review and approve this document:</p>
<p><a href="{{ $url }}">{{ $url }}</a></p>

<p>Thank you,<br>ValiFlow</p>
