<!DOCTYPE html>
<html>
<head>
    <title>Form Render</title>
</head>
<body>

<h2>{{ $schema['header']['title_main'] }}</h2>
<p>{{ $schema['header']['title_company'] }}</p>

<hr>

@foreach($schema['sections'] as $section)

    @if(!empty($section['title']))
        <h3>{{ $section['title'] }}</h3>
    @endif

    {{-- TEXT TYPE --}}
    @if($section['type'] === 'text')
        @foreach($section['paragraphs'] as $p)
            <p>{{ $p }}</p>
        @endforeach
    @endif

    {{-- TABLE TYPE --}}
    @if($section['type'] === 'table')
        <table border="1" cellpadding="5">
            <tr>
                @foreach($section['headers'] as $header)
                    <th>{{ $header }}</th>
                @endforeach
            </tr>

            @foreach($section['rows'] as $row)
                <tr>
                    @foreach($row as $cell)
                        <td>{!! $cell !!}</td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    @endif

@endforeach


</body>
</html>
