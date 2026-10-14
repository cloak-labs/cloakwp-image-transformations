<?php

declare(strict_types=1);

/** Run with WP-CLI against a development site. Uses only disposable fixtures. */
use CloakWP\ImageTransformations\Core\Config;
use CloakWP\ImageTransformations\Core\Contracts\Transformation;
use CloakWP\ImageTransformations\Core\Image;
use CloakWP\ImageTransformations\Core\Pipeline;
use CloakWP\ImageTransformations\Core\TransformationFailed;
use CloakWP\ImageTransformations\ImageTransformations;
use CloakWP\ImageTransformations\Plugin\MediaActions;
use CloakWP\ImageTransformations\Plugin\ImagickEditor;
use CloakWP\ImageTransformations\Plugin\Plugin;
use CloakWP\ImageTransformations\Plugin\Processor;
use CloakWP\ImageTransformations\Plugin\SourceInspector;
use CloakWP\ImageTransformations\Plugin\Uploads;
use CloakWP\ImageTransformations\Transformations\Avif;
use CloakWP\ImageTransformations\Transformations\Resize;
use CloakWP\ImageTransformations\Transformations\Webp;
use CloakWP\ImageTransformations\Transformations\When;

require_once dirname(__DIR__, 2) . '/autoload.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
require_once ABSPATH . WPINC . '/class-wp-image-editor-imagick.php';

class PipelineQualityProbe extends WP_Image_Editor_Imagick
{
  public static ?int $savedQuality = null;

  protected function _save($image, $filename = null, $mime_type = null)
  {
    $result = parent::_save($image, $filename, $mime_type);
    self::$savedQuality = $this->get_quality();
    return $result;
  }
}
if (wp_get_environment_type() !== 'development' && wp_get_environment_type() !== 'local') {
  WP_CLI::error('Integration checks require a local or development WordPress site.');
}

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
  $checks++;
  WP_CLI::log('PASS ' . $message);
};
$uploads = wp_upload_dir();
$prefix = 'cloakwp-pipeline-test-' . bin2hex(random_bytes(5));
$path = $uploads['path'] . '/' . $prefix . '.png';
$bitmap = imagecreatetruecolor(240, 120);
imagealphablending($bitmap, false);
imagesavealpha($bitmap, true);
imagefill($bitmap, 0, 0, imagecolorallocatealpha($bitmap, 25, 100, 200, 80));
imagepng($bitmap, $path);
imagedestroy($bitmap);
$originalHash = hash_file('sha256', $path);
$opaquePath = $uploads['path'] . '/' . $prefix . '-opaque.png';
$bitmap = imagecreatetruecolor(240, 120);
imagefill($bitmap, 0, 0, imagecolorallocate($bitmap, 25, 100, 200));
imagepng($bitmap, $opaquePath);
imagedestroy($bitmap);
$opaqueHash = hash_file('sha256', $opaquePath);
$attachmentIds = [];
$forceGd = static fn(): array => ['WP_Image_Editor_GD'];
$config = (new Config())->withPipeline('webp', Pipeline::make()->through([Resize::make(80, 80), Webp::make()->quality(91)]));
$images = ImageTransformations::make()->pipeline('webp', $config->pipeline('webp'))
  ->pipeline('avif', Pipeline::make()->through([Resize::make(80, 80), Avif::make()->quality(70)]))
  ->pipeline('png', Pipeline::make()->pipe(new class implements Transformation {
    public function apply(Image $image): void { $image->format('image/png'); }
  }));

try {
  $hooks = array_keys($GLOBALS['wp_filter']);
  (new Plugin(ImageTransformations::make()))->register();
  $check($hooks === array_keys($GLOBALS['wp_filter']), 'Empty configuration installs no processing or UI hooks.');
  $check(!(new SourceInspector())->inspect($path)->animated, 'Still PNG passes animation inspection.');

  foreach (['webp' => 'image/webp', 'avif' => 'image/avif'] as $name => $mime) {
    if (!wp_image_editor_supports(['mime_type' => $mime])) {
      WP_CLI::log('SKIP ' . $name . ': no encoder available');
      continue;
    }
    $sourcePath = $name === 'avif' ? $opaquePath : $path;
    $sourceHash = $name === 'avif' ? $opaqueHash : $originalHash;
    $result = $images->transformFile($sourcePath, $name);
    $check($result->changed && $result->mimeType === $mime && wp_get_image_mime($result->path) === $mime, "{$name} writes its actual requested codec.");
    $check($result->width === 80 && $result->height === 40, "{$name} respects both resize bounds and aspect ratio.");
    $check(hash_file('sha256', $sourcePath) === $sourceHash, "{$name} leaves the original byte-for-byte intact.");
    if ($name === 'webp') {
      $decoded = new Imagick($result->path);
      $alpha = $decoded->getImagePixelColor(10, 10)->getColor(1)['a'];
      $check($decoded->getImageAlphaChannel() && $alpha > 0 && $alpha < 1, 'WebP preserves image transparency.');
      $decoded->clear();
    }
  }

  $avifFailed = false;
  try { $images->transformFile($path, 'avif'); } catch (TransformationFailed) { $avifFailed = true; }
  $check($avifFailed === !ImagickEditor::supportsAvifAlpha(), 'AVIF cannot silently discard transparency on an incompatible encoder.');
  $fallback = ImageTransformations::make()->pipeline('fallback', Pipeline::make()->through([
    Resize::make(80), Avif::make()->skipIfUnsupported(),
    When::make(fn(Image $image): bool => !$image->supports('image/avif'), Webp::make()),
  ]));
  $result = $fallback->transformFile($path, 'fallback');
  $check($result->mimeType === (ImagickEditor::supportsAvifAlpha() ? 'image/avif' : 'image/webp'), 'An explicit fallback selects a transparency-capable encoder.');

  $animation = new Imagick();
  foreach (['red', 'blue'] as $color) {
    $frame = new Imagick();
    $frame->newImage(20, 20, new ImagickPixel($color), 'webp');
    $frame->setImageDelay(10);
    $animation->addImage($frame);
    $frame->clear();
  }
  $animatedPath = $uploads['path'] . '/' . $prefix . '-animated.webp';
  $animation->writeImages($animatedPath, true);
  $animation->clear();
  $source = (new SourceInspector())->inspect($animatedPath);
  $check($source->animated && !(new Processor())->process($source, $config->pipeline('webp'))->changed, 'Animated WebP bypasses transformations without losing frames.');

  $forceQualityProbe = static fn(): array => [PipelineQualityProbe::class];
  add_filter('wp_image_editors', $forceQualityProbe);
  $filterCount = isset($GLOBALS['wp_filter']['wp_editor_set_quality']) ? count($GLOBALS['wp_filter']['wp_editor_set_quality']) : 0;
  $images->transformFile($path, 'webp');
  remove_filter('wp_image_editors', $forceQualityProbe);
  $check(PipelineQualityProbe::$savedQuality === 91, 'Explicit quality survives WordPress resetting quality during format conversion.');
  $check($filterCount === (isset($GLOBALS['wp_filter']['wp_editor_set_quality']) ? count($GLOBALS['wp_filter']['wp_editor_set_quality']) : 0), 'Temporary encode quality filters do not persist.');

  add_filter('wp_image_editors', $forceGd);
  $failed = false;
  try {
    $images->transformFile($path, 'webp');
  } catch (TransformationFailed) {
    $failed = true;
  }
  $check($failed === !WP_Image_Editor_GD::supports_mime_type('image/webp'), 'Unsupported GD codec fails explicitly.');
  $skipping = ImageTransformations::make()->pipeline('skip', Pipeline::make()->through([Resize::make(90), Webp::make()->skipIfUnsupported()]));
  $skipped = $skipping->transformFile($path, 'skip');
  $check($skipped->width === 90 && ($skipped->mimeType === 'image/png' || $skipped->mimeType === 'image/webp'), 'Skipping an unsupported encoder retains the resize.');
  remove_filter('wp_image_editors', $forceGd);

  $throws = new class implements Transformation {
    public function apply(Image $image): void { $image->resize(40); throw new RuntimeException('test failure'); }
  };
  $failing = ImageTransformations::make()->pipeline('fail', Pipeline::make()->pipe($throws));
  $before = glob($uploads['path'] . '/' . $prefix . '*');
  try { $failing->transformFile($path, 'fail'); } catch (RuntimeException) { }
  $check($before === glob($uploads['path'] . '/' . $prefix . '*') && hash_file('sha256', $path) === $originalHash,
    'A failed custom step creates no output and leaves the original intact.');

  $config = $config->withUploadPipeline('webp');
  $handler = new Uploads($config, new Processor());
  $handler->register();
  $upload = $handler->handle(['file' => $path, 'url' => $uploads['url'] . '/' . basename($path), 'type' => 'image/png'], 'sideload');
  $check($upload['type'] === 'image/webp' && basename($upload['file']) === basename($upload['url']), 'Upload path, URL and MIME are updated together.');
  $check(!wp_is_client_side_media_processing_enabled(), 'Configured PHP uploads disable browser image processing.');
  $id = wp_insert_attachment(['post_title' => $prefix, 'post_mime_type' => $upload['type']], $upload['file'], 0, true);
  if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
  $attachmentIds[] = $id;
  wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $upload['file']));
  $meta = wp_get_attachment_metadata($id);
  $check($meta['original_image'] === basename($path) && wp_get_original_image_path($id) === $path, 'Core attachment metadata retains the original upload.');
  $guid = get_post($id)->guid;
  $oldPath = get_attached_file($id);
  $lock = fopen($oldPath, 'rb');
  flock($lock, LOCK_EX);
  $locked = false;
  try { $images->transformAttachment($id, 'png'); } catch (TransformationFailed) { $locked = true; }
  flock($lock, LOCK_UN);
  fclose($lock);
  $check($locked && get_attached_file($id) === $oldPath, 'A held image lock prevents concurrent attachment transformations.');

  $blockFileUpdate = static function ($value, $objectId, $key) use ($id) { return $objectId === $id && $key === '_wp_attached_file' ? false : $value; };
  add_filter('update_post_metadata', $blockFileUpdate, 10, 3);
  $before = glob($uploads['path'] . '/' . $prefix . '*');
  $failed = false;
  try { $images->transformAttachment($id, 'png'); } catch (TransformationFailed) { $failed = true; }
  remove_filter('update_post_metadata', $blockFileUpdate, 10);
  $check($failed && $before === glob($uploads['path'] . '/' . $prefix . '*') && get_attached_file($id) === $oldPath,
    'Failed file-reference persistence rolls back and removes the new output.');

  $failGeneration = static function (array $metadata, int $objectId) use ($id): array {
    if ($objectId === $id) { throw new RuntimeException('test metadata generation failure'); }
    return $metadata;
  };
  add_filter('wp_generate_attachment_metadata', $failGeneration, 100, 2);
  $failed = false;
  try { $images->transformAttachment($id, 'png'); } catch (RuntimeException) { $failed = true; }
  remove_filter('wp_generate_attachment_metadata', $failGeneration, 100);
  $check($failed && get_attached_file($id) === $oldPath && get_post_mime_type($id) === 'image/webp'
    && wp_get_attachment_metadata($id) === $meta && $before === glob($uploads['path'] . '/' . $prefix . '*'),
    'Late metadata failure restores the old file, MIME and metadata without leaving generated files.');

  $result = $images->transformAttachment($id, 'png');
  $check(get_post_mime_type($id) === 'image/png' && get_attached_file($id) === $result->path, 'Existing attachment switches MIME and file together.');
  $check(wp_get_attachment_metadata($id)['width'] === 80 && get_post($id)->guid === $guid, 'Regenerated metadata is valid and GUID stays stable.');
  $check(is_file($oldPath) && is_file($path) && wp_get_original_image_path($id) === $path, 'Manual processing preserves earlier URLs and the oldest original.');

  $request = new WP_REST_Request('GET', '/wp/v2/media/' . $id);
  $response = rest_do_request($request);
  $data = $response->get_data();
  $check($response->get_status() === 200 && $data['mime_type'] === 'image/png'
    && basename($data['source_url']) === basename($result->path) && $data['media_details']['width'] === 80,
    'The attachment REST response exposes consistent transformed URLs, MIME and dimensions.');

  wp_set_current_user(0);
  $actions = (new MediaActions($images))->rowActions([], get_post($id));
  $check($actions === [], 'Unauthorized users receive no image transformation actions.');
  wp_delete_attachment($id, true);
  $attachmentIds = [];
  $check(!is_file($path) && !is_file($oldPath) && !is_file($result->path), 'Core deletion cleans the original, backups and transformed attachment.');
  $id = wp_insert_attachment(['post_title' => $prefix . '-opaque', 'post_mime_type' => 'image/png'], $opaquePath, 0, true);
  if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
  $attachmentIds[] = $id;
  wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $opaquePath));
  $result = $images->transformAttachment($id, 'avif');
  $check($result->mimeType === 'image/avif' && get_post_mime_type($id) === 'image/avif'
    && wp_get_attachment_metadata($id)['width'] === 80, 'Opaque attachments convert to AVIF with coherent metadata.');
  WP_CLI::success("{$checks} real WordPress integration checks passed.");
} finally {
  remove_filter('wp_image_editors', $forceGd);
  foreach ($attachmentIds as $id) { wp_delete_attachment($id, true); }
  foreach (glob($uploads['path'] . '/' . $prefix . '*') ?: [] as $file) { wp_delete_file($file); }
}
