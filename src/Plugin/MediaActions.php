<?php

declare(strict_types=1);

namespace CloakWP\ImageTransformations\Plugin;

use CloakWP\ImageTransformations\ImageTransformations;
use Throwable;
use WP_Post;

/** Optional native list-table actions; PHP recipes remain the only settings. */
final class MediaActions
{
  private const ACTION = 'cloakwp-transform-image';

  public function __construct(private readonly ImageTransformations $images)
  {
  }

  public function register(): void
  {
    add_filter('media_row_actions', [$this, 'rowActions'], 10, 2);
    add_filter('bulk_actions-upload', [$this, 'bulkActions']);
    add_filter('handle_bulk_actions-upload', [$this, 'handleBulk'], 10, 3);
    add_action('admin_post_' . self::ACTION, [$this, 'handleRow']);
  }

  public function rowActions(array $actions, WP_Post $post): array
  {
    if (!str_starts_with($post->post_mime_type, 'image/') || !$this->canTransform($post->ID)) {
      return $actions;
    }
    foreach (array_keys($this->images->config()->pipelines) as $name) {
      $pipeline = $this->images->config()->pipeline($name);
      if (!$pipeline->acceptsMimeType($post->post_mime_type)) {
        continue;
      }
      $url = wp_nonce_url(add_query_arg([
        'action' => self::ACTION, 'attachment_id' => $post->ID, 'pipeline' => $name,
      ], admin_url('admin-post.php')), self::ACTION . ':' . $post->ID . ':' . $name);
      $actions[self::ACTION . '-' . $name] = '<a href="' . esc_url($url) . '">' . esc_html($this->label($name)) . '</a>';
    }
    return $actions;
  }

  public function bulkActions(array $actions): array
  {
    if (current_user_can('upload_files')) {
      foreach ($this->images->config()->pipelines as $name => $pipeline) {
        $actions[self::ACTION . ':' . $name] = $this->label($name);
      }
    }
    return $actions;
  }

  public function handleRow(): void
  {
    $id = absint($_GET['attachment_id'] ?? 0);
    $name = sanitize_key(wp_unslash($_GET['pipeline'] ?? ''));
    check_admin_referer(self::ACTION . ':' . $id . ':' . $name);
    if (!$this->canTransform($id)) {
      wp_die(esc_html__('You are not allowed to edit this image.', 'image-transformations'), '', ['response' => 403]);
    }
    try {
      $this->images->transformAttachment($id, $name);
    } catch (Throwable $error) {
      wp_die(esc_html($error->getMessage()), '', ['response' => 400]);
    }
    wp_safe_redirect(admin_url('upload.php?mode=list'));
    exit;
  }

  public function handleBulk(string $redirect, string $action, array $ids): string
  {
    $prefix = self::ACTION . ':';
    if (!str_starts_with($action, $prefix)) {
      return $redirect;
    }
    check_admin_referer('bulk-media');
    $name = substr($action, strlen($prefix));
    $failures = [];
    foreach ($ids as $id) {
      $id = (int) $id;
      if (!$this->canTransform($id)) {
        continue;
      }
      try {
        $this->images->transformAttachment($id, $name);
      } catch (Throwable $error) {
        do_action('cloakwp/image-transformations/failed', $error, get_attached_file($id), $name);
        $failures[] = "{$id}: {$error->getMessage()}";
      }
    }
    if ($failures !== []) {
      wp_die(esc_html(implode("\n", $failures)), esc_html__('Some images could not be transformed.', 'image-transformations'), ['response' => 400]);
    }
    return $redirect;
  }

  private function canTransform(int $id): bool
  {
    return current_user_can('upload_files') && current_user_can('edit_post', $id);
  }

  private function label(string $name): string
  {
    return sprintf(__('Transform: %s', 'image-transformations'), $name);
  }
}
