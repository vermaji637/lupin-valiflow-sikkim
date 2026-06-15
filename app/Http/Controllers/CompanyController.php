<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Company;
class CompanyController extends Controller
{
    public function index()
{
    $companies = Company::all();
    return view('companies.index', compact('companies'));
}

public function create()
{
    return view('companies.create');
}

public function store(Request $request)
{
    $request->validate([
        'name' => 'required|string|max:255',
        'address' => 'required|string|max:255',
        'city' => 'required|string|max:255',
        'state' => 'required|string|max:255',
        'postal_code' => 'required|string|max:20',
        'country' => 'required|string|max:255',
        'phone' => 'nullable|string|max:20',
        'email' => 'nullable|email|max:255',
        
        'logo' => 'nullable|string',
        
    ]);

    Company::create($request->all());

    return redirect()->route('companies.index')->with('success', 'Company added successfully.');
}

// Add other methods as needed (show, edit, update, destroy)

}
