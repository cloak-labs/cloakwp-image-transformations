<?php

declare(strict_types=1);

namespace CloakWP\ImageTransformations\Plugin;

use CloakWP\ImageTransformations\Core\Pipeline;
use CloakWP\ImageTransformations\Core\Result;
use CloakWP\ImageTransformations\Core\TransformationFailed;
use Throwable;

/** Explicit processing of existing attachments. No library-wide background work. */
final class Attachments
{
  public function __construct(private readonly Processor $processor)
  {
  }

  public function transform(int $attachmentId, Pipeline $pipeline): Result
  {
    $post = get_post($attachmentId);
    $path = apply_filters('cloakwp/image-transformations/attachment-source', get_attached_file($attachmentId, true), $attachmentId);
    if (!$post || $post->post_type !== 'attachment' || !$path) {
      throw new TransformationFailed('The attachment has no local image file.');
    }
    $source = (new SourceInspector())->inspect($path, 'attachment', $attachmentId);
    $uploads = wp_get_upload_dir();
    $directory = realpath($uploads['basedir']);
    $realPath = realpath($path);
    if ($directory === false || $realPath === false || !str_starts_with($realPath, $directory . DIRECTORY_SEPARATOR)) {
      throw new TransformationFailed('Only attachments inside this site\'s uploads directory can be transformed.');
    }

    $lock = fopen($path, 'rb');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
      if (is_resource($lock)) {
        fclose($lock);
      }
      throw new TransformationFailed('This attachment is already being transformed.');
    }
    try {
      // A second process may have read the old path before acquiring this lock.
      wp_cache_delete($attachmentId, 'post_meta');
      if (get_attached_file($attachmentId, true) !== $path) {
        throw new TransformationFailed('The attachment changed while waiting for its image lock.');
      }
      $result = $this->processor->process($source, $pipeline);
      if (!$result->changed) {
        return $result;
      }
      return $this->commit($attachmentId, $post->post_mime_type, $result);
    } finally {
      flock($lock, LOCK_UN);
      fclose($lock);
    }
  }

  private function commit(int $attachmentId, string $previousMimeType, Result $result): Result
  {
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $oldMetadata = wp_get_attachment_metadata($attachmentId);
    $oldRecord = get_post_meta($attachmentId, Uploads::META_KEY, true);
    $oldBackups = get_post_meta($attachmentId, '_wp_attachment_backup_sizes', true);
    $metadata = [];
    try {
      if (!update_attached_file($attachmentId, $result->path)) {
        throw new TransformationFailed('WordPress could not save the transformed attachment file reference.');
      }
      $updated = wp_update_post(['ID' => $attachmentId, 'post_mime_type' => $result->mimeType], true);
      if (is_wp_error($updated)) {
        throw new TransformationFailed($updated->get_error_message());
      }
      // Preserve the oldest original, and every previous thumbnail in core's
      // existing backup table. Old content URLs remain valid until deletion.
      $source = is_array($oldRecord) && !empty($oldRecord['source'])
        ? $oldRecord['source']
        : _wp_relative_upload_path(dirname($result->source->path) . '/' . ($oldMetadata['original_image'] ?? basename($result->source->path)));
      $this->saveMeta($attachmentId, Uploads::META_KEY, [
        'source' => $source, 'output' => _wp_relative_upload_path($result->path),
        'width' => $result->width, 'height' => $result->height,
      ]);
      $metadata = wp_generate_attachment_metadata($attachmentId, $result->path);
      if (empty($metadata['file']) || empty($metadata['width']) || empty($metadata['height'])) {
        throw new TransformationFailed('WordPress could not generate metadata for the transformed attachment.');
      }
      $metadata['original_image'] = basename($source);
      if (!wp_update_attachment_metadata($attachmentId, $metadata)
        && wp_get_attachment_metadata($attachmentId) !== $metadata) {
        throw new TransformationFailed('WordPress could not save the transformed image metadata.');
      }
      $backups = get_post_meta($attachmentId, '_wp_attachment_backup_sizes', true);
      $backups = is_array($backups) ? $backups : [];
      $previousFiles = ['full' => [
        'file' => basename($result->source->path), 'width' => $result->source->width, 'height' => $result->source->height,
      ], ...($oldMetadata['sizes'] ?? [])];
      foreach ($previousFiles as $name => $size) {
        $backups['cloakwp-' . md5($size['file'])] = $size;
      }
      $this->saveMeta($attachmentId, '_wp_attachment_backup_sizes', $backups);
      return $result;
    } catch (Throwable $error) {
      // Core writes intermediate metadata during thumbnail generation. Restore
      // all of it if the full commit fails, without changing the stable GUID.
      update_attached_file($attachmentId, $result->source->path);
      wp_update_post(['ID' => $attachmentId, 'post_mime_type' => $previousMimeType]);
      $this->restoreMeta($attachmentId, '_wp_attachment_metadata', $oldMetadata);
      $this->restoreMeta($attachmentId, Uploads::META_KEY, $oldRecord);
      $this->restoreMeta($attachmentId, '_wp_attachment_backup_sizes', $oldBackups);
      // Core may already have written sizes before a later hook throws. All
      // outputs derived from our exclusive random filename belong to this run.
      $stem = pathinfo($result->path, PATHINFO_FILENAME);
      foreach (glob(dirname($result->path) . '/' . $stem . '*') ?: [] as $generated) {
        $suffix = substr(basename($generated), strlen($stem), 1);
        if ($suffix === '.' || $suffix === '-') {
          wp_delete_file($generated);
        }
      }
      throw $error;
    }
  }

  private function restoreMeta(int $attachmentId, string $key, mixed $value): void
  {
    if ($value === false || $value === '') {
      delete_post_meta($attachmentId, $key);
    } else {
      update_post_meta($attachmentId, $key, $value);
    }
  }

  private function saveMeta(int $attachmentId, string $key, array $value): void
  {
    if (!update_post_meta($attachmentId, $key, $value) && get_post_meta($attachmentId, $key, true) !== $value) {
      throw new TransformationFailed('WordPress could not save transformed image bookkeeping.');
    }
  }
}
