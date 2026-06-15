{{-- resources/views/admin/form_template_edit.blade.php --}}
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Edit Template</title>
    <style>
        textarea{width:100%;height:75vh;font-family:monospace}
    </style>
</head>
<body>
<h2>Edit Template: {{ $template->form_key }}</h2>

@if(session('status')) <p style="color:green">{{ session('status') }}</p> @endif
@if($errors->any())
    <ul style="color:red">
        @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
    </ul>
@endif

<form method="POST" action="{{ route('admin.form_templates.update', $template->form_key) }}">
    @csrf
    <textarea name="schema_json">{{ old('schema_json', $template->schema_json) }}</textarea>
    <br><br>
    <button type="submit">Save</button>
</form>
</body>
</html>
