<?php

declare(strict_types=1);

namespace CloakWP\ImageTransformations\Core\Contracts;

use CloakWP\ImageTransformations\Core\Image;

/** A step edits the working image; the processor writes it once after all steps. */
interface Transformation
{
  public function apply(Image $image): void;
}
