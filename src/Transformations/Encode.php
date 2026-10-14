<?php

declare(strict_types=1);

namespace CloakWP\ImageTransformations\Transformations;

use CloakWP\ImageTransformations\Core\Contracts\Transformation;
use CloakWP\ImageTransformations\Core\Image;
use InvalidArgumentException;

/** Select output encoding without encoding intermediate pipeline states. */
abstract class Encode implements Transformation
{
  protected const MIME_TYPE = '';
  protected const QUALITY = 82;

  final protected function __construct(
    public readonly int $outputQuality,
    public readonly bool $skipUnsupported = false,
  ) {
    if ($outputQuality < 1 || $outputQuality > 100) {
      throw new InvalidArgumentException('Quality must be between 1 and 100.');
    }
  }

  public static function make(): static
  {
    return new static(static::QUALITY);
  }

  public function quality(int $quality): static
  {
    return new static($quality, $this->skipUnsupported);
  }

  public function skipIfUnsupported(bool $skip = true): static
  {
    return new static($this->outputQuality, $skip);
  }

  public function apply(Image $image): void
  {
    if ($this->skipUnsupported && !$image->supports(static::MIME_TYPE)) {
      return;
    }
    $image->format(static::MIME_TYPE, $this->outputQuality);
  }
}
