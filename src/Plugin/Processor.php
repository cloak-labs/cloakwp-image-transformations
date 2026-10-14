<?php

declare(strict_types=1);

namespace CloakWP\ImageTransformations\Plugin;

use Closure;
use CloakWP\ImageTransformations\Core\Image;
use CloakWP\ImageTransformations\Core\Pipeline;
use CloakWP\ImageTransformations\Core\Result;
use CloakWP\ImageTransformations\Core\Source;
use CloakWP\ImageTransformations\Core\TransformationFailed;
use Throwable;

final class Processor
{
  /** @param null|Closure(Source): \CloakWP\ImageTransformations\Core\Contracts\Editor $editorFactory */
  public function __construct(private readonly ?Closure $editorFactory = null)
  {
  }

  public function process(Source $source, Pipeline $pipeline): Result
  {
    if (!$pipeline->matches($source)) {
      return Result::unchanged($source);
    }
    $editor = $this->editorFactory === null ? WordPressEditor::open($source) : ($this->editorFactory)($source);
    $image = new Image($source, $editor);
    $pipeline->apply($image);
    if (!$image->changed()) {
      return Result::unchanged($source);
    }

    $extension = wp_get_default_extension_for_mime_type($image->mimeType());
    if (!$extension) {
      throw new TransformationFailed('The output format has no registered file extension.');
    }
    $stem = pathinfo($source->path, PATHINFO_FILENAME);
    $directory = dirname($source->path);
    // Reserve our output exclusively. Never overwrite a source or another upload.
    do {
      $path = $directory . '/' . $stem . '-transformed-' . bin2hex(random_bytes(6)) . '.' . $extension;
      $reservation = @fopen($path, 'x');
    } while ($reservation === false && is_file($path));
    if ($reservation === false) {
      throw new TransformationFailed('The image output directory is not writable.');
    }
    fclose($reservation);

    try {
      $image->save($path);
      clearstatcache(true, $path);
      $output = (new SourceInspector())->inspect($path);
      $dimensions = $image->dimensions();
      if (filesize($path) === 0 || $output->mimeType !== $image->mimeType()
        || $output->width !== $dimensions['width'] || $output->height !== $dimensions['height']) {
        throw new TransformationFailed('The encoded image failed output validation.');
      }
      return new Result($source, $path, $output->mimeType, $output->width, $output->height, true);
    } catch (Throwable $error) {
      wp_delete_file($path);
      throw $error;
    }
  }
}
