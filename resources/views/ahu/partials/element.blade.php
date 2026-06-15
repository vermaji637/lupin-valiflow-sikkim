{{-- resources/views/ahu/partials/element.blade.php --}}
@php
  $type = $el['type'] ?? 'text';
  $mode = $mode ?? 'user';
  $isDesigner = ($mode === 'designer');
  $values = $values ?? [];
@endphp

@if($type === 'label')
  <strong style="margin-right:6px;">{{ $el['text'] ?? '' }}</strong>

@elseif($type === 'text')
  {{-- make it editable in designer if you want --}}
  @php
    // optional: if element is rendered via render-node (data-path), keep that system.
  @endphp
  <span>{{ $el['text'] ?? '' }}</span>

@elseif($type === 'image_ref')
  @php
    $ref = $el['ref'] ?? '';
    $img = null;
    if($ref === 'meta.logo') $img = $schema['meta']['logo'] ?? null;
  @endphp
  @if($img)
    <img src="{{ $img['src'] }}" alt="{{ $img['alt'] ?? '' }}" style="height:80px;width:auto;">
  @endif

@elseif($type === 'group')
  <div>
    @foreach(($el['content'] ?? []) as $child)
      <div style="margin-bottom:8px;">
        @include('ahu.partials.element', ['el' => $child, 'schema' => $schema ?? [], 'documents' => $documents ?? [], 'users' => $users ?? [], 'mode' => $mode, 'values' => $values])
      </div>
    @endforeach
  </div>

@elseif($type === 'field')
  @php
    $name = $el['name'] ?? '';
    $fieldType = $el['field_type'] ?? 'text';
    $class = $el['class'] ?? 'input';
    $placeholder = $el['placeholder'] ?? '';
    $default = $el['default'] ?? '';
    $rows = $el['rows'] ?? 3;
    $style = $el['style'] ?? '';
    $val = $name !== '' ? ($values[$name] ?? $default) : $default;
  @endphp

  @if($isDesigner)
    <span class="badge bg-light text-dark">FIELD: {{ $name }} ({{ $fieldType }})</span>
  @else
    @if($fieldType === 'textarea')
      <textarea name="{{ $name }}" class="form-control {{ $class }}" rows="{{ $rows }}" style="{{ $style }}" placeholder="{{ $placeholder }}">{{ old($name, $val) }}</textarea>
    @elseif($fieldType === 'date')
      <input type="date" name="{{ $name }}" class="form-control {{ $class }}" value="{{ old($name, $val) }}"/>
    @elseif($fieldType === 'checkbox')
      <input type="checkbox" name="{{ $name }}" value="1" @checked((bool)old($name, $val)) />
    @elseif($fieldType === 'select')
      @php
        $source = $el['options']['source'] ?? null;
        $ph = $el['options']['placeholder'] ?? 'Select';
        $list = [];

        if($source === 'users') $list = $users ?? [];
        if($source === 'documents') $list = $documents ?? [];

        $valueKey = 'id';
        $labelKey = ($source === 'users') ? 'first_name' : 'title';
      @endphp
      <select name="{{ $name }}" class="form-control {{ $class }}">
        <option value="">{{ $ph }}</option>
        @foreach($list as $item)
          <option value="{{ $item->$valueKey }}" @selected(old($name) == $item->$valueKey)>
            {{ $item->$labelKey }}
          </option>
        @endforeach
      </select>
    @else
      <input type="text" name="{{ $name }}" class="form-control {{ $class }}" value="{{ old($name, $val) }}" placeholder="{{ $placeholder }}"/>
    @endif
  @endif
@endif
