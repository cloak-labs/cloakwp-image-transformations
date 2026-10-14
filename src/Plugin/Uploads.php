<?php

declare(strict_types=1);

namespace CloakWP\ImageTransformations\Plugin;

use CloakWP\ImageTransformations\Core\Config;
use CloakWP\ImageTransformations\Core\Result;
use Throwable;

final class Uploads
{
  public const META_KEY = '_cloakwp_image_transformations';

  /** @var array<string, Result> */
  private array $pending = [];

  public function __construct(private readonly Config $config, private readonly ?Processor $processor = null)
  {
  }

  public function register(): void
  {
    if ($this->config->uploadPipeline === null) {
      return;
    }
    add_filter('wp_handle_upload', [$this, 'handle'], 20, 2);
    add_action('add_attachment', [$this, 'attach']);
    add_filter('wp_generate_attachment_metadata', [$this, 'metadata'], 20, 2);
    // Arbitrary PHP transformations cannot run in the browser's WASM pipeline.
    add_filter('wp_client_side_media_processing_enabled', fn(bool $enabled): bool => $enabled && $this->config->uploadName() === null, PHP_INT_MAX);
  }

  public function handle(array $upload, string $context): array
  {
    $name = $this->config->uploadName();
    if ($name === null) {
      return $upload;
    }
    if (!isset($upload['file'], $upload['url'], $upload['type']) || !$this->config->pipeline($name)->acceptsMimeType($upload['type'])) {
      return $upload;
    }
    try {
      $source = (new SourceInspector())->inspect($upload['file'], $context);
      $result = ($this->processor ?? new Processor())->process($source, $this->config->pipeline($name));
      if (!$result->changed) {
        return $upload;
      }
      $this->pending[$result->path] = $result;
      $upload['file'] = $result->path;
      $upload['url'] = substr($upload['url'], 0, strrpos($upload['url'], '/') + 1) . rawurlencode(basename($result->path));
      $upload['type'] = $result->mimeType;
      do_action('cloakwp/image-transformations/transformed', $result, $name);
    } catch (Throwable $error) {
      // An optimization failure must not turn a valid upload into data loss.
      do_action('cloakwp/image-transformations/failed', $error, $upload['file'], $name);
    }
    return $upload;
  }

  public function attach(int $attachmentId): void
  {
    $path = get_attached_file($attachmentId, true);
    $result = $this->pending[$path] ?? null;
    if ($result === null) {
      return;
    }
    unset($this->pending[$path]);
    update_post_meta($attachmentId, self::META_KEY, [
      'source' => _wp_relative_upload_path($result->source->path),
      'output' => _wp_relative_upload_path($result->path),
      'width' => $result->width,
      'height' => $result->height,
      'pipeline' => $this->config->uploadName(),
    ]);
  }

  public function metadata(array $metadata, int $attachmentId): array
  {
    $record = get_post_meta($attachmentId, self::META_KEY, true);
    if (!is_array($record) || empty($record['source']) || empty($metadata['file'])
      || dirname($metadata['file']) !== dirname($record['source'])) {
      return $metadata;
    }
    $metadata['original_image'] = basename($record['source']);
    // Core may create an additional -scaled image after our upload transform.
    // Track that intermediate in core's backup metadata for deletion/restore.
    if ($metadata['file'] !== $record['output']) {
      $backups = get_post_meta($attachmentId, '_wp_attachment_backup_sizes', true);
      $backups = is_array($backups) ? $backups : [];
      $backups['cloakwp-' . md5($record['output'])] = [
        'file' => basename($record['output']), 'width' => $record['width'], 'height' => $record['height'],
      ];
      update_post_meta($attachmentId, '_wp_attachment_backup_sizes', $backups);
    }
    return $metadata;
  }
}
