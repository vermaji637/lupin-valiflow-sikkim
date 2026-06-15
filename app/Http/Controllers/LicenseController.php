<?php

namespace App\Http\Controllers;

use App\Services\LicenseService;
use Illuminate\Http\Request;

class LicenseController extends Controller
{
    public function __construct(private LicenseService $licenseService)
    {
    }

    public function show()
    {
        if (!$this->licenseService->isEnforced()) {
            return redirect()->route('login');
        }

        $status = $this->licenseService->activationStatus();

        return view('license.activate', [
            'licenseStatus' => $status,
            'maskedLicenseKey' => $this->licenseService->maskLicenseKey(optional($status['activation'])->license_key),
        ]);
    }

    public function activate(Request $request)
    {
        if (!$this->licenseService->isEnforced()) {
            return redirect()->route('login');
        }

        $request->validate([
            'license_key' => 'required|string|max:255',
            'activation_token' => 'required|string',
        ]);

        try {
            $this->licenseService->activate(
                trim((string) $request->license_key),
                trim((string) $request->activation_token),
                auth()->id()
            );
        } catch (\RuntimeException $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        return redirect()->route('login')
            ->with('success', 'License activated successfully. You can now sign in to the portal.');
    }
}
