@php
  $mode = $mode ?? 'user'; // 'admin' or 'user'
  //print_r($schema);
@endphp

@foreach(($schema['form']['blocks'] ?? []) as $bIndex => $block)
  @include('ahu.partials.block', [
      'block' => $block,
      'path'  => "schema[form][blocks][$bIndex]",
      'mode'  => $mode,
      'documents' => $documents ?? [],
      'users' => $users ?? []
  ])
@endforeach
