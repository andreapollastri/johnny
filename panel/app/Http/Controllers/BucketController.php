<?php

namespace App\Http\Controllers;

use App\Services\GarageS3;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Illuminate\View\View;

class BucketController extends Controller
{
    public function __construct(
        private GarageS3 $garage
    ) {}

    public function index(): View
    {
        $buckets = $this->garage->listBuckets();

        return view('buckets.index', compact('buckets'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9._-]{1,254}$/'],
        ]);

        $name = $validated['name'];

        $result = Process::run([
            'sudo', '-u', 'johnny',
            '/usr/local/bin/johnny',
            'bucket', 'create', $name,
        ]);

        if (! $result->successful()) {
            return back()->withErrors(['name' => $result->errorOutput() ?: $result->output()])->withInput();
        }

        $this->allowKeyOnBucket($name);

        return redirect()->route('buckets.index')->with('status', 'Bucket created.');
    }

    public function destroy(string $bucket): RedirectResponse
    {
        // Garage asks for the bucket name on stdin as confirmation.
        $result = Process::input($bucket."\n")->run([
            'sudo', '-u', 'johnny',
            '/usr/local/bin/johnny',
            'bucket', 'delete', $bucket,
        ]);

        if (! $result->successful()) {
            return back()->withErrors(['error' => $result->errorOutput() ?: $result->output()]);
        }

        return redirect()->route('buckets.index')->with('status', 'Bucket deleted.');
    }

    private function allowKeyOnBucket(string $bucket): void
    {
        $key = config('services.garage.key_name', 'johnny-default');

        Process::run([
            'sudo', '-u', 'johnny',
            '/usr/local/bin/johnny',
            'bucket', 'allow', '--read', '--write', '--owner', $bucket, '--key', $key,
        ]);
    }
}
