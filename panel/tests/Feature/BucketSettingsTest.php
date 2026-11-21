<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GarageS3;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\FakesJohnnyCli;
use Tests\TestCase;

class BucketSettingsTest extends TestCase
{
    use FakesJohnnyCli, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.garage.endpoint' => 'https://s3.example.com', 'services.garage.region' => 'johnny']);
        $this->actingAs(User::factory()->create());
        $this->fakeJohnny(['bucket info' => $this->fixture('bucket-info-quotas')]);
    }

    public function test_the_settings_tab_shows_usage_quotas_and_rules(): void
    {
        $garage = $this->mock(GarageS3::class);
        $garage->shouldReceive('getCors')->once()->with('media')->andReturn([
            'origins' => ['https://app.example.com'], 'methods' => ['GET', 'PUT'], 'headers' => ['*'], 'max_age' => 3600, 'rules' => 1,
        ]);
        $garage->shouldReceive('getLifecycle')->once()->with('media')->andReturn([
            'expire_days' => 90, 'expire_prefix' => 'tmp/', 'abort_days' => 7, 'other_rules' => 2,
        ]);

        $this->get('/buckets/media?tab=settings')
            ->assertOk()
            ->assertSee('510.90 MB')
            ->assertSee('of 10.00 GB')
            ->assertSee('35 objects')
            ->assertSee('of 1,000')
            ->assertSee('https://app.example.com')
            ->assertSee('value="90"', false)
            ->assertSee('value="tmp/"', false)
            ->assertSee('2 more lifecycle rules written by another tool');
    }

    public function test_rules_that_cannot_be_read_do_not_break_the_tab(): void
    {
        $garage = $this->mock(GarageS3::class);
        $garage->shouldReceive('getCors')->andThrow(new \RuntimeException('AccessDenied'));
        $garage->shouldReceive('getLifecycle')->andReturn(['expire_days' => null, 'expire_prefix' => '', 'abort_days' => null, 'other_rules' => 0]);

        $this->get('/buckets/media?tab=settings')
            ->assertOk()
            ->assertSee('CORS rules could not be read: AccessDenied');
    }

    public function test_quotas_are_saved_in_bytes(): void
    {
        $this->post('/buckets/media/settings/quotas', ['max_size' => '2.5', 'max_size_unit' => 'GB', 'max_objects' => '5000'])
            ->assertRedirect('/buckets/media?tab=settings')
            ->assertSessionHas('status', 'Quotas saved.');

        $this->assertJohnnyRan(['bucket', 'set-quotas', '--max-size', '2684354560', '--max-objects', '5000', 'media']);
    }

    public function test_empty_quota_fields_remove_the_limits(): void
    {
        $this->post('/buckets/media/settings/quotas', ['max_size' => '', 'max_objects' => ''])
            ->assertSessionHas('status', 'Quotas removed.');

        $this->assertJohnnyRan(['bucket', 'set-quotas', '--max-size', 'none', '--max-objects', 'none', 'media']);
    }

    public function test_invalid_quotas_never_reach_the_cli(): void
    {
        $this->post('/buckets/media/settings/quotas', ['max_size' => '-1'])->assertSessionHasErrors('max_size');
        $this->post('/buckets/media/settings/quotas', ['max_size' => '1', 'max_size_unit' => 'PB'])->assertSessionHasErrors('max_size_unit');

        $this->assertJohnnyDidntRun(['bucket', 'set-quotas']);
    }

    public function test_a_refused_quota_is_reported(): void
    {
        $this->fakeJohnny(['bucket set-quotas' => Process::result('', 'Bucket not found', 1)]);

        $this->post('/buckets/media/settings/quotas', ['max_objects' => '10'])
            ->assertSessionHasErrors(['quotas' => 'Bucket not found']);
    }

    public function test_expiry_rules_are_written_through_s3(): void
    {
        $this->mock(GarageS3::class)->shouldReceive('putLifecycle')->once()->with('media', 30, 'logs/', 7);

        $this->post('/buckets/media/settings/lifecycle', ['expire_days' => '30', 'expire_prefix' => '/logs/', 'abort_days' => '7'])
            ->assertRedirect('/buckets/media?tab=settings')
            ->assertSessionHas('status', 'Expiry rules saved.');
    }

    public function test_empty_expiry_fields_remove_the_rules(): void
    {
        $this->mock(GarageS3::class)->shouldReceive('putLifecycle')->once()->with('media', null, '', null);

        $this->post('/buckets/media/settings/lifecycle', [])->assertSessionHas('status', 'Expiry rules removed.');
    }

    public function test_expiry_needs_whole_positive_days(): void
    {
        $this->mock(GarageS3::class)->shouldNotReceive('putLifecycle');

        $this->post('/buckets/media/settings/lifecycle', ['expire_days' => '0'])->assertSessionHasErrors('expire_days');
        $this->post('/buckets/media/settings/lifecycle', ['abort_days' => '1.5'])->assertSessionHasErrors('abort_days');
    }

    public function test_a_cors_rule_takes_origins_one_per_line(): void
    {
        $this->mock(GarageS3::class)->shouldReceive('putCors')->once()
            ->with('media', ['https://app.example.com', 'https://admin.example.com'], ['GET', 'PUT'], ['*'], 3600);

        $this->post('/buckets/media/settings/cors', [
            'origins' => "https://app.example.com\nhttps://admin.example.com\nhttps://app.example.com",
            'methods' => ['PUT', 'GET'],
        ])
            ->assertRedirect('/buckets/media?tab=settings')
            ->assertSessionHas('status', 'CORS rule saved.');
    }

    public function test_cors_origins_have_no_path(): void
    {
        $this->mock(GarageS3::class)->shouldNotReceive('putCors');

        $this->post('/buckets/media/settings/cors', ['origins' => 'https://app.example.com/upload', 'methods' => ['GET']])
            ->assertSessionHasErrors('origins');
        $this->post('/buckets/media/settings/cors', ['origins' => 'https://app.example.com', 'methods' => []])
            ->assertSessionHasErrors('methods');
        $this->post('/buckets/media/settings/cors', ['origins' => '*', 'methods' => ['PATCH']])
            ->assertSessionHasErrors('methods.0');
    }

    public function test_no_origins_removes_the_cors_rule(): void
    {
        $this->mock(GarageS3::class)->shouldReceive('putCors')->once()->with('media', [], [], ['*'], 3600);

        $this->post('/buckets/media/settings/cors', ['origins' => ''])->assertSessionHas('status', 'CORS rule removed.');
    }

    public function test_the_connect_tab_has_ready_to_paste_configuration(): void
    {
        $this->get('/buckets/media?tab=connect')
            ->assertOk()
            ->assertSee('https://s3.example.com')
            ->assertSee('AWS_BUCKET=media')
            ->assertSee('AWS_USE_PATH_STYLE_ENDPOINT=true')
            ->assertSee('YOUR_SECRET_ACCESS_KEY')
            ->assertSee('@aws-sdk/client-s3', false);
    }

    public function test_the_protected_bucket_has_no_delete_button_in_its_settings(): void
    {
        $garage = $this->mock(GarageS3::class);
        $garage->shouldReceive('getCors')->andReturn(['origins' => [], 'methods' => [], 'headers' => [], 'max_age' => 3600, 'rules' => 0]);
        $garage->shouldReceive('getLifecycle')->andReturn(['expire_days' => null, 'expire_prefix' => '', 'abort_days' => null, 'other_rules' => 0]);

        $this->get('/buckets/default?tab=settings')->assertOk()->assertDontSee('Delete bucket');
        $this->get('/buckets/media?tab=settings')->assertOk()->assertSee('Delete bucket');
    }
}
