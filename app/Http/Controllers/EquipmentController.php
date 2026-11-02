<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Imports\EquipmentImport;
use App\Imports\EquipmentImportPreview;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class EquipmentController extends Controller
{
    public function index(Request $request)
    {
        $query = $this->buildEquipmentQuery($request);

        $equipment = $query->orderByDesc('id')->paginate(10)->withQueryString();

        return view('equipment.index', [
            'equipment' => $equipment,
            'isMyApprovedView' => false,
            'stats' => [
                'total' => Equipment::count(),
                'pending' => Equipment::query()
                    ->where(function ($statusQuery) {
                        $statusQuery->where('approval_status', 'pending')
                            ->orWhereNull('approval_status');
                    })->count(),
                'approved' => Equipment::query()
                    ->where('approval_status', 'approved')
                    ->count(),
            ],
        ]);
    }

    public function myApproved(Request $request)
    {
        $query = $this->buildEquipmentQuery($request)
            ->where('approval_status', 'approved')
            ->where('approved_by', Auth::id());

        $equipment = $query->orderByDesc('approved_at')->orderByDesc('id')->paginate(10)->withQueryString();

        $assignedPendingCount = Equipment::query()
            ->where(function ($approverQuery) {
                $this->applyCurrentUserApproverConstraint($approverQuery);
            })
            ->where(function ($statusQuery) {
                $statusQuery->where('approval_status', 'pending')
                    ->orWhereNull('approval_status');
            })
            ->count();

        return view('equipment.index', [
            'equipment' => $equipment,
            'isMyApprovedView' => true,
            'stats' => [
                'total' => Equipment::count(),
                'pending' => $assignedPendingCount,
                'approved' => Equipment::query()
                    ->where('approval_status', 'approved')
                    ->where('approved_by', Auth::id())
                    ->count(),
            ],
        ]);
    }

    public function create()
    {
        $users = User::select('id', 'first_name', 'last_name', 'email')->get();
        return view('equipment.create', compact('users'));
    }

    public function importPreview(Request $request)
    {
        $request->validate(
            [
                'file' => 'required|file|mimes:csv,xlsx,xls|max:2048',
            ],
            [
                'file.required' => 'Please upload a file.',
                'file.mimes' => '❌ Invalid file format. Please upload only CSV or Excel (.csv, .xlsx, .xls).',
                'file.max' => 'File size should not exceed 2MB.',
            ]
        );

        $this->clearEquipmentImportSession();
        Storage::disk('local')->makeDirectory('temp/equipment-imports');

        $storedPath = $request->file('file')->store('temp/equipment-imports');
        $fullPath   = Storage::path($storedPath);

        try {
            $previewImport = new EquipmentImportPreview();
            Excel::import($previewImport, $fullPath);
            $rows = $previewImport->rows ?? collect();
        } catch (\Throwable $e) {
            Storage::delete($storedPath);

            return redirect()->route('equipment.index')
                ->with('error', '❌ Could not read the file. Please check the format and column headers.');
        }

        $parsedRows = $rows
            ->map(fn ($row) => $row->toArray())
            ->filter(function (array $row) {
                $id   = trim((string) ($row['equipment_id'] ?? ''));
                $name = trim((string) ($row['equipment_name'] ?? ''));

                return $id !== '' || $name !== '';
            })
            ->values();

        if ($parsedRows->isEmpty()) {
            Storage::delete($storedPath);

            return redirect()->route('equipment.index')
                ->with('error', '❌ The file has no equipment rows to import.');
        }

        $firstRow = $parsedRows->first();
        foreach (['equipment_name', 'equipment_id', 'facility'] as $requiredColumn) {
            if (! array_key_exists($requiredColumn, $firstRow)) {
                Storage::delete($storedPath);

                return redirect()->route('equipment.index')
                    ->with('error', '❌ CSV must include columns: equipment_name, equipment_id, facility.');
            }
        }

        $duplicateCount = $parsedRows->filter(function (array $row) {
            $equipmentId = trim((string) ($row['equipment_id'] ?? ''));

            return $equipmentId !== '' && Equipment::where('equipment_id', $equipmentId)->exists();
        })->count();

        $importableCount = $parsedRows->count() - $duplicateCount;

        session([
            'equipment_import_preview' => [
                'user_id'         => Auth::id(),
                'path'            => $storedPath,
                'original_name'   => $request->file('file')->getClientOriginalName(),
                'total_rows'      => $parsedRows->count(),
                'duplicate_rows'  => $duplicateCount,
                'importable_rows' => max(0, $importableCount),
                'preview_rows'    => $parsedRows->take(10)->values()->all(),
            ],
        ]);

        return redirect()->route('equipment.import.preview.show');
    }

    public function showImportPreview()
    {
        $preview = session('equipment_import_preview');

        if (! $this->hasValidImportSession($preview)) {
            return redirect()->route('equipment.index')
                ->with('error', '❌ Upload session expired. Please upload the CSV file again.');
        }

        $users = User::select('id', 'first_name', 'last_name', 'email')->get();

        return view('equipment.import-preview', [
            'users'          => $users,
            'previewRows'    => collect($preview['preview_rows'] ?? []),
            'totalRows'      => (int) ($preview['total_rows'] ?? 0),
            'duplicateRows'  => (int) ($preview['duplicate_rows'] ?? 0),
            'importableRows' => (int) ($preview['importable_rows'] ?? 0),
            'originalName'   => (string) ($preview['original_name'] ?? 'Uploaded file'),
        ]);
    }

    public function importCancel()
    {
        $this->clearEquipmentImportSession();

        return redirect()->route('equipment.index')
            ->with('status', 'Import cancelled.');
    }

    public function import(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'approver_username' => 'required|exists:users,id',
        ], [
            'approver_username.required' => 'Please select an approver before importing.',
            'approver_username.exists'   => 'The selected approver is invalid.',
        ]);

        if ($validator->fails()) {
            return redirect()->route('equipment.import.preview.show')
                ->withErrors($validator)
                ->withInput();
        }

        $preview = session('equipment_import_preview');

        if (! $this->hasValidImportSession($preview)) {
            return redirect()->route('equipment.index')
                ->with('error', '❌ Upload session expired. Please upload the CSV file again.');
        }

        $importable = (int) ($preview['importable_rows'] ?? 0);
        if ($importable === 0) {
            return redirect()->route('equipment.import.preview.show')
                ->with('error', '❌ All rows in this file already exist. There is nothing new to import.');
        }

        try {
            Excel::import(
                new EquipmentImport((int) $request->approver_username),
                Storage::path($preview['path'])
            );

            $this->clearEquipmentImportSession();

            return redirect()->route('equipment.index')
                ->with('success', "✅ {$importable} equipment record(s) imported and sent for approval.");
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('equipment.import.preview.show')
                ->with('error', '❌ Import failed. Please check file format or data and try again.')
                ->withInput();
        }
    }

    public function store(Request $request)
    {
        if (!Auth::check()) {
            abort(403, 'Unauthorized');
        }

        $request->validate([
            'equipment_name' => 'required|string|max:255',
            'equipment_id' => 'required|string|max:255|unique:equipment,equipment_id',
            'facility' => 'required|string|max:255',
            'approver_username' => 'required|exists:users,id',
        ]);

        Equipment::create([
            'equipment_name' => $request->equipment_name,
            'equipment_id' => $request->equipment_id,
            'facility' => $request->facility,
            'approver_username' => (string) $request->approver_username,
            'created_by' => Auth::id(),
            'approval_status' => 'pending',
        ]);

        return redirect()->route('equipment.index')
            ->with('success', 'Equipment added successfully.');
    }

    public function approve(Equipment $equipment)
    {
        if (!$this->isCurrentUserApprover($equipment)) {
            abort(403, 'Only the assigned approver can approve this equipment.');
        }

        if ((string) ($equipment->approval_status ?? 'pending') === 'approved') {
            return back()->with('status', 'This equipment is already approved.');
        }

        $equipment->update([
            'approval_status' => 'approved',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
        ]);

        return back()->with('success', 'Equipment approved successfully.');
    }

    private function buildEquipmentQuery(Request $request)
    {
        $query = Equipment::query()->with([
            'approverUser:id,first_name,last_name,email',
            'approvedByUser:id,first_name,last_name,email',
        ]);

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($equipmentQuery) use ($search) {
                $equipmentQuery->where('equipment_name', 'like', "%{$search}%")
                    ->orWhere('equipment_id', 'like', "%{$search}%")
                    ->orWhere('facility', 'like', "%{$search}%")
                    ->orWhere('approver_username', 'like', "%{$search}%")
                    ->orWhereHas('approverUser', function ($approverQuery) use ($search) {
                        $approverQuery->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })
                    ->orWhereHas('approvedByUser', function ($approvedByQuery) use ($search) {
                        $approvedByQuery->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('status') && in_array($request->status, ['pending', 'approved'], true)) {
            if ($request->status === 'pending') {
                $query->where(function ($statusQuery) {
                    $statusQuery->where('approval_status', 'pending')
                        ->orWhereNull('approval_status');
                });
            } else {
                $query->where('approval_status', 'approved');
            }
        }

        return $query;
    }

    private function applyCurrentUserApproverConstraint($query): void
    {
        $user = Auth::user();

        $query->where('approver_username', (string) $user->id);

        if (!empty($user->email)) {
            $query->orWhereRaw('LOWER(approver_username) = ?', [strtolower((string) $user->email)]);
        }
    }

    private function hasValidImportSession(mixed $preview): bool
    {
        return is_array($preview)
            && (int) ($preview['user_id'] ?? 0) === (int) Auth::id()
            && ! empty($preview['path'])
            && Storage::exists($preview['path']);
    }

    private function clearEquipmentImportSession(): void
    {
        $preview = session('equipment_import_preview');

        if (is_array($preview) && ! empty($preview['path'])) {
            Storage::delete($preview['path']);
        }

        session()->forget('equipment_import_preview');
    }

    private function isCurrentUserApprover(Equipment $equipment): bool
    {
        $approverRaw = trim((string) ($equipment->approver_username ?? ''));
        $currentUser = Auth::user();

        if ($approverRaw === '') {
            return false;
        }

        if (is_numeric($approverRaw) && (int) $approverRaw === (int) $currentUser->id) {
            return true;
        }

        return !empty($currentUser->email)
            && strtolower($approverRaw) === strtolower((string) $currentUser->email);
    }

}
