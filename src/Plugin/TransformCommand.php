<?php

declare(strict_types=1);

namespace CloakWP\ImageTransformations\Plugin;

use CloakWP\ImageTransformations\ImageTransformations;
use Throwable;

final class TransformCommand
{
  public function __construct(private readonly ImageTransformations $images)
  {
  }

  /**
   * Apply a configured pipeline to specific attachments.
   *
   * ## OPTIONS
   *
   * <id>...
   * : Attachment IDs to transform.
   *
   * --pipeline=<name>
   * : Name of a PHP-defined pipeline.
   *
   * [--dry-run]
   * : Report matching attachments without decoding or writing any files.
   *
   * ## EXAMPLES
   *
   *     wp images transform 42 57 --pipeline=optimized --dry-run
   */
  public function __invoke(array $args, array $options): void
  {
    $name = (string) ($options['pipeline'] ?? '');
    try {
      $pipeline = $this->images->config()->pipeline($name);
    } catch (Throwable $error) {
      \WP_CLI::error($error->getMessage());
    }
    $failures = 0;
    foreach ($args as $arg) {
      try {
        if (!ctype_digit((string) $arg) || (int) $arg < 1) {
          throw new \InvalidArgumentException("Invalid attachment ID '{$arg}'.");
        }
        $id = (int) $arg;
        if (isset($options['dry-run'])) {
          $path = get_attached_file($id, true);
          if (!$path) {
            throw new \RuntimeException("Attachment {$id} has no file.");
          }
          $source = (new SourceInspector())->inspect($path, 'attachment', $id);
          \WP_CLI::log("{$id}: " . ($pipeline->matches($source) ? 'matches' : 'skipped'));
          continue;
        }
        $result = $this->images->transformAttachment($id, $name);
        \WP_CLI::log("{$id}: " . ($result->changed ? basename($result->path) : 'unchanged'));
      } catch (Throwable $error) {
        $failures++;
        \WP_CLI::warning("{$arg}: {$error->getMessage()}");
      }
    }
    if ($failures > 0) {
      \WP_CLI::error("{$failures} attachment(s) failed.");
    }
    \WP_CLI::success(isset($options['dry-run']) ? 'Dry run complete.' : 'Image transformations complete.');
  }
}
