<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Ai\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\ProviderDocument;
use Laravel\Ai\Files\ProviderImage;

trait MapsConverseAttachments
{
    /**
     * Bedrock Converse API format strings that can be inferred from file extensions.
     */
    protected const array FILENAME_FORMATS = [
        'csv',
        'doc',
        'docx',
        'flv',
        'gif',
        'html',
        'jpeg',
        'md',
        'mkv',
        'mov',
        'mp4',
        'mpeg',
        'mpg',
        'pdf',
        'png',
        'txt',
        'webm',
        'wmv',
        'xls',
        'xlsx',
    ];

    protected const array FORMAT_ALIASES = [
        '3gp' => 'three_gp',
        'jpg' => 'jpeg',
    ];

    protected const string DOCUMENT_NAME_PATTERN = '/[^A-Za-z0-9\s()\[\]-]/';

    /**
     * Map the given Laravel attachments to Bedrock Converse content blocks.
     */
    protected function mapConverseAttachments(Collection $attachments): array
    {
        return $attachments->map(function (mixed $attachment): array {
            if ($attachment instanceof ProviderImage || $attachment instanceof ProviderDocument) {
                throw new InvalidArgumentException('Unsupported attachment type ['.get_class($attachment).']');
            }

            if (! $attachment instanceof File && ! $attachment instanceof UploadedFile) {
                throw new InvalidArgumentException('Unsupported attachment type ['.get_debug_type($attachment).']');
            }

            if ($this->isConverseImageAttachment($attachment)) {
                return [
                    'image' => [
                        'format' => $this->imageMimeToFormat($this->attachmentMimeType($attachment)),
                        'source' => [
                            'bytes' => base64_encode($this->attachmentContent($attachment)),
                        ],
                    ],
                ];
            }

            if ($this->isConverseAudioAttachment($attachment)) {
                return [
                    'audio' => [
                        'format' => $this->audioMimeToFormat($this->attachmentMimeType($attachment)),
                        'source' => [
                            'bytes' => base64_encode($this->attachmentContent($attachment)),
                        ],
                    ],
                ];
            }

            if ($this->isConverseVideoAttachment($attachment)) {
                return [
                    'video' => [
                        'format' => $this->videoMimeToFormat($this->attachmentMimeType($attachment), $this->attachmentName($attachment)),
                        'source' => [
                            'bytes' => base64_encode($this->attachmentContent($attachment)),
                        ],
                    ],
                ];
            }

            if ($this->isConverseDocumentAttachment($attachment)) {
                return [
                    'document' => [
                        'format' => $this->documentMimeToFormat($this->attachmentMimeType($attachment), $this->attachmentName($attachment)),
                        'name' => $this->converseDocumentName($this->attachmentName($attachment)),
                        'source' => [
                            'bytes' => base64_encode($this->attachmentContent($attachment)),
                        ],
                    ],
                ];
            }

            throw new InvalidArgumentException('Unsupported attachment type ['.get_class($attachment).']');
        })->all();
    }

    protected function isConverseImageAttachment(File|UploadedFile $attachment): bool
    {
        return $attachment instanceof Image
            || ($attachment instanceof UploadedFile && $this->isConverseImage($attachment));
    }

    protected function isConverseAudioAttachment(File|UploadedFile $attachment): bool
    {
        return $attachment instanceof Audio
            || ($attachment instanceof UploadedFile && $this->isConverseAudio($attachment));
    }

    protected function isConverseVideoAttachment(File|UploadedFile $attachment): bool
    {
        return $attachment instanceof UploadedFile && $this->isConverseVideo($attachment);
    }

    protected function isConverseDocumentAttachment(File|UploadedFile $attachment): bool
    {
        return $attachment instanceof Document || $attachment instanceof UploadedFile;
    }

    protected function attachmentContent(File|UploadedFile $attachment): string
    {
        return $attachment instanceof UploadedFile
            ? $attachment->get()
            : $attachment->content();
    }

    protected function attachmentMimeType(File|UploadedFile $attachment): ?string
    {
        return $attachment instanceof UploadedFile
            ? ($attachment->getMimeType() ?: $attachment->getClientMimeType())
            : $attachment->mimeType();
    }

    protected function attachmentName(File|UploadedFile $attachment): ?string
    {
        return $attachment instanceof UploadedFile
            ? $attachment->getClientOriginalName()
            : $attachment->name();
    }

    protected function isConverseImage(UploadedFile $attachment): bool
    {
        return Str::startsWith((string) $this->attachmentMimeType($attachment), 'image/');
    }

    protected function isConverseAudio(UploadedFile $attachment): bool
    {
        return Str::startsWith((string) $this->attachmentMimeType($attachment), 'audio/');
    }

    protected function isConverseVideo(UploadedFile $attachment): bool
    {
        return Str::startsWith((string) $this->attachmentMimeType($attachment), 'video/');
    }

    protected function imageMimeToFormat(?string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg', 'image/jpg' => 'jpeg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'png',
        };
    }

    protected function audioMimeToFormat(?string $mimeType): string
    {
        return match ($mimeType) {
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/flac', 'audio/x-flac' => 'flac',
            'audio/ogg', 'audio/ogg; codecs=opus' => 'ogg',
            'audio/opus' => 'opus',
            'audio/aac', 'audio/x-aac' => 'aac',
            'audio/mp4', 'audio/m4a', 'audio/x-m4a' => 'm4a',
            'audio/webm' => 'webm',
            'audio/x-matroska' => 'mka',
            'video/x-matroska' => 'mkv',
            default => 'mp3',
        };
    }

    protected function documentMimeToFormat(?string $mimeType, ?string $name = null): string
    {
        return match ($mimeType) {
            null => $this->inferFormatFromFilename($name, 'txt'),
            'application/pdf' => 'pdf',
            'text/csv', 'application/csv' => 'csv',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'text/html' => 'html',
            'text/markdown' => 'md',
            'text/plain' => 'txt',
            default => $this->inferFormatFromFilename($name, 'txt'),
        };
    }

    protected function videoMimeToFormat(?string $mimeType, ?string $name = null): string
    {
        return match ($mimeType) {
            null => $this->inferFormatFromFilename($name, 'mp4'),
            'video/x-matroska' => 'mkv',
            'video/quicktime' => 'mov',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/x-flv' => 'flv',
            'video/mpeg' => 'mpeg',
            'video/x-ms-wmv' => 'wmv',
            'video/3gpp' => 'three_gp',
            default => $this->inferFormatFromFilename($name, 'mp4'),
        };
    }

    protected function inferFormatFromFilename(?string $name, string $default): string
    {
        $extension = Str::of((string) pathinfo((string) $name, PATHINFO_EXTENSION))->lower()->toString();

        if (array_key_exists($extension, self::FORMAT_ALIASES)) {
            return self::FORMAT_ALIASES[$extension];
        }

        if (in_array($extension, self::FILENAME_FORMATS, true)) {
            return $extension;
        }

        return $default;
    }

    /**
     * Build a Bedrock-safe document name.
     *
     * Bedrock document names may only contain letters, numbers, whitespace,
     * hyphens, parentheses, and square brackets, and must be at most 200
     * characters.
     */
    protected function converseDocumentName(?string $name): string
    {
        $name = Str::of($name ?: 'Document')
            ->beforeLast('.')
            ->replaceMatches(self::DOCUMENT_NAME_PATTERN, ' ')
            ->squish()
            ->limit(200, '')
            ->toString();

        return filled($name) ? $name : 'Document';
    }
}
