<?php

namespace Tests\Feature;

use App\Models\Sync;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Transfers\SyncScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesJohnnyCli;
use Tests\Concerns\MakesTransfers;
use Tests\TestCase;

/**
 * Scheduled syncs: a transfer that repeats on an interval, in or out.
 */
class SyncsTest extends TestCase
{
    use FakesJohnnyCli, MakesTransfers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->fakeJohnny();
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function form(int $connectionId, array $fields = []): array
    {
        return $fields + [
            'direction' => 'export',
            'connection_id' => $connectionId,
            'remote_bucket' => 'media-offsite',
            'local_bucket' => 'media',
            'mode' => 'copy',
            'schedule' => 'repeat',
            'interval' => '5',
        ];
    }

    public function test_the_form_creates_a_sync_and_starts_its_first_run(): void
    {
        $this->freezeSecond();
        $connection = $this->makeConnection();

        $this->post('/transfers', $this->form($connection->id, ['create_bucket' => '1']))
            ->assertRedirect('/syncs/1')
            ->assertSessionHas('status', 'Sync created: every 5 minutes. The first run has started.');

        $sync = Sync::query()->sole();
        $this->assertSame(5, $sync->interval_minutes);
        $this->assertTrue($sync->enabled);
        $this->assertTrue($sync->next_run_at->equalTo(now()->addMinutes(5)->startOfMinute()));

        $this->assertDatabaseHas('transfers', [
            'sync_id' => $sync->id,
            'direction' => 'export',
            'local_bucket' => 'media',
            'remote_bucket' => 'media-offsite',
            'status' => 'pending',
            'dry_run' => false,
            'create_bucket' => true,
        ]);
    }

    public function test_a_sync_in_creates_the_local_bucket(): void
    {
        $connection = $this->makeConnection();

        $this->post('/transfers', $this->form($connection->id, [
            'direction' => 'import',
            'remote_bucket' => 'cernusco-media',
            'local_bucket' => 'mirror',
            'mode' => 'sync',
            'interval' => '1',
        ]))->assertRedirect('/syncs/1');

        $this->assertJohnnyRan(['bucket', 'create', 'mirror']);
        $this->assertDatabaseHas('syncs', ['direction' => 'import', 'mode' => 'sync', 'interval_minutes' => 1, 'local_bucket' => 'mirror']);
    }

    public function test_any_interval_can_be_typed(): void
    {
        $connection = $this->makeConnection();

        $this->post('/transfers', $this->form($connection->id, ['interval' => 'custom', 'every' => '2', 'unit' => 'hours']))
            ->assertRedirect('/syncs/1');

        $this->assertSame(120, Sync::query()->sole()->interval_minutes);
    }

    public function test_an_interval_that_makes_no_sense_is_refused(): void
    {
        $connection = $this->makeConnection();

        $this->post('/transfers', $this->form($connection->id, ['interval' => '7']))->assertSessionHasErrors('interval');
        $this->post('/transfers', $this->form($connection->id, ['interval' => 'custom', 'every' => '0', 'unit' => 'minutes']))->assertSessionHasErrors('every');
        $this->post('/transfers', $this->form($connection->id, ['interval' => 'custom', 'every' => '3', 'unit' => 'weeks']))->assertSessionHasErrors('unit');
        $this->post('/transfers', $this->form($connection->id, ['interval' => 'custom', 'every' => '500', 'unit' => 'days']))->assertSessionHasErrors('interval');

        $this->assertDatabaseCount('syncs', 0);
        $this->assertDatabaseCount('transfers', 0);
    }

    public function test_a_dry_run_is_not_a_schedule(): void
    {
        $connection = $this->makeConnection();

        $this->post('/transfers', $this->form($connection->id, ['dry_run' => '1']))->assertRedirect('/syncs/1');

        $this->assertDatabaseHas('transfers', ['sync_id' => 1, 'dry_run' => false]);
    }

    public function test_the_scheduler_starts_the_syncs_that_are_due(): void
    {
        $this->freezeSecond();
        $connection = $this->makeConnection();
        $due = $this->makeSync($connection);
        $later = $this->makeSync($connection, ['local_bucket' => 'default', 'next_run_at' => now()->addMinutes(3)]);
        $paused = $this->makeSync($connection, ['local_bucket' => 'tenant-acme', 'enabled' => false]);

        $this->artisan('johnny:syncs:run')->assertSuccessful();

        $this->assertSame(1, $due->runs()->count());
        $this->assertSame(0, $later->runs()->count());
        $this->assertSame(0, $paused->runs()->count());

        $due->refresh();
        $this->assertTrue($due->last_run_at->equalTo(now()));
        // Five minutes after the time it was planned for (a minute ago), on the minute.
        $this->assertTrue($due->next_run_at->equalTo(now()->addMinutes(4)->startOfMinute()));
        $this->assertNotNull($due->runs()->first()->launched_at);
        $this->assertTrue(app(SyncScheduler::class)->isRunning());
    }

    public function test_an_every_minute_sync_is_due_at_every_tick_of_cron(): void
    {
        $this->travelTo(now()->startOfMinute()->addSeconds(2));
        $sync = $this->makeSync($this->makeConnection(), ['interval_minutes' => 1, 'next_run_at' => now()->startOfMinute()]);

        // Cron runs the scheduler a couple of seconds into the minute.
        app(SyncScheduler::class)->runDue();
        $sync->runs()->update(['status' => Transfer::COMPLETED]);

        $this->travelTo(now()->addMinute());
        app(SyncScheduler::class)->runDue();

        $this->assertSame(2, $sync->runs()->count());
    }

    public function test_missed_runs_are_not_made_up_for(): void
    {
        $this->travelTo(now()->startOfMinute());
        $sync = $this->makeSync($this->makeConnection(), ['next_run_at' => now()->subHours(3)]);

        app(SyncScheduler::class)->runDue();

        $this->assertSame(1, $sync->runs()->count());
        $this->assertTrue($sync->refresh()->next_run_at->equalTo(now()->addMinutes(5)));
    }

    public function test_a_sync_never_runs_twice_at_the_same_time(): void
    {
        $connection = $this->makeConnection();
        $sync = $this->makeSync($connection);
        $this->makeTransfer($connection, ['sync_id' => $sync->id, 'status' => Transfer::RUNNING, 'started_at' => now(), 'heartbeat_at' => now()]);

        $this->artisan('johnny:syncs:run')->assertSuccessful();

        $this->assertSame(1, $sync->runs()->count());
        // Still due: it starts as soon as the run in progress ends.
        $this->assertTrue($sync->refresh()->isDue());
    }

    public function test_the_transfers_page_keeps_syncs_moving_without_cron(): void
    {
        $connection = $this->makeConnection();
        $sync = $this->makeSync($connection);

        $this->get('/transfers')
            ->assertOk()
            ->assertSee('Syncs')
            ->assertSee('Every 5 minutes')
            ->assertSee('The scheduler is not running');

        $this->assertSame(1, $sync->runs()->count());
    }

    public function test_the_warning_goes_away_once_cron_runs_the_scheduler(): void
    {
        $connection = $this->makeConnection();
        $this->makeSync($connection);

        $this->artisan('johnny:syncs:run')->assertSuccessful();

        $this->get('/transfers')->assertOk()->assertDontSee('The scheduler is not running');
    }

    public function test_sync_runs_stay_out_of_the_history(): void
    {
        $connection = $this->makeConnection();
        $sync = $this->makeSync($connection, ['next_run_at' => now()->addHour()]);
        $this->makeTransfer($connection, ['sync_id' => $sync->id, 'status' => Transfer::COMPLETED, 'remote_bucket' => 'media-offsite', 'direction' => Transfer::EXPORT]);
        $this->makeTransfer($connection, ['sync_id' => $sync->id, 'status' => Transfer::RUNNING, 'started_at' => now(), 'heartbeat_at' => now()]);

        $this->get('/transfers')->assertOk()->assertSee('Syncs')->assertDontSee('History');

        // The page still follows the run in progress.
        $this->getJson('/transfers/status')->assertJsonCount(1, 'transfers')->assertJsonPath('active', true);
    }

    public function test_the_sync_page_shows_its_schedule_and_runs(): void
    {
        $connection = $this->makeConnection();
        $sync = $this->makeSync($connection, ['interval_minutes' => 90, 'next_run_at' => now()->addHour()]);
        $this->makeTransfer($connection, ['sync_id' => $sync->id, 'status' => Transfer::FAILED, 'message' => 'The provider refused the credentials.']);

        $this->get("/syncs/{$sync->id}")
            ->assertOk()
            ->assertSee('Johnny: media')
            ->assertSee('Spaces production: media-offsite')
            ->assertSee('every 90 minutes')
            ->assertSee('The provider refused the credentials.');
    }

    public function test_a_sync_can_be_paused_and_resumed(): void
    {
        $connection = $this->makeConnection();
        $sync = $this->makeSync($connection, ['next_run_at' => now()->addHour()]);

        $this->post("/syncs/{$sync->id}/toggle")->assertSessionHas('status');
        $this->assertFalse($sync->refresh()->enabled);
        $this->assertSame(0, $sync->runs()->count());

        // Resuming does not wait for the old schedule.
        $this->post("/syncs/{$sync->id}/toggle")->assertSessionHas('status', 'Sync resumed.');
        $this->assertTrue($sync->refresh()->enabled);
        $this->assertSame(1, $sync->runs()->count());
    }

    public function test_a_sync_can_be_run_right_now(): void
    {
        $connection = $this->makeConnection();
        $sync = $this->makeSync($connection, ['next_run_at' => now()->addHour()]);

        $this->post("/syncs/{$sync->id}/run")->assertRedirect('/transfers/1');
        $this->post("/syncs/{$sync->id}/run")->assertSessionHasErrors('sync');

        $this->assertSame(1, $sync->runs()->count());
    }

    public function test_the_interval_and_the_mode_can_be_changed(): void
    {
        $this->freezeSecond();
        $connection = $this->makeConnection();
        $sync = $this->makeSync($connection, ['last_run_at' => now()->subMinutes(10), 'next_run_at' => now()->addMinutes(50)]);

        $this->put("/syncs/{$sync->id}", ['interval' => '15', 'mode' => 'sync'])
            ->assertRedirect("/syncs/{$sync->id}")
            ->assertSessionHas('status', 'Sync saved: every 15 minutes.');

        $sync->refresh();
        $this->assertSame(15, $sync->interval_minutes);
        $this->assertSame(Transfer::SYNC, $sync->mode);
        // Counted from the last run: 10 minutes ago plus 15, on the minute.
        $this->assertTrue($sync->next_run_at->equalTo(now()->addMinutes(5)->startOfMinute()));

        $this->put("/syncs/{$sync->id}", ['interval' => 'custom', 'every' => '0', 'unit' => 'hours', 'mode' => 'copy'])
            ->assertSessionHasErrors('every');
    }

    public function test_old_runs_are_dropped(): void
    {
        $connection = $this->makeConnection();
        $sync = $this->makeSync($connection);

        for ($i = 0; $i < Sync::KEEP_RUNS + 5; $i++) {
            $this->makeTransfer($connection, ['sync_id' => $sync->id, 'status' => Transfer::COMPLETED]);
        }

        $run = app(SyncScheduler::class)->start($sync);

        $this->assertNotNull($run);
        $this->assertSame(Sync::KEEP_RUNS, $sync->runs()->count());
        $this->assertTrue($sync->runs()->whereKey($run->id)->exists());
    }

    public function test_deleting_a_sync_removes_its_runs_but_waits_for_one_in_progress(): void
    {
        $connection = $this->makeConnection();
        $sync = $this->makeSync($connection, ['next_run_at' => now()->addHour()]);
        $run = $this->makeTransfer($connection, ['sync_id' => $sync->id, 'status' => Transfer::RUNNING, 'started_at' => now(), 'heartbeat_at' => now()]);

        $this->delete("/syncs/{$sync->id}")->assertSessionHasErrors('sync');
        $this->assertDatabaseCount('syncs', 1);

        $run->forceFill(['status' => Transfer::COMPLETED])->save();

        $this->delete("/syncs/{$sync->id}")->assertRedirect('/transfers');
        $this->assertDatabaseCount('syncs', 0);
        $this->assertDatabaseCount('transfers', 0);
    }

    public function test_a_connection_with_a_sync_cannot_be_deleted(): void
    {
        $connection = $this->makeConnection();
        $this->makeSync($connection, ['next_run_at' => now()->addHour()]);

        $this->delete("/connections/{$connection->id}")->assertSessionHasErrors('connection');

        $this->assertDatabaseCount('remote_connections', 1);
    }
}
