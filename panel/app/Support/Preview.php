<?php

namespace App\Support;

/**
 * What the panel is willing to show inline, decided by file extension only: the content type
 * stored with an object is whatever its uploader said, so it is never trusted for display.
 * HTML and SVG are shown as text, never rendered.
 */
class Preview
{
    /** Bytes of a text file shown in the preview. */
    public const TEXT_LIMIT = 262144;

    private const TYPES = [
        'image' => [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
            'webp' => 'image/webp', 'avif' => 'image/avif', 'bmp' => 'image/bmp', 'ico' => 'image/x-icon',
        ],
        'video' => ['mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime'],
        'audio' => [
            'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'flac' => 'audio/flac',
            'm4a' => 'audio/mp4', 'aac' => 'audio/aac',
        ],
        'pdf' => ['pdf' => 'application/pdf'],
    ];

    private const TEXT = [
        'txt', 'md', 'log', 'csv', 'tsv', 'json', 'xml', 'yml', 'yaml', 'toml', 'ini', 'conf', 'env',
        'html', 'htm', 'svg', 'css', 'js', 'mjs', 'ts', 'php', 'py', 'rb', 'go', 'rs', 'sh', 'sql',
    ];

    /**
     * "image", "video", "audio", "pdf", "text", or null when the file is only offered as a download.
     */
    public static function kind(string $key): ?string
    {
        $extension = self::extension($key);

        foreach (self::TYPES as $kind => $types) {
            if (isset($types[$extension])) {
                return $kind;
            }
        }

        return in_array($extension, self::TEXT, true) ? 'text' : null;
    }

    /**
     * The content type the preview is served with; text of any kind goes out as plain text.
     */
    public static function contentType(string $key): ?string
    {
        $extension = self::extension($key);

        foreach (self::TYPES as $types) {
            if (isset($types[$extension])) {
                return $types[$extension];
            }
        }

        return in_array($extension, self::TEXT, true) ? 'text/plain; charset=utf-8' : null;
    }

    private static function extension(string $key): string
    {
        return strtolower(pathinfo($key, PATHINFO_EXTENSION));
    }
}
