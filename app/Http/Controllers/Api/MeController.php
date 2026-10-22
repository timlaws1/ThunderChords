<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $u = $request->user();

        return response()->json([
            'id' => (string) $u->id,
            'email' => $u->email,
            'name' => $u->name,
        ]);
    }
}
