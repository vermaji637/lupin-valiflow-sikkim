<?php
// app/Http/Controllers/Admin/FormTemplateController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FormTemplate;
use Illuminate\Http\Request;


class FormTemplateController extends Controller
{
    public function index()
    { 
        $templates = FormTemplate::orderBy('form_key')->get();
        
        
        return view('form_templates.index', compact('templates'));
    }

    public function edit(FormTemplate $formTemplate)
    {     $schema = $formTemplate->schema();
        $json = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        return view('form_templates.edit', [
            'template' => $formTemplate,
            'json' => $json,
        ]);
    }

  

    public function update(Request $request, FormTemplate $formTemplate)
    {
        $request->validate([
            'schema_json' => ['required', 'string'],
        ]);

        $decoded = json_decode($request->schema_json, true);

        if (!is_array($decoded)) {
            return back()
                ->withInput()
                ->withErrors(['schema_json' => 'Invalid JSON. Please fix JSON format.']);
        }

        $formTemplate->update([
            'schema_json' => $decoded, // cast saves it properly
        ]);

        return redirect()
            ->route('admin.form-templates.index')
            ->with('success', 'Template updated successfully');
    }
}
