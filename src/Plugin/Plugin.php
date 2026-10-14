<?php

declare(strict_types=1);

namespace CloakWP\ImageTransformations\Plugin;

use CloakWP\ImageTransformations\ImageTransformations;

final class Plugin
{
  public function __construct(private readonly ImageTransformations $images)
  {
  }

  public function register(): void
  {
    $config = $this->images->config();
    if ($config->pipelines === []) {
      return;
    }
    (new Uploads($config))->register();
    if ($config->mediaActions && is_admin()) {
      (new MediaActions($this->images))->register();
    }
    if (defined('WP_CLI') && WP_CLI) {
      \WP_CLI::add_command('images transform', new TransformCommand($this->images));
    }
  }
}
