<?php

namespace App\Http\Controllers;

use App\Models\NewEquipmentQualification;
use App\Services\NewEquipmentQualificationDocxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class NewEquipmentQualificationController extends Controller
{
    public function index()
    {
        $uploads = NewEquipmentQualification::query()
            ->where('uploaded_by', auth()->id())
            ->orderByDesc('created_at')
            ->get();

        return view('protocol.new_equipment_qualification.index', [
            'uploads' => $uploads,
        ]);
    }

    public function upload(Request $request)
    {
        if (! Hash::check($request->password, auth()->user()->password)) {
            return back()->with('error', 'Wrong E-Sign password.');
        }

        $request->validate([
            'password'  => ['required', 'string'],
            'docx_file' => ['required', 'file', 'mimes:docx', 'max:20480'],
        ]);

        $file = $request->file('docx_file');
        $originalName = $file->getClientOriginalName();
        $storedName = Str::uuid()->toString() . '.docx';
        $directory = 'new_equipment_qualifications/' . auth()->id();

        $storedPath = $file->storeAs($directory, $storedName, 'public');

        $record = NewEquipmentQualification::create([
            'original_filename' => $originalName,
            'stored_path'       => $storedPath,
            'uploaded_by'       => auth()->id(),
        ]);

        return redirect()
            ->route('protocol.new-equipment-qualification.index')
            ->with('success', 'File uploaded successfully.');
    }

    public function view(NewEquipmentQualification $newEquipmentQualification)
    {
        $this->authorizeRecord($newEquipmentQualification);

        return redirect()->route('protocol.new-equipment-qualification.index');
    }

    public function file(NewEquipmentQualification $newEquipmentQualification): BinaryFileResponse
    {
        $this->authorizeRecord($newEquipmentQualification);

        $absolutePath = Storage::disk('public')->path($newEquipmentQualification->stored_path);

        if (! is_file($absolutePath)) {
            abort(404, 'File not found.');
        }

        return response()->file($absolutePath, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }

    public function previewHtml(NewEquipmentQualification $newEquipmentQualification): JsonResponse
    {
        $this->authorizeRecord($newEquipmentQualification);

        $absolutePath = Storage::disk('public')->path($newEquipmentQualification->stored_path);

        if (! is_file($absolutePath)) {
            return response()->json(['error' => 'File not found.'], 404);
        }

        $pythonUrl = rtrim(env('PYTHON_NLP_URL', 'http://127.0.0.1:8001'), '/');

        $response = Http::timeout(120)
            ->attach(
                'file',
                file_get_contents($absolutePath),
                $newEquipmentQualification->original_filename
            )
            ->post($pythonUrl . '/docx-image-map');

        if (! $response->ok()) {
            return response()->json([
                'error'   => 'Image resolution failed.',
                'details' => $response->json('detail') ?? $response->body(),
            ], 500);
        }

        return response()->json([
            'placements' => $response->json('placements', []),
            'messages'   => $response->json('messages', []),
        ]);
    }

    public function saveAs(
        Request $request,
        NewEquipmentQualification $newEquipmentQualification,
        NewEquipmentQualificationDocxService $docxService
    ): JsonResponse {
        $this->authorizeRecord($newEquipmentQualification);

        if (! Hash::check($request->password, auth()->user()->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Wrong E-Sign password.',
            ], 422);
        }

        $request->validate([
            'password' => ['required', 'string'],
            'html'     => ['required', 'string', 'max:5000000'],
            'filename' => ['nullable', 'string', 'max:255'],
        ]);

        $html = $request->input('html');
        $baseName = pathinfo($newEquipmentQualification->original_filename, PATHINFO_FILENAME);
        $originalFilename = $request->input('filename')
            ?: ($baseName . '_edited_' . now()->format('Ymd_His') . '.docx');

        if (strtolower(substr($originalFilename, -5)) !== '.docx') {
            $originalFilename .= '.docx';
        }

        $tempDocxPath = $docxService->createDocxFromHtml($html);

        try {
            $storedName = Str::uuid()->toString() . '.docx';
            $storedPath = 'new_equipment_qualifications/' . auth()->id() . '/' . $storedName;

            Storage::disk('public')->put($storedPath, file_get_contents($tempDocxPath));

            $record = NewEquipmentQualification::create([
                'original_filename' => $originalFilename,
                'stored_path'       => $storedPath,
                'uploaded_by'       => auth()->id(),
            ]);
        } finally {
            if (is_file($tempDocxPath)) {
                @unlink($tempDocxPath);
            }
        }

        return response()->json([
            'success'      => true,
            'message'      => 'Edited document saved successfully.',
            'download_url' => route('protocol.new-equipment-qualification.download', $record),
            'filename'     => $record->original_filename,
        ]);
    }

    public function download(NewEquipmentQualification $newEquipmentQualification): BinaryFileResponse
    {
        $this->authorizeRecord($newEquipmentQualification);

        $absolutePath = Storage::disk('public')->path($newEquipmentQualification->stored_path);

        if (! is_file($absolutePath)) {
            abort(404, 'File not found.');
        }

        return response()->download(
            $absolutePath,
            $newEquipmentQualification->original_filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']
        );
    }

    private function authorizeRecord(NewEquipmentQualification $record): void
    {
        if ((int) $record->uploaded_by !== (int) auth()->id()) {
            abort(403);
        }
    }
}
