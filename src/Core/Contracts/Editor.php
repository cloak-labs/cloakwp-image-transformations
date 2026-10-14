<?php

declare(strict_types=1);

namespace CloakWP\ImageTransformations\Core\Contracts;

/** Engine boundary: the pipeline itself has no WordPress dependency. */
interface Editor
{
  /** @return array{width: int, height: int} */
  public function dimensions(): array;

  public function supports(string $mimeType): bool;

  public function resize(int $width, ?int $height): void;

  public function rotate(float $degrees): void;

  public function flip(bool $horizontal, bool $vertical): void;

  public function crop(int $x, int $y, int $width, int $height): void;

  /** Escape hatch for operations supplied by a custom engine/editor. */
  public function native(): object;

  public function save(string $path, string $mimeType, ?int $quality): void;
}
