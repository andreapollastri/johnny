<?php

namespace App\Http\Controllers;

use App\Services\GarageS3;
use App\Support\Format;
use App\Support\Preview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ObjectController extends Controller
{
    /** Objects removed from one folder by one request; the page says so when a folder holds more. */
    private const DELETE_FOLDER_LIMIT = 5000;

    /** Share-link lifetimes offered, in seconds (SigV4 allows at most 7 days). */
    public const SHARE_LIFETIMES = [3600 => '1 hour', 86400 => '1 day', 259200 => '3 days', 604800 => '7 days'];

    /** Unfinished chunked uploads are removed after a day. */
    private const STALE_UPLOAD_SECONDS = 86400;

    public function __construct(
        private GarageS3 $garage
    ) {}

    /**
     * The plain form upload (no JavaScript). The file browser normally uploads in chunks: see chunk().
     */
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

    /**
     * One piece of a file. The browser cuts files into chunks smaller than PHP's upload limit and
     * sends them in order; they are appended to a temporary file, and the last one sends the
     * whole file to Garage (in parts, for large files). File size is limited by disk space only.
     */
    public function chunk(Request $request, string $bucket): JsonResponse
    {
        $validated = $request->validate([
            'upload_id' => ['required', 'uuid'],
            'index' => ['required', 'integer', 'min:0'],
            'total' => ['required', 'integer', 'min:1', 'max:1000000'],
            'offset' => ['required', 'integer', 'min:0'],
            'size' => ['required', 'integer', 'min:0'],
            'name' => ['required', 'string', 'max:1024'],
            'type' => ['nullable', 'string', 'max:255', 'regex:/^[\w.+-]+\/[\w.+-]+$/'],
            'prefix' => ['nullable', 'string', 'max:1024'],
            'chunk' => ['required', 'file'],
        ]);

        $name = $this->safeRelativePath($validated['name']);
        if ($name === '') {
            return response()->json(['message' => 'The file name is not valid.'], 422);
        }

        $directory = storage_path('app/private/uploads');
        File::ensureDirectoryExists($directory);
        $part = $directory.'/'.$request->user()->getAuthIdentifier().'-'.$validated['upload_id'].'.part';
        $index = (int) $validated['index'];

        if ($index === 0) {
            $this->pruneStaleUploads($directory);
            File::delete($part);
        }

        clearstatcache(true, $part);
        if ((is_file($part) ? filesize($part) : 0) !== (int) $validated['offset']) {
            File::delete($part);

            return response()->json(['message' => 'The upload was interrupted. Start it again.'], 409);
        }

        File::append($part, $request->file('chunk')->getContent());

        if ($index < (int) $validated['total'] - 1) {
            return response()->json(['received' => $index + 1]);
        }

        clearstatcache(true, $part);
        if (filesize($part) !== (int) $validated['size']) {
            File::delete($part);

            return response()->json(['message' => 'The file arrived incomplete. Upload it again.'], 422);
        }

        $key = $this->normalizePrefix((string) ($validated['prefix'] ?? '')).$name;

        try {
            $this->garage->uploadFile($bucket, $key, $part, $validated['type'] ?? null);
        } catch (\Throwable $e) {
            return response()->json(['message' => "Upload of {$key} failed: {$e->getMessage()}"], 502);
        } finally {
            File::delete($part);
        }

        return response()->json(['done' => true, 'key' => $key]);
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

    /**
     * What the details panel of the file browser shows for one object.
     */
    public function info(Request $request, string $bucket): JsonResponse
    {
        $key = (string) $request->query('key', '');
        if ($key === '') {
            abort(404);
        }

        try {
            $object = $this->garage->headObject($bucket, $key);
        } catch (\Throwable) {
            return response()->json(['message' => 'This object does not exist any more.'], 404);
        }

        $kind = Preview::kind($key);

        return response()->json([
            'key' => $key,
            'name' => basename($key),
            'size' => $object['size'],
            'size_label' => Format::bytes($object['size']),
            'content_type' => $object['content_type'],
            'etag' => $object['etag'],
            'last_modified' => Format::date($object['last_modified']),
            's3_uri' => "s3://{$bucket}/{$key}",
            'kind' => $kind,
            'truncated' => $kind === 'text' && $object['size'] > Preview::TEXT_LIMIT,
            'preview_url' => $kind ? route('objects.preview', ['bucket' => $bucket, 'key' => $key]) : null,
            'download_url' => route('objects.download', ['bucket' => $bucket, 'key' => $key]),
        ]);
    }

    /**
     * An object shown in the browser instead of downloaded: images, audio, video, PDF, and the
     * beginning of text files. The type comes from the extension (see Preview) and the response is
     * sandboxed, so an uploaded HTML or SVG file can never run in the panel's origin.
     */
    public function preview(Request $request, string $bucket): StreamedResponse
    {
        $key = (string) $request->query('key', '');
        $kind = Preview::kind($key);
        if ($key === '' || $kind === null) {
            abort(404);
        }

        $range = $request->headers->get('Range');
        if (! is_string($range) || ! preg_match('/^bytes=\d*-\d*$/', $range)) {
            $range = null;
        }

        try {
            if ($kind === 'text') {
                $size = $this->garage->headObject($bucket, $key)['size'];
                $range = $size > Preview::TEXT_LIMIT ? 'bytes=0-'.(Preview::TEXT_LIMIT - 1) : null;
            }

            $object = $this->garage->getObject($bucket, $key, $range);
        } catch (\Throwable $e) {
            abort(404, $e->getMessage());
        }

        $partial = $kind !== 'text' && $range !== null && isset($object['ContentRange']);
        $stream = $object['Body'];
        $headers = array_filter([
            'Content-Type' => Preview::contentType($key),
            'Content-Length' => isset($object['ContentLength']) ? (string) $object['ContentLength'] : null,
            'Content-Range' => $partial ? (string) $object['ContentRange'] : null,
            'Content-Disposition' => 'inline; filename="'.addcslashes(basename($key), '"\\').'"',
            'Accept-Ranges' => $kind === 'text' ? null : 'bytes',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            // Chrome's PDF viewer does not load in a sandboxed response; a PDF cannot script the page anyway.
            'Content-Security-Policy' => $kind === 'pdf' ? null : "sandbox; default-src 'none'; style-src 'unsafe-inline'",
        ]);

        return response()->stream(function () use ($stream) {
            while (! $stream->eof()) {
                echo $stream->read(1048576);
            }
        }, $partial ? 206 : 200, $headers);
    }

    /**
     * A link that lets anyone download the object until it expires, signed with the panel's key.
     */
    public function share(Request $request, string $bucket): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:4096'],
            'expires' => ['required', 'integer', Rule::in(array_keys(self::SHARE_LIFETIMES))],
        ]);
        $seconds = (int) $validated['expires'];

        try {
            $url = $this->garage->presignedUrl($bucket, $validated['key'], $seconds);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'url' => $url,
            'expires_at' => Format::date(now()->addSeconds($seconds)->toIso8601String()),
        ]);
    }

    /**
     * Rename an object or move it to another folder of the same bucket (copy, then delete).
     */
    public function move(Request $request, string $bucket): RedirectResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:4096'],
            'to' => ['required', 'string', 'max:1024'],
            'prefix' => ['nullable', 'string', 'max:1024'],
        ]);

        $from = $validated['key'];
        $to = $this->safeRelativePath($validated['to']);
        $prefix = (string) ($validated['prefix'] ?? '');

        if ($to === '' || str_ends_with($validated['to'], '/')) {
            return $this->backToFolder($bucket, $prefix)->withErrors(['to' => 'Enter the new path of the file, including its name.']);
        }

        if ($to === $from) {
            return $this->backToFolder($bucket, $prefix);
        }

        try {
            if ($this->garage->objectExists($bucket, $to)) {
                return $this->backToFolder($bucket, $prefix)->withErrors(['to' => "\"{$to}\" already exists in this bucket."]);
            }

            $this->garage->copyObject($bucket, $from, $to);
            $this->garage->deleteObject($bucket, $from);
        } catch (\Throwable $e) {
            return $this->backToFolder($bucket, $prefix)->withErrors(['to' => $e->getMessage()]);
        }

        $folder = str_contains($to, '/') ? dirname($to).'/' : '';

        return $this->backToFolder($bucket, $folder)->with('status', 'Moved to '.$to.'.');
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
     * Delete the selected files and folders (a folder goes with everything inside it).
     */
    public function destroyMany(Request $request, string $bucket): RedirectResponse
    {
        $validated = $request->validate([
            'keys' => ['nullable', 'array', 'max:1000'],
            'keys.*' => ['string', 'max:4096'],
            'folders' => ['nullable', 'array', 'max:100'],
            'folders.*' => ['string', 'max:4096'],
            'prefix' => ['nullable', 'string', 'max:1024'],
        ]);

        $prefix = (string) ($validated['prefix'] ?? '');
        $keys = array_values(array_filter($validated['keys'] ?? [], fn (string $key) => $key !== ''));
        $folders = array_values(array_filter(
            array_map(fn (string $folder) => $this->normalizePrefix($folder), $validated['folders'] ?? []),
            fn (string $folder) => $folder !== '',
        ));

        if ($keys === [] && $folders === []) {
            return $this->backToFolder($bucket, $prefix)->withErrors(['error' => 'Select at least one file or folder.']);
        }

        $deleted = 0;
        $truncated = false;

        try {
            if ($keys !== []) {
                $deleted += $this->garage->deleteObjects($bucket, $keys);
            }

            foreach ($folders as $folder) {
                $result = $this->garage->deletePrefix($bucket, $folder, self::DELETE_FOLDER_LIMIT);
                $deleted += $result['deleted'];
                $truncated = $truncated || $result['truncated'];
            }
        } catch (\Throwable $e) {
            return $this->backToFolder($bucket, $prefix)
                ->withErrors(['error' => $e->getMessage()])
                ->with('status', $deleted > 0 ? "Deleted {$deleted} object(s) before the error." : null);
        }

        $status = $deleted === 1 ? 'Deleted 1 object.' : 'Deleted '.number_format($deleted).' objects.';
        if ($truncated) {
            $status .= ' A folder holds more than '.number_format(self::DELETE_FOLDER_LIMIT).' objects: delete it again to continue.';
        }

        return $this->backToFolder($bucket, $prefix)->with('status', $status);
    }

    /**
     * "" stays "" (bucket root); anything else becomes "a/b/".
     */
    private function normalizePrefix(string $prefix): string
    {
        $prefix = trim($prefix, '/');

        return $prefix === '' ? '' : $prefix.'/';
    }

    /**
     * "docs//../a.txt" → "docs/a.txt": a key relative to a folder, without empty, "." or ".." segments.
     */
    private function safeRelativePath(string $path): string
    {
        $segments = array_filter(
            explode('/', str_replace('\\', '/', $path)),
            fn (string $segment) => ! in_array(trim($segment), ['', '.', '..'], true),
        );

        return implode('/', $segments);
    }

    private function pruneStaleUploads(string $directory): void
    {
        foreach (File::glob($directory.'/*.part') ?: [] as $file) {
            if (@filemtime($file) < time() - self::STALE_UPLOAD_SECONDS) {
                File::delete($file);
            }
        }
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
