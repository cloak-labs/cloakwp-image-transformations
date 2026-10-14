<?php

declare(strict_types=1);

namespace CloakWP\ImageTransformations\Core;

use CloakWP\ImageTransformations\Core\Contracts\Editor;
use InvalidArgumentException;

/** Mutable working state, confined to one execution of an immutable pipeline. */
final class Image
{
  private string $mimeType;
  private ?int $quality = null;
  private bool $changed = false;

  public function __construct(
    public readonly Source $source,
    private readonly Editor $editor,
  ) {
    $this->mimeType = $source->mimeType;
  }

  /** @return array{width: int, height: int} */
  public function dimensions(): array
  {
    return $this->editor->dimensions();
  }

  public function mimeType(): string
  {
    return $this->mimeType;
  }

  public function outputQuality(): ?int
  {
    return $this->quality;
  }

  public function changed(): bool
  {
    return $this->changed;
  }

  public function supports(string $mimeType): bool
  {
    return $this->editor->supports($mimeType);
  }

  public function resize(int $width, ?int $height = null): void
  {
    if ($width < 1 || ($height !== null && $height < 1)) {
      throw new InvalidArgumentException('Resize bounds must be positive.');
    }

    $size = $this->dimensions();
    if ($size['width'] <= $width && ($height === null || $size['height'] <= $height)) {
      return;
    }

    $this->editor->resize($width, $height);
    $this->changed = $this->changed || $size !== $this->dimensions();
  }

  /** Positive degrees rotate counterclockwise, following WordPress's editor. */
  public function rotate(float $degrees): void
  {
    if (!is_finite($degrees)) {
      throw new InvalidArgumentException('Rotation must be finite.');
    }
    if (fmod($degrees, 360.0) === 0.0) {
      return;
    }
    $this->editor->rotate($degrees);
    $this->changed = true;
  }

  public function flip(bool $horizontal = false, bool $vertical = false): void
  {
    if (!$horizontal && !$vertical) {
      return;
    }
    $this->editor->flip($horizontal, $vertical);
    $this->changed = true;
  }

  public function crop(int $x, int $y, int $width, int $height): void
  {
    $size = $this->dimensions();
    if ($x < 0 || $y < 0 || $width < 1 || $height < 1
      || $x + $width > $size['width'] || $y + $height > $size['height']) {
      throw new InvalidArgumentException('Crop must fit inside the working image.');
    }
    $this->editor->crop($x, $y, $width, $height);
    $this->changed = true;
  }

  public function format(string $mimeType, ?int $quality = null): void
  {
    if (!$this->supports($mimeType)) {
      throw new TransformationFailed("The selected image editor cannot encode {$mimeType}.");
    }
    if ($quality !== null) {
      $this->quality($quality);
    }
    $this->changed = $this->changed || $this->mimeType !== $mimeType;
    $this->mimeType = $mimeType;
  }

  public function quality(int $quality): void
  {
    if ($quality < 1 || $quality > 100) {
      throw new InvalidArgumentException('Quality must be between 1 and 100.');
    }
    $this->quality = $quality;
    $this->changed = true;
  }

  public function save(string $path): void
  {
    $this->editor->save($path, $this->mimeType, $this->quality);
  }

  /** @param callable(Editor): void $operation */
  public function edit(callable $operation): void
  {
    $operation($this->editor);
    $this->changed = true;
  }
}
