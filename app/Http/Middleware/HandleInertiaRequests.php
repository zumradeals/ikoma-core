<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [...parent::share($request), 'auth' => ! in_array($request->path(), ['/', 'poste/shell', 'controle/shell'], true) && $request->user() ? ['id' => $request->user()->id, 'name' => $request->user()->name, 'permissions' => $request->user()->getAllPermissions()->pluck('name')] : null];
    }
}
