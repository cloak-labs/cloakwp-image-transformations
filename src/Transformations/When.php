<?php

declare(strict_types=1);

namespace CloakWP\ImageTransformations\Transformations;

use Closure;
use CloakWP\ImageTransformations\Core\Contracts\Transformation;
use CloakWP\ImageTransformations\Core\Image;

final class When implements Transformation
{
  private function __construct(
    private readonly Closure $condition,
    private readonly Transformation $step,
  ) {
  }

  /** @param callable(Image): bool $condition */
  public static function make(callable $condition, Transformation $step): self
  {
    return new self(Closure::fromCallable($condition), $step);
  }

  public function apply(Image $image): void
  {
    if (($this->condition)($image)) {
      $this->step->apply($image);
    }
  }
}
