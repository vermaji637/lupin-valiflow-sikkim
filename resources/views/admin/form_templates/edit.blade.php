<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Edit Template: {{ $template->form_key }}</title>
</head>
<body>

<h2>Edit Template: {{ $template->form_key }}</h2>

@if($errors->any())
    <div style="color:red">
        <ul>
            @foreach($errors->all() as $e)
                <li>{{ $e }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('admin.form-templates.update', $template) }}">
    @csrf
    @method('PUT')

    <textarea name="schema_json" rows="40" style="width:100%">{{ old('schema_json', $json) }}</textarea>

    <button type="submit">Save</button>
    <a href="{{ route('admin.form-templates.index') }}">Back</a>
</form>

</body>
</html>
