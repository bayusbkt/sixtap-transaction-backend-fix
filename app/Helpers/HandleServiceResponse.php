<?php

namespace App\Helpers;

use Illuminate\Http\JsonResponse;

class HandleServiceResponse
{
    public static function format(array $result): JsonResponse
    {
        $response = [
            'status' => $result['status'],
            'message' => $result['message'],
        ];

        if ($response['status'] === 'error') {
            if (!empty($result['error'])) {
                $response['error'] = $result['error'];
                $statusCode = $result['code'] ?? 500;
            }
        } else {
            if (!empty($result['data'])) {
                $response['data'] = $result['data'];
                $statusCode = $result['code'] ?? 200;
            }
        }

        return response()->json($response, $statusCode);
    }
}
