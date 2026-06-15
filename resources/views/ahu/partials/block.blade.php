{{-- resources/views/ahu/partials/block.blade.php --}}
@php
  $type = $block['type'] ?? 'text';
  $mode = $mode ?? 'user';
  $isDesigner = ($mode === 'designer');

@endphp

@if($type === 'heading')
  @php $lvl = $block['level'] ?? 3; @endphp
  @if($lvl == 2) <h2>{{ $block['text'] ?? '' }}</h2>
  @elseif($lvl == 3) <h3>{{ $block['text'] ?? '' }}</h3>
  @elseif($lvl == 5) <h5>{{ $block['text'] ?? '' }}</h5>
  @elseif($lvl == 6) <h6>{{ $block['text'] ?? '' }}</h6>
  @else <h3>{{ $block['text'] ?? '' }}</h3>
  @endif

@elseif($type === 'paragraph')
  <div style="margin-bottom:12px;">
    @if(isset($block['text']))
      <p>{{ $block['text'] }}</p>
    @elseif(isset($block['content']) && is_array($block['content']))
      <div class="d-flex gap-2 flex-wrap">
        @foreach($block['content'] as $item)
          @include('ahu.partials.element', ['el' => $item, 'schema' => $schema ?? [], 'documents' => $documents ?? [], 'users' => $users ?? [], 'mode' => $mode, 'values' => $values ?? []])
        @endforeach
      </div>
    @endif
  </div>

@elseif($type === 'table')
  <table class="table table-bordered">
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
                  @include('ahu.partials.element', ['el' => $el, 'schema' => $schema ?? [], 'documents' => $documents ?? [], 'users' => $users ?? [], 'mode' => $mode, 'values' => $values ?? []])
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

@elseif($type === 'repeat_table')
  @php
    $columns = $block['columns'] ?? [];
    $rowSchema = $block['row_schema'] ?? [];
    $defaultRows = $block['default_rows'] ?? [];
    $key = $block['key'] ?? 'repeat';
  @endphp

  <div class="table-responsive">
    <table class="table table-bordered">
      <thead>
        <tr>
          @foreach($columns as $c)
            <th>{{ $c }}</th>
          @endforeach
        </tr>
      </thead>
      <tbody>
        @if(count($defaultRows))
          @foreach($defaultRows as $rIndex => $row)
            <tr>
              @foreach(array_keys($rowSchema) as $fieldKey)
                <td>
                  @php
                    $ft = data_get($rowSchema, $fieldKey.'.type', 'text');
                    $cls = data_get($rowSchema, $fieldKey.'.class', 'input');
                    $name = $key.'['.$rIndex.']['.$fieldKey.']';
                    $val = $row[$fieldKey] ?? data_get($rowSchema, $fieldKey.'.default', '');
                  @endphp

                  @if($isDesigner)
                    <span class="badge bg-light text-dark">{{ $fieldKey }}</span>
                  @else
                    @if($ft === 'date')
                      <input type="date" class="form-control {{ $cls }}" name="{{ $name }}" value="{{ $val }}">
                    @elseif($ft === 'checkbox')
                      <input type="checkbox" name="{{ $name }}" value="1" @checked((bool)$val)>
                    @else
                      <input type="text" class="form-control {{ $cls }}" name="{{ $name }}" value="{{ $val }}">
                    @endif
                  @endif
                </td>
              @endforeach
            </tr>
          @endforeach
        @else
          <tr>
            <td colspan="{{ max(count($columns),1) }}" class="text-muted">No default rows</td>
          </tr>
        @endif
      </tbody>
    </table>
  </div>

@elseif($type === 'list')
  @php $style = $block['style'] ?? 'ul'; @endphp
  @if($style === 'ol' || $style === 'ol_fields')
    <ol>
      @foreach(($block['items'] ?? []) as $item)
        <li>
          @if(is_string($item))
            {{ $item }}
          @elseif(is_array($item) && isset($item['field']))
            @include('ahu.partials.element', ['el' => ['type'=>'field'] + $item['field'], 'schema' => $schema ?? [], 'documents' => $documents ?? [], 'users' => $users ?? [], 'mode' => $mode, 'values' => $values ?? []])
          @elseif(is_array($item) && ($item['type'] ?? '') === 'field')
            @include('ahu.partials.element', ['el' => $item, 'schema' => $schema ?? [], 'documents' => $documents ?? [], 'users' => $users ?? [], 'mode' => $mode, 'values' => $values ?? []])
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
            @include('ahu.partials.element', ['el' => $item, 'schema' => $schema ?? [], 'documents' => $documents ?? [], 'users' => $users ?? [], 'mode' => $mode, 'values' => $values ?? []])
          @else
            {{ json_encode($item) }}
          @endif
        </li>
      @endforeach
    </ul>
  @endif

@elseif($type === 'submit')
  <button type="submit" class="btn btn-primary">{{ $block['text'] ?? 'Submit' }}</button>

@else
  <pre>{{ json_encode($block, JSON_PRETTY_PRINT) }}</pre>
@endif
