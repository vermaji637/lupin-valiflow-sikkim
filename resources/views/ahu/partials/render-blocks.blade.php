@php $mode = $mode ?? 'user'; @endphp

@foreach(($blocks ?? []) as $i => $block)
  @php
    $type = $block['type'] ?? 'text';
    $blockKey = $block['key'] ?? ('missing_key_'.$i);
    $basePath = "form.blocks.$i";
    $isDesigner = ($mode === 'designer');
  @endphp

  <div class="border rounded p-2 mb-3">

    @if($isDesigner)
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="text-muted small">{{ $type }} key: <b>{{ $blockKey }}</b></div>

        <div class="d-flex gap-1 flex-wrap">

          @if($type === 'table')
            <button type="button" class="btn btn-sm btn-outline-primary"
              onclick="designerTableAddRow('{{ $blockKey }}');return false;">+ Row</button>

            <button type="button" class="btn btn-sm btn-outline-primary"
              onclick="designerTableAddColumn('{{ $blockKey }}');return false;">+ Col</button>

            <button type="button" class="btn btn-sm btn-outline-danger"
              onclick="designerTableDeleteRow('{{ $blockKey }}');return false;">Del Row</button>

            <button type="button" class="btn btn-sm btn-outline-danger"
              onclick="designerTableDeleteColumn('{{ $blockKey }}');return false;">Del Col</button>
          @endif

          @if($type === 'repeat_table')
            <button type="button" class="btn btn-sm btn-outline-primary"
              onclick="designerRepeatAddDefaultRow('{{ $blockKey }}');return false;">+ Row</button>

            <button type="button" class="btn btn-sm btn-outline-primary"
              onclick="designerRepeatAddColumn('{{ $blockKey }}');return false;">+ Col</button>

            <button type="button" class="btn btn-sm btn-outline-danger"
              onclick="designerRepeatDeleteDefaultRow('{{ $blockKey }}');return false;">Del Row</button>

            <button type="button" class="btn btn-sm btn-outline-danger"
              onclick="designerRepeatDeleteColumn('{{ $blockKey }}');return false;">Del Col</button>
          @endif

          @if($type === 'list')
            <button type="button" class="btn btn-sm btn-outline-primary"
              onclick="designerListAddItem('{{ $blockKey }}');return false;">+ Item</button>
          @endif

        </div>
      </div>
    @endif

    @include('ahu.partials.render-block', [
      'block' => $block,
      'schema' => $schema ?? [],
      'documents' => $documents ?? [],
      'users' => $users ?? [],
      'mode' => $mode,
      'values' => $values ?? [],
      'basePath' => $basePath,
      'blockKey' => $blockKey, // ✅ add this
    ])

  </div>
@endforeach
