# Direct Media

Direct Media is a WordPress plugin that allows you to transfer remote images, videos, and audio files directly into the WordPress Media Library.

## Features

* Add up to **20 remote media URLs** at once.
* Transfer **two files concurrently**.
* Transfer images without conversion.
* Convert images to **JPG, WebP, or AVIF**.
* Set **image quality** for converted images.
* Set optional **maximum width and height** for images.
* Transfer **video and audio files without conversion**.
* View the **transfer status** of each file.
* Display **errors** when a transfer fails.
* Preview successfully transferred images.
* Automatically add transferred files to the **WordPress Media Library**.
* Limit each remote file to **100 MB** by default.

> WebP and AVIF conversion depends on the image libraries available on the server.

## Requirements

* WordPress **6.2 or later**
* PHP **7.4 or later**

## Installation

1. Download or clone this repository.
2. Copy the `direct-media` directory to:

```text
wp-content/plugins/direct-media/
```

3. Activate **Direct Media** from the WordPress Plugins screen.
4. Open the **Media Library**.
5. Select the **Direct Media** tab.

## Usage

1. Open the WordPress Media Library.
2. Select the **Direct Media** tab.
3. Enter the URLs of the remote media files.
4. Choose the desired image options, if needed.
5. Start the transfer.
6. Transferred files are added to the WordPress Media Library.

## Image Conversion

Direct Media can transfer images in their original format or convert them to:

* **JPG**
* **WebP**
* **AVIF**

For converted images, you can configure:

* Image quality
* Maximum width
* Maximum height

WebP and AVIF support depends on the image processing capabilities available on the server.

## File Size Limit

The default maximum size for each remote file is **100 MB**.

## Changelog

### 1.0.1

* Added a permanent Direct Media page under the WordPress **Media** menu.
* Fixed Direct Media tab integration with WordPress media frames.

### 1.0.0

* Initial release.

## License

Direct Media is licensed under the **GPL v2 or later**.

See the [GNU General Public License v2.0](https://www.gnu.org/licenses/gpl-2.0.html).

## Author

**Arashk Rajabpour**
