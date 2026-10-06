@props(['name'])
@php
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $type = match (true) {
        in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'heic', 'ico'], true) => 'image',
        in_array($ext, ['mp4', 'mov', 'webm', 'mkv', 'avi', 'mp3', 'wav', 'flac', 'ogg'], true) => 'media',
        in_array($ext, ['zip', 'gz', 'tgz', 'tar', 'bz2', 'xz', 'zst', '7z', 'rar'], true) => 'archive',
        in_array($ext, ['js', 'mjs', 'ts', 'css', 'html', 'json', 'xml', 'yml', 'yaml', 'php', 'py', 'sh', 'sql', 'toml'], true) => 'code',
        in_array($ext, ['pdf', 'doc', 'docx', 'txt', 'md', 'csv', 'xls', 'xlsx', 'log'], true) => 'doc',
        default => 'file',
    };
@endphp
<svg {{ $attributes->merge(['class' => 'file-icon file-icon-'.$type]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
@switch($type)
    @case('image')
        <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.09-3.09a2 2 0 0 0-2.82 0L6 21"/>
        @break
    @case('media')
        <rect x="2" y="4" width="20" height="16" rx="2"/><path d="m10 9 5 3-5 3z"/>
        @break
    @case('archive')
        <path d="M21 8v13H3V8"/><path d="M1 3h22v5H1z"/><path d="M10 12h4"/>
        @break
    @case('code')
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="m10 13-2 2 2 2"/><path d="m14 17 2-2-2-2"/>
        @break
    @case('doc')
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="13" y2="17"/>
        @break
    @default
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>
@endswitch
</svg>
