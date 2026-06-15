@php
  $mode = $mode ?? 'user';
  $isDesigner = ($mode === 'designer');
  echo $type = $block['type'] ?? 'text';
@endphp

@if($type === 'heading')
  @php
    $lvl = $block['level'] ?? 3;
    $path = $basePath . '.text';
  @endphp
  @if($lvl == 2)
    <h2 class="{{ $isDesigner ? 'editable-text' : '' }}" data-path="{{ $path }}" @if($isDesigner) contenteditable="true" @endif>
      {{ $block['text'] ?? '' }}
    </h2>
  @elseif($lvl == 3)
    <h3 class="{{ $isDesigner ? 'editable-text' : '' }}" data-path="{{ $path }}" @if($isDesigner) contenteditable="true" @endif>
      {{ $block['text'] ?? '' }}
    </h3>
  @else
    <h5 class="{{ $isDesigner ? 'editable-text' : '' }}" data-path="{{ $path }}" @if($isDesigner) contenteditable="true" @endif>
      {{ $block['text'] ?? '' }}
    </h5>
  @endif

@elseif($type === 'paragraph')
  @if(isset($block['text']))
    @php $path = $basePath.'.text'; @endphp
    <p class="{{ $isDesigner ? 'editable-text' : '' }}" data-path="{{ $path }}" @if($isDesigner) contenteditable="true" @endif>
      {{ $block['text'] }}
    </p>
  @elseif(isset($block['content']) && is_array($block['content']))
    <div class="d-flex gap-2 flex-wrap">
      @foreach($block['content'] as $ci => $node)
        @include('ahu.partials.render-node', [
          'node' => $node,
          'basePath' => $basePath.'.content.'.$ci,
          'mode' => $mode,
          'values' => $values ?? [],
          'schema' => $schema ?? [],
          'documents' => $documents ?? [],
          'users' => $users ?? []
        ])
      @endforeach
    </div>
  @endif

  @elseif($type === 'label')
  @php $path = $basePath.'.text'; @endphp
  <div class="mb-1 fw-bold {{ $isDesigner ? 'editable-text' : '' }}"
       data-path="{{ $path }}"
       @if($isDesigner) contenteditable="true" @endif>
    {{ $block['text'] ?? '' }}
  </div>
@elseif($type === 'group')
  @php
    $key = $block['key'] ?? '';
    $content = $block['content'] ?? [];

    // helper: find node by name
    $findByName = function($name) use ($content) {
      foreach ($content as $n) {
        if (($n['type'] ?? '') === 'field' && ($n['name'] ?? '') === $name) return $n;
      }
      return null;
    };
  @endphp

  {{-- Special layout for history cards --}}
  @if(in_array($key, ['history_card_1','history_card_2']))
    @php
      $fromName = $key === 'history_card_1' ? 'history_from_date_1' : 'history_from_date_2';
      $toName   = $key === 'history_card_1' ? 'history_to_date_1'   : 'history_to_date_2';
      $revName  = $key === 'history_card_1' ? 'history_reviewed_by_1' : 'history_reviewed_by_2';
      $recName  = $key === 'history_card_1' ? 'history_recommendation_1' : 'history_recommendation_2';

      $fromNode = $findByName($fromName);
      $toNode   = $findByName($toName);
      $revNode  = $findByName($revName);
      $recNode  = $findByName($recName);
    @endphp

    <div class="history-card">

      {{-- Row 1 --}}
      <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
        <label class="fw-bold mb-0">History card from date:</label>

        @include('ahu.partials.render-node', [
          'node' => $fromNode,
          'mode' => $mode,
          'values' => $values ?? [],
          'schema' => $schema ?? [],
          'documents' => $documents ?? [],
          'users' => $users ?? []
        ])

        <span class="mx-1">to</span>

        @include('ahu.partials.render-node', [
          'node' => $toNode,
          'mode' => $mode,
          'values' => $values ?? [],
          'schema' => $schema ?? [],
          'documents' => $documents ?? [],
          'users' => $users ?? []
        ])
      </div>

      {{-- Row 2 --}}
      <div class="mb-2">
        <label class="fw-bold d-block">Reviewed by (Engineering Department)</label>
        @include('ahu.partials.render-node', [
          'node' => $revNode,
          'mode' => $mode,
          'values' => $values ?? [],
          'schema' => $schema ?? [],
          'documents' => $documents ?? [],
          'users' => $users ?? []
        ])
      </div>

      {{-- Row 3 --}}
      <div>
        <label class="fw-bold d-block">Recommendation:</label>
        @include('ahu.partials.render-node', [
          'node' => $recNode,
          'mode' => $mode,
          'values' => $values ?? [],
          'schema' => $schema ?? [],
          'documents' => $documents ?? [],
          'users' => $users ?? []
        ])
      </div>

    </div>

  @else
    {{-- Default group layout --}}
    <div class="d-flex gap-2 flex-wrap">
      @foreach(($block['content'] ?? []) as $node)
        @include('ahu.partials.render-node', [
          'node' => $node,
          'mode' => $mode,
          'values' => $values ?? [],
          'schema' => $schema ?? [],
          'documents' => $documents ?? [],
          'users' => $users ?? []
        ])
      @endforeach
    </div>
  @endif
@elseif($type === 'rich_text')
  @php
    $path = $basePath.'.html';
    $html = $node['html'] ?? '';
  @endphp

  @if($isDesigner)
    {{-- Designer: editable HTML as plain text --}}
    <div class="border rounded p-2 bg-light">
      <div class="small text-muted mb-1">RICH TEXT (HTML)</div>
      <div class="editable-text"
           data-path="{{ $path }}"
           contenteditable="true"
           style="white-space:pre-wrap; font-family:monospace;">
        {{ $html }}
      </div>
    </div>
  @else
    {{-- User: render HTML --}}
    <div class="rich-text">
      {!! $html !!}
    </div>
  @endif


@elseif($type === 'table')
 <div data-table-key="{{ $blockKey }}">
  <table class="table table-bordered">
    <tbody>
    @foreach(($block['rows'] ?? []) as $ri => $row)
      @if(($row['type'] ?? '') === 'header')
        <tr>
          @foreach(($row['cells'] ?? []) as $ci => $cell)
            @php $path = $basePath.".rows.$ri.cells.$ci.text"; @endphp
            <th>
              @if(isset($cell['content']) && is_array($cell['content']))
                @foreach($cell['content'] as $ei => $node)
                  @include('ahu.partials.render-node', [
                    'node' => $node,
                    'basePath' => $basePath.".rows.$ri.cells.$ci.content.$ei",
                    'mode' => $mode,
                    'values' => $values ?? [],
                    'schema' => $schema ?? [],
                    'documents' => $documents ?? [],
                    'users' => $users ?? []
                  ])
                @endforeach
              @else
                <span class="{{ $isDesigner ? 'editable-text' : '' }} 5"
                      data-path="{{ $path }}"
                      @if($isDesigner) contenteditable="true" @endif>
                  {{ $cell['text'] ?? '' }}
                </span>
              @endif
            </th>
          @endforeach
        </tr>
      @else
        <tr> 
          @foreach(($row['cells'] ?? []) as $ci => $cell)
         
            <td>
              @if(isset($cell['content']) && is_array($cell['content']))
                @foreach($cell['content'] as $ei => $node)
                  @include('ahu.partials.render-node', [
                    'node' => $node,
                    'basePath' => $basePath.".rows.$ri.cells.$ci.content.$ei",
                    'mode' => $mode,
                    'values' => $values ?? [],
                    'schema' => $schema ?? [],
                    'documents' => $documents ?? [],
                    'users' => $users ?? []
                  ])
                @endforeach
                @elseif(isset($cell['type']) && in_array($cell['type'], ['field','group','text','label','image_ref']))
                @include('ahu.partials.render-node', [
                  'node' => $cell,
                  'basePath' => $basePath.".rows.$ri.cells.$ci",
                  'mode' => $mode,
                  'values' => $values ?? [],
                  'schema' => $schema ?? [],
                  'documents' => $documents ?? [],
                  'users' => $users ?? []
                ])
              @else
                @php $path = $basePath.".rows.$ri.cells.$ci.text"; @endphp
                <span class="{{ $isDesigner ? 'editable-text' : '' }} 8"
                      data-path="{{ $path }}"
                      @if($isDesigner) contenteditable="true" @endif>
                  {{ $cell['text'] ?? '' }}
                </span>
              @endif
            </td>
          @endforeach
        </tr>
      @endif
    @endforeach
    </tbody>
  </table>
  </div>
@elseif($type === 'repeat_table')
  @php
    $columns = $block['columns'] ?? [];
    $rowSchema = $block['row_schema'] ?? [];
    $schemaKeys = array_keys($rowSchema);
    $defaultRows = $block['default_rows'] ?? [];
  @endphp
  <div data-repeat-key="{{ $blockKey }}">
  <table class="table table-bordered">
    <thead>
      <tr>
        @foreach($columns as $ci => $label)
          @php $path = $basePath.".columns.$ci"; @endphp
          <th>
            <span class="{{ $isDesigner ? 'editable-text' : '' }}"
                  data-path="{{ $path }}"
                  @if($isDesigner) contenteditable="true" @endif>
              {{ $label }}
            </span>
          </th>
        @endforeach
      </tr>
    </thead>

    <tbody>
      @foreach($defaultRows as $ri => $r)
        <tr>
          @foreach($schemaKeys as $ci => $fieldKey)
            @php $path = $basePath.".default_rows.$ri.$fieldKey"; @endphp
            <td>
              <span class="{{ $isDesigner ? 'editable-text' : '' }}"
                    data-path="{{ $path }}"
                    @if($isDesigner) contenteditable="true" @endif>
                {{ $r[$fieldKey] ?? '' }}
              </span>
            </td>
          @endforeach
        </tr>
      @endforeach

      @if(empty($defaultRows))
        <tr>
          <td colspan="{{ max(count($columns),1) }}" class="text-muted">
            No default rows. Use + Row.
          </td>
        </tr>
      @endif
    </tbody>
  </table>
</div>
@elseif($type === 'list')
  @php
    $style = $block['style'] ?? 'ul';
    $tag = ($style === 'ol' || $style === 'ol_fields') ? 'ol' : 'ul';
  @endphp
<div data-list-key="{{ $blockKey }}">
  @if($tag === 'ol')
    <ol>
      @foreach(($block['items'] ?? []) as $ii => $item)
        <li>
          @if(is_string($item))
            @php $path = $basePath.".items.$ii"; @endphp
            <span class="{{ $isDesigner ? 'editable-text' : '' }}"
                  data-path="{{ $path }}"
                  @if($isDesigner) contenteditable="true" @endif>
              {{ $item }}
            </span>
          @elseif(is_array($item) && isset($item['field']))
            @include('ahu.partials.render-node', [
              'node' => ['type'=>'field'] + $item['field'],
              'basePath' => $basePath.".items.$ii.field",
              'mode' => $mode,
              'values' => $values ?? [],
              'schema' => $schema ?? [],
              'documents' => $documents ?? [],
              'users' => $users ?? []
            ])
          @else
            <pre>{{ json_encode($item, JSON_PRETTY_PRINT) }}</pre>
          @endif
        </li>
      @endforeach
    </ol>
  @else
    <ul>
      @foreach(($block['items'] ?? []) as $ii => $item)
        <li>
          @if(is_string($item))
            @php $path = $basePath.".items.$ii"; @endphp
            <span class="{{ $isDesigner ? 'editable-text' : '' }}"
                  data-path="{{ $path }}"
                  @if($isDesigner) contenteditable="true" @endif>
              {{ $item }}
            </span>
          @elseif(is_array($item) && isset($item['field']))
            @include('ahu.partials.render-node', [
              'node' => ['type'=>'field'] + $item['field'],
              'basePath' => $basePath.".items.$ii.field",
              'mode' => $mode,
              'values' => $values ?? [],
              'schema' => $schema ?? [],
              'documents' => $documents ?? [],
              'users' => $users ?? []
            ])
          @else
            <pre>{{ json_encode($item, JSON_PRETTY_PRINT) }}</pre>
          @endif
        </li>
      @endforeach
    </ul>
  @endif
</div>
@elseif($type === 'submit')
  <button type="{{ $isDesigner ? 'button' : 'submit' }}" class="btn btn-primary">
    {{ $block['text'] ?? 'Submit' }}
  </button>

@else
  <pre>{{ json_encode($block, JSON_PRETTY_PRINT) }}</pre>
@endif
