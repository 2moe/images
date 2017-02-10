<?php

namespace AndriesLouw\imagesweserv\Api;

use AndriesLouw\imagesweserv\Client;
use AndriesLouw\imagesweserv\Exception\ImageNotReadableException;
use AndriesLouw\imagesweserv\Exception\ImageTooLargeException;
use AndriesLouw\imagesweserv\Exception\RateExceededException;
use AndriesLouw\imagesweserv\Manipulators\Background;
use AndriesLouw\imagesweserv\Manipulators\Blur;
use AndriesLouw\imagesweserv\Manipulators\Helpers\Utils;
use AndriesLouw\imagesweserv\Manipulators\ManipulatorInterface;
use AndriesLouw\imagesweserv\Manipulators\Shape;
use AndriesLouw\imagesweserv\Manipulators\Sharpen;
use AndriesLouw\imagesweserv\Manipulators\Size;
use AndriesLouw\imagesweserv\Throttler\ThrottlerInterface;
use GuzzleHttp\Exception\RequestException;
use InvalidArgumentException;
use Jcupitt\Vips\Access;
use Jcupitt\Vips\BandFormat;
use Jcupitt\Vips\Exception as VipsException;
use Jcupitt\Vips\Image;

class Api implements ApiInterface
{
    /**
     * Collection of manipulators.
     *
     * @var array
     */
    protected $manipulators;

    /**
     * The PHP HTTP client
     *
     * @var Client
     */
    protected $client;

    /**
     * The throttler
     *
     * @var ThrottlerInterface|null
     */
    protected $throttler;

    /**
     * The current mime type
     *
     * @var Client
     */
    protected $mimeType;

    /**
     * Create API instance.
     *
     * @param Client $client The Guzzle
     * @param ThrottlerInterface|null $throttler Throttler
     * @param array $manipulators Collection of manipulators.
     */
    public function __construct(Client $client, $throttler, array $manipulators)
    {
        $this->setClient($client);
        $this->setThrottler($throttler);
        $this->setManipulators($manipulators);
    }

    /**
     * Get the PHP HTTP client
     *
     * @return Client The Guzzle client
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Set the PHP HTTP client
     *
     * @param Client $client Guzzle client
     */
    public function setClient(Client $client)
    {
        $this->client = $client;
    }

    /**
     * Get the throttler
     *
     * @return ThrottlerInterface|null Throttler class
     */
    public function getThrottler()
    {
        return $this->throttler;
    }

    /**
     * Set the throttler
     *
     * @param ThrottlerInterface|null $throttler Throttler class
     */
    public function setThrottler($throttler)
    {
        $this->throttler = $throttler;
    }

    /**
     * Get the manipulators.
     *
     * @return array Collection of manipulators.
     */
    public function getManipulators(): array
    {
        return $this->manipulators;
    }

    /**
     * Set the manipulators.
     *
     * @param array $manipulators Collection of manipulators.
     *
     * @throws InvalidArgumentException if there's a manipulator which not extends
     *      ManipulatorInterface
     */
    public function setManipulators(array $manipulators)
    {
        foreach ($manipulators as $manipulator) {
            if (!($manipulator instanceof ManipulatorInterface)) {
                throw new InvalidArgumentException('Not a valid manipulator.');
            }
        }

        $this->manipulators = $manipulators;
    }

    /**
     * {@inheritdoc}
     *
     * @throws RateExceededException if a user rate limit is exceeded
     * @throws ImageNotReadableException if the provided image is not readable.
     * @throws ImageTooLargeException if the provided image is too large for
     *      processing.
     * @throws RequestException for errors that occur during a transfer or during
     *      the on_headers event
     * @throws VipsException for errors that occur during the processing of a Image
     */
    public function run(string $url, string $extension, array $params): array
    {
        if ($this->throttler !== null) {
            // For PHPUnit check if REMOTE_ADDR is set
            $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';

            // Check if rate is exceeded for IP
            if ($this->throttler->isExceeded($ip, $this->ban())) {
                throw new RateExceededException();
            }
        }

        $tmpFileName = $this->client->get($url);

        // Things that won't work with sequential mode images:
        //  - Trim (will scan the whole image once to find the crop area).
        //  - A 90/270-degree rotate (will need to read a column of pixels for every output line it writes).
        //  - Adjustments such blurring or sharpen.
        $isTrim = isset($params['trim']);
        $isOrientation = isset($params['or']) && in_array($params['or'], ['90', '270'], true);
        $isBlur = isset($params['blur']);
        $isSharp = isset($params['sharp']);

        // If any of the above adjustments; don't use sequential mode read.
        $params['accessMethod'] = $isTrim || $isOrientation || $isBlur || $isSharp ?
            Access::RANDOM :
            Access::SEQUENTIAL;

        try {
            $image = Image::newFromFile($tmpFileName, ['access' => $params['accessMethod']]);
        } catch (VipsException $e) {
            trigger_error('Image not readable. Message: ' . $e->getMessage() . ' URL: ' . $url, E_USER_WARNING);

            // Keep throwing it (with a wrapper).
            throw new ImageNotReadableException('Image not readable. Is it a valid image?', 0, $e);
        }

        $allowed = $this->getAllowedImageTypes();

        $interpretation = $image->interpretation;

        // Put common variables in the parameters
        $params['hasAlpha'] = Utils::hasAlpha($image);
        $params['is16Bit'] = Utils::is16Bit($interpretation);
        $params['isPremultiplied'] = false;

        foreach ($this->manipulators as $manipulator) {
            $manipulator->setParams($params);

            $image = $manipulator->run($image);

            // Size and shape manipulators can override `hasAlpha` parameter.
            if ($manipulator instanceof Size || $manipulator instanceof Shape) {
                $params['hasAlpha'] = $manipulator->hasAlpha;
            }

            // Size, sharpen, blur and background manipulators can override `isPremultiplied` parameter.
            if ($manipulator instanceof Size
                || $manipulator instanceof Sharpen
                || $manipulator instanceof Blur
                || $manipulator instanceof Background
            ) {
                $params['isPremultiplied'] = $manipulator->isPremultiplied;
            }
        }

        // Reverse premultiplication after all transformations:
        if ($params['isPremultiplied']) {
            $image = $image->unpremultiply();

            // Cast pixel values to integer
            if ($params['is16Bit']) {
                $image = $image->cast(BandFormat::USHORT);
            } else {
                $image = $image->cast(BandFormat::UCHAR);
            }
        }

        $needsGif = (isset($params['output']) && $params['output'] == 'gif')
            || (!isset($params['output']) && $extension == 'gif');

        // Check if output is set and allowed
        if (isset($params['output']) && isset($allowed[$params['output']])) {
            $extension = $params['output'];
        } else {
            $supportsAlpha = ['png', 'webp'];
            if ($params['hasAlpha'] && !isset($supportsAlpha[$extension])) {
                // If image has alpha and doesn't have the right extension to output alpha.
                // Then force it to PNG (useful for shape masking and letterboxing).
                $extension = 'png';
            } elseif (!isset($allowed[$extension])) {
                // If extension is not allowed (and doesn't have alpha) we need to output it as jpg.
                $extension = 'jpg';
            }
        }

        $options = [];

        if ($extension == 'jpg' || $extension == 'webp') {
            $options['Q'] = $this->getQuality($params);
        }
        if ($extension == 'jpg' || $extension == 'png') {
            $options['interlace'] = array_key_exists('il', $params);
        }
        if ($extension == 'png') {
            $options['compression'] = $this->getCompressionLevel($params);
        }

        $buffer = $image->writeToBuffer('.' . $extension, $options);
        $mimeType = $allowed[$extension];

        /*
         * Note:
         * It's currently not possible to save gif through libvips.
         *
         * We don't deprecate GIF output to make sure to not break
         * anyone's apps.
         * If gif output is needed then we are using GD
         * to convert our libvips image to a gif.
         *
         * (Feels a little hackish but there is not an alternative at
         * this moment..)
         */

        // Check if GD library is installed on the server
        $gdAvailable = extension_loaded('gd') && function_exists('gd_info');

        // If the GD library is installed and a gif output is needed.
        if ($gdAvailable && $needsGif) {
            // Create GD image from string (suppress any warnings)
            $gdImage = @imagecreatefromstring($buffer);

            // If image is valid
            if ($gdImage !== false) {
                // Enable interlacing if needed
                if ($options['interlace']) {
                    imageinterlace($gdImage, true);
                }

                // Preserve transparency
                if ($params['hasAlpha']) {
                    imagecolortransparent($gdImage, imagecolorallocatealpha($gdImage, 0, 0, 0, 127));
                    imagealphablending($gdImage, false);
                    imagesavealpha($gdImage, true);
                }

                // Turn output buffering on
                ob_start();

                // Output the image to the buffer
                imagegif($gdImage);

                // Read from buffer
                $buffer = ob_get_contents();

                // Delete buffer
                ob_end_clean();

                // Free up memory
                imagedestroy($gdImage);

                // Extension and mime-type are now gif
                $extension = 'gif';
                $mimeType = 'image/gif';
            }
        }

        return [
            $buffer,
            $mimeType,
            $extension
        ];
    }

    /**
     * Ban's the user if it's getting throttled.
     *
     * For example:
     * This script can call CloudFlare directly through cURL
     * or log the ban and invoke Fail2Ban on the server.
     *
     * Note: only getting called once.
     */
    public function ban(): \Closure
    {
        $log = 'User rate limit exceeded. IP: %s Expires: %d';
        return function ($ip, $banTime) use ($log) {
            // Ban script (just use your imagination)
            trigger_error(sprintf($log, $ip, $banTime), E_USER_WARNING);
        };
    }

    /**
     * Get the allowed image types to convert to.
     *
     * Note: It's currently not possible to save gif through libvips
     * See: https://github.com/jcupitt/libvips/issues/235
     *
     * @return array
     */
    public function getAllowedImageTypes(): array
    {
        return [
            //'gif' => 'image/gif',
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
        ];
    }

    /**
     * Resolve quality.
     *
     * @param array $params Parameters array
     *
     * @return int The resolved quality.
     */
    public function getQuality(array $params): int
    {
        $default = 85;

        if (!isset($params['q']) || !is_numeric($params['q'])) {
            return $default;
        }

        if ($params['q'] < 0 || $params['q'] > 100) {
            return $default;
        }

        return (int)$params['q'];
    }

    /**
     * Get the zlib compression level of the lossless PNG output format.
     * The default level is 6.
     *
     * @param array $params Parameters array
     *
     * @return int The resolved zlib compression level.
     */
    public function getCompressionLevel(array $params): int
    {
        $default = 6;

        if (!isset($params['level']) || !is_numeric($params['level'])) {
            return $default;
        }

        if ($params['level'] < 0 || $params['level'] > 9) {
            return $default;
        }

        return (int)$params['level'];
    }
}
