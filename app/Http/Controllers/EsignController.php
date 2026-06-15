<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EsignController extends Controller
{

   public function validatePassword(Request $request)
{
    if (!auth()->check()) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthenticated'
        ], 401);
    }

    if (Hash::check($request->password, auth()->user()->password)) {
        return response()->json(['success' => true]);
    }

    return response()->json([
        'success' => false,
        'message' => 'Incorrect password'
    ], 422);
}
}
