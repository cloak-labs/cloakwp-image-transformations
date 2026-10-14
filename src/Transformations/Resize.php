<?php

declare(strict_types=1);

namespace CloakWP\ImageTransformations\Transformations;

use CloakWP\ImageTransformations\Core\Contracts\Transformation;
use CloakWP\ImageTransformations\Core\Image;
use InvalidArgumentException;

/** Fit within a bounding box, preserving aspect ratio and never enlarging. */
final class Resize implements Transformation
{
  private function __construct(
    public readonly int $width,
    public readonly ?int $height,
  ) {
    if ($width < 1 || ($height !== null && $height < 1)) {
      throw new InvalidArgumentException('Resize bounds must be positive.');
    }
  }

  public static function make(int $width, ?int $height = null): self
  {
    return new self($width, $height);
  }

  public function apply(Image $image): void
  {
    $image->resize($this->width, $this->height);
  }
}
