<?php

declare(strict_types=1);

// ZIP installs work without Composer; Composer installs use their PSR-4 loader.
if (is_readable(__DIR__ . '/vendor/autoload.php')) {
  require_once __DIR__ . '/vendor/autoload.php';
}

if (!class_exists(\CloakWP\ImageTransformations\ImageTransformations::class)) {
  spl_autoload_register(static function (string $class): void {
    $prefix = 'CloakWP\\ImageTransformations\\';
    if (!str_starts_with($class, $prefix)) {
      return;
    }

    $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_readable($path)) {
      require_once $path;
    }
  });
}
