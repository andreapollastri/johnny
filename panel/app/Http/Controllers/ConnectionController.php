<?php

namespace App\Http\Controllers;

use App\Models\RemoteConnection;
use App\Models\Sync;
use App\Models\Transfer;
use App\Services\ConnectionInput;
use App\Services\GarageS3;
use App\Services\RemoteS3;
use App\Support\StorageProviders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Connections to other S3 providers: the accounts buckets are imported from and exported to.
 */
class ConnectionController extends Controller
{
    public function __construct(
        private RemoteS3 $remote,
        private GarageS3 $garage
    ) {}

    /**
     * The buckets of the account, each ready to import, and the form to export a bucket to it.
     */
    public function show(RemoteConnection $connection): View
    {
        $remoteBuckets = [];
        $listError = null;

        try {
            $remoteBuckets = $this->remote->listBuckets($connection);
        } catch (\Throwable $e) {
            $listError = $this->remote->isAccessDenied($e)
                ? 'This access key is not allowed to list buckets. Type the name of the bucket instead.'
                : $this->remote->describe($e);
        }

        try {
            $localBuckets = array_column($this->garage->listBuckets(), 'name');
        } catch (\Throwable) {
            $localBuckets = [];
        }

        return view('transfers.connection', [
            'connection' => $connection,
            'provider' => StorageProviders::get($connection->provider),
            'providers' => StorageProviders::all(),
            'remoteBuckets' => $remoteBuckets,
            'listError' => $listError,
            'localBuckets' => $localBuckets,
            'transfers' => $connection->transfers()->latest('id')->limit(10)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $connection = RemoteConnection::query()->create(ConnectionInput::validate($request));

        $error = $this->remote->check($connection);

        return redirect()
            ->route('connections.show', $connection)
            ->with('status', $error === null ? "Connected to {$connection->name}." : null)
            ->withErrors($error === null ? [] : ['connection' => "Saved, but the provider did not accept the connection: {$error}"]);
    }

    public function update(Request $request, RemoteConnection $connection): RedirectResponse
    {
        $connection->update(ConnectionInput::validate($request, $connection));
        $error = $this->remote->check($connection);

        return redirect()
            ->route('connections.show', $connection)
            ->with('status', $error === null ? 'Connection saved.' : null)
            ->withErrors($error === null ? [] : ['connection' => "Saved, but the provider did not accept the connection: {$error}"]);
    }

    public function check(RemoteConnection $connection): RedirectResponse
    {
        $error = $this->remote->check($connection);

        return back()
            ->with('status', $error === null ? "{$connection->name} answers." : null)
            ->withErrors($error === null ? [] : ['connection' => "{$connection->name}: {$error}"]);
    }

    public function destroy(RemoteConnection $connection): RedirectResponse
    {
        if ($connection->transfers()->whereIn('status', [Transfer::PENDING, Transfer::RUNNING])->exists()) {
            return back()->withErrors(['connection' => 'A transfer is using this connection. Cancel it or wait for it to finish.']);
        }

        if (Sync::query()->where('remote_connection_id', $connection->id)->exists()) {
            return back()->withErrors(['connection' => 'A scheduled sync uses this connection. Delete the sync first.']);
        }

        $name = $connection->name;
        $connection->delete();

        return redirect()->route('transfers.index')->with('status', "Connection {$name} deleted. Its past transfers stay in the history.");
    }
}
