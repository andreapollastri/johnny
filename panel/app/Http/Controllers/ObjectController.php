<?php

namespace App\Http\Controllers;

use App\Services\GarageS3;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ObjectController extends Controller
{
    public function __construct(
        private GarageS3 $garage
    ) {}

    public function index(Request $request, string $bucket): View
    {
        $prefix = (string) $request->query('prefix', '');
        $objects = $this->garage->listObjects($bucket, $prefix);

        return view('objects.index', [
            'bucket' => $bucket,
            'prefix' => $prefix,
            'objects' => $objects,
        ]);
    }

    public function store(Request $request, string $bucket): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:512000'],
            'prefix' => ['nullable', 'string', 'max:1024'],
        ]);

        $prefix = rtrim((string) $request->input('prefix', ''), '/');
        $file = $request->file('file');
        $key = ($prefix !== '' ? $prefix.'/' : '').$file->getClientOriginalName();

        try {
            $this->garage->putObject(
                $bucket,
                $key,
                (string) file_get_contents($file->getRealPath()),
                $file->getMimeType()
            );
        } catch (\Throwable $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return redirect()->route('objects.index', ['bucket' => $bucket, 'prefix' => $prefix])->with('status', 'Uploaded.');
    }

    public function download(Request $request, string $bucket): StreamedResponse|Response
    {
        $key = (string) $request->query('key', '');
        if ($key === '') {
            abort(404);
        }
        try {
            $stream = $this->garage->getObjectStream($bucket, $key);
        } catch (\Throwable $e) {
            abort(404, $e->getMessage());
        }

        $filename = basename($key);

        return response()->streamDownload(function () use ($stream) {
            while (! $stream->eof()) {
                echo $stream->read(1048576);
            }
        }, $filename);
    }

    public function destroy(Request $request, string $bucket): RedirectResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:4096'],
            'prefix' => ['nullable', 'string', 'max:1024'],
        ]);
        $prefix = (string) ($validated['prefix'] ?? '');

        try {
            $this->garage->deleteObject($bucket, $validated['key']);
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect()->route('objects.index', ['bucket' => $bucket, 'prefix' => $prefix])->with('status', 'Deleted.');
    }
}
