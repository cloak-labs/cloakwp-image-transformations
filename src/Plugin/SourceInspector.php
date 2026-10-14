<?php

declare(strict_types=1);

namespace CloakWP\ImageTransformations\Plugin;

use CloakWP\ImageTransformations\Core\Source;
use CloakWP\ImageTransformations\Core\TransformationFailed;

final class SourceInspector
{
  public function inspect(string $path, string $context = 'file', ?int $attachmentId = null): Source
  {
    if (!is_file($path) || !is_readable($path)) {
      throw new TransformationFailed('The source image is not a readable local file.');
    }
    $size = wp_getimagesize($path);
    $mimeType = wp_get_image_mime($path);
    if (!$size || !$mimeType) {
      throw new TransformationFailed('The source file is not a supported raster image.');
    }
    return new Source($path, $mimeType, (int) $size[0], (int) $size[1], $context, $attachmentId,
      $this->animated($path, $mimeType));
  }

  /** Core editors flatten animation. Skip it before decoding or writing. */
  private function animated(string $path, string $mimeType): bool
  {
    if ($mimeType === 'image/gif') {
      return true;
    }
    $file = fopen($path, 'rb');
    if ($file === false) {
      throw new TransformationFailed('Unable to inspect the source image.');
    }
    try {
      $header = fread($file, 256);
      if ($mimeType === 'image/webp') {
        return substr($header, 12, 4) === 'VP8X' && strlen($header) > 20 && (ord($header[20]) & 2) !== 0;
      }
      if ($mimeType === 'image/avif') {
        return str_contains($header, 'avis');
      }
      if ($mimeType !== 'image/png') {
        return false;
      }
      fseek($file, 8);
      $fileSize = filesize($path);
      while (ftell($file) + 8 <= $fileSize) {
        $chunk = fread($file, 8);
        if (strlen($chunk) !== 8) {
          break;
        }
        $type = substr($chunk, 4, 4);
        if ($type === 'acTL') {
          return true;
        }
        if ($type === 'IDAT' || $type === 'IEND') {
          return false;
        }
        $length = unpack('Nlength', $chunk)['length'];
        if ($length + 4 > $fileSize - ftell($file) || fseek($file, $length + 4, SEEK_CUR) !== 0) {
          break;
        }
      }
      return false;
    } finally {
      fclose($file);
    }
  }
}
