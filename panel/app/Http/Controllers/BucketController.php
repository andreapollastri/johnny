<?php

namespace App\Http\Controllers;

use App\Services\GarageS3;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        try {
            $this->garage->createBucket($validated['name']);
        } catch (\Throwable $e) {
            return back()->withErrors(['name' => $e->getMessage()])->withInput();
        }

        return redirect()->route('buckets.index')->with('status', 'Bucket created.');
    }

    public function destroy(string $bucket): RedirectResponse
    {
        try {
            $this->garage->deleteBucket($bucket);
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect()->route('buckets.index')->with('status', 'Bucket deleted.');
    }
}
