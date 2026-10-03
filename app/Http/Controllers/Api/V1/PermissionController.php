<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    /**
     * Permission registry grouped by module, used by the role editor.
     */
    public function index(PermissionRegistry $registry): JsonResponse
    {
        return response()->json(['data' => $registry->grouped()]);
    }
}
