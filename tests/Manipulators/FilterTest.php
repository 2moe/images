<?php

namespace AndriesLouw\imagesweserv\Test\Manipulators;

use AndriesLouw\imagesweserv\Api\Api;
use AndriesLouw\imagesweserv\Client;
use AndriesLouw\imagesweserv\Manipulators\Filter;
use AndriesLouw\imagesweserv\Test\ImagesweservTestCase;
use Jcupitt\Vips\Image;
use Mockery\MockInterface;

class FilterTest extends ImagesweservTestCase
{
    /**
     * @var Client|MockInterface $client
     */
    private $client;

    /**
     * @var Api $api
     */
    private $api;

    /**
     * @var Filter $manipulator
     */
    private $manipulator;

    public function setUp()
    {
        $this->client = $this->getMockery(Client::class);
        $this->api = new Api($this->client, $this->getManipulators());
        $this->manipulator = new Filter();
    }

    public function testCreateInstance()
    {
        $this->assertInstanceOf(Filter::class, $this->manipulator);
    }

    public function testGreyscaleFilter()
    {
        $testImage = $this->inputJpg;
        $expectedImage = $this->expectedDir . '/greyscale.jpg';
        $params = [
            'w' => '320',
            'h' => '240',
            't' => 'square',
            'filt' => 'greyscale'
        ];

        $uri = basename($testImage);

        $this->client->shouldReceive('get')->with($uri)->andReturn($testImage);

        /** @var Image $image */
        $image = $this->api->run($uri, $params);

        $this->assertEquals('jpegload', $image->get('vips-loader'));
        $this->assertEquals(1, $image->bands);
        $this->assertEquals(320, $image->width);
        $this->assertEquals(240, $image->height);
        $this->assertSimilarImage($expectedImage, $image);
    }

    public function testSepiaFilterJpeg()
    {
        $testImage = $this->inputJpg;
        $expectedImage = $this->expectedDir . '/sepia.jpg';
        $params = [
            'w' => '320',
            'h' => '240',
            't' => 'square',
            'filt' => 'sepia'
        ];

        $uri = basename($testImage);

        $this->client->shouldReceive('get')->with($uri)->andReturn($testImage);

        /** @var Image $image */
        $image = $this->api->run($uri, $params);

        $this->assertEquals('jpegload', $image->get('vips-loader'));
        $this->assertEquals(320, $image->width);
        $this->assertEquals(240, $image->height);
        $this->assertSimilarImage($expectedImage, $image);
    }

    public function testSepiaFilterPngTransparent()
    {
        $testImage = $this->inputPngOverlayLayer1;
        $expectedImage = $this->expectedDir . '/sepia-trans.png';
        $params = [
            'w' => '320',
            'h' => '240',
            't' => 'square',
            'filt' => 'sepia'
        ];

        $uri = basename($testImage);

        $this->client->shouldReceive('get')->with($uri)->andReturn($testImage);

        /** @var Image $image */
        $image = $this->api->run($uri, $params);

        $this->assertEquals('pngload', $image->get('vips-loader'));
        $this->assertEquals(320, $image->width);
        $this->assertEquals(240, $image->height);
        $this->assertSimilarImage($expectedImage, $image);
    }

    public function testNegateFilterJpeg()
    {
        $testImage = $this->inputJpg;
        $expectedImage = $this->expectedDir . '/negate.jpg';
        $params = [
            'w' => '320',
            'h' => '240',
            't' => 'square',
            'filt' => 'negate'
        ];

        $uri = basename($testImage);

        $this->client->shouldReceive('get')->with($uri)->andReturn($testImage);

        /** @var Image $image */
        $image = $this->api->run($uri, $params);

        $this->assertEquals('jpegload', $image->get('vips-loader'));
        $this->assertEquals(320, $image->width);
        $this->assertEquals(240, $image->height);
        $this->assertSimilarImage($expectedImage, $image);
    }

    public function testNegateFilterPng()
    {
        $testImage = $this->inputPng;
        $expectedImage = $this->expectedDir . '/negate.png';
        $params = [
            'w' => '320',
            'h' => '240',
            't' => 'square',
            'filt' => 'negate'
        ];

        $uri = basename($testImage);

        $this->client->shouldReceive('get')->with($uri)->andReturn($testImage);

        /** @var Image $image */
        $image = $this->api->run($uri, $params);

        $this->assertEquals('pngload', $image->get('vips-loader'));
        $this->assertEquals(320, $image->width);
        $this->assertEquals(240, $image->height);
        $this->assertSimilarImage($expectedImage, $image);
    }

    public function testNegateFilterPngTransparent()
    {
        $testImage = $this->inputPngWithTransparency;
        $expectedImage = $this->expectedDir . '/negate-trans.png';
        $params = [
            'w' => '320',
            'h' => '240',
            't' => 'square',
            'filt' => 'negate'
        ];

        $uri = basename($testImage);

        $this->client->shouldReceive('get')->with($uri)->andReturn($testImage);

        /** @var Image $image */
        $image = $this->api->run($uri, $params);

        $this->assertEquals('pngload', $image->get('vips-loader'));
        $this->assertEquals(320, $image->width);
        $this->assertEquals(240, $image->height);
        $this->assertSimilarImage($expectedImage, $image);
    }

    public function testNegateFilterPngWithGreyAlpha()
    {
        $testImage = $this->inputPngWithGreyAlpha;
        $expectedImage = $this->expectedDir . '/negate-alpha.png';
        $params = [
            'w' => '320',
            'h' => '240',
            't' => 'square',
            'filt' => 'negate'
        ];

        $uri = basename($testImage);

        $this->client->shouldReceive('get')->with($uri)->andReturn($testImage);

        /** @var Image $image */
        $image = $this->api->run($uri, $params);

        $this->assertEquals('pngload', $image->get('vips-loader'));
        $this->assertEquals(320, $image->width);
        $this->assertEquals(240, $image->height);
        $this->assertSimilarImage($expectedImage, $image);
    }

    public function testNegateFilterWebp()
    {
        $testImage = $this->inputWebP;
        $expectedImage = $this->expectedDir . '/negate.webp';
        $params = [
            'w' => '320',
            'h' => '240',
            't' => 'square',
            'filt' => 'negate'
        ];

        $uri = basename($testImage);

        $this->client->shouldReceive('get')->with($uri)->andReturn($testImage);

        /** @var Image $image */
        $image = $this->api->run($uri, $params);

        $this->assertEquals('webpload', $image->get('vips-loader'));
        $this->assertEquals(320, $image->width);
        $this->assertEquals(240, $image->height);
        $this->assertSimilarImage($expectedImage, $image);
    }

    public function testNegateFilterWebpTransparent()
    {
        $testImage = $this->inputWebPWithTransparency;
        $expectedImage = $this->expectedDir . '/negate-trans.webp';
        $params = [
            'w' => '320',
            'h' => '240',
            't' => 'square',
            'filt' => 'negate'
        ];

        $uri = basename($testImage);

        $this->client->shouldReceive('get')->with($uri)->andReturn($testImage);

        /** @var Image $image */
        $image = $this->api->run($uri, $params);

        $this->assertEquals('webpload', $image->get('vips-loader'));
        $this->assertEquals(320, $image->width);
        $this->assertEquals(240, $image->height);
        $this->assertSimilarImage($expectedImage, $image);
    }
}
