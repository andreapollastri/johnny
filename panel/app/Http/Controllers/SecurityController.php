<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class SecurityController extends Controller
{
    /**
     * Two-factor setup: Fortify exposes POST /user/two-factor-authentication (after password confirm)
     * and GET /user/two-factor-qr-code for the SVG. This page links to those flows.
     */
    public function show(): View
    {
        return view('security');
    }
}
