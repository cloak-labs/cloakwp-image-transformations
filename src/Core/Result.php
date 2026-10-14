<?php

declare(strict_types=1);

namespace CloakWP\ImageTransformations\Core;

final class Result
{
  public function __construct(
    public readonly Source $source,
    public readonly string $path,
    public readonly string $mimeType,
    public readonly int $width,
    public readonly int $height,
    public readonly bool $changed,
  ) {
  }

  public static function unchanged(Source $source): self
  {
    return new self($source, $source->path, $source->mimeType, $source->width, $source->height, false);
  }
}
