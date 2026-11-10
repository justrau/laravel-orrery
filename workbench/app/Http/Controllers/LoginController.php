<?php

namespace Workbench\App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Workbench\App\Support\WorkbenchAuth;

/**
 * The login form of the Workbench's strict-auth mode. It is not part of the package: a real
 * application signs its users in with its own login.
 */
final class LoginController
{
    public const VIEW = __DIR__.'/../../../resources/views/login.blade.php';

    public function show(Request $request): Response
    {
        $user = $request->user();

        return response(view()->file(self::VIEW, [
            'users' => WorkbenchAuth::USERS,
            'password' => WorkbenchAuth::PASSWORD,
            'current' => $user === null ? null : (string) $user->getAttribute('email'),
            'dashboard' => '/'.trim((string) config('orrery.path'), '/'),
            'error' => $request->session()->get('workbench_login_error'),
        ]));
    }

    public function login(Request $request): RedirectResponse
    {
        $email = $request->input('email');
        $password = $request->input('password');

        if (! is_string($email) || ! is_string($password) || ! Auth::guard('web')->attempt(['email' => $email, 'password' => $password])) {
            return redirect('/'.WorkbenchAuth::LOGIN_PATH)->with('workbench_login_error', 'Unknown email or wrong password.');
        }

        $request->session()->regenerate();

        return redirect('/'.trim((string) config('orrery.path'), '/'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/'.WorkbenchAuth::LOGIN_PATH);
    }
}
