<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\GarageS3;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\Concerns\FakesJohnnyCli;
use Tests\TestCase;

class BucketSettingsApiTest extends TestCase
{
    use FakesJohnnyCli, RefreshDatabase;

    private const CORS = ['origins' => ['https://app.example.com'], 'methods' => ['GET', 'PUT'], 'headers' => ['*'], 'max_age' => 3600, 'rules' => 1];

    private const LIFECYCLE = ['expire_days' => 30, 'expire_prefix' => 'tmp/', 'abort_days' => null, 'other_rules' => 0];

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());
        $this->fakeJohnny(['bucket info' => $this->fixture('bucket-info-quotas')]);
    }

    public function test_settings_show_usage_quotas_cors_and_expiry_rules(): void
    {
        $this->mock(GarageS3::class, function (MockInterface $garage) {
            $garage->shouldReceive('getCors')->with('media')->andReturn(self::CORS);
            $garage->shouldReceive('getLifecycle')->with('media')->andReturn(self::LIFECYCLE);
        });

        $this->getJson('/api/buckets/media/settings')
            ->assertOk()
            ->assertJsonPath('data.usage.objects', 35)
            ->assertJsonPath('data.quotas', ['max_size' => 10 * 1024 ** 3, 'max_objects' => 1000])
            ->assertJsonPath('data.cors.origins', ['https://app.example.com'])
            ->assertJsonPath('data.lifecycle.expire_days', 30);
    }

    public function test_quotas_are_set_in_bytes_and_removed_with_null(): void
    {
        $this->putJson('/api/buckets/media/quotas', ['max_size' => 1024 ** 3, 'max_objects' => null])
            ->assertOk()
            ->assertJsonPath('data', ['bucket' => 'media', 'max_size' => 1024 ** 3, 'max_objects' => null]);

        $this->assertJohnnyRan(['bucket', 'set-quotas', '--max-size', '1073741824', '--max-objects', 'none', 'media']);
    }

    public function test_both_quotas_must_be_sent(): void
    {
        $this->putJson('/api/buckets/media/quotas', ['max_size' => 1024])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('max_objects');

        $this->assertJohnnyDidntRun(['bucket', 'set-quotas']);
    }

    public function test_a_cors_rule_is_saved(): void
    {
        $this->mock(GarageS3::class, function (MockInterface $garage) {
            $garage->shouldReceive('putCors')->once()->with('media', ['https://app.example.com'], ['GET', 'PUT'], ['*'], 3600);
            $garage->shouldReceive('getCors')->with('media')->andReturn(self::CORS);
        });

        $this->putJson('/api/buckets/media/cors', ['origins' => ['https://app.example.com'], 'methods' => ['PUT', 'GET']])
            ->assertOk()
            ->assertJsonPath('data.methods', ['GET', 'PUT']);
    }

    public function test_an_empty_origin_list_removes_the_cors_rule(): void
    {
        $this->mock(GarageS3::class, function (MockInterface $garage) {
            $garage->shouldReceive('putCors')->once()->with('media', [], [], ['*'], 3600);
            $garage->shouldReceive('getCors')->andReturn(['origins' => [], 'methods' => [], 'headers' => [], 'max_age' => 3600, 'rules' => 0]);
        });

        $this->putJson('/api/buckets/media/cors', ['origins' => []])->assertOk()->assertJsonPath('data.rules', 0);
    }

    public function test_a_cors_rule_needs_real_origins_and_a_method(): void
    {
        $this->mock(GarageS3::class)->shouldNotReceive('putCors');

        $this->putJson('/api/buckets/media/cors', ['origins' => ['app.example.com/path'], 'methods' => ['GET']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('origins.0');

        $this->putJson('/api/buckets/media/cors', ['origins' => ['*']])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'List at least one method.');
    }

    public function test_expiry_rules_are_saved(): void
    {
        $this->mock(GarageS3::class, function (MockInterface $garage) {
            $garage->shouldReceive('putLifecycle')->once()->with('media', 30, 'tmp/', null);
            $garage->shouldReceive('getLifecycle')->with('media')->andReturn(self::LIFECYCLE);
        });

        $this->putJson('/api/buckets/media/lifecycle', ['expire_days' => 30, 'expire_prefix' => '/tmp/', 'abort_days' => null])
            ->assertOk()
            ->assertJsonPath('data.expire_prefix', 'tmp/');
    }

    public function test_settings_of_an_unknown_bucket_are_not_found(): void
    {
        $this->mock(GarageS3::class)->shouldNotReceive('putLifecycle');

        $this->getJson('/api/buckets/nope/settings')->assertNotFound();
        $this->putJson('/api/buckets/nope/quotas', ['max_size' => null, 'max_objects' => null])->assertNotFound();
        $this->putJson('/api/buckets/nope/lifecycle', ['expire_days' => 1, 'abort_days' => null])->assertNotFound();

        $this->assertJohnnyDidntRun(['bucket', 'set-quotas']);
    }
}
