<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationPromptController extends Controller
{
    public function __invoke(Request $request): RedirectResponse|\Illuminate\View\View
    {
        return $request->user()->hasVerifiedEmail()
            ? redirect()->route('tontines.index')
            : view('auth.verify-email');
    }
}
