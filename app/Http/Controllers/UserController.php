<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Exports\UsersExport;
use App\Imports\UsersImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Hash;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use App\Services\PasswordExpiryService;
use App\Services\PasswordHistoryService;

class UserController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('permission:user-list', ['only' => ['index', 'show']]);
        $this->middleware('permission:user-create', ['only' => ['create', 'store']]);
        $this->middleware('permission:user-edit', ['only' => ['edit', 'update']]);
        $this->middleware('permission:user-delete', ['only' => ['destroy']]);
    }


    /**
     * List User
     * @param Nill
     * @return Array $user
     *
     */
    public function index(Request $request)
    {
        $query = User::with('roles');

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($userQuery) use ($search) {
                $userQuery->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('designation', 'like', "%{$search}%")
                    ->orWhere('department_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('mobile_number', 'like', "%{$search}%")
                    ->orWhereHas('roles', function ($roleQuery) use ($search) {
                        $roleQuery->where('name', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('status') && in_array($request->status, ['0', '1'], true)) {
            $query->where('status', (int) $request->status);
        }

        $users = $query->latest()->paginate(10)->withQueryString();

        return view('users.index', [
            'users' => $users,
            'stats' => [
                'total' => User::count(),
                'active' => User::where('status', 1)->count(),
                'inactive' => User::where('status', 0)->count(),
                'roles' => Role::count(),
            ],
        ]);
    }

    /**
     * Create User
     * @param Nill
     * @return Array $user
     * @author
     */
    public function create()
    {
        return view('users.add', [
            'roles' => Role::orderBy('name')->get(),
        ]);
    }
    public function showForm()
    {
        return view('auth.password-setup', compact('token')); // ❌ $token not defined
    }

    /**
     * Display the specified user.
     * Required for Route::resource('users', UserController::class)
     *
     * @param  \App\Models\User  $user
     * @return \Illuminate\Http\Response
     */
    public function show(User $user)
    {
        // For now, redirect to edit page or show user details
        // This maintains compatibility with Route::resource
        return redirect()->route('users.edit', $user);
    }
    /**
     * Store User
     * @param Request $request
     * @return View Users
     * @author
     */
    public function store(Request $request)
{
    \Log::info('UserController::store() method started');
    \Log::info('Request data: ' . json_encode($request->all()));

    try {
        $validated = $request->validate([
            'first_name'    => 'required|string|max:255',
            'last_name'     => 'required|string|max:255',
            'designation'     => 'nullable|string|max:255',
            'department_name' => 'nullable|string|max:255',
            'email'         => 'required|email|unique:users,email',
            'mobile_number' => 'required|numeric|digits:10',
            'role_id'       => 'required|exists:roles,id',
            'status'        => 'required|numeric|in:0,1',
        ]);
    } catch (\Illuminate\Validation\ValidationException $e) {
        return back()->withErrors($e->errors())->withInput();
    }

    if (!Hash::check($request->password, auth()->user()->password)) {
        return back()->with('error', 'Wrong E-Sign password.');
    }

    DB::beginTransaction();
    try {
        $token = Str::random(60);

        $user = User::create([
            'first_name'    => $request->first_name,
            'last_name'     => $request->last_name,
            'designation'     => $request->designation,
            'department_name' => $request->department_name,
            'designation_id'  => null,
            'department_id'   => null,
            'email'           => $request->email,
            'mobile_number'   => $request->mobile_number,
            'role_id'         => $request->role_id,
            'status'        => $request->status,
            'password'      => '',
            'password_setup_token'            => $token,
            'password_setup_token_created_at' => now(),
        ]);

        // ✅ FIX: Role model dhundo, integer mat do
        $role = Role::findById((int) $request->role_id, 'web');

        // ✅ Cache clear karo
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // ✅ Sahi tarike se role assign karo
        $user->assignRole($role);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $link = url('/setup-password/' . $token);
        // SMTP MAIL TRIGGER — Password Setup Email
        // This line sends the "Set Your Password" email to the new user.
        // Email is sent using MAIL_* values from .env file.
        // If MAIL_MAILER=log in .env, email content appears in
        // storage/logs/laravel.log instead of actually being sent.
        // To send real email: set MAIL_MAILER=smtp and fill MAIL_HOST,
        // MAIL_PORT, MAIL_FROM_ADDRESS etc in .env
        // Example of what gets sent: resources/views/emails/password-setup.blade.php
        //   (or whatever the actual blade view name is — check the Mailable class)
        Mail::to($user->email)->send(new \App\Mail\PasswordSetupLink($link));

        DB::commit();
        return back()->with('success', 'User created and email sent.');

    } catch (\Throwable $th) {
        DB::rollBack();
        return redirect()->back()->withInput()->with('error', $th->getMessage());
    }
}
    /**
     * Update Status Of User
     * @param Integer $status
     * @return List Page With Success
     * @author
     */
    public function updateStatus($user_id, $status)
    {
        // Validation
        $validate = Validator::make([
            'user_id'   => $user_id,
            'status'    => $status
        ], [
            'user_id'   =>  'required|exists:users,id',
            'status'    =>  'required|in:0,1',
        ]);

        // If Validations Fails
        if($validate->fails()){
            return redirect()->route('users.index')->with('error', $validate->errors()->first());
        }

        try {
            DB::beginTransaction();

            // Update Status
            User::whereId($user_id)->update(['status' => $status]);

            // Commit And Redirect on index with Success Message
            DB::commit();
            return redirect()->route('users.index')->with('success','User Status Updated Successfully!');
        } catch (\Throwable $th) {

            // Rollback & Return Error Message
            DB::rollBack();
            return redirect()->back()->with('error', $th->getMessage());
        }
    }

    /**
     * Edit User
     * @param Integer $user
     * @return Collection $user
     * @author
     */
    public function edit(User $user)
    {
        return view('users.edit')->with([
            'roles' => Role::orderBy('name')->get(),
            'user' => $user,
        ]);
    }

    /**
     * Update User
     * @param Request $request, User $user
     * @return View Users
     * @author
     */
   public function update(Request $request, User $user)
{
    $request->validate([
        'first_name'    => 'required|string|max:255',
        'last_name'     => 'required|string|max:255',
        'designation'     => 'nullable|string|max:255',
        'department_name' => 'nullable|string|max:255',
        'email'         => 'required|email|unique:users,email,' . $user->id,
        'mobile_number' => 'required|numeric|digits:10',
        'role_id'       => 'required|exists:roles,id',
        'status'        => 'required|in:0,1',
    ]);

    if (!Hash::check($request->password, auth()->user()->password)) {
        return back()->with('error', 'Wrong E-Sign password.');
    }

    try {
        DB::beginTransaction();

        $user->update([
            'first_name'    => $request->first_name,
            'last_name'     => $request->last_name,
            'designation'     => $request->designation,
            'department_name' => $request->department_name,
            'designation_id'  => null,
            'department_id'   => null,
            'email'           => $request->email,
            'mobile_number'   => $request->mobile_number,
            'role_id'         => (int) $request->role_id, // keep in sync
            'status'        => $request->status,
        ]);

        // ✅ Use syncRoles — Spatie handles model_has_roles correctly
        $role = Role::findById((int) $request->role_id, 'web');
        //  \Log::info('Before sync - User roles: ' . $user->roles->pluck('name'));
        $user->syncRoles([$role]);

        // ✅ Clear permission cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        DB::commit();

        return redirect()->route('users.index')
            ->with('success', 'User Updated Successfully.');

    } catch (\Throwable $th) {
        DB::rollBack();
        return back()->withInput()->with('error', $th->getMessage());
    }
}
    /**
     * Delete User
     * @param User $user
     * @return Index Users
     * @author
     */
    public function destroy(Request $request, User $user)
    {
        // E-Sign Password Verification
        if (!Hash::check($request->password, auth()->user()->password)) {
            return back()->with('error', 'Wrong E-Sign password.');
        }

        DB::beginTransaction();
        try {
            // Delete User
            $user->delete();

            DB::commit();
            return redirect()->route('users.index')->with('success', 'User Deleted Successfully!.');

        } catch (\Throwable $th) {
            DB::rollBack();
            return redirect()->back()->with('error', $th->getMessage());
        }
    }

    /**
     * Import Users
     * @param Null
     * @return View File
     */
    public function importUsers()
    {
        return view('users.import');
    }

    public function uploadUsers(Request $request)
    {
        Excel::import(new UsersImport, $request->file);

        return redirect()->route('users.index')->with('success', 'User Imported Successfully');
    }

    public function export()
    {
        return Excel::download(new UsersExport, 'users.xlsx');
    }


    public function changePasswordForm(User $user)
    {
        return view('change-password', compact('user'));
    }

    // Update the user's password
    public function updatePassword(Request $request, User $user)
    {
        $request->validate([
            'password' => 'required|min:8|confirmed',
        ]);

        $history = app(PasswordHistoryService::class);
        $history->assertNotReused($user, $request->password);
        $history->rememberCurrent($user);

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        app(PasswordExpiryService::class)->markPasswordChanged($user);

        return redirect()
            ->back()
            ->with('success', "Password for {$user->name} updated successfully!");
    }

}
