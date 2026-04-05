<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Laravel\Fortify\Fortify;

class SecurityController extends Controller
{
    /**
     * Two-factor setup: Fortify exposes POST /user/two-factor-authentication (after password confirm).
     * The QR is rendered in the view via User::twoFactorQrCodeSvg() (Fortify’s QR URL returns JSON, not an image).
     */
    public function show(Request $request): View
    {
        $user = $request->user();

        $tokens = $user
            ->tokens()
            ->orderByDesc('created_at')
            ->get();

        $twoFactorManualSecret = null;
        if ($user->two_factor_secret && ! $user->hasEnabledTwoFactorAuthentication()) {
            $twoFactorManualSecret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
        }

        $recoveryCodes = null;
        $recoveryCodesExhausted = false;
        if ($user->hasEnabledTwoFactorAuthentication() && $user->two_factor_recovery_codes) {
            $codes = $user->recoveryCodes();
            $recoveryCodesExhausted = count($codes) === 0;
            // Only pass codes to the view right after regeneration (one-time display).
            if ($request->session()->get('status') === Fortify::RECOVERY_CODES_GENERATED) {
                $recoveryCodes = $codes;
            }
        }

        return view('security', compact('tokens', 'twoFactorManualSecret', 'recoveryCodes', 'recoveryCodesExhausted'));
    }

    public function storeToken(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $plainTextToken = $request->user()->createToken($validated['name'])->plainTextToken;

        return redirect()
            ->route('security.show')
            ->with('status', 'API token created.')
            ->with('sanctum_token_plain', $plainTextToken);
    }

    public function destroyToken(Request $request, int $tokenId): RedirectResponse
    {
        $deleted = $request->user()->tokens()->where('id', $tokenId)->delete();

        if ($deleted === 0) {
            return redirect()
                ->route('security.show')
                ->withErrors(['token' => 'Token not found.']);
        }

        return redirect()
            ->route('security.show')
            ->with('status', 'API token revoked.');
    }
}
