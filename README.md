# CloakWP Image Transformations

Create image transformation pipelines for WordPress. A pipeline is an ordered list of steps, such as resizing or format conversion.

Configure pipelines in PHP. The plugin has no settings page and does not need a service account. The plugin does not change your images when you install or activate it.

The plugin requires PHP 8.2 or later and WordPress 6.5 or later. The available output formats depend on the server's WordPress image editor: Imagick or GD.

## Configure a pipeline

Add this code to your theme's `functions.php` or a must-use plugin (MU plugin). Make sure that the code runs before the WordPress `init` action:

```php
use CloakWP\ImageTransformations\ImageTransformations;
use CloakWP\ImageTransformations\Core\Pipeline;
use CloakWP\ImageTransformations\Transformations\Resize;
use CloakWP\ImageTransformations\Transformations\Webp;

ImageTransformations::make()
  ->pipeline('optimized', Pipeline::make()->through([
    Resize::make(width: 1900, height: 1500),
    Webp::make()->quality(92),
  ]))
  ->onUpload('optimized')
  ->register();
```

`pipeline()` defines a named pipeline. `onUpload()` selects the pipeline for new uploads. The plugin also uses this pipeline for sideloads: uploads that PHP code adds, for example from a remote URL. `register()` connects the plugin to WordPress.

Without `onUpload()`, the plugin does not automatically process uploads. PHP calls, WP-CLI commands, and enabled Media Library actions can still run the pipelines.

The plugin loads the source file into a working image in memory. Each step runs on this working image in the specified order. If the working image changes, the plugin encodes it once to create the output file.

`Resize` keeps the image proportions and fits the image within the specified width and height limits. It does not enlarge images. If you specify only a width, `Resize` applies only the width limit. Before resizing or other shape changes, the editor corrects the image orientation from its EXIF data.

To create a pipeline only when the plugin needs it, pass a function called a factory:

```php
->pipeline('optimized', fn(): Pipeline => Pipeline::make()->through([
  Resize::make(1900, 1500), Webp::make()->quality(92),
]))
```

The plugin calls each factory once per `Core\Config` object, when it first needs that pipeline. If a request does not need a pipeline, the plugin does not call its factory.

Use an upload selector or pipeline condition for decisions that depend on the current multisite site. These functions can check the current site after `switch_to_blog()`.

The supplied pipeline and transformation builder methods return new objects. They do not change existing pipeline or transformation objects. Each processing run has its own working image. After `register()`, the `ImageTransformations` object rejects configuration changes.

## Formats, conditions, and nested pipelines

```php
use CloakWP\ImageTransformations\Core\Image;
use CloakWP\ImageTransformations\Core\Source;
use CloakWP\ImageTransformations\Transformations\Avif;
use CloakWP\ImageTransformations\Transformations\When;

$webp = Pipeline::make()->through([
  Resize::make(1600, 1600),
  Webp::make()->quality(85),
]);

$avif = Pipeline::make()
  ->onlyMimeTypes(['image/jpeg', 'image/png'])
  ->when(fn(Source $source): bool => $source->width > 800)
  ->through([
    Resize::make(1900, 1500),
    Avif::make()->quality(75)->skipIfUnsupported(),
    When::make(
      fn(Image $image): bool => !$image->supports('image/avif'),
      Webp::make()->quality(85),
    ),
  ]);

ImageTransformations::make()
  ->pipeline('webp', $webp)
  ->pipeline('avif', $avif)
  ->onUpload('avif')
  ->mediaActions() // Optional native Media Library list/bulk actions.
  ->register();
```

By default, pipelines accept JPEG, PNG, WebP, and AVIF images without animation. The plugin skips animated WebP, APNG, AVIF sequences, all GIF files, SVG files, and files that are not images.

`onlyMimeTypes()` restricts the accepted image types. A MIME type identifies a file format, such as `image/jpeg`. The plugin skips animated images even if their MIME type is in this list. WordPress image editors can remove animation when they save an image.

If the image editor cannot encode the selected format, the pipeline fails. Use `skipIfUnsupported()` to skip that encoding step when the editor does not support the format. The plugin still saves changes from earlier steps, such as resizing.

For transparent images, the default Imagick editor checks whether AVIF preserves transparency. Some older servers support AVIF but remove transparency. On these servers, the plugin treats AVIF as unsupported for transparent images. AVIF remains available for images without transparency. If you register a custom WordPress image editor, that editor controls format support and encoding.

A pipeline creates at most one output file. If you add multiple encoding steps, the last step that selects a format determines the output format. To create separate WebP and AVIF files, define separate named pipelines. Run each pipeline separately.

A `Pipeline` implements the `Transformation` interface. Thus, you can add one pipeline as a step in another pipeline. The inner pipeline still checks its MIME types and conditions.

The plugin can check conditions before and during processing. Write condition functions that return a result without changing data. Make sure that these functions are safe to call more than once.

## Add your own transformation

Create a class that implements the `Transformation` interface. Pass an object of this class to `through()` or `pipe()`. You do not need to register the class or extend a base class:

```php
use CloakWP\ImageTransformations\Core\Contracts\Transformation;
use CloakWP\ImageTransformations\Core\Image;

final class RotatePortrait implements Transformation
{
  public function apply(Image $image): void
  {
    $size = $image->dimensions();
    if ($size['height'] > $size['width']) {
      $image->rotate(90);
    }
  }
}

$recipe = $webp->pipe(new RotatePortrait());
```

Use `dimensions()` to read the working image's width and height. The `source` property describes the input file. Use `resize()`, `rotate()`, `flip()`, or `crop()` to change the working image. Use `format()` and `quality()` to control the output file. Use `supports()` to check whether the editor supports a format.

Positive rotation angles turn the image counterclockwise, as in WordPress. `format()` accepts a registered image MIME type that the editor supports. You can use formats other than WebP and AVIF. To add an encoding step, extend `Transformations\Encode` with the MIME type and default quality for your format.

The core pipeline and image classes use the `Editor` interface. They do not call WordPress, GD, or Imagick directly.

For an operation that `Image` does not provide, use `Image::edit()`. This method passes the editor object to your function and marks the working image as changed. The editor's `native()` method returns the selected WordPress image editor. A custom editor registered through `wp_image_editors` can provide extra methods, as in this example:

```php
$image->edit(function (\CloakWP\ImageTransformations\Core\Contracts\Editor $editor): void {
  $native = $editor->native();
  // A method implemented by your custom WP_Image_Editor subclass:
  $native->watermark($this->watermarkPath);
});
```

Make your function change the working image in memory. If the operation fails, throw an exception. Do not change the source file. The plugin encodes the output file and checks the result.

## Existing images and reusable file processing

```php
$images = ImageTransformations::instance();
$result = $images->transformAttachment(42, 'webp');

// A separate output file, without attachment/database changes:
$result = $images->transformFile('/absolute/path/photo.png', 'avif');
```

`transformAttachment()` processes an image in the Media Library and updates its attachment data. `transformFile()` creates a separate output file without changing attachment data or the database.

These PHP methods do not check user permissions or WordPress nonces. A nonce is a request token that helps WordPress reject forged requests.

If your HTTP endpoint calls `transformAttachment()`, do these checks before the call:

1. Check the WordPress nonce for the request.
2. Check that the user can upload files (`upload_files`).
3. Check that the user can edit the attachment (`edit_post` with the attachment ID).

Reject the request if any check fails. The plugin's Media Library actions do these checks for you.

You can also run configured pipelines with WP-CLI:

```sh
wp images transform 42 57 --pipeline=webp --dry-run
wp images transform 42 57 --pipeline=webp
```

On multisite, use `--url` to select the site. The command processes only the attachment IDs that you specify. If any attachment fails, the command reports the failure and returns a nonzero exit status.

Use `--dry-run` to check whether the pipeline accepts each image. This option does not decode images or write output files.

Each manual run uses the current image. If the pipeline changes the image, the plugin creates another output file. The plugin does not schedule these runs or process existing images on activation.

## Originals and WordPress integration

The plugin saves each output file in the source file's directory with a unique filename. The plugin does not overwrite or delete the source file during processing.

For new uploads, the plugin changes the file path, URL, and MIME type before WordPress creates thumbnails. The plugin records the original file in WordPress's `original_image` metadata. WordPress functions that retrieve the original image continue to work.

Existing attachments keep their ID and GUID. After it checks the output file, the plugin updates the attachment's file reference, MIME type, and metadata. The plugin keeps previous full-size files and thumbnails and records them in WordPress's attachment backup metadata. Existing links to these files continue to work. When you delete an attachment, WordPress removes its original file, backups, and current image sizes through its usual cleanup process.

A file lock prevents simultaneous transformations of the same attachment. If the plugin cannot complete an attachment update, it restores the attachment's previous data.

WordPress creates registered thumbnail sizes from the processed upload. Pipeline resizing and quality settings apply to the full-size working image. WordPress filters control the size, quality, and output format of thumbnails.

WordPress can use the retained original file when it regenerates images. To apply a pipeline again, run the transformation command.

On WordPress 7.1 or later, a PHP upload pipeline disables image processing in the browser for that site. The browser cannot run custom PHP steps. Without an upload pipeline, WordPress continues to use its own image processing behavior.

If an upload pipeline fails, WordPress continues the upload with the valid original file. To record these failures, add a function to the failure action:

```php
add_action('cloakwp/image-transformations/failed', function (
  Throwable $error,
  string $path,
  string $pipeline,
): void {
  error_log($error->getMessage());
}, 10, 3);
```

After successful processing, the `cloakwp/image-transformations/transformed` action receives the `Core\Result` object and pipeline name. If a PHP processing call fails, it throws an exception to the calling code.

## PHP configuration filter and multisite

The plugin calls the `cloakwp/image-transformations/config` filter during registration. Without a manual `register()` call, the plugin registers automatically during the `init` action.

To configure pipelines without a `register()` call, add a function to this filter. Return a `Core\Config` object from the function:

```php
use CloakWP\ImageTransformations\Core\Config;

add_filter('cloakwp/image-transformations/config', function (Config $config) use ($webp): Config {
  return $config->withPipeline('optimized', $webp)->withUploadPipeline('optimized');
});
```

An upload selector is a function that chooses the upload pipeline. Return a pipeline name to enable processing for the current site. Return `null` to disable upload processing for that site.

The plugin calls the selector when it needs the upload pipeline name, including after `switch_to_blog()`:

```php
->onUpload(fn(): ?string => get_current_blog_id() === 2 ? 'optimized' : null)
```

Pipeline conditions can also check the current site. The plugin does not save PHP configuration to `wp_options`. It saves attachment metadata that describes the processed files.

## Install

### Composer

Run:

```sh
composer require cloakwp/image-transformations
```

The Composer package type is `wordpress-muplugin`. The package uses `composer/installers` to set the installation directory.

Configure your project's installer paths to use `wp-content/mu-plugins/image-transformations/`. For Bedrock, use `public/app/mu-plugins/image-transformations/`.

WordPress does not automatically load PHP files from folders inside `mu-plugins`. Use [Roots Bedrock Autoloader](https://github.com/roots/bedrock-autoloader) to load the plugin. If you do not use this loader, create a PHP file directly in your `mu-plugins` directory. Add this code to the file:

```php
<?php require_once WPMU_PLUGIN_DIR . '/image-transformations/image-transformations.php';
```

### Traditional ZIP

Not using Composer? Install the plugin with these steps:

1. Open the repository's GitHub page (if you aren't already there).
2. Select **Code → Download ZIP**.
3. Extract the downloaded ZIP file.
4. Rename the extracted folder to `image-transformations`.
5. Create a ZIP file from that folder.
6. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**.
7. Upload the ZIP file.
8. Select **Install Now**.
9. Activate the plugin.

This installation does not require Composer or a build step. Configure your pipelines in PHP as shown above. Activation alone does not enable image processing.

## Development

```sh
composer install
composer test
wp eval-file tests/integration/wordpress.php --use-include
```

The integration test requires the WordPress environment type `local` or `development`. If your Bedrock project defines only `WP_ENV`, also set `WP_ENVIRONMENT_TYPE=development` for the test command.

The test creates temporary images and attachments. It checks WebP and AVIF encoding, quality, unsupported formats, uploads, original files, metadata, permissions, and attachment deletion. The test requires GD and Imagick with WebP and AVIF support.

The package contains these files and directories:

- `src/Core` contains interfaces, pipeline definitions, configuration, and working image data.
- `src/Transformations` contains reusable transformation steps.
- `src/Plugin` contains WordPress editors, hooks, attachment updates, Media Library actions, and WP-CLI commands.
- `image-transformations.php` contains the plugin header, autoloader, and startup code.

The license is LGPL-3.0-only.
