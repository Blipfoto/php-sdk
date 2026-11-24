<?php

declare(strict_types=1);

namespace Blipfoto\Tests;

use Blipfoto\Api\File;
use Blipfoto\Tests\Support\TestServerTrait;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    use TestServerTrait;

    public function testMethodResourceParamsAndFilesAreGettersAndSetters(): void
    {
        $client = $this->makeClient();
        $request = $client->request();

        $this->assertSame('GET', $request->method('GET'));
        $this->assertSame('GET', $request->method());

        $this->assertSame('entry', $request->resource('entry'));
        $this->assertSame('entry', $request->resource());

        $this->assertSame(['a' => 1], $request->params(['a' => 1]));
        $this->assertSame(['a' => 1], $request->params());

        $this->assertSame([], $request->files());
    }

    public function testHeaderIsAGetterAndSetterForAnIndividualHeader(): void
    {
        $client = $this->makeClient();
        $request = $client->request();

        $this->assertSame('bar', $request->header('X-Foo', 'bar'));
        $this->assertSame('bar', $request->header('X-Foo'));
    }

    public function testCustomHeadersAreSentWithTheRequest(): void
    {
        $client = $this->makeClient();
        $request = $client->request();
        $request->method('GET');
        $request->resource('echo');
        $request->header('X-Custom-Header', 'hello-world');

        $response = $request->send();

        $this->assertSame('hello-world', $response->data('echo.headers.X-Custom-Header'));
    }

    public function testGetRequestBuildsAUrlEncodedQueryString(): void
    {
        $client = $this->makeClient();
        $response = $client->get('echo', ['a' => 'one two', 'b' => 3]);

        $this->assertSame('one two', $response->data('echo.query.a'));
        $this->assertSame('3', $response->data('echo.query.b'));
    }

    public function testPostWithoutFilesSendsAUrlEncodedBody(): void
    {
        $client = $this->makeClient();
        $response = $client->post('echo', ['title' => 'hello world']);

        $this->assertStringStartsWith(
            'application/x-www-form-urlencoded',
            (string) $response->data('echo.content_type')
        );
        $this->assertSame('title=hello+world', base64_decode((string) $response->data('echo.body_base64')));
    }

    public function testPostWithFilesSendsAMultipartBody(): void
    {
        $client = $this->makeClient();
        $file = new File(__DIR__ . '/Fixtures/valid.jpg');

        $response = $client->post('echo', ['title' => 'hello world'], ['image' => $file->path()]);

        $this->assertStringStartsWith(
            'multipart/form-data; boundary=',
            (string) $response->data('echo.content_type')
        );

        $body = base64_decode((string) $response->data('echo.body_base64'));
        $this->assertStringContainsString('name="title"', $body);
        $this->assertStringContainsString('hello world', $body);
        $this->assertStringContainsString('name="image"', $body);
        $this->assertStringContainsString('filename="valid.jpg"', $body);
        // The multipart body must be larger than the raw file (boundaries + headers add overhead).
        $this->assertGreaterThan((int) filesize($file->path()), (int) $response->data('echo.body_length'));
    }

    /**
     * Request::send() casts "X-RateLimit-*" response headers to int when
     * building the rate_limit array passed into Response - so these come
     * back as integers, not strings.
     */
    public function testResponseExposesRateLimitHeadersFromTheServer(): void
    {
        $client = $this->makeClient();
        $response = $client->get('echo');

        $this->assertSame(500, $response->rateLimit('Limit'));
        $this->assertSame(499, $response->rateLimit('Remaining'));
    }
}
