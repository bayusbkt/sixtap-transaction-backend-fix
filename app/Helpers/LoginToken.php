<?php

namespace App\Helpers;

use Illuminate\Http\Request;

class LoginToken
{
    public static function getUserLoginFromToken(Request $request): int
    {
        return $request->input('auth_user')['id'];
    }
}