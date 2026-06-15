<?php
// app/Http/Controllers/AhuFormController.php
namespace App\Http\Controllers;

use App\Models\FormTemplate;
use App\Models\FormSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AhuFormController extends Controller
{
    // ADMIN: designer
    public function designer(string $form_key)
    {
        $template = FormTemplate::where('form_key', $form_key)->firstOrFail();
       
        $schema = $template->schema();
         $documents = \App\Models\Document::select('id','title')->get();
    $users = \App\Models\User::select('id','first_name')->get();

    return view('ahu.designer', compact('template','schema','documents','users'));
       //dd($template);
       // return view('ahu.designer', compact('template','schema'));
    }

    // ADMIN: save schema_json
    public function save(Request $request, string $form_key)
    {
        $template = FormTemplate::where('form_key', $form_key)->firstOrFail();

        $decoded = json_decode($request->input('schema_json'), true);
        if (!is_array($decoded)) {
            return back()->with('error', 'Invalid JSON schema');
        }

        $template->update([
            'schema_json' => $decoded,
            'title' => data_get($decoded, 'meta.title', $template->title),
        ]);

        return back()->with('success', 'Template saved successfully!');
    }

    // USER: show form
    public function show($template_key)
    {
        $template = FormTemplate::where('form_key', $template_key)->firstOrFail();
       

        
        $schema = ($template->schema_json);

        $documents = \App\Models\Document::query()->get();
        $users = \App\Models\User::query()->get();

        // for edit mode: load latest submission
        $submission = FormSubmission::where('template_key', $template_key)
            ->where('user_id', Auth::id())
            ->latest()
            ->first();

        $values = $submission?->values ?? [];

        return view('ahu.form', compact('template', 'schema', 'documents', 'users', 'values'));

        //return view('ahu.form', compact('template','schema','documents','users'));
    }

    // USER: submit form -> save values_json
    public function submit(Request $request, string $form_key)
    {
        $template = FormTemplate::where('form_key', $form_key)->firstOrFail();

        // values are posted with field names
        $values = $request->except(['_token']);

        $submission = FormSubmission::create([
            'form_template_id' => $template->id,
            'user_id' => Auth::id(),
            'status' => 'submitted',
            'values_json' => $values,
        ]);

        return redirect()->route('forms.submission', $submission->id)
            ->with('success', 'Submitted!');
    }

    // view saved submission merged with template
    public function viewSubmission(int $id)
    {
         $submission = FormSubmission::with('template')->findOrFail($id);
    $template = $submission->template;

    $schema = $template->schema_json;
    $documents = \App\Models\Document::select('id','title')->get();
    $users = \App\Models\User::select('id','first_name')->get();

    $values = $submission->values_json ?? [];

    return view('ahu.form', compact('template','schema','documents','users','values','submission'));
       } 

    



}
