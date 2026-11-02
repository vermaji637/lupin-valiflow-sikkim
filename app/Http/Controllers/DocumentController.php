<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Document;
use App\Models\ProtocolType;
use App\Models\Equipment;
use App\Models\Facility;
use App\Models\Report;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class DocumentController extends Controller
{
    /**
     * Display a listing of documents.
     * This is the main Documents index page for the sidebar.
     */
    public function index()
    {
        // For now, redirect to admin documents since that's the main document view
        // This can be customized later to show a different view based on user role
        return redirect()->route('admin.documents');
    }

    /* ================= ADMIN ================= */
    public function adminIndex(Request $request)
    {
        $query = Document::with(['protocolType', 'equipment', 'facility'])->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($documentQuery) use ($search) {
                $documentQuery->where('title', 'like', "%{$search}%")
                    ->orWhere('doc_title', 'like', "%{$search}%")
                    ->orWhereHas('protocolType', function ($protocolQuery) use ($search) {
                        $protocolQuery->where('protocol_type_name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('equipment', function ($equipmentQuery) use ($search) {
                        $equipmentQuery->where('equipment_id', 'like', "%{$search}%")
                            ->orWhere('equipment_name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('facility', function ($facilityQuery) use ($search) {
                        $facilityQuery->where('facility_name', 'like', "%{$search}%");
                    });
            });
        }

        $documents = $query->paginate(10)->withQueryString();

        return view('document.admin.index', [
            'documents' => $documents,
            'stats' => [
                'total' => Document::count(),
                'pending' => Document::where('status', 'pending')->count(),
                'completed' => Document::where('status', 'completed')->count(),
                'protocols' => Document::query()->distinct('protocol_type_id')->count('protocol_type_id'),
            ],
        ]);
    }

    public function getDocumentDetails($id)
    {
        try {
            $document = Document::with(['equipment', 'facility'])->findOrFail($id);

            $latestVersion = Report::query()
                ->where('document_no', $document->id)
                ->whereNotNull('version')
                ->orderByDesc('id')
                ->value('version');

            return response()->json([
                'facility'        => (string) ($document->facility->facility_name ?? ''),
                'equipment_name'  => (string) ($document->equipment->equipment_name ?? ''),
                'equipment_id'    => (string) ($document->equipment->equipment_id ?? ''),
                // keep legacy key for old JS readers
                'equipment_code'  => (string) ($document->equipment->equipment_id ?? ''),
                'version'         => (string) ($latestVersion ?? ''),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed loading document details', [
                'document_id' => $id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to load document details.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function create()
    {
        return view('document.admin.create', [
            'protocolTypes' => ProtocolType::all(),
            'equipments'    => Equipment::all(),
            'facilities'    => Facility::all(),
        ]);
    }

    public function store(Request $request)
    {
        if (!Auth::user()->can('document-create')) {
        abort(403, 'You do not have permission to create documents.');
        }
        $request->validate([
            'protocol_type_id' => 'required|exists:protocol_type_master,id',
             'equipment_id'     => 'required|exists:equipment,id',

           'facility_id'      => 'required|exists:facility_master,id',
        ]);

        // Duplicate check
          $exists = Document::where('protocol_type_id', $request->protocol_type_id)
            ->where('equipment_id', $request->equipment_id)
            ->where('facility_id', $request->facility_id)
            ->exists();
        if ($exists) {
            return back()->withErrors([
                'duplicate' =>
                'A document already exists for the selected Protocol, Equipment and Facility.'
            ])->withInput();
        }

      $protocol  = ProtocolType::findOrFail($request->protocol_type_id);
        $equipment = Equipment::findOrFail($request->equipment_id);
        $facility  = Facility::findOrFail($request->facility_id);

        $title = $protocol->protocol_type_name . ' / ' .
                 $equipment->equipment_id . ' / ' .
                 $equipment->equipment_name . ' / ' .
                 $facility->facility_name;

        Document::create([
            'title'            => $title,
            'protocol_type_id' => $request->protocol_type_id,
            'equipment_id'     => $request->equipment_id,
             'equipment_name_id' => $request->equipment_name_id,
            'equipment_code_id' => $request->equipment_code_id,
            'facility_id'      => $request->facility_id,
            'created_by'       => Auth::id(),
            'status'           => 'pending',
        ]);

        return redirect()->route('admin.documents')->with('success', 'Document sent to QA.');
    }

    /* ================= QA ================= */
    public function qaIndex()
    {
        // Only pending documents
        $documents = Document::where('status', 'pending')->with(['protocolType','equipment','facility'])->get();
        return view('document.qa.index', compact('documents'));
    }

    public function updateText(Request $request)
    {
       // if (Auth::user()->isAdmin()) abort(403);

        $request->validate([
            'document_id'   => 'required|exists:documents,id',
            'document_text' => 'required|string|max:1000',
        ]);

        $document = Document::findOrFail($request->document_id);


        if (!empty($document->doc_title)) {
            return back()->withErrors('Document text already submitted.');
        }

        $document->update([
            'doc_title' => $request->document_text,
            'qa_user_id'    => Auth::id(),
            'status'        => 'completed',
        ]);

        return back()->with('success', 'Document submitted successfully.');
    }
}
