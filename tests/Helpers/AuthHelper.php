<?php

namespace Tests\Helpers;

use Illuminate\Support\Facades\Http;

class AuthHelper
{
    protected static function getToken(string $identifier, string $password)
    {
        $response = Http::post('http://localhost:3000/login', [
            'identifier' => $identifier,
            'password' => $password
        ]);

        $cookies = $response->cookies();
        $accessTokenCookie = $cookies->getCookieByName('access_token');

        return $accessTokenCookie ? $accessTokenCookie->getValue() : null;
    }

    public static function getAdminTokenFromNodejs()
    {
        return self::getToken('admin@email.com', 'admin#123');
    }

    public static function getCanteenTokenFromNodejs()
    {
        return self::getToken('farhan@email.com', 'farhan123');
    }

    public static function getStudentTokenFromNodejs()
    {
        return self::getToken('260803', 'bayu123');
    }
}
