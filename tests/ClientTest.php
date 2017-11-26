<?php

namespace AndriesLouw\imagesweserv\Test;

use AndriesLouw\imagesweserv\Client;
use AndriesLouw\imagesweserv\Exception\ImageNotValidException;
use AndriesLouw\imagesweserv\Exception\ImageTooBigException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

class ClientTest extends ImagesweservTestCase
{
    /**
     * @var Client
     */
    private $client;

    /**
     * @var string
     */
    private $tempFile;

    /**
     * @var array
     */
    private $options;

    public function setUp()
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'phpunit');
        $this->options = [
            'user_agent' => 'Mozilla/5.0 (compatible; ImageFetcher/6.0; +http://images.weserv.nl/)',
            'connect_timeout' => 5,
            'timeout' => 10,
            'max_image_size' => 0,
            'max_redirects' => 10,
            'allowed_mime_types' => []
        ];
        $this->client = new Client($this->tempFile, $this->options);
    }

    public function tearDown()
    {
        parent::tearDown();
        unlink($this->tempFile);
    }

    public function testCreateInstance()
    {
        $this->assertInstanceOf(Client::class, $this->client);
    }

    public function testSetClient()
    {
        $client = $this->getMockery(ClientInterface::class);
        $this->client->setClient($client);
        $this->assertInstanceOf(ClientInterface::class, $this->client->getClient());
    }

    public function testGetClient()
    {
        $this->assertInstanceOf(ClientInterface::class, $this->client->getClient());
    }

    public function testGetOptions()
    {
        $this->assertSame($this->options, $this->client->getOptions());
    }

    public function testInvalidRedirectURI()
    {
        $this->expectException(RequestException::class);

        $this->client->get('http://test');
    }

    public function testImageNotValidException()
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/zip'])
        ]);

        $handler = HandlerStack::create($mock);

        $options = $this->options;
        $options['allowed_mime_types'] = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/bmp' => 'bmp',
            'image/tiff' => 'tiff',
            'image/webp' => 'webp',
            'image/x-icon' => 'ico',
            'image/vnd.microsoft.icon' => 'ico'
        ];

        $this->client = new Client($this->tempFile, $options, ['handler' => $handler]);

        $this->expectException(ImageNotValidException::class);

        $this->client->get('image.zip');
    }

    public function testImageTooBigException()
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Length' => 2048])
        ]);

        $handler = HandlerStack::create($mock);

        $options = $this->options;
        $options['max_image_size'] = 1024;

        $this->client = new Client($this->tempFile, $options, ['handler' => $handler]);

        $this->expectException(ImageTooBigException::class);
        $this->expectExceptionMessage('2 KB');

        $this->client->get('image.jpg');
    }

    public function testUserAgent()
    {
        $mock = new MockHandler([
            new Response(200)
        ]);

        $handler = HandlerStack::create($mock);

        $history = [];
        $handler->push(Middleware::history($history));

        $this->client = new Client($this->tempFile, $this->options, ['handler' => $handler]);
        $this->client->get('image.jpg');

        $this->assertSame($this->options['user_agent'], end($history)['request']->getHeaderLine('User-Agent'));
    }

    public function testTempFile()
    {
        $mock = new MockHandler([
            new Response(200)
        ]);

        $handler = HandlerStack::create($mock);

        $this->client = new Client($this->tempFile, $this->options, ['handler' => $handler]);
        $this->client->get('image.jpg');

        $this->assertSame($this->client->getFileName(), $mock->getLastOptions()['sink']);
    }
}
