<?php

declare(strict_types=1);

namespace CloakWP\ImageTransformations;

use Closure;
use CloakWP\ImageTransformations\Core\Config;
use CloakWP\ImageTransformations\Core\Pipeline;
use CloakWP\ImageTransformations\Core\Result;
use CloakWP\ImageTransformations\Plugin\Attachments;
use CloakWP\ImageTransformations\Plugin\Plugin;
use CloakWP\ImageTransformations\Plugin\Processor;
use CloakWP\ImageTransformations\Plugin\SourceInspector;
use LogicException;
use UnexpectedValueException;

/** PHP composition root: definitions first, hooks only after register(). */
final class ImageTransformations
{
  private static ?self $instance = null;
  private bool $registered = false;

  private function __construct(private Config $config)
  {
  }

  public static function make(): self
  {
    return new self(new Config());
  }

  public static function instance(): ?self
  {
    return self::$instance;
  }

  public static function booted(): bool
  {
    return self::$instance !== null;
  }

  /** @param Pipeline|Closure(): Pipeline $pipeline */
  public function pipeline(string $name, Pipeline|Closure $pipeline): self
  {
    $this->assertMutable();
    $this->config = $this->config->withPipeline($name, $pipeline);
    return $this;
  }

  /** @param string|Closure(): ?string|null $pipeline */
  public function onUpload(string|Closure|null $pipeline): self
  {
    $this->assertMutable();
    $this->config = $this->config->withUploadPipeline($pipeline);
    return $this;
  }

  public function mediaActions(bool $enabled = true): self
  {
    $this->assertMutable();
    $this->config = $this->config->withMediaActions($enabled);
    return $this;
  }

  public function config(): Config
  {
    return $this->config;
  }

  public function register(): self
  {
    if ($this->registered) {
      return $this;
    }
    if (self::$instance !== null) {
      throw new LogicException('Image Transformations is already registered. Define all pipelines in one composition root.');
    }
    $config = apply_filters('cloakwp/image-transformations/config', $this->config);
    if (!$config instanceof Config) {
      throw new UnexpectedValueException('The image transformations config filter must return a Config instance.');
    }
    $this->config = $config;
    (new Plugin($this))->register();
    $this->registered = true;
    self::$instance = $this;
    return $this;
  }

  /** Transform a local file into a new file without attachment side effects. */
  public function transformFile(string $path, string $pipeline): Result
  {
    return (new Processor())->process((new SourceInspector())->inspect($path), $this->config->pipeline($pipeline));
  }

  /** Trusted PHP API. HTTP callers must enforce nonce and attachment permissions. */
  public function transformAttachment(int $attachmentId, string $pipeline): Result
  {
    $result = (new Attachments(new Processor()))->transform($attachmentId, $this->config->pipeline($pipeline));
    if ($result->changed) {
      do_action('cloakwp/image-transformations/transformed', $result, $pipeline);
    }
    return $result;
  }

  private function assertMutable(): void
  {
    if ($this->registered) {
      throw new LogicException('Image transformation configuration cannot change after register().');
    }
  }
}
