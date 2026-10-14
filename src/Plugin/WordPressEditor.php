<?php

declare(strict_types=1);

namespace CloakWP\ImageTransformations\Plugin;

use CloakWP\ImageTransformations\Core\Contracts\Editor;
use CloakWP\ImageTransformations\Core\Source;
use CloakWP\ImageTransformations\Core\TransformationFailed;
use WP_Image_Editor;

/** Adapter for WordPress's Imagick/GD selection and error handling. */
final class WordPressEditor implements Editor
{
  private function __construct(private readonly WP_Image_Editor $editor)
  {
  }

  public static function open(Source $source): self
  {
    // Preserve custom editor choices; enrich only WordPress's default Imagick
    // editor with a working-buffer transparency check.
    $editorFilter = static fn(array $editors): array => array_map(
      static fn(string $class): string => $class === 'WP_Image_Editor_Imagick' ? ImagickEditor::class : $class,
      $editors,
    );
    add_filter('wp_image_editors', $editorFilter, PHP_INT_MAX);
    try {
      $editor = wp_get_image_editor($source->path);
    } finally {
      remove_filter('wp_image_editors', $editorFilter, PHP_INT_MAX);
    }
    self::check($editor);
    // Work in display orientation before applying user-defined geometry.
    self::check($editor->maybe_exif_rotate());
    return new self($editor);
  }

  public function dimensions(): array
  {
    return $this->editor->get_size();
  }

  public function supports(string $mimeType): bool
  {
    if ($mimeType === 'image/avif' && $this->editor instanceof ImagickEditor
      && $this->editor->hasTransparency() && !ImagickEditor::supportsAvifAlpha()) {
      return false;
    }
    return $this->editor::supports_mime_type($mimeType);
  }

  public function resize(int $width, ?int $height): void
  {
    self::check($this->editor->resize($width, $height, false));
  }

  public function rotate(float $degrees): void
  {
    self::check($this->editor->rotate($degrees));
  }

  public function flip(bool $horizontal, bool $vertical): void
  {
    // WordPress's parameters are vertical first, horizontal second.
    self::check($this->editor->flip($vertical, $horizontal));
  }

  public function crop(int $x, int $y, int $width, int $height): void
  {
    self::check($this->editor->crop($x, $y, $width, $height));
  }

  public function native(): object
  {
    return $this->editor;
  }

  public function save(string $path, string $mimeType, ?int $quality): void
  {
    // Core resets quality when changing MIME type in save(). Scope the quality
    // filter to this encode, and prevent other format maps overriding the recipe.
    $formatFilter = static fn(array $formats): array => [];
    $qualityFilter = static fn(int $default): int => $quality ?? $default;
    add_filter('image_editor_output_format', $formatFilter, PHP_INT_MAX);
    add_filter('wp_editor_set_quality', $qualityFilter, PHP_INT_MAX);
    try {
      self::check($this->editor->set_quality($quality));
      $saved = $this->editor->save($path, $mimeType);
      self::check($saved);
      if ($saved['path'] !== $path || $saved['mime-type'] !== $mimeType) {
        throw new TransformationFailed('The image editor did not honor the requested output format.');
      }
    } finally {
      remove_filter('image_editor_output_format', $formatFilter, PHP_INT_MAX);
      remove_filter('wp_editor_set_quality', $qualityFilter, PHP_INT_MAX);
    }
  }

  private static function check(mixed $result): void
  {
    if (is_wp_error($result)) {
      throw new TransformationFailed($result->get_error_message());
    }
  }
}
