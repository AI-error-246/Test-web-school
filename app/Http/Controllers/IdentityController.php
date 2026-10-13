<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdentityController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->attributes->get('supabase_user')]);
    }
}
