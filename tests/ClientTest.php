<?php

declare(strict_types=1);

namespace Blipfoto\Tests;

use Blipfoto\Api\Client;
use Blipfoto\Api\OAuth;
use Blipfoto\Api\Request;
use Blipfoto\Exceptions\NetworkException;
use Blipfoto\Tests\Support\TestServerTrait;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    use TestServerTrait;

    public function testConstructorStoresIdSecretAndAccessToken(): void
    {
        $client = new Client('my-id', 'my-secret', 'my-token');
        $this->assertSame('my-id', $client->id());
        $this->assertSame('my-secret', $client->secret());
        $this->assertSame('my-token', $client->accessToken());
    }

    public function testAccessTokenDefaultsToNull(): void
    {
        $client = new Client('my-id', 'my-secret');
        $this->assertNull($client->accessToken());
    }

    public function testIdAndSecretAndAccessTokenActAsGettersAndSetters(): void
    {
        $client = new Client('id', 'secret');

        $this->assertSame('new-id', $client->id('new-id'));
        $this->assertSame('new-id', $client->id());

        $this->assertSame('new-secret', $client->secret('new-secret'));
        $this->assertSame('new-secret', $client->secret());

        $this->assertSame('new-token', $client->accessToken('new-token'));
        $this->assertSame('new-token', $client->accessToken());
    }

    public function testEndpointDefaultsToTheLiveApi(): void
    {
        $client = new Client('id', 'secret');
        $this->assertSame('https://api.blipfoto.com/4/', $client->endpoint());
    }

    public function testAuthorizationEndpointDefaultsToTheLiveOAuthAuthorizeUrl(): void
    {
        $client = new Client('id', 'secret');
        $this->assertSame('https://www.blipfoto.com/oauth/authorize/', $client->authorizationEndpoint());
    }

    public function testEndpointAcceptsAnHttpsOverride(): void
    {
        $client = new Client('id', 'secret');
        $client->endpoint('https://example.test/api/');
        $this->assertSame('https://example.test/api/', $client->endpoint());
    }

    public function testEndpointThrowsNetworkExceptionForNonHttpsUrl(): void
    {
        $client = new Client('id', 'secret');

        // endpoint() validates on every call, including the setter call
        // itself - the exception fires immediately, not on a later getter.
        $this->expectException(NetworkException::class);
        $client->endpoint('http://example.test/api/');
    }

    public function testAuthorizationEndpointThrowsNetworkExceptionForNonHttpsUrl(): void
    {
        $client = new Client('id', 'secret');

        $this->expectException(NetworkException::class);
        $client->authorizationEndpoint('http://example.test/oauth/');
    }

    public function testBeforeAndAfterAreGettersAndSetters(): void
    {
        $client = new Client('id', 'secret');
        $callback = static function () {};

        $this->assertSame($callback, $client->before($callback));
        $this->assertSame($callback, $client->before());

        $this->assertSame($callback, $client->after($callback));
        $this->assertSame($callback, $client->after());
    }

    public function testRequestReturnsARequestBoundToThisClient(): void
    {
        $client = new Client('id', 'secret');
        $request = $client->request();

        $this->assertInstanceOf(Request::class, $request);
    }

    public function testOauthReturnsAnOAuthInstanceBoundToThisClient(): void
    {
        $client = new Client('id', 'secret');
        $this->assertInstanceOf(OAuth::class, $client->oauth());
    }

    public function testGetSendsAGetRequestWithQueryStringParams(): void
    {
        $client = $this->makeClient();
        $response = $client->get('echo', ['username' => 'arya', 'limit' => 5]);

        $this->assertSame('GET', $response->data('echo.method'));
        $this->assertSame('arya', $response->data('echo.query.username'));
        $this->assertSame('5', $response->data('echo.query.limit'));
    }

    public function testDeleteSendsADeleteRequestWithQueryStringParams(): void
    {
        $client = $this->makeClient();
        $response = $client->delete('echo', ['entry_id' => 'abc123']);

        $this->assertSame('DELETE', $response->data('echo.method'));
        $this->assertSame('abc123', $response->data('echo.query.entry_id'));
    }

    public function testPostSendsParamsInTheRequestBodyNotTheQueryString(): void
    {
        $client = $this->makeClient();
        $response = $client->post('echo', ['title' => 'Ser Bronn']);

        $this->assertSame('POST', $response->data('echo.method'));
        $this->assertSame([], $response->data('echo.query'));
        $this->assertStringContainsString('title', base64_decode((string) $response->data('echo.body_base64')));
    }

    public function testPutSendsParamsInTheRequestBodyNotTheQueryString(): void
    {
        $client = $this->makeClient();
        $response = $client->put('echo', ['title' => 'Updated title']);

        $this->assertSame('PUT', $response->data('echo.method'));
        $this->assertSame([], $response->data('echo.query'));
        $this->assertStringContainsString('Updated+title', base64_decode((string) $response->data('echo.body_base64')));
    }

    public function testAccessTokenIsSentAsABearerAuthorizationHeader(): void
    {
        $client = $this->makeClient('id', 'secret', 'my-access-token');
        $response = $client->get('echo');

        $this->assertSame('Bearer my-access-token', $response->data('echo.authorization'));
    }

    /**
     * When no access token is set, Request::send() falls back to using the
     * client ID itself as the bearer token - it does NOT omit the header.
     */
    public function testAuthorizationHeaderFallsBackToClientIdWithoutAnAccessToken(): void
    {
        $client = $this->makeClient('my-client-id', 'secret', null);
        $response = $client->get('echo');

        $this->assertSame('Bearer my-client-id', $response->data('echo.authorization'));
    }

    public function testBeforeCallbackReceivesTheRequestBeforeItIsSent(): void
    {
        $client = $this->makeClient();
        $seen = null;
        $client->before(function (Request $request) use (&$seen) {
            curl_setopt($request->curl(), CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($request->curl(), CURLOPT_SSL_VERIFYHOST, false);
            $seen = $request;
        });

        $client->get('echo');

        $this->assertInstanceOf(Request::class, $seen);
    }
}
