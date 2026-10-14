<?php

declare(strict_types=1);

namespace CloakWP\ImageTransformations\Plugin;

use Imagick;
use ImagickPixel;
use Throwable;
use WP_Image_Editor_Imagick;

/** Exposes working-buffer capabilities without reflection into WordPress internals. */
final class ImagickEditor extends WP_Image_Editor_Imagick
{
  private static ?bool $avifAlphaSupported = null;

  public function image(): Imagick
  {
    return $this->image;
  }

  public function hasTransparency(): bool
  {
    return $this->image->getImageAlphaChannel()
      && $this->image->getImageChannelRange(Imagick::CHANNEL_ALPHA)['minima'] < Imagick::getQuantumRange()['quantumRangeLong'];
  }

  /** Some older libheif/ImageMagick builds advertise AVIF but discard alpha. */
  public static function supportsAvifAlpha(): bool
  {
    if (self::$avifAlphaSupported !== null) {
      return self::$avifAlphaSupported;
    }
    $probe = new Imagick();
    $decoded = new Imagick();
    try {
      $probe->newImage(2, 2, new ImagickPixel('rgba(25,100,200,0.4)'), 'png');
      $probe->setImageFormat('avif');
      $decoded->readImageBlob($probe->getImageBlob());
      $range = $decoded->getImageChannelRange(Imagick::CHANNEL_ALPHA);
      $quantum = Imagick::getQuantumRange()['quantumRangeLong'];
      self::$avifAlphaSupported = $decoded->getImageAlphaChannel()
        && abs($range['minima'] / $quantum - 0.4) < 0.05;
    } catch (Throwable) {
      self::$avifAlphaSupported = false;
    } finally {
      $probe->clear();
      $decoded->clear();
    }
    return self::$avifAlphaSupported;
  }
}
