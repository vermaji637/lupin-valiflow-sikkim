@php
  $mode = $mode ?? 'user';
  $isDesigner = ($mode === 'designer');
  $type = $node['type'] ?? 'text';
@endphp

@if($type === 'text')
  @php $path = $basePath.'.text'; @endphp
  <span class="{{ $isDesigner ? 'editable-text' : '' }}"
        data-path="{{ $path }}"
        @if($isDesigner) contenteditable="true" @endif>
    {{ $node['text'] ?? '' }}
  </span>

@elseif($type === 'label')
  @php $path = $basePath.'.text'; @endphp
  <strong class="{{ $isDesigner ? 'editable-text' : '' }}"
          data-path="{{ $path }}"
          @if($isDesigner) contenteditable="true" @endif>
    {{ $node['text'] ?? '' }}
  </strong>

@elseif($type === 'image_ref')
  @php
    $ref = $node['ref'] ?? '';
    $img = data_get($schema ?? [], $ref);
    $style = $img['style'] ?? [];
    $styleStr = '';
    if(is_array($style)){
      foreach($style as $k=>$v){ $styleStr .= $k.':'.$v.';'; }
    }
  @endphp
  @if(isset($img['src']))
    <img src="{{ $img['src'] }}"
         alt="{{ $img['alt'] ?? '' }}"
         style="{{ $styleStr ?: 'height:80px;width:auto;' }}">
  @endif

@elseif($type === 'group')
  <div class="d-flex gap-2 flex-wrap align-items-center">
    @foreach(($node['content'] ?? []) as $i => $child)
      @include('ahu.partials.render-node', [
        'node' => $child,
        'basePath' => $basePath.'.content.'.$i,
        'mode' => $mode,
        'values' => $values ?? [],
        'schema' => $schema ?? [],
        'documents' => $documents ?? [],
        'users' => $users ?? []
      ])
    @endforeach
  </div>

@elseif($type === 'field')
  @php 
    $name = $node['name'] ?? '';
     $ft = $node['field_type'] ?? 'text';

    $default = $node['default'] ?? '';
    $val = $name !== '' ? (data_get($values ?? [], $name, $default)) : $default;

    $class = $node['class'] ?? 'form-control';
    $style = $node['style'] ?? null;
    $placeholder = $node['placeholder'] ?? null;

    // build inline style string safely
    $styleAttr = $style ? 'style="'.$style.'"' : '';

    // select options from datasources
    $optionsSource = data_get($node, 'options.source');
    $optionsPlaceholder = data_get($node, 'options.placeholder', '--Select--');

    $optionsList = [];
    if($optionsSource === 'documents') {
      $optionsList = $documents ?? [];
    } elseif($optionsSource === 'users') {
      $optionsList = $users ?? [];
    }

    // labels for select
    $defaultLabel = ($optionsSource === 'users') ? 'first_name' : 'title';
    $optValueKey = data_get($schema, "datasources.$optionsSource.value", 'id');
    $optLabelKey = data_get($schema, "datasources.$optionsSource.label", $defaultLabel);
  @endphp

  @if($isDesigner)
    <span class="badge bg-light text-dark">
      FIELD: {{ $name }} ({{ $ft }})
    </span>
  @else
 
    @if($ft === 'textarea')
   
      <textarea name="{{ $name }}"
                rows="{{ $node['rows'] ?? 3 }}"
                class="{{ $class }}"
                {!! $styleAttr !!}
                @if($placeholder) placeholder="{{ $placeholder }}" @endif
      >{{ $val }}</textarea>

    @elseif($ft === 'date')
      <input type="date"
             name="{{ $name }}"
             value="{{ $val }}"
             class="{{ $class }}"
             {!! $styleAttr !!}
             @if($placeholder) placeholder="{{ $placeholder }}" @endif
      >

    @elseif($ft === 'checkbox')
      <input type="checkbox"
             name="{{ $name }}"
             value="1"
             @checked((bool)$val)
      >

    @elseif($ft === 'select')
      <select name="{{ $name }}" class="{{ $class }}" {!! $styleAttr !!}>
        <option value="">{{ $optionsPlaceholder }}</option>

        @foreach($optionsList as $opt)
          @php
            $ov = data_get($opt, $optValueKey);
            $ol = data_get($opt, $optLabelKey);
          @endphp
          <option value="{{ $ov }}" @selected((string)$val === (string)$ov)>{{ $ol }}</option>
        @endforeach
      </select>

    @else
      <input type="text"
             name="{{ $name }}"
             value="{{ $val }}"
             class="{{ $class }}"
             {!! $styleAttr !!}
             @if($placeholder) placeholder="{{ $placeholder }}" @endif
      >
    @endif

  @endif

@else
  {{-- fallback --}}
  <pre>{{ json_encode($node, JSON_PRETTY_PRINT) }}</pre>
@endif
