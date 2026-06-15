@php
  $t = $item['type'] ?? 'text';
@endphp

@if($t === 'label' || $t === 'text')
  @if($mode === 'admin')
    <input class="input" name="{{ $path }}[text]" value="{{ $item['text'] ?? '' }}">
    <input type="hidden" name="{{ $path }}[type]" value="{{ $t }}">
  @else
    @if($t === 'label')
      <strong>{{ $item['text'] ?? '' }}</strong>
    @else
      {{ $item['text'] ?? '' }}
    @endif
  @endif

@elseif($t === 'field')
  @php
    $name = $item['name'] ?? '';
    $ft = $item['field_type'] ?? 'text';
  @endphp

  {{-- USER fills these --}}
  @if($ft === 'textarea')
    <textarea class="{{ $item['class'] ?? 'input' }}"
      name="{{ $name }}"
      rows="{{ $item['rows'] ?? 3 }}">{{ old($name, $reportData[$name] ?? ($item['default'] ?? '')) }}</textarea>
  @elseif($ft === 'select')
    <select class="{{ $item['class'] ?? 'input' }}" name="{{ $name }}">
      <option value="">{{ $item['options']['placeholder'] ?? 'Select' }}</option>
      @php $src = $item['options']['source'] ?? null; @endphp

      @if($src === 'users')
        @foreach($users as $u)
          <option value="{{ $u->id }}" @selected(old($name, $reportData[$name] ?? '') == $u->id)>
            {{ $u->first_name }}
          </option>
        @endforeach
      @elseif($src === 'documents')
        @foreach($documents as $d)
          <option value="{{ $d->id }}" @selected(old($name, $reportData[$name] ?? '') == $d->id)>
            {{ $d->title }}
          </option>
        @endforeach
      @endif
    </select>
  @else
    <input
      type="{{ $ft }}"
      class="{{ $item['class'] ?? 'input' }}"
      name="{{ $name }}"
      value="{{ old($name, $reportData[$name] ?? ($item['default'] ?? '')) }}"
      placeholder="{{ $item['placeholder'] ?? '' }}">
  @endif

  {{-- ADMIN can also edit default/placeholder/name etc if you want --}}
  @if($mode === 'admin')
    <div style="margin:4px 0 12px 0; font-size:12px; opacity:.7;">
      <em>Field: {{ $name }} (user fills)</em>
    </div>
  @endif

@endif
