<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

class ApiDocsController extends Controller
{
    public function index(): View
    {
        return view('api-docs', [
            'specUrl' => url('/api/openapi.yaml'),
        ]);
    }

    public function spec(): Response
    {
        $path = resource_path('openapi/openapi.yaml');
        if (! File::isFile($path)) {
            abort(404);
        }

        return response(File::get($path), 200, [
            'Content-Type' => 'application/yaml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
