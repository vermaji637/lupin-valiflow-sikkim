<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Form Templates</title>
</head>
<body>
<h2>Form Templates</h2>

@if(session('success'))
    <p style="color:green">{{ session('success') }}</p>
@endif

<table border="1" cellpadding="8" cellspacing="0">
    <tr>
        <th>ID</th>
        <th>Key</th>
        <th>Action</th>
    </tr>
    @foreach($templates as $t)
        <tr>
            <td>{{ $t->id }}</td>
            <td>{{ $t->form_key }}</td>
            <td>
                <a href="{{ route('admin.form-templates.edit', $t) }}">Edit JSON</a>
            </td>
        </tr>
    @endforeach
</table>

</body>
</html>
