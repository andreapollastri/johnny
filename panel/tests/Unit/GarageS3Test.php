<?php

namespace Tests\Unit;

use App\Services\GarageS3;
use Aws\Command;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Tests\TestCase;

/**
 * GarageS3 against the AWS SDK's own mock handler: the commands it sends and how it reads the answers.
 */
class GarageS3Test extends TestCase
{
    /** @var list<CommandInterface> */
    private array $sent = [];

    /**
     * A GarageS3 whose client answers with the given results, in order, and records each command.
     *
     * @param  list<Result|\Throwable>  $answers
     */
    private function garage(array $answers): GarageS3
    {
        $this->sent = [];
        $handler = new MockHandler;

        foreach ($answers as $answer) {
            $handler->append(function (CommandInterface $command) use ($answer) {
                $this->sent[] = $command;

                return $answer;
            });
        }

        $client = new S3Client([
            'version' => '2006-03-01',
            'region' => 'johnny',
            'endpoint' => 'https://s3.example.com',
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => 'GKtest', 'secret' => 'secret'],
            'handler' => $handler,
        ]);

        $garage = Mockery::mock(GarageS3::class)->makePartial();
        $garage->shouldReceive('client')->andReturn($client);

        return $garage;
    }

    private function missing(string $operation, string $code): S3Exception
    {
        return new S3Exception($code, new Command($operation), ['code' => $code, 'response' => new Response(404)]);
    }

    public function test_copy_encodes_the_source_key_but_keeps_its_slashes(): void
    {
        $this->garage([new Result])->copyObject('media', 'my docs/a b.txt', 'archive/a.txt');

        $this->assertSame('CopyObject', $this->sent[0]->getName());
        $this->assertSame('media/my%20docs/a%20b.txt', $this->sent[0]['CopySource']);
        $this->assertSame('archive/a.txt', $this->sent[0]['Key']);
    }

    public function test_many_objects_are_deleted_a_thousand_per_request(): void
    {
        $keys = array_map(fn (int $i) => "logs/{$i}.log", range(1, 2500));

        $deleted = $this->garage([new Result, new Result, new Result])->deleteObjects('media', $keys);

        $this->assertSame(2500, $deleted);
        $this->assertCount(3, $this->sent);
        $this->assertCount(1000, $this->sent[0]['Delete']['Objects']);
        $this->assertCount(500, $this->sent[2]['Delete']['Objects']);
        $this->assertSame(['Key' => 'logs/1.log'], $this->sent[0]['Delete']['Objects'][0]);
    }

    public function test_an_object_garage_refuses_to_delete_is_reported(): void
    {
        $garage = $this->garage([new Result(['Errors' => [['Key' => 'a.txt', 'Code' => 'AccessDenied', 'Message' => 'Access Denied']]])]);

        $this->expectExceptionMessage('Could not delete a.txt: Access Denied');

        $garage->deleteObjects('media', ['a.txt', 'b.txt']);
    }

    public function test_a_folder_is_deleted_with_everything_under_its_prefix(): void
    {
        $garage = $this->garage([
            new Result(['Contents' => [['Key' => 'tmp/a'], ['Key' => 'tmp/sub/b']], 'IsTruncated' => false]),
            new Result,
        ]);

        $this->assertSame(['deleted' => 2, 'truncated' => false], $garage->deletePrefix('media', 'tmp/'));
        $this->assertSame('ListObjectsV2', $this->sent[0]->getName());
        $this->assertSame('tmp/', $this->sent[0]['Prefix']);
        $this->assertSame([['Key' => 'tmp/a'], ['Key' => 'tmp/sub/b']], $this->sent[1]['Delete']['Objects']);
    }

    public function test_a_folder_larger_than_the_limit_is_left_for_another_pass(): void
    {
        $garage = $this->garage([
            new Result(['Contents' => [['Key' => 'tmp/a'], ['Key' => 'tmp/b']], 'IsTruncated' => true, 'NextContinuationToken' => 'next']),
            new Result,
        ]);

        $this->assertSame(['deleted' => 2, 'truncated' => true], $garage->deletePrefix('media', 'tmp/', 2));
        $this->assertSame(2, $this->sent[0]['MaxKeys']);
    }

    public function test_an_empty_prefix_is_never_deleted(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->garage([])->deletePrefix('media', '');
    }

    public function test_expiry_rules_are_read_from_the_panels_own_rules(): void
    {
        $garage = $this->garage([new Result(['Rules' => [
            ['ID' => GarageS3::EXPIRE_RULE, 'Status' => 'Enabled', 'Filter' => ['Prefix' => 'tmp/'], 'Expiration' => ['Days' => 30]],
            ['ID' => GarageS3::ABORT_UPLOADS_RULE, 'Status' => 'Enabled', 'AbortIncompleteMultipartUpload' => ['DaysAfterInitiation' => 7]],
            ['ID' => 'written-by-terraform', 'Status' => 'Enabled', 'Expiration' => ['Days' => 365]],
        ]])]);

        $this->assertSame(
            ['expire_days' => 30, 'expire_prefix' => 'tmp/', 'abort_days' => 7, 'other_rules' => 1],
            $garage->getLifecycle('media'),
        );
    }

    public function test_a_bucket_without_expiry_rules_reads_as_empty(): void
    {
        $empty = ['expire_days' => null, 'expire_prefix' => '', 'abort_days' => null, 'other_rules' => 0];

        // Garage answers 204 with no body, S3 answers 404.
        $this->assertSame($empty, $this->garage([new Result])->getLifecycle('media'));
        $this->assertSame($empty, $this->garage([
            $this->missing('GetBucketLifecycleConfiguration', 'NoSuchLifecycleConfiguration'),
        ])->getLifecycle('media'));
    }

    public function test_saving_expiry_rules_keeps_the_rules_of_other_tools(): void
    {
        $foreign = ['ID' => 'written-by-terraform', 'Status' => 'Enabled', 'Filter' => ['Prefix' => 'x/'], 'Expiration' => ['Days' => 365]];
        $garage = $this->garage([
            new Result(['Rules' => [
                $foreign,
                ['ID' => GarageS3::EXPIRE_RULE, 'Status' => 'Enabled', 'Filter' => ['Prefix' => ''], 'Expiration' => ['Days' => 5]],
            ]]),
            new Result,
        ]);

        $garage->putLifecycle('media', 30, 'logs/', 7);

        $this->assertSame('PutBucketLifecycleConfiguration', $this->sent[1]->getName());
        $rules = $this->sent[1]['LifecycleConfiguration']['Rules'];
        $this->assertSame(['written-by-terraform', GarageS3::EXPIRE_RULE, GarageS3::ABORT_UPLOADS_RULE], array_column($rules, 'ID'));
        $this->assertSame($foreign, $rules[0]);
        $this->assertSame(['Prefix' => 'logs/'], $rules[1]['Filter']);
        $this->assertSame(['Days' => 30], $rules[1]['Expiration']);
        $this->assertSame(['DaysAfterInitiation' => 7], $rules[2]['AbortIncompleteMultipartUpload']);
    }

    public function test_removing_the_last_expiry_rule_deletes_the_configuration(): void
    {
        $garage = $this->garage([
            new Result(['Rules' => [['ID' => GarageS3::EXPIRE_RULE, 'Status' => 'Enabled', 'Expiration' => ['Days' => 5]]]]),
            new Result,
        ]);

        $garage->putLifecycle('media', null, '', null);

        $this->assertSame('DeleteBucketLifecycle', $this->sent[1]->getName());
    }

    public function test_cors_is_read_and_written_as_one_rule(): void
    {
        $garage = $this->garage([
            new Result(['CORSRules' => [
                ['AllowedOrigins' => ['https://app.example.com'], 'AllowedMethods' => ['GET', 'PUT'], 'AllowedHeaders' => ['*'], 'MaxAgeSeconds' => 600],
                ['AllowedOrigins' => ['*'], 'AllowedMethods' => ['GET']],
            ]]),
            new Result,
            new Result,
        ]);

        $this->assertSame(
            ['origins' => ['https://app.example.com'], 'methods' => ['GET', 'PUT'], 'headers' => ['*'], 'max_age' => 600, 'rules' => 2],
            $garage->getCors('media'),
        );

        $garage->putCors('media', ['https://app.example.com'], ['GET']);
        $this->assertSame('PutBucketCors', $this->sent[1]->getName());
        $this->assertSame(['https://app.example.com'], $this->sent[1]['CORSConfiguration']['CORSRules'][0]['AllowedOrigins']);

        $garage->putCors('media', [], []);
        $this->assertSame('DeleteBucketCors', $this->sent[2]->getName());
    }

    public function test_a_bucket_without_cors_reads_as_empty(): void
    {
        $empty = ['origins' => [], 'methods' => [], 'headers' => [], 'max_age' => 3600, 'rules' => 0];

        $this->assertSame($empty, $this->garage([new Result])->getCors('media'));
        $this->assertSame($empty, $this->garage([$this->missing('GetBucketCors', 'NoSuchCORSConfiguration')])->getCors('media'));
    }

    public function test_a_missing_bucket_is_not_mistaken_for_missing_rules(): void
    {
        $this->expectException(S3Exception::class);

        $this->garage([$this->missing('GetBucketCors', 'NoSuchBucket')])->getCors('gone');
    }

    public function test_object_existence_is_a_head_request(): void
    {
        $this->assertTrue($this->garage([new Result(['ContentLength' => 3])])->objectExists('media', 'a.txt'));
        $this->assertFalse($this->garage([$this->missing('HeadObject', 'NotFound')])->objectExists('media', 'a.txt'));
    }

    public function test_head_returns_the_details_the_panel_shows(): void
    {
        $garage = $this->garage([new Result(['ContentLength' => 2400000, 'ContentType' => 'image/jpeg', 'ETag' => '"abc123"'])]);

        $this->assertSame(
            ['key' => 'photos/cover.jpg', 'size' => 2400000, 'content_type' => 'image/jpeg', 'etag' => 'abc123', 'last_modified' => null],
            $garage->headObject('media', 'photos/cover.jpg'),
        );
    }

    public function test_a_range_is_sent_only_when_asked_for(): void
    {
        $garage = $this->garage([new Result, new Result]);

        $garage->getObject('media', '0');
        $garage->getObject('media', 'clip.mp4', 'bytes=0-99');

        $this->assertSame('0', $this->sent[0]['Key']);
        $this->assertNull($this->sent[0]['Range']);
        $this->assertSame('bytes=0-99', $this->sent[1]['Range']);
    }

    public function test_share_links_are_signed_and_capped_at_seven_days(): void
    {
        $garage = $this->garage([]);

        $day = $garage->presignedUrl('media', 'photos/cover.jpg', 86400);
        $this->assertStringStartsWith('https://s3.example.com/media/photos/cover.jpg?', $day);
        $this->assertStringContainsString('X-Amz-Expires=86400', $day);
        $this->assertStringContainsString('X-Amz-Signature=', $day);

        $this->assertStringContainsString('X-Amz-Expires=604800', $garage->presignedUrl('media', 'a.txt', 9_999_999));
    }
}
