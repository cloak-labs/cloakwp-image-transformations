<?php

declare(strict_types=1);

namespace CloakWP\ImageTransformations\Core;

use Closure;
use CloakWP\ImageTransformations\Core\Contracts\Transformation;
use InvalidArgumentException;

/** A reusable value object. Each builder method returns a new recipe. */
final class Pipeline implements Transformation
{
  /** @param list<Transformation> $steps @param list<string> $mimeTypes */
  private function __construct(
    private readonly array $steps = [],
    private readonly array $mimeTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/avif'],
    private readonly ?Closure $condition = null,
  ) {
  }

  public static function make(): self
  {
    return new self();
  }

  /** @param list<Transformation> $steps */
  public function through(array $steps): self
  {
    foreach ($steps as $step) {
      if (!$step instanceof Transformation) {
        throw new InvalidArgumentException('Every pipeline step must implement Transformation.');
      }
    }
    return new self(array_values($steps), $this->mimeTypes, $this->condition);
  }

  public function pipe(Transformation $step): self
  {
    return $this->through([...$this->steps, $step]);
  }

  /** @param list<string> $mimeTypes */
  public function onlyMimeTypes(array $mimeTypes): self
  {
    foreach ($mimeTypes as $mimeType) {
      if (!is_string($mimeType) || !str_starts_with($mimeType, 'image/')) {
        throw new InvalidArgumentException('Pipeline MIME types must be image MIME strings.');
      }
    }
    return new self($this->steps, array_values($mimeTypes), $this->condition);
  }

  /** @param callable(Source): bool $condition */
  public function when(callable $condition): self
  {
    return new self($this->steps, $this->mimeTypes, Closure::fromCallable($condition));
  }

  public function matches(Source $source): bool
  {
    return $this->steps !== []
      && !$source->animated
      && $this->acceptsMimeType($source->mimeType)
      && ($this->condition === null || ($this->condition)($source));
  }

  public function acceptsMimeType(string $mimeType): bool
  {
    return in_array($mimeType, $this->mimeTypes, true);
  }

  public function apply(Image $image): void
  {
    if (!$this->matches($image->source)) {
      return;
    }
    foreach ($this->steps as $step) {
      $step->apply($image);
    }
  }
}
