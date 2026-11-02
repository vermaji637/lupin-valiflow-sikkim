<p>Hello {{ $recipientName }},</p>

@if(!empty($isSendBack))
    @if($isCreator)
        <p>Your protocol was <strong>sent back</strong> for correction. Please update it and resubmit.</p>
    @else
        <p>A protocol you previously approved has been <strong>sent back</strong> for correction.</p>
    @endif
@elseif($isCreator)
    <p>Your protocol was <strong>rejected</strong> and is now closed. It cannot be edited or resubmitted.</p>
@else
    <p>A protocol you previously approved has been <strong>rejected</strong> and closed.</p>
@endif

<p>
    <strong>Protocol:</strong> {{ $reportTitle }}<br>
    <strong>Report ID:</strong> {{ $reportId }}<br>
    <strong>Stage:</strong> {{ $stage }}<br>
    <strong>{{ !empty($isSendBack) ? 'Sent back by' : 'Rejected by' }}:</strong> {{ $rejecterName }}
</p>

<p><strong>Remark:</strong></p>
<p>{{ $remark }}</p>

<p>View the report:</p>
<p><a href="{{ $url }}">{{ $url }}</a></p>

<p>Thank you,<br>ValiFlow</p>
