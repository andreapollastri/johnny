<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\KeyScopes;
use Illuminate\Http\JsonResponse;

class ScopeApiController extends Controller
{
    /**
     * The scopes a key can get on a bucket, and the Garage permissions behind each.
     */
    public function __invoke(): JsonResponse
    {
        $scopes = [];

        foreach (KeyScopes::all() as $id => $scope) {
            $scopes[] = ['id' => $id] + $scope;
        }

        return response()->json(['data' => $scopes]);
    }
}
