<?php

namespace App\Http\Controllers;

use App\Models\AccessProfile;
use Illuminate\Http\JsonResponse;

class AccessProfilesController extends Controller
{
    public function index(): JsonResponse
    {
        $profiles = AccessProfile::query()
            ->select('id', 'label', 'company', 'role_type')
            ->orderBy('label')
            ->get();

        return response()->json(['data' => $profiles]);
    }
}
