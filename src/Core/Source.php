<?php

declare(strict_types=1);

namespace CloakWP\ImageTransformations\Core;

final class Source
{
  public function __construct(
    public readonly string $path,
    public readonly string $mimeType,
    public readonly int $width,
    public readonly int $height,
    public readonly string $context = 'file',
    public readonly ?int $attachmentId = null,
    public readonly bool $animated = false,
  ) {
  }
}
