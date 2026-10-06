<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GarageS3;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Mockery;
use Tests\TestCase;

class ObjectBrowserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_it_lists_folders_and_files_with_a_next_page_link(): void
    {
        $this->mock(GarageS3::class)->shouldReceive('listObjects')
            ->with('media', 'photos/', null, 200)
            ->andReturn([
                'folders' => ['photos/2026/'],
                'files' => [['key' => 'photos/cover.jpg', 'size' => 2_400_000, 'last_modified' => '2026-10-06T22:25:00+00:00']],
                'next_token' => 'tok-2',
            ]);

        // route() encodes the trailing slash; a literal "?prefix=photos/" would be trimmed by the test client.
        $this->get(route('buckets.show', ['bucket' => 'media', 'prefix' => 'photos/']))
            ->assertOk()
            ->assertSee('2026/')
            ->assertSee('cover.jpg')
            ->assertSee('2.29 MB')
            ->assertSee('Parent folder')
            ->assertSee('token=tok-2', false);
    }

    public function test_continuation_tokens_are_forwarded(): void
    {
        $this->mock(GarageS3::class)->shouldReceive('listObjects')
            ->with('media', '', 'tok-2', 200)
            ->andReturn(['folders' => [], 'files' => [], 'next_token' => null]);

        $this->get('/buckets/media?token=tok-2')->assertOk()->assertSee('First page');
    }

    public function test_multiple_files_are_streamed_into_the_current_folder(): void
    {
        $garage = $this->mock(GarageS3::class);
        foreach (['docs/a.txt', 'docs/b.txt'] as $key) {
            $garage->shouldReceive('putObject')
                ->once()
                ->with('media', $key, Mockery::type('resource'), Mockery::any());
        }

        $this->post('/buckets/media/objects', [
            'prefix' => 'docs',
            'files' => [UploadedFile::fake()->create('a.txt', 4), UploadedFile::fake()->create('b.txt', 4)],
        ])
            ->assertRedirect('/buckets/media?tab=objects&prefix=docs%2F')
            ->assertSessionHas('status', '2 files uploaded.');
    }

    public function test_folders_can_be_created(): void
    {
        $this->mock(GarageS3::class)->shouldReceive('createFolder')->once()->with('media', 'docs/invoices/');

        $this->post('/buckets/media/folders', ['prefix' => 'docs/', 'name' => 'invoices'])
            ->assertRedirect('/buckets/media?tab=objects&prefix=docs%2Finvoices%2F');
    }

    public function test_folder_names_cannot_contain_slashes(): void
    {
        $this->mock(GarageS3::class)->shouldNotReceive('createFolder');

        $this->post('/buckets/media/folders', ['name' => 'a/b'])->assertSessionHasErrors('name');
        $this->post('/buckets/media/folders', ['name' => '..'])->assertSessionHasErrors('name');
    }
}
