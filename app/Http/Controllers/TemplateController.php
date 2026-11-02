<?php

namespace App\Http\Controllers;

use App\Models\Template;
use App\Services\DocxDownloadProtection;
use App\Services\DocxTemplateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Mews\Purifier\Facades\Purifier;

class TemplateController extends Controller
{
    private const TEMPLATE_TYPES = [
        'dq',
        'ioq',
        'pq',
        'facility',
        'pqr',
        'docx',
    ];

    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('permission:template-list', ['only' => ['index', 'show']]);
        $this->middleware('permission:template-create', ['only' => ['create', 'store']]);
        // $this->middleware('permission:template-edit', ['only' => ['edit', 'update']]);
        $this->middleware('permission:template-edit', ['only' => ['edit', 'update', 'convertDocxToHtml', 'downloadSourceDoc']]);
        $this->middleware('permission:template-delete', ['only' => ['destroy']]);
    }
    public function index()
    {
        $templates = Template::orderByDesc('id')->paginate(20);
      //  dd($templates );
        return view('templates.index', compact('templates'));
    }

   public function create(Request $request)
    {

        $type = $request->get('type', 'dq');

        $templateFiles = [
            'dq'       => 'DesignQualificationProtocol.html',
            'ioq'      => 'InstallationOperationalQualificationProtocol.html',
            'pq'       => 'PerformanceQualification.html',
            'facility' => 'FacilityQualificationProtocolReport.html',
            'pqr'      => 'PERIODICREQUALIFICATION.html',

        ];

        if ($type === 'docx') {
            $starter = file_get_contents(
                resource_path('views/templates/PERIODIC_HVAC_VALIDATION_REPORT.html')
            );
            $type = 'pqr';

            return view('templates.create', compact('starter', 'type'));
        }

        if (!array_key_exists($type, $templateFiles)) {
            abort(404, 'Invalid template type');
        }

        $starter = file_get_contents(
            resource_path('views/templates/' . $templateFiles[$type])
        );

        return view('templates.create', compact('starter', 'type'));
    }

    public function store(Request $request)
    {
        if ($request->template_type === 'docx') {
            return $this->storeDocxTemplate($request);
        }

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'template_type' => ['required', 'in:' . implode(',', self::TEMPLATE_TYPES)],
            'body_html' => ['required', 'string'],
        ]);
//dd(config('purifier.settings.template_body'));
        $clean = Purifier::clean($request->body_html, 'template_body');

        $template = Template::create([
            'name' => $request->name,
            'template_type' => $request->template_type,
            'body_html' => $clean,
            'created_by' => auth()->id(),
        ]);

        return redirect()->route('templates.edit', $template)->with('status', 'Template created');
    }

    public function edit(Template $template)
    {
        if ($template->template_type === 'docx') {
            return view('templates.edit_docx', compact('template'));
        }

        return view('templates.edit', compact('template'));
    }

    public function update(Request $request, Template $template)
    {
        if ($template->template_type === 'docx') {
            return $this->updateDocxTemplate($request, $template);
        }

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'template_type' => ['required', 'in:' . implode(',', self::TEMPLATE_TYPES)],
            'body_html' => ['required', 'string'],
        ]);

        $clean = Purifier::clean($request->body_html, 'template_body');

        $template->update([
            'name' => $request->name,
            'template_type' => $request->template_type,
            'body_html' => $clean,
        ]);

        return back()->with('status', 'Template updated');
    }

    public function destroy(Template $template)
    {
        $template->delete();
        return redirect()->route('templates.index')->with('status', 'Template deleted');
    }

    public function show(Template $template)
    {
        return redirect()->route('templates.edit', $template);
    }

    public function downloadSourceDoc(Template $template): BinaryFileResponse
    {
        abort_unless($template->template_type === 'docx', 404);
        abort_unless(!empty($template->docx_template_path), 404);

        $absolutePath = storage_path('app/public/' . ltrim((string) $template->docx_template_path, '/'));
        abort_unless(is_file($absolutePath), 404);

        $lockedPath = app(DocxDownloadProtection::class)->protectedCopy($absolutePath);

        return response()->download($lockedPath, basename($absolutePath))
            ->deleteFileAfterSend($lockedPath !== $absolutePath);
    }

    public function convertDocxToHtml(Request $request, Template $template)
    {
        abort_unless($template->template_type === 'docx', 404);

        $request->validate([
            'target_template_type' => ['nullable', 'in:dq,ioq,pq,facility,pqr'],
        ]);

        if (empty($template->docx_template_path)) {
            return back()->withErrors(['docx_file' => 'Template source file is missing.']);
        }

        try {
            $html = app(DocxTemplateService::class)->convertPublicDocToHtml((string) $template->docx_template_path);
        } catch (\Throwable $exception) {
            return back()->withErrors(['docx_file' => $exception->getMessage()]);
        }

        if (trim(strip_tags($html)) === '') {
            return back()->withErrors(['docx_file' => 'Unable to convert DOCX content to HTML.']);
        }

        $template->update([
            'template_type' => $request->input('target_template_type', 'pqr'),
            'body_html' => Purifier::clean($html, 'template_body'),
        ]);

        return redirect()
            ->route('templates.edit', $template)
            ->with('status', 'DOCX converted to HTML template successfully.');
    }

    private function storeDocxTemplate(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'template_type' => ['required', 'in:' . implode(',', self::TEMPLATE_TYPES)],
            'docx_file' => ['required', 'file', 'mimes:doc,docx'],
            'create_as_html' => ['nullable', 'in:0,1'],
            'target_template_type' => ['nullable', 'in:dq,ioq,pq,facility,pqr'],
        ]);

        Storage::disk('public')->makeDirectory('templates/docx');
        $path = $request->file('docx_file')->store('templates/docx', 'public');
        $absolutePath = storage_path('app/public/' . $path);
        $docxService = app(DocxTemplateService::class);

        $conversionFailedForDoc = false;
        try {
            $processablePath = $docxService->ensureProcessableDocxPath($absolutePath);
        } catch (\RuntimeException $exception) {
            $extension = strtolower((string) pathinfo($absolutePath, PATHINFO_EXTENSION));
            if ($extension === 'doc') {
                $conversionFailedForDoc = true;
                $processablePath = $absolutePath;
            } else {
                Storage::disk('public')->delete($path);
                return back()
                    ->withInput()
                    ->withErrors(['docx_file' => $exception->getMessage()]);
            }
        }

        if ($processablePath !== $absolutePath) {
            $publicRoot = str_replace('\\', '/', storage_path('app/public/'));
            $normalizedProcessable = str_replace('\\', '/', $processablePath);
            if (str_starts_with($normalizedProcessable, $publicRoot)) {
                $newRelativePath = ltrim(substr($normalizedProcessable, strlen($publicRoot)), '/');
                Storage::disk('public')->delete($path);
                $path = $newRelativePath;
                $absolutePath = $processablePath;
            }
        }

        $placeholders = [];
        if (!$conversionFailedForDoc) {
            $placeholders = $docxService->detectPlaceholdersFromFilePath($absolutePath);
            if (empty($placeholders)) {
                $placeholders = $docxService->autoInjectPlaceholdersFromBlanks($absolutePath);
            }
            if (empty($placeholders)) {
                $placeholders = $docxService->autoInjectPlaceholdersFromEmptyTableCells($absolutePath);
            }
        }

        if (empty($placeholders)) {
            $placeholders = [];
        }

        $createAsHtml = $request->boolean('create_as_html');
        if ($createAsHtml) {
            try {
                $html = $docxService->convertPublicDocToHtml($path);
            } catch (\Throwable $exception) {
                return back()
                    ->withInput()
                    ->withErrors(['docx_file' => 'DOCX uploaded, but HTML conversion failed: ' . $exception->getMessage()]);
            }

            $template = Template::create([
                'name' => $request->name,
                'template_type' => $request->input('target_template_type', 'pqr'),
                'body_html' => Purifier::clean($html, 'template_body'),
                'docx_template_path' => $path,
                'docx_placeholders' => $placeholders,
                'created_by' => auth()->id(),
            ]);

            return redirect()
                ->route('templates.edit', $template)
                ->with('status', 'DOCX converted and created as HTML template.');
        }

        $template = Template::create([
            'name' => $request->name,
            'template_type' => 'docx',
            'body_html' => '',
            'docx_template_path' => $path,
            'docx_placeholders' => $placeholders,
            'created_by' => auth()->id(),
        ]);

        $status = !empty($placeholders)
            ? 'DOCX template created with detected fields.'
            : 'DOCX template created in manual upload mode (fill file offline and upload completed document at submit).';

        return redirect()->route('templates.edit', $template)->with('status', $status);
    }

    private function updateDocxTemplate(Request $request, Template $template)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'template_type' => ['required', 'in:' . implode(',', self::TEMPLATE_TYPES)],
            'docx_file' => ['nullable', 'file', 'mimes:doc,docx'],
        ]);

        $template->name = $request->name;

        if ($request->hasFile('docx_file')) {
            Storage::disk('public')->makeDirectory('templates/docx');
            $newPath = $request->file('docx_file')->store('templates/docx', 'public');
            $absolutePath = storage_path('app/public/' . $newPath);
            $docxService = app(DocxTemplateService::class);

            $conversionFailedForDoc = false;
            try {
                $processablePath = $docxService->ensureProcessableDocxPath($absolutePath);
            } catch (\RuntimeException $exception) {
                $extension = strtolower((string) pathinfo($absolutePath, PATHINFO_EXTENSION));
                if ($extension === 'doc') {
                    $conversionFailedForDoc = true;
                    $processablePath = $absolutePath;
                } else {
                    Storage::disk('public')->delete($newPath);
                    return back()
                        ->withInput()
                        ->withErrors(['docx_file' => $exception->getMessage()]);
                }
            }

            if ($processablePath !== $absolutePath) {
                $publicRoot = str_replace('\\', '/', storage_path('app/public/'));
                $normalizedProcessable = str_replace('\\', '/', $processablePath);
                if (str_starts_with($normalizedProcessable, $publicRoot)) {
                    $convertedRelativePath = ltrim(substr($normalizedProcessable, strlen($publicRoot)), '/');
                    Storage::disk('public')->delete($newPath);
                    $newPath = $convertedRelativePath;
                    $absolutePath = $processablePath;
                }
            }

            $placeholders = [];
            if (!$conversionFailedForDoc) {
                $placeholders = $docxService->detectPlaceholdersFromFilePath($absolutePath);
                if (empty($placeholders)) {
                    $placeholders = $docxService->autoInjectPlaceholdersFromBlanks($absolutePath);
                }
                if (empty($placeholders)) {
                    $placeholders = $docxService->autoInjectPlaceholdersFromEmptyTableCells($absolutePath);
                }
            }

            if (empty($placeholders)) {
                $placeholders = [];
            }

            if (!empty($template->docx_template_path)) {
                Storage::disk('public')->delete($template->docx_template_path);
            }

            $template->docx_template_path = $newPath;
            $template->docx_placeholders = $placeholders;
        }

        $template->save();

        return back()->with('status', !empty($template->docx_placeholders)
            ? 'DOCX template updated.'
            : 'DOCX template updated in manual upload mode.');
    }

}
