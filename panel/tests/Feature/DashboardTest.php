<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GarageS3;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesJohnnyCli;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use FakesJohnnyCli, RefreshDatabase;

    public function test_it_summarises_buckets_and_caches_the_expensive_listing(): void
    {
        $this->actingAs(User::factory()->create());
        $this->fakeJohnny();

        $garage = $this->mock(GarageS3::class);
        $garage->shouldReceive('listBuckets')->once()->andReturn([
            ['name' => 'media', 'created' => null],
            ['name' => 'logs', 'created' => null],
        ]);
        $garage->shouldReceive('getBucketStats')->with('media')->once()->andReturn(['size' => 3 * 1024 ** 3, 'count' => 42]);
        $garage->shouldReceive('getBucketStats')->with('logs')->once()->andReturn(['size' => 512 * 1024 ** 2, 'count' => 1200]);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('3.50 GB')
            ->assertSee('1,242')
            ->assertSee('2 access keys')
            ->assertSeeInOrder(['Largest buckets', 'media', 'logs'])
            ->assertSeeInOrder(['Most objects', 'logs', 'media']);

        // Served from cache: the mock would fail on a second listBuckets() call.
        $this->get('/dashboard')->assertOk()->assertSee('3.50 GB');
    }

    public function test_storage_errors_are_reported_instead_of_crashing(): void
    {
        $this->actingAs(User::factory()->create());
        $this->fakeJohnny();
        $this->mock(GarageS3::class)->shouldReceive('listBuckets')->andThrow(new \RuntimeException('Garage S3 is not configured'));

        $this->get('/dashboard')->assertOk()->assertSee('Garage S3 is not configured');
    }
}
