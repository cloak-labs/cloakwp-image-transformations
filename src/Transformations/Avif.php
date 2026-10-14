<?php

declare(strict_types=1);

namespace CloakWP\ImageTransformations\Transformations;

final class Avif extends Encode
{
  protected const MIME_TYPE = 'image/avif';
  protected const QUALITY = 80;
}
