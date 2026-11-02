<p>Hello {{ $recipientName }},</p>

@if(!empty($isSendBack))
    @if($isCreator)
        <p>Document <strong>{{ $documentTitle }}</strong> was <strong>sent back</strong> for correction. Please update it and resubmit.</p>
    @else
        <p>Document <strong>{{ $documentTitle }}</strong>, which you previously approved, has been <strong>sent back</strong> for correction.</p>
    @endif
@elseif($isCreator)
    <p>Document <strong>{{ $documentTitle }}</strong> was <strong>rejected</strong> and is now closed. It cannot be edited or resubmitted.</p>
@else
    <p>Document <strong>{{ $documentTitle }}</strong>, which you previously approved, has been <strong>rejected</strong> and closed.</p>
@endif

<p>
    <strong>Module:</strong> New Equipment Qualification<br>
    @if(!empty($documentNumber))
        <strong>Reference Document No.:</strong> {{ $documentNumber }}<br>
    @endif
    @if(!empty($displayCode))
        <strong>Document ID:</strong> {{ $displayCode }}<br>
    @endif
    <strong>{{ !empty($isSendBack) ? 'Sent back by' : 'Rejected by' }}:</strong> {{ $actorName }}
</p>

<p><strong>Note:</strong></p>
<p>{{ $remark }}</p>

<p>Open the document in ValiFlow:</p>
<p><a href="{{ $url }}">{{ $url }}</a></p>

<p>Thank you,<br>ValiFlow</p>
