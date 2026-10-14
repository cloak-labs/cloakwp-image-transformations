<?php

declare(strict_types=1);

namespace CloakWP\ImageTransformations\Core;

use InvalidArgumentException;
use Closure;
use UnexpectedValueException;

final class Config
{
  /** Internal memoization doesn't change the immutable recipe definitions. */
  private array $resolvedPipelines = [];

  /** @param array<string, Pipeline|Closure> $pipelines */
  public function __construct(
    public readonly array $pipelines = [],
    public readonly string|Closure|null $uploadPipeline = null,
    public readonly bool $mediaActions = false,
  ) {
    foreach ($pipelines as $name => $pipeline) {
      if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_-]*$/D', $name)
        || (!$pipeline instanceof Pipeline && !$pipeline instanceof Closure)) {
        throw new InvalidArgumentException('Pipelines need a slug name and a Pipeline instance or factory.');
      }
    }
    if (is_string($uploadPipeline) && !isset($pipelines[$uploadPipeline])) {
      throw new InvalidArgumentException("Upload pipeline '{$uploadPipeline}' has not been defined.");
    }
  }

  /** @param Pipeline|Closure(): Pipeline $pipeline */
  public function withPipeline(string $name, Pipeline|Closure $pipeline): self
  {
    return new self([...$this->pipelines, $name => $pipeline], $this->uploadPipeline, $this->mediaActions);
  }

  /** A selector can resolve PHP configuration for the current multisite blog. */
  public function withUploadPipeline(string|Closure|null $name): self
  {
    return new self($this->pipelines, $name, $this->mediaActions);
  }

  public function withMediaActions(bool $enabled): self
  {
    return new self($this->pipelines, $this->uploadPipeline, $enabled);
  }

  public function pipeline(string $name): Pipeline
  {
    $definition = $this->pipelines[$name] ?? throw new InvalidArgumentException("Unknown image pipeline '{$name}'.");
    if ($definition instanceof Pipeline) {
      return $definition;
    }
    if (isset($this->resolvedPipelines[$name])) {
      return $this->resolvedPipelines[$name];
    }
    $pipeline = $definition();
    if (!$pipeline instanceof Pipeline) {
      throw new UnexpectedValueException("The '{$name}' pipeline factory must return a Pipeline instance.");
    }
    return $this->resolvedPipelines[$name] = $pipeline;
  }

  public function uploadName(): ?string
  {
    $name = $this->uploadPipeline instanceof Closure ? ($this->uploadPipeline)() : $this->uploadPipeline;
    if ($name !== null) {
      if (!is_string($name)) {
        throw new InvalidArgumentException('An upload pipeline selector must return a pipeline name or null.');
      }
      if (!isset($this->pipelines[$name])) {
        throw new InvalidArgumentException("Unknown image pipeline '{$name}'.");
      }
    }
    return $name;
  }
}
