<p>Hello {{ $recipientName }},</p>

<p>Your protocol has been <strong>fully approved</strong>.</p>

<p>
    <strong>Protocol:</strong> {{ $reportTitle }}<br>
    <strong>Report ID:</strong> {{ $reportId }}
</p>

<p>You can view the approved report here:</p>
<p><a href="{{ $url }}">{{ $url }}</a></p>

<p>Thank you,<br>ValiFlow</p>
