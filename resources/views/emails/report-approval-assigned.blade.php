<p>Hello {{ $recipientName }},</p>

<p>{{ $bodyText }}</p>

<p>
    <strong>Protocol:</strong> {{ $reportTitle }}<br>
    <strong>Report ID:</strong> {{ $reportId }}
</p>

<p>Please log in to ValiFlow and review the report:</p>
<p><a href="{{ $url }}">{{ $url }}</a></p>

<p>Thank you,<br>ValiFlow</p>
