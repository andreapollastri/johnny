<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GarageS3;
use App\Support\Preview;
use Aws\Result;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ObjectActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        File::delete(File::glob(storage_path('app/private/uploads/*.part')) ?: []);

        parent::tearDown();
    }

    /**
     * One piece of a chunked upload of "report.txt" into docs/.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function sendChunk(string $id, int $index, int $total, int $offset, int $size, string $content, array $overrides = []): TestResponse
    {
        return $this->post('/buckets/media/objects/chunks', array_merge([
            'upload_id' => $id,
            'index' => $index,
            'total' => $total,
            'offset' => $offset,
            'size' => $size,
            'name' => 'report.txt',
            'type' => 'text/plain',
            'prefix' => 'docs',
            'chunk' => UploadedFile::fake()->createWithContent('chunk', $content),
        ], $overrides), ['Accept' => 'application/json']);
    }

    public function test_a_small_file_is_one_chunk(): void
    {
        $this->mock(GarageS3::class)->shouldReceive('uploadFile')->once()->withArgs(
            fn (string $bucket, string $key, string $path, ?string $type) => $bucket === 'media'
                && $key === 'docs/report.txt'
                && file_get_contents($path) === 'hello world'
                && $type === 'text/plain'
        );

        $this->sendChunk((string) Str::uuid(), 0, 1, 0, 11, 'hello world')
            ->assertOk()
            ->assertExactJson(['done' => true, 'key' => 'docs/report.txt']);

        $this->assertSame([], File::glob(storage_path('app/private/uploads/*.part')));
    }

    public function test_chunks_are_put_together_before_the_upload_to_garage(): void
    {
        $id = (string) Str::uuid();
        $garage = $this->mock(GarageS3::class);
        $garage->shouldReceive('uploadFile')->once()->withArgs(
            fn (string $bucket, string $key, string $path) => $key === 'docs/report.txt' && file_get_contents($path) === 'hello world'
        );

        $this->sendChunk($id, 0, 2, 0, 11, 'hello ')->assertOk()->assertExactJson(['received' => 1]);
        $this->sendChunk($id, 1, 2, 6, 11, 'world')->assertOk()->assertJsonPath('done', true);
    }

    public function test_a_chunk_out_of_order_stops_the_upload(): void
    {
        $id = (string) Str::uuid();
        $this->mock(GarageS3::class)->shouldNotReceive('uploadFile');

        $this->sendChunk($id, 0, 3, 0, 15, 'hello ')->assertOk();
        // The second chunk is lost: the third arrives with an offset the server does not have.
        $this->sendChunk($id, 2, 3, 11, 15, 'tail')->assertStatus(409);
    }

    public function test_an_incomplete_file_is_not_uploaded(): void
    {
        $this->mock(GarageS3::class)->shouldNotReceive('uploadFile');

        $this->sendChunk((string) Str::uuid(), 0, 1, 0, 999, 'short')
            ->assertStatus(422)
            ->assertJsonPath('message', 'The file arrived incomplete. Upload it again.');
    }

    public function test_upload_names_cannot_climb_out_of_the_folder(): void
    {
        $this->mock(GarageS3::class)->shouldReceive('uploadFile')->once()->withArgs(
            fn (string $bucket, string $key) => $key === 'docs/photos/a.txt'
        );

        $this->sendChunk((string) Str::uuid(), 0, 1, 0, 1, 'x', ['name' => '../../photos/./a.txt'])->assertOk();
    }

    public function test_a_failed_upload_to_garage_is_reported(): void
    {
        $this->mock(GarageS3::class)->shouldReceive('uploadFile')->andThrow(new \RuntimeException('QuotaExceeded'));

        $this->sendChunk((string) Str::uuid(), 0, 1, 0, 1, 'x')
            ->assertStatus(502)
            ->assertJsonPath('message', 'Upload of docs/report.txt failed: QuotaExceeded');
    }

    public function test_guests_cannot_upload(): void
    {
        Auth::logout();
        $this->mock(GarageS3::class)->shouldNotReceive('uploadFile');

        $this->sendChunk((string) Str::uuid(), 0, 1, 0, 1, 'x')->assertUnauthorized();
    }

    public function test_selected_files_and_folders_are_deleted_together(): void
    {
        $garage = $this->mock(GarageS3::class);
        $garage->shouldReceive('deleteObjects')->once()->with('media', ['docs/a.txt', 'docs/b.txt'])->andReturn(2);
        $garage->shouldReceive('deletePrefix')->once()->with('media', 'docs/old/', 5000)->andReturn(['deleted' => 40, 'truncated' => false]);

        $this->post('/buckets/media/objects/delete', [
            'prefix' => 'docs/',
            'keys' => ['docs/a.txt', 'docs/b.txt'],
            'folders' => ['docs/old/'],
        ])
            ->assertRedirect('/buckets/media?tab=objects&prefix=docs%2F')
            ->assertSessionHas('status', 'Deleted 42 objects.');
    }

    public function test_a_very_large_folder_is_deleted_in_several_passes(): void
    {
        $this->mock(GarageS3::class)->shouldReceive('deletePrefix')->once()->andReturn(['deleted' => 5000, 'truncated' => true]);

        $this->post('/buckets/media/objects/delete', ['folders' => ['logs/']])
            ->assertSessionHas('status', 'Deleted 5,000 objects. A folder holds more than 5,000 objects: delete it again to continue.');
    }

    public function test_deleting_needs_a_selection_and_never_takes_the_whole_bucket(): void
    {
        $garage = $this->mock(GarageS3::class);
        $garage->shouldNotReceive('deleteObjects');
        $garage->shouldNotReceive('deletePrefix');

        $this->post('/buckets/media/objects/delete', [])->assertSessionHasErrors('error');
        // "/" is the root of the bucket, not a folder.
        $this->post('/buckets/media/objects/delete', ['folders' => ['/']])->assertSessionHasErrors('error');
    }

    public function test_an_object_is_renamed_by_copy_then_delete(): void
    {
        $garage = $this->mock(GarageS3::class);
        $garage->shouldReceive('objectExists')->once()->with('media', 'docs/final.txt')->andReturn(false);
        $garage->shouldReceive('copyObject')->once()->with('media', 'docs/draft.txt', 'docs/final.txt')->ordered();
        $garage->shouldReceive('deleteObject')->once()->with('media', 'docs/draft.txt')->ordered();

        $this->post('/buckets/media/objects/move', ['key' => 'docs/draft.txt', 'to' => 'docs/final.txt', 'prefix' => 'docs/'])
            ->assertRedirect('/buckets/media?tab=objects&prefix=docs%2F')
            ->assertSessionHas('status', 'Moved to docs/final.txt.');
    }

    public function test_moving_to_the_root_shows_the_root(): void
    {
        $garage = $this->mock(GarageS3::class);
        $garage->shouldReceive('objectExists')->andReturn(false);
        $garage->shouldReceive('copyObject')->once()->with('media', 'docs/draft.txt', 'draft.txt');
        $garage->shouldReceive('deleteObject')->once();

        $this->post('/buckets/media/objects/move', ['key' => 'docs/draft.txt', 'to' => '/draft.txt', 'prefix' => 'docs/'])
            ->assertRedirect('/buckets/media?tab=objects');
    }

    public function test_a_move_never_overwrites_another_object(): void
    {
        $garage = $this->mock(GarageS3::class);
        $garage->shouldReceive('objectExists')->once()->with('media', 'docs/final.txt')->andReturn(true);
        $garage->shouldNotReceive('copyObject');
        $garage->shouldNotReceive('deleteObject');

        $this->post('/buckets/media/objects/move', ['key' => 'docs/draft.txt', 'to' => 'docs/final.txt'])
            ->assertSessionHasErrors('to');
    }

    public function test_a_move_needs_a_file_name(): void
    {
        $garage = $this->mock(GarageS3::class);
        $garage->shouldNotReceive('copyObject');

        $this->post('/buckets/media/objects/move', ['key' => 'docs/draft.txt', 'to' => 'archive/'])->assertSessionHasErrors('to');
    }

    public function test_details_describe_an_object_and_how_to_preview_it(): void
    {
        $this->mock(GarageS3::class)->shouldReceive('headObject')->once()->with('media', 'photos/cover.jpg')->andReturn([
            'key' => 'photos/cover.jpg',
            'size' => 2_400_000,
            'content_type' => 'image/jpeg',
            'etag' => '9b2cf535f27731c974343645a3985328',
            'last_modified' => '2026-10-06T22:25:00+00:00',
        ]);

        $this->getJson('/buckets/media/objects/info?key=photos%2Fcover.jpg')
            ->assertOk()
            ->assertJson([
                'name' => 'cover.jpg',
                'size_label' => '2.29 MB',
                'last_modified' => 'Oct 6, 2026 22:25',
                's3_uri' => 's3://media/photos/cover.jpg',
                'kind' => 'image',
                'truncated' => false,
            ])
            ->assertJsonPath('preview_url', route('objects.preview', ['bucket' => 'media', 'key' => 'photos/cover.jpg']));
    }

    public function test_details_of_a_missing_object_are_a_404(): void
    {
        $this->mock(GarageS3::class)->shouldReceive('headObject')->andThrow(new \RuntimeException('NoSuchKey'));

        $this->getJson('/buckets/media/objects/info?key=gone.txt')->assertNotFound();
    }

    public function test_files_that_cannot_be_shown_have_no_preview(): void
    {
        $garage = $this->mock(GarageS3::class);
        $garage->shouldNotReceive('getObject');

        $this->get('/buckets/media/objects/preview?key=archive.zip')->assertNotFound();
        $this->get('/buckets/media/objects/preview')->assertNotFound();
    }

    public function test_markup_is_previewed_as_sandboxed_plain_text(): void
    {
        $garage = $this->mock(GarageS3::class);
        $garage->shouldReceive('headObject')->once()->with('media', 'page.html')->andReturn(['size' => 25]);
        $garage->shouldReceive('getObject')->once()->with('media', 'page.html', null)->andReturn(new Result([
            'Body' => Utils::streamFor('<script>alert(1)</script>'),
            'ContentLength' => 25,
            'ContentType' => 'text/html',
        ]));

        $response = $this->get('/buckets/media/objects/preview?key=page.html');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
        $this->assertSame('<script>alert(1)</script>', $response->streamedContent());
    }

    public function test_only_the_beginning_of_a_long_text_file_is_previewed(): void
    {
        $garage = $this->mock(GarageS3::class);
        $garage->shouldReceive('headObject')->andReturn(['size' => Preview::TEXT_LIMIT + 1]);
        $garage->shouldReceive('getObject')->once()->with('media', 'app.log', 'bytes=0-'.(Preview::TEXT_LIMIT - 1))->andReturn(new Result([
            'Body' => Utils::streamFor('first lines'),
        ]));

        $this->get('/buckets/media/objects/preview?key=app.log')->assertOk();
    }

    public function test_video_previews_can_be_read_by_range(): void
    {
        $this->mock(GarageS3::class)->shouldReceive('getObject')->once()->with('media', 'clip.mp4', 'bytes=0-99')->andReturn(new Result([
            'Body' => Utils::streamFor(str_repeat('v', 100)),
            'ContentLength' => 100,
            'ContentRange' => 'bytes 0-99/1000',
        ]));

        $this->get('/buckets/media/objects/preview?key=clip.mp4', ['Range' => 'bytes=0-99'])
            ->assertStatus(206)
            ->assertHeader('Content-Type', 'video/mp4')
            ->assertHeader('Content-Range', 'bytes 0-99/1000');
    }

    public function test_a_share_link_is_signed_for_the_chosen_time(): void
    {
        $this->mock(GarageS3::class)->shouldReceive('presignedUrl')->once()
            ->with('media', 'photos/cover.jpg', 86400)
            ->andReturn('https://s3.example.com/media/photos/cover.jpg?X-Amz-Signature=abc');

        $this->postJson('/buckets/media/objects/share', ['key' => 'photos/cover.jpg', 'expires' => 86400])
            ->assertOk()
            ->assertJsonPath('url', 'https://s3.example.com/media/photos/cover.jpg?X-Amz-Signature=abc')
            ->assertJsonStructure(['url', 'expires_at']);
    }

    public function test_share_links_only_last_as_long_as_offered(): void
    {
        $this->mock(GarageS3::class)->shouldNotReceive('presignedUrl');

        $this->postJson('/buckets/media/objects/share', ['key' => 'photos/cover.jpg', 'expires' => 31_536_000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expires');
    }

    public function test_the_filter_lists_names_that_start_with_it(): void
    {
        $this->mock(GarageS3::class)->shouldReceive('listObjects')->once()
            ->with('media', 'photos/cov', null, 200)
            ->andReturn([
                'folders' => [],
                'files' => [['key' => 'photos/cover.jpg', 'size' => 10, 'last_modified' => null]],
                'next_token' => null,
            ]);

        $this->get(route('buckets.show', ['bucket' => 'media', 'prefix' => 'photos/', 'q' => 'cov']))
            ->assertOk()
            ->assertSee('cover.jpg')
            ->assertSee('value="cov"', false);
    }

    public function test_the_file_browser_carries_what_its_script_needs(): void
    {
        $this->mock(GarageS3::class)->shouldReceive('listObjects')->andReturn(['folders' => [], 'files' => [], 'next_token' => null]);

        $this->get('/buckets/media')
            ->assertOk()
            ->assertSee('This bucket is empty')
            ->assertSee('data-chunk-url="'.route('objects.chunk', 'media').'"', false)
            ->assertSee('js/browser.js', false);
    }
}
