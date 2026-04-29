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
     * Map the given Laravel attachments to Bedrock Converse content blocks.
     */
    protected function mapConverseAttachments(Collection $attachments): array
    {
        return $attachments->map(function (mixed $attachment): array {
            if (! $attachment instanceof File && ! $attachment instanceof UploadedFile) {
                throw new InvalidArgumentException('Unsupported attachment type ['.get_debug_type($attachment).']');
            }

            if ($attachment instanceof ProviderImage || $attachment instanceof ProviderDocument) {
                throw new InvalidArgumentException('Unsupported attachment type ['.get_class($attachment).']');
            }

            if ($attachment instanceof Image || ($attachment instanceof UploadedFile && $this->isConverseImage($attachment))) {
                return [
                    'image' => [
                        'format' => $this->imageMimeToFormat($this->attachmentMimeType($attachment)),
                        'source' => [
                            'bytes' => base64_encode($this->attachmentContent($attachment)),
                        ],
                    ],
                ];
            }

            if ($attachment instanceof Audio || ($attachment instanceof UploadedFile && $this->isConverseAudio($attachment))) {
                return [
                    'audio' => [
                        'format' => $this->audioMimeToFormat($this->attachmentMimeType($attachment)),
                        'source' => [
                            'bytes' => base64_encode($this->attachmentContent($attachment)),
                        ],
                    ],
                ];
            }

            if ($attachment instanceof UploadedFile && $this->isConverseVideo($attachment)) {
                return [
                    'video' => [
                        'format' => $this->videoMimeToFormat($this->attachmentMimeType($attachment), $attachment->getClientOriginalName()),
                        'source' => [
                            'bytes' => base64_encode($this->attachmentContent($attachment)),
                        ],
                    ],
                ];
            }

            if ($attachment instanceof Document || $attachment instanceof UploadedFile) {
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

    protected function documentMimeToFormat(?string $mimeType, ?string $name = null): string
    {
        return match ($mimeType) {
            'application/pdf' => 'pdf',
            'text/csv', 'application/csv' => 'csv',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'text/html' => 'html',
            'text/markdown' => 'md',
            'text/plain' => 'txt',
            default => $this->formatFromFilename($name, 'txt'),
        };
    }

    protected function videoMimeToFormat(?string $mimeType, ?string $name = null): string
    {
        return match ($mimeType) {
            'video/x-matroska' => 'mkv',
            'video/quicktime' => 'mov',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/x-flv' => 'flv',
            'video/mpeg' => 'mpeg',
            'video/x-ms-wmv' => 'wmv',
            'video/3gpp' => 'three_gp',
            default => $this->formatFromFilename($name, 'mp4'),
        };
    }

    protected function formatFromFilename(?string $name, string $default): string
    {
        $extension = Str::of((string) $name)->afterLast('.')->lower()->toString();

        return match ($extension) {
            'jpg' => 'jpeg',
            '3gp' => 'three_gp',
            'csv', 'doc', 'docx', 'flv', 'gif', 'html', 'jpeg', 'md', 'mkv', 'mov', 'mp4', 'mpeg', 'mpg', 'pdf', 'png', 'txt', 'webm', 'wmv', 'xls', 'xlsx' => $extension,
            default => $default,
        };
    }

    protected function converseDocumentName(?string $name): string
    {
        $name = Str::of($name ?: 'Document')
            ->beforeLast('.')
            ->replaceMatches('/[^A-Za-z0-9\s\-\(\)\[\]]/', ' ')
            ->squish()
            ->limit(200, '')
            ->toString();

        return filled($name) ? $name : 'Document';
    }
}
