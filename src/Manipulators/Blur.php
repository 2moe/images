<?php

namespace AndriesLouw\imagesweserv\Manipulators;

use AndriesLouw\imagesweserv\Manipulators\Helpers\Utils;
use Jcupitt\Vips\Image;

/**
 * @property string $blur
 * @property bool $hasAlpha
 * @property int $maxAlpha
 */
class Blur extends BaseManipulator
{
    /**
     * Perform blur image manipulation.
     *
     * @param  Image $image The source image.
     *
     * @return Image The manipulated image.
     */
    public function run(Image $image): Image
    {
        if ($this->blur === null) {
            return $image;
        }

        if ($this->hasAlpha) {
            // Ensures that the image alpha channel is premultiplied before doing any blur transformations
            // to avoid dark fringing around bright pixels
            // See: http://entropymine.com/imageworsener/resizealpha/
            $image = Utils::premultiplyImage($image, $this->maxAlpha);
        }

        $blur = $this->getBlur();

        if ($blur == -1.0) {
            // Fast, mild blur - averages neighbouring pixels
            $blur = Image::newFromArray(
                [
                    [1.0, 1.0, 1.0],
                    [1.0, 1.0, 1.0],
                    [1.0, 1.0, 1.0]
                ],
                9.0
            );
            $image = $image->conv($blur);
        } else {
            $image = $image->gaussblur($blur);
        }

        return $image;
    }

    /**
     * Resolve blur amount.
     *
     * @return float The resolved blur amount.
     */
    public function getBlur(): float
    {
        if (!preg_match('/^[0-9]\.*[0-9]*$/', $this->blur)) {
            return -1.0;
        }

        if ($this->blur >= 0.3 || $this->blur <= 1000) {
            return (float)$this->blur;
        }

        return -1.0;
    }
}
