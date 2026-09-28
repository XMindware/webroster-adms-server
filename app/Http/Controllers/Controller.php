<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * Identify who triggered an action, for "triggered_by" log context.
     *
     * Prefers the email and falls back to the id; null when the request is
     * unauthenticated.
     */
    protected function actorIdentifier(Request $request)
    {
        $user = $request->user();

        return optional($user)->email ?? optional($user)->id;
    }
}
