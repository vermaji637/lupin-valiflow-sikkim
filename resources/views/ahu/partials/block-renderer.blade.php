@php
    $type = $block['type'] ?? 'text';
@endphp

@if($type === 'heading')
    @php $lvl = $block['level'] ?? 3; @endphp
    @if($lvl == 2) <h2>{{ $block['text'] ?? '' }}</h2>
    @elseif($lvl == 3) <h3>{{ $block['text'] ?? '' }}</h3>
    @elseif($lvl == 5) <h5>{{ $block['text'] ?? '' }}</h5>
    @else <h3>{{ $block['text'] ?? '' }}</h3>
    @endif

@elseif($type === 'paragraph')
    <div style="margin-bottom:12px;">
        @if(isset($block['text']))
            <p>{{ $block['text'] }}</p>
        @elseif(isset($block['content']) && is_array($block['content']))
            <div class="row-flex">
                @foreach($block['content'] as $item)
                    @include('ahu.partials.element', ['el' => $item, 'schema' => $schema, 'documents' => $documents, 'users' => $users, 'mode' => $mode])
                @endforeach
            </div>
        @endif
    </div>

@elseif($type === 'table')
    <table>
        @foreach(($block['rows'] ?? []) as $row)
            @if(($row['type'] ?? '') === 'header')
                <tr>
                    @foreach(($row['cells'] ?? []) as $cell)
                        <th style="{{ $cell['style'] ?? '' }}">{{ $cell['text'] ?? '' }}</th>
                    @endforeach
                </tr>
            @else
                <tr>
                    @foreach(($row['cells'] ?? []) as $cell)
                        <td style="{{ $cell['style'] ?? '' }}" class="{{ $cell['class'] ?? '' }}"
                            @if(isset($cell['rowspan'])) rowspan="{{ $cell['rowspan'] }}" @endif
                            @if(isset($cell['colspan'])) colspan="{{ $cell['colspan'] }}" @endif
                        >
                            @if(isset($cell['content']) && is_array($cell['content']))
                                @foreach($cell['content'] as $el)
                                    @include('ahu.partials.element', ['el' => $el, 'schema' => $schema, 'documents' => $documents, 'users' => $users, 'mode' => $mode])
                                @endforeach
                            @elseif(isset($cell['text']))
                                {{ $cell['text'] }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endif
        @endforeach
    </table>

@elseif($type === 'list')
    @php $style = $block['style'] ?? 'ul'; @endphp
    @if($style === 'ol' || $style === 'ol_fields')
        <ol>
            @foreach(($block['items'] ?? []) as $item)
                <li>
                    @if(is_string($item))
                        {{ $item }}
                    @elseif(is_array($item) && isset($item['field']))
                        @include('ahu.partials.element', ['el' => ['type'=>'field'] + $item['field'], 'schema' => $schema, 'documents' => $documents, 'users' => $users, 'mode' => $mode])
                    @else
                        {{ json_encode($item) }}
                    @endif
                </li>
            @endforeach
        </ol>
    @else
        <ul>
            @foreach(($block['items'] ?? []) as $item)
                <li>
                    @if(is_string($item))
                        {{ $item }}
                    @elseif(is_array($item) && ($item['type'] ?? '') === 'field')
                        @include('ahu.partials.element', ['el' => $item, 'schema' => $schema, 'documents' => $documents, 'users' => $users, 'mode' => $mode])
                    @else
                        {{ json_encode($item) }}
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

@elseif($type === 'submit')
    <button type="submit" class="btn">{{ $block['text'] ?? 'Submit' }}</button>

@else
    {{-- fallback --}}
    <pre>{{ json_encode($block, JSON_PRETTY_PRINT) }}</pre>
@endif
