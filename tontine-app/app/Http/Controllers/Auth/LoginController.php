<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    public function create()
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request)
    {
        $credentials = $request->validated();

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors([
                'email' => 'Identifiants incorrects.',
            ])->onlyInput('email');
        }

        if (Auth::user()->is_blocked) {
            Auth::logout();

            return back()->withErrors([
                'email' => 'Ce compte a été suspendu. Contacte le support.',
            ])->onlyInput('email');
        }

        $request->session()->regenerate();

        $defaultRedirect = Auth::user()->isAdmin()
            ? route('admin.dashboard')
            : route('tontines.index');

        return redirect()->intended($defaultRedirect);
    }

    public function destroy()
    {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('login');
    }
}
