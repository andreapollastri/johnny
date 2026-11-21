{{-- Ready-to-paste configuration for common S3 clients. $keyId / $secret are placeholders unless a key was just created. --}}
@php
    $snippetId = 'snippet-'.substr(md5($bucket.$keyId), 0, 8);
    $tabs = ['laravel' => 'Laravel', 'cli' => 'AWS CLI', 'rclone' => 'rclone', 'python' => 'Python', 'node' => 'Node.js'];
@endphp
<div class="snippet">
    <div class="snippet-tabs" role="tablist" aria-label="S3 clients">
        @foreach ($tabs as $id => $label)
            <button type="button" class="snippet-tab" role="tab" aria-selected="{{ $loop->first ? 'true' : 'false' }}" aria-controls="{{ $snippetId }}-{{ $id }}">{{ $label }}</button>
        @endforeach
    </div>
    <div class="snippet-pane" id="{{ $snippetId }}-laravel" role="tabpanel">
        <button type="button" class="btn btn-xs" data-copy-from="#{{ $snippetId }}-laravel pre">Copy</button>
<pre># .env
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID={{ $keyId }}
AWS_SECRET_ACCESS_KEY={{ $secret }}
AWS_DEFAULT_REGION={{ $region }}
AWS_BUCKET={{ $bucket }}
AWS_ENDPOINT={{ $endpoint }}
AWS_USE_PATH_STYLE_ENDPOINT=true</pre>
    </div>
    <div class="snippet-pane" id="{{ $snippetId }}-cli" role="tabpanel" hidden>
        <button type="button" class="btn btn-xs" data-copy-from="#{{ $snippetId }}-cli pre">Copy</button>
<pre>export AWS_ACCESS_KEY_ID={{ $keyId }}
export AWS_SECRET_ACCESS_KEY={{ $secret }}
export AWS_DEFAULT_REGION={{ $region }}
export AWS_ENDPOINT_URL={{ $endpoint }}

aws s3 ls s3://{{ $bucket }}
aws s3 cp ./report.pdf s3://{{ $bucket }}/reports/report.pdf</pre>
    </div>
    <div class="snippet-pane" id="{{ $snippetId }}-rclone" role="tabpanel" hidden>
        <button type="button" class="btn btn-xs" data-copy-from="#{{ $snippetId }}-rclone pre">Copy</button>
<pre># ~/.config/rclone/rclone.conf
[johnny]
type = s3
provider = Other
access_key_id = {{ $keyId }}
secret_access_key = {{ $secret }}
endpoint = {{ $endpoint }}
region = {{ $region }}
force_path_style = true

# rclone ls johnny:{{ $bucket }}</pre>
    </div>
    <div class="snippet-pane" id="{{ $snippetId }}-python" role="tabpanel" hidden>
        <button type="button" class="btn btn-xs" data-copy-from="#{{ $snippetId }}-python pre">Copy</button>
<pre>import boto3

s3 = boto3.client(
    "s3",
    endpoint_url="{{ $endpoint }}",
    region_name="{{ $region }}",
    aws_access_key_id="{{ $keyId }}",
    aws_secret_access_key="{{ $secret }}",
)

s3.upload_file("report.pdf", "{{ $bucket }}", "reports/report.pdf")</pre>
    </div>
    <div class="snippet-pane" id="{{ $snippetId }}-node" role="tabpanel" hidden>
        <button type="button" class="btn btn-xs" data-copy-from="#{{ $snippetId }}-node pre">Copy</button>
<pre>import { S3Client, PutObjectCommand } from "@@aws-sdk/client-s3";

const s3 = new S3Client({
  endpoint: "{{ $endpoint }}",
  region: "{{ $region }}",
  forcePathStyle: true,
  credentials: { accessKeyId: "{{ $keyId }}", secretAccessKey: "{{ $secret }}" },
});

await s3.send(new PutObjectCommand({ Bucket: "{{ $bucket }}", Key: "hello.txt", Body: "hello" }));</pre>
    </div>
</div>
