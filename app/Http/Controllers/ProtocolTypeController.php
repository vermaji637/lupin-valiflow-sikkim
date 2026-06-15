<?php

namespace App\Http\Controllers;

use App\Models\ProtocolType;
use Illuminate\Http\Request;

class ProtocolTypeController extends Controller
{
    public function index(Request $request)
    {
        $query = ProtocolType::query();

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where('protocol_type_name', 'like', "%{$search}%");
        }

        $protocols = $query->orderByDesc('id')->paginate(10)->withQueryString();

        return view('protocoltype.index', [
            'protocols' => $protocols,
            'stats' => [
                'total' => ProtocolType::count(),
            ],
        ]);
    }

    public function create()
    { 
        return view('protocoltype.create1');
    }

    public function store(Request $request)
    {
        $request->validate([
            'protocol_type_name' => 'required|unique:protocol_type_master|max:100'
        ]);

        ProtocolType::create($request->all());

        return redirect()->route('protocol-type.index')
                         ->with('success','Protocol Type Added');
    }

    public function edit($id)
    {
        $protocol = ProtocolType::findOrFail($id);
        return view('protocol_type.edit', compact('protocol'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'protocol_type_name' => 'required|max:100|unique:protocol_type_master,protocol_type_name,' . $id
        ]);

        $protocol = ProtocolType::findOrFail($id);
        $protocol->update($request->only('protocol_type_name'));

        return redirect()->route('protocol-type.index')
                         ->with('success','Protocol Type Updated');
    }

    public function destroy($id)
    {
        ProtocolType::findOrFail($id)->delete();
        return redirect()->route('protocol-type.index')
                         ->with('success','Protocol Type Deleted');
    }
}
