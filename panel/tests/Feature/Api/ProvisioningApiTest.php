<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesJohnnyCli;
use Tests\TestCase;

class ProvisioningApiTest extends TestCase
{
    use FakesJohnnyCli, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.garage.endpoint' => 'https://s3.example.com', 'services.garage.region' => 'johnny']);
        $this->fakeJohnny();
    }

    public function test_the_api_requires_a_token(): void
    {
        $this->postJson('/api/buckets/provision')->assertUnauthorized();
        $this->getJson('/api/buckets')->assertUnauthorized();

        $this->assertJohnnyDidntRun(['bucket']);
    }

    public function test_docs_and_spec_are_public(): void
    {
        $this->get('/api/docs')->assertOk()->assertSee('swagger-ui');
        $this->get('/api/openapi.yaml')->assertOk()->assertHeader('Content-Type', 'application/yaml; charset=UTF-8');
    }

    public function test_it_provisions_a_bucket_with_a_dedicated_key(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/buckets/provision', ['bucket' => 'tenant-acme'])
            ->assertCreated()
            ->assertJson([
                'bucket' => 'tenant-acme',
                'region' => 'johnny',
                'endpoint' => 'https://s3.example.com',
                'path_style' => true,
                'credentials' => ['access_key_id' => 'GK9e3b8d1f2a4c6e8b0d2f4a6c'],
                'env' => ['AWS_ENDPOINT_URL' => 'https://s3.example.com'],
            ]);

        $keyName = $response->json('key_name');
        $this->assertMatchesRegularExpression('/^tenant-acme-[0-9a-f]{8}$/', $keyName);

        $this->assertJohnnyRan(['bucket', 'create', 'tenant-acme']);
        $this->assertJohnnyRan(['key', 'create', $keyName]);
        $this->assertJohnnyRan(['bucket', 'allow', '--read', '--write', '--owner', 'tenant-acme', '--key', $keyName]);
        $this->assertJohnnyRan(['bucket', 'allow', '--read', '--write', '--owner', 'tenant-acme', '--key', 'johnny-default']);
        $this->assertJohnnyRan(['bucket', 'allow', '--read', 'tenant-acme', '--key', 'johnny-backup']);
    }

    public function test_a_bucket_name_is_generated_when_omitted(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/buckets/provision')
            ->assertCreated()
            ->assertJsonPath('bucket', fn ($name) => (bool) preg_match('/^b-[0-9a-f]{16}$/', $name));
    }

    public function test_invalid_names_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/buckets/provision', ['bucket' => '-Nope'])->assertUnprocessable();

        $this->assertJohnnyDidntRun(['bucket', 'create']);
    }

    public function test_cli_failures_are_reported(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->fakeJohnny(['bucket create' => Process::result('', 'Bucket name already in use', 1)]);

        $this->postJson('/api/buckets/provision', ['bucket' => 'media'])
            ->assertUnprocessable()
            ->assertJson(['message' => 'Failed to create bucket.', 'detail' => 'Bucket name already in use']);

        $this->assertJohnnyDidntRun(['key', 'create']);
    }
}
