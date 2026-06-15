@php
    $mode = $mode ?? 'user'; // FIX: prevent undefined variable
@endphp

<!doctype html>
<html>
<head>
    <title>{{ $schema['meta']['title'] ?? 'Form' }}</title>
    <style>
        body{font-family:Arial; padding:20px;}
        table{width:100%; border-collapse:collapse;}
        th,td{border:1px solid #ddd; padding:8px;}
        .input{width:100%; padding:6px;}
        .btn{padding:10px 15px; background:#0d9488; color:#fff; border:none;}
    </style>
</head>
<body>

@if(session('success'))
    <p style="color:green">{{ session('success') }}</p>
@endif

<form method="POST" action="{{ route('forms.submit', $template->form_key) }}">
    @csrf

    {{-- IMPORTANT: form only ONCE --}}
    @foreach($blocks as $block)

        @if($block['type'] === 'heading')
            <h3>{{ $block['text'] }}</h3>

        @elseif($block['type'] === 'paragraph')
            <p>{{ $block['text'] ?? '' }}</p>

        @elseif($block['type'] === 'table')
            <table>
                @foreach($block['rows'] as $row)
                    @if(($row['type'] ?? '') === 'header')
                        <tr>
                            @foreach($row['cells'] as $cell)
                                <th>{{ $cell['text'] }}</th>
                            @endforeach
                        </tr>
                    @else
                        <tr>
                            @foreach($row['cells'] as $cell)
                                <td>
                                    @if(isset($cell['text']))
                                        {{ $cell['text'] }}
                                    @endif

                                    @if(isset($cell['type']) && $cell['type'] === 'field')
                                        <input
                                            type="{{ $cell['field_type'] ?? 'text' }}"
                                            name="{{ $cell['name'] }}"
                                            class="input"
                                            value="{{ old($cell['name'], $cell['default'] ?? '') }}"
                                        >
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endif
                @endforeach
            </table>
        @endif

    @endforeach

    <br>
    <button type="submit" class="btn">Submit</button>

</form>

</body>
</html>
