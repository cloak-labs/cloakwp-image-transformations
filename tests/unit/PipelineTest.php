<?php

declare(strict_types=1);

namespace CloakWP\ImageTransformations\Tests;

use CloakWP\ImageTransformations\Core\Config;
use CloakWP\ImageTransformations\Core\Contracts\Editor;
use CloakWP\ImageTransformations\Core\Contracts\Transformation;
use CloakWP\ImageTransformations\Core\Image;
use CloakWP\ImageTransformations\Core\Pipeline;
use CloakWP\ImageTransformations\Core\Source;
use CloakWP\ImageTransformations\Core\TransformationFailed;
use CloakWP\ImageTransformations\Transformations\Avif;
use CloakWP\ImageTransformations\Transformations\Resize;
use CloakWP\ImageTransformations\Transformations\Webp;
use CloakWP\ImageTransformations\Transformations\When;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PipelineTest extends TestCase
{
  public function testStepsRunInOrderAndEncodingIsDeferred(): void
  {
    $editor = new MemoryEditor();
    $image = new Image($this->source(), $editor);
    $observe = new class implements Transformation {
      public function apply(Image $image): void
      {
        TestCase::assertSame(['width' => 100, 'height' => 50], $image->dimensions());
        $image->rotate(90);
      }
    };
    Pipeline::make()->through([Resize::make(100, 100), $observe, Webp::make(), Avif::make()->quality(65)])->apply($image);
    self::assertSame(['width' => 50, 'height' => 100], $image->dimensions());
    self::assertSame('image/avif', $image->mimeType());
    self::assertSame([], $editor->saves);
    $image->save('/output.avif');
    self::assertSame([['/output.avif', 'image/avif', 65]], $editor->saves);
  }

  public function testRecipesAndEncodingStepsAreImmutable(): void
  {
    $empty = Pipeline::make();
    $resize = $empty->pipe(Resize::make(100));
    $webp = Webp::make();
    $custom = $webp->quality(90)->skipIfUnsupported();
    self::assertFalse($empty->matches($this->source()));
    self::assertTrue($resize->matches($this->source()));
    self::assertSame(82, $webp->outputQuality);
    self::assertFalse($webp->skipUnsupported);
    self::assertSame(90, $custom->outputQuality);
    self::assertTrue($custom->skipUnsupported);
  }

  public function testBoundingBoxResizesOnlyDownwardIncludingTallLandscapes(): void
  {
    $editor = new MemoryEditor(1900, 1600);
    $image = new Image($this->source(), $editor);
    Resize::make(1900, 1500)->apply($image);
    self::assertSame(['width' => 1781, 'height' => 1500], $image->dimensions());
    self::assertTrue($image->changed());
    $editor = new MemoryEditor(80, 40);
    $image = new Image($this->source(), $editor);
    Resize::make(100, 100)->apply($image);
    self::assertFalse($image->changed());
    self::assertSame(['width' => 80, 'height' => 40], $image->dimensions());
  }

  public function testConditionsAndMimeFiltersSkipUnmatchedSources(): void
  {
    $pipeline = Pipeline::make()->pipe(Webp::make())->onlyMimeTypes(['image/png'])->when(fn(Source $s): bool => $s->context === 'upload');
    self::assertFalse($pipeline->matches($this->source()));
    self::assertTrue($pipeline->matches(new Source('/source.png', 'image/png', 200, 100, 'upload')));
    self::assertFalse($pipeline->matches(new Source('/animated.png', 'image/png', 200, 100, 'upload', animated: true)));
    $image = new Image($this->source(), new MemoryEditor());
    When::make(fn(Image $i): bool => $i->dimensions()['width'] > 500, Webp::make())->apply($image);
    self::assertFalse($image->changed());
  }

  public function testUnsupportedCodecCanBeSkippedWhileRetainingOtherSteps(): void
  {
    $image = new Image($this->source(), new MemoryEditor(supported: ['image/png']));
    Pipeline::make()->through([Resize::make(100), Webp::make()->skipIfUnsupported()])->apply($image);
    self::assertTrue($image->changed());
    self::assertSame('image/png', $image->mimeType());
    $this->expectException(TransformationFailed::class);
    Webp::make()->apply($image);
  }

  public function testConfigSelectorsFollowCurrentContextWithoutMutatingDefinitions(): void
  {
    $site = 'one';
    $empty = new Config();
    $config = $empty->withPipeline('optimized', Pipeline::make()->pipe(Webp::make()))
      ->withUploadPipeline(static function () use (&$site): ?string { return $site === 'one' ? 'optimized' : null; });
    self::assertSame([], $empty->pipelines);
    self::assertSame('optimized', $config->uploadName());
    $site = 'two';
    self::assertNull($config->uploadName());
  }

  public function testFactoriesResolveOnlyOnDemandAndOnlyOnce(): void
  {
    $calls = 0;
    $pipeline = Pipeline::make()->pipe(Webp::make());
    $config = (new Config())->withPipeline('lazy', static function () use (&$calls, $pipeline): Pipeline {
      $calls++;
      return $pipeline;
    })->withUploadPipeline('lazy');
    self::assertSame('lazy', $config->uploadName());
    self::assertSame(0, $calls);
    self::assertSame($pipeline, $config->pipeline('lazy'));
    self::assertSame($pipeline, $config->pipeline('lazy'));
    self::assertSame(1, $calls);
  }

  public function testUndefinedUploadPipelineIsRejected(): void
  {
    $this->expectException(InvalidArgumentException::class);
    (new Config())->withUploadPipeline('missing');
  }

  public function testInvalidQualityIsRejectedAtConfigurationTime(): void
  {
    $this->expectException(InvalidArgumentException::class);
    Webp::make()->quality(0);
  }

  public function testInvalidDimensionsAreRejectedAtConfigurationTime(): void
  {
    $this->expectException(InvalidArgumentException::class);
    Resize::make(-1);
  }

  public function testOutOfBoundsCropCannotReachTheEditor(): void
  {
    $this->expectException(InvalidArgumentException::class);
    (new Image($this->source(), new MemoryEditor()))->crop(190, 0, 20, 20);
  }

  public function testCustomEngineOperationsMarkTheImageForItsFinalEncode(): void
  {
    $editor = new MemoryEditor();
    $image = new Image($this->source(), $editor);
    $image->edit(static fn(Editor $engine) => $engine->native()->rotate(90));
    self::assertTrue($image->changed());
    self::assertSame(['width' => 100, 'height' => 200], $image->dimensions());
    self::assertSame([], $editor->saves);
  }

  private function source(): Source
  {
    return new Source('/source.png', 'image/png', 200, 100);
  }
}

final class MemoryEditor implements Editor
{
  public array $saves = [];

  public function __construct(private int $width = 200, private int $height = 100, private array $supported = ['image/png', 'image/webp', 'image/avif'])
  {
  }

  public function dimensions(): array
  {
    return ['width' => $this->width, 'height' => $this->height];
  }

  public function supports(string $mimeType): bool
  {
    return in_array($mimeType, $this->supported, true);
  }

  public function resize(int $width, ?int $height): void
  {
    $ratio = min(1, $width / $this->width, $height === null ? 1 : $height / $this->height);
    $this->width = (int) round($this->width * $ratio);
    $this->height = (int) round($this->height * $ratio);
  }

  public function rotate(float $degrees): void
  {
    [$this->width, $this->height] = [$this->height, $this->width];
  }

  public function flip(bool $horizontal, bool $vertical): void
  {
  }

  public function crop(int $x, int $y, int $width, int $height): void
  {
    $this->width = $width;
    $this->height = $height;
  }

  public function save(string $path, string $mimeType, ?int $quality): void
  {
    $this->saves[] = [$path, $mimeType, $quality];
  }

  public function native(): object
  {
    return $this;
  }
}
