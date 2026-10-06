<?php

namespace App\Http\Controllers;

use App\Services\GarageS3;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ObjectController extends Controller
{
    public function __construct(
        private GarageS3 $garage
    ) {}

    public function store(Request $request, string $bucket): RedirectResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'max:50'],
            'files.*' => ['file', 'max:512000'],
            'prefix' => ['nullable', 'string', 'max:1024'],
        ]);

        $prefix = $this->normalizePrefix((string) $request->input('prefix', ''));
        $uploaded = 0;

        foreach ($request->file('files') as $file) {
            $key = $prefix.basename($file->getClientOriginalName());
            $stream = fopen($file->getRealPath(), 'rb');

            try {
                $this->garage->putObject($bucket, $key, $stream, $file->getMimeType());
                $uploaded++;
            } catch (\Throwable $e) {
                return $this->backToFolder($bucket, $prefix)
                    ->withErrors(['files' => "Upload of {$key} failed: {$e->getMessage()}"])
                    ->with('status', $uploaded > 0 ? "Uploaded {$uploaded} file(s) before the error." : null);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }

        return $this->backToFolder($bucket, $prefix)
            ->with('status', $uploaded === 1 ? 'File uploaded.' : "{$uploaded} files uploaded.");
    }

    public function storeFolder(Request $request, string $bucket): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[^\/\\\\]+$/', 'not_in:.,..'],
            'prefix' => ['nullable', 'string', 'max:1024'],
        ]);

        $prefix = $this->normalizePrefix((string) ($validated['prefix'] ?? ''));
        $folder = $prefix.$validated['name'].'/';

        try {
            $this->garage->createFolder($bucket, $folder);
        } catch (\Throwable $e) {
            return $this->backToFolder($bucket, $prefix)->withErrors(['name' => $e->getMessage()]);
        }

        return $this->backToFolder($bucket, $folder)->with('status', 'Folder created.');
    }

    public function download(Request $request, string $bucket): StreamedResponse
    {
        $key = (string) $request->query('key', '');
        if ($key === '') {
            abort(404);
        }

        try {
            $object = $this->garage->getObject($bucket, $key);
        } catch (\Throwable $e) {
            abort(404, $e->getMessage());
        }

        $stream = $object['Body'];
        $headers = array_filter([
            'Content-Type' => $object['ContentType'] ?? 'application/octet-stream',
            'Content-Length' => isset($object['ContentLength']) ? (string) $object['ContentLength'] : null,
        ]);

        return response()->streamDownload(function () use ($stream) {
            while (! $stream->eof()) {
                echo $stream->read(1048576);
            }
        }, basename($key), $headers);
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

        return $this->backToFolder($bucket, $prefix)->with('status', 'Deleted.');
    }

    /**
     * "" stays "" (bucket root); anything else becomes "a/b/".
     */
    private function normalizePrefix(string $prefix): string
    {
        $prefix = trim($prefix, '/');

        return $prefix === '' ? '' : $prefix.'/';
    }

    private function backToFolder(string $bucket, string $prefix): RedirectResponse
    {
        return redirect()->route('buckets.show', array_filter([
            'bucket' => $bucket,
            'tab' => 'objects',
            'prefix' => $prefix,
        ]));
    }
}
