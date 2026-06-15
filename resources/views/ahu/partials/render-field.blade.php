@php
$type = $item['type'] ?? $item['field_type'] ?? 'text';
$name = $item['name'] ?? null;
$default = $item['default'] ?? '';
$class = $item['class'] ?? 'input';
@endphp

{{-- IMAGE FROM META REF --}}
@if($type === 'image_ref')
    @php
        // Example ref: meta.logo
        $ref = $item['ref'] ?? '';
        $logo = data_get($schema, str_replace('.', '.', $ref));
    @endphp

    @if($logo && isset($logo['src']))
        <img src="{{ $logo['src'] }}"
             alt="{{ $logo['alt'] ?? '' }}"
             style="
                height: {{ $logo['style']['height'] ?? '80px' }};
                width: {{ $logo['style']['width'] ?? 'auto' }};
             ">
    @endif

{{-- DIRECT IMAGE --}}
@elseif($type === 'image')
    <img src="{{ $item['src'] ?? '' }}"
         alt="{{ $item['alt'] ?? '' }}"
         style="
            height: {{ $item['style']['height'] ?? '80px' }};
            width: {{ $item['style']['width'] ?? 'auto' }};
         ">

{{-- LABEL --}}
@elseif($type === 'label')
    <strong>{{ $item['text'] ?? '' }}</strong>

{{-- PLAIN TEXT --}}
@elseif($type === 'text' && isset($item['text']) && !$name)
    {{ $item['text'] }}

@elseif($type === 'text')
    <input type="text" name="{{ $name }}" value="{{ $default }}" class="{{ $class }}">

@elseif($type === 'date')
    <input type="date" name="{{ $name }}" value="{{ $default }}" class="input-date">

@elseif($type === 'textarea')
    <textarea name="{{ $name }}" rows="{{ $item['rows'] ?? 3 }}" class="{{ $class }}">{{ $default }}</textarea>

@elseif($type === 'checkbox')
    <input type="checkbox" name="{{ $name }}" value="1" {{ $default ? 'checked' : '' }}>

@elseif($type === 'select')
    <select name="{{ $name }}" class="{{ $class }}">
        <option value="">Select</option>

        @if(isset($item['options']['source']) && $item['options']['source'] === 'users')
            @foreach($users as $user)
                <option value="{{ $user->id }}">{{ $user->first_name }}</option>
            @endforeach
        @endif

        @if(isset($item['options']['source']) && $item['options']['source'] === 'documents')
            @foreach($documents as $doc)
                <option value="{{ $doc->id }}">{{ $doc->title }}</option>
            @endforeach
        @endif
    </select>

@elseif($type === 'label')
    <strong>{{ $item['text'] }}</strong>

@elseif($type === 'text')
    {{ $item['text'] ?? '' }}

@elseif($type === 'field')
       <input type="text" name="{{ $name }}" value="{{ $default }}" class="{{ $class }}">

@endif
