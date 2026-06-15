<?php

namespace App\Http\Controllers;

use App\Models\Facility;
use App\Models\Document;
use Illuminate\Http\Request;

class FacilityController extends Controller
{
    public function index(Request $request)
    {
        $query = Facility::query();

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where('facility_name', 'like', "%{$search}%");
        }

        $facilities = $query->orderByDesc('id')->paginate(10)->withQueryString();

        return view('facility.index', [
            'facilities' => $facilities,
            'stats' => [
                'total' => Facility::count(),
                'mapped_documents' => Document::query()->distinct('facility_id')->count('facility_id'),
            ],
        ]);
    }

    public function create()
    {
        return view('facility.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'facility_name' => 'required|unique:facility_master|max:100'
        ]);

        Facility::create($request->only('facility_name'));

        return redirect()->route('facility.index')
                         ->with('success','Facility Added');
    }

    public function edit($id)
    {
        $facility = Facility::findOrFail($id);
        return view('facility.edit', compact('facility'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'facility_name' => 'required|max:100|unique:facility_master,facility_name,' . $id
        ]);

        $facility = Facility::findOrFail($id);
        $facility->update($request->only('facility_name'));

        return redirect()->route('facility.index')
                         ->with('success','Facility Updated');
    }

    public function destroy($id)
    {
        $facility = Facility::findOrFail($id);
        $facility->delete();

        return redirect()->route('facility.index')
                         ->with('success','Facility Deleted');
    }
}
