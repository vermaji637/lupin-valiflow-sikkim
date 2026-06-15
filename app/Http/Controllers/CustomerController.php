<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Customer;
use App\Models\Company;
class CustomerController extends Controller
{
    public function index()
    {
        $customers = Customer::all();
		 return view('customers.index', compact('customers'));
        //return response()->json($customers);
    }
public function create()
{
	 $companies = Company::pluck('name','id');
	//  dd($companies);
	// $companies = ['Tech Corp', 'Business Inc', 'Creative Solutions', 'Enterprise LLC'];
    return view('customers.create', compact('companies'));
}

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required',
            'email' => 'required|email|unique:customers',
            'phone_number' => 'required',
        ]);
     $customer = Customer::create($request->all());
        //$customers = Customer::all();

		return redirect()->route('customers.index')->with('success', 'Customer created successfully.');
       // return response()->json($customer, 201);
    }

public function edit(Customer $customer)
{
    $companies = Company::pluck('name', 'id');
    return view('customers.edit', compact('customer', 'companies'));
}
    public function show($id)
    {
        $customer = Customer::findOrFail($id);
        return response()->json($customer);
    }

    public function update(Request $request, $id)
    {
        $customer = Customer::findOrFail($id);
        $customer->update($request->all());
        return response()->json($customer);
    }

    public function destroy($id)
    {
        $customer = Customer::findOrFail($id);
        $customer->delete();
        return response()->json(null, 204);
    }
}
