<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    { 
        $this->middleware('auth');
    $this->middleware('permission:role-list|role-create|role-edit|role-delete', ['only' => ['index']]);
      $this->middleware('permission:role-create', ['only' => ['create','store']]);
      $this->middleware('permission:role-edit', ['only' => ['edit','update']]);
        $this->middleware('permission:role-delete', ['only' => ['destroy']]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $query = Role::query()->withCount('permissions');

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($roleQuery) use ($search) {
                $roleQuery->where('name', 'like', "%{$search}%")
                    ->orWhere('guard_name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('guard_name')) {
            $query->where('guard_name', $request->guard_name);
        }

        $roles = $query->orderBy('name')->paginate(10)->withQueryString();

        return view('roles.premmsionindex', [
            'roles' => $roles,
            'guards' => Role::query()->select('guard_name')->distinct()->orderBy('guard_name')->pluck('guard_name'),
            'stats' => [
                'total' => Role::count(),
                'guards' => Role::query()->distinct('guard_name')->count('guard_name'),
                'shown' => $roles->total(),
                'permission_assignable' => Role::has('permissions')->count(),
            ],
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $permissions = Permission::all();

        return view('roles.add', ['permissions' => $permissions]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        // Validations first - before E-Sign check
        // This will automatically redirect back with errors if validation fails
        $validated = $request->validate([
            'name' => 'required|unique:roles,name',
            'guard_name' => 'required'
        ]);

        // E-Sign Password Verification after validation passes
        if (!Hash::check($request->password, auth()->user()->password)) {
            return back()->with('error', 'Wrong E-Sign password.');
        }

        DB::beginTransaction();
        try {
            Role::create($request->all());

            DB::commit();
            return redirect()->route('roles.index')->with('success','Roles created successfully.');
        } catch (\Throwable $th) {
            DB::rollback();
            return redirect()->route('roles.create')->with('error',$th->getMessage());
        }
        
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $role = Role::whereId($id)->with('permissions')->first();
        
        $permissions = Permission::all();

        return view('roles.edit', ['role' => $role, 'permissions' => $permissions]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        // Validations first - before E-Sign check
        // This will automatically redirect back with errors if validation fails
        $validated = $request->validate([
            'name' => 'required|unique:roles,name,' . $id,
            'guard_name' => 'required'
        ]);

        // E-Sign Password Verification after validation passes
        if (!Hash::check($request->password, auth()->user()->password)) {
            return back()->with('error', 'Wrong E-Sign password.');
        }

        DB::beginTransaction();
        try {
            $role = Role::whereId($id)->first();

            $role->name = $request->name;
            $role->guard_name = $request->guard_name;
            $role->save();

            // Sync Permissions
            $permissions = $request->permissions;
            $role->syncPermissions($permissions);
            
            DB::commit();
            return redirect()->route('roles.index')->with('success','Roles updated successfully.');
        } catch (\Throwable $th) {
            DB::rollback();
            return redirect()->route('roles.edit',['role' => $role])->with('error',$th->getMessage());
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, $id)
    {
        // E-Sign Password Verification
        if (!Hash::check($request->password, auth()->user()->password)) {
            return back()->with('error', 'Wrong E-Sign password.');
        }

        DB::beginTransaction();
        try {
            Role::whereId($id)->delete();
            
            DB::commit();
            return redirect()->route('roles.index')->with('success','Roles deleted successfully.');
        } catch (\Throwable $th) {
            DB::rollback();
            return redirect()->route('roles.index')->with('error',$th->getMessage());
        }
    }
}
