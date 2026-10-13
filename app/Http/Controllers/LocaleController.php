<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(array_keys(config('app.supported_locales')))],
            'return_to' => ['nullable', 'string', 'max:2048'],
        ]);
        $destination = $data['return_to'] ?? '/';
        $decoded = rawurldecode($destination);
        if (! str_starts_with($decoded, '/') || str_starts_with($decoded, '//')
            || preg_match('/[\\\\\x00-\x1f\x7f]/', $decoded) || str_starts_with($decoded, '/language')) {
            $destination = '/';
        }
        $request->session()->put('locale', $data['locale']);

        return redirect($destination, 303)->withCookie(cookie(
            'locale', $data['locale'], 60 * 24 * 180, '/', null,
            $request->isSecure(), true, false, 'lax'
        ));
    }
}
