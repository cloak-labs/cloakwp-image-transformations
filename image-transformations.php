<?php

/**
 * Plugin Name:       Image Transformations
 * Description:       Composable image transformation pipelines configured in PHP. Inert until configured.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.2
 * Author:            Cloak Labs
 * Author URI:        https://cloakwp.com
 * License:           LGPL-3.0-only
 * Text Domain:       image-transformations
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
  exit;
}

require_once __DIR__ . '/autoload.php';

// PHP config in a theme or another MU plugin can register before this fallback.
add_action('init', static function (): void {
  if (!\CloakWP\ImageTransformations\ImageTransformations::booted()) {
    \CloakWP\ImageTransformations\ImageTransformations::make()->register();
  }
}, 1);
