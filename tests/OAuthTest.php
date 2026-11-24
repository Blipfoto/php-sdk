<?php

declare(strict_types=1);

namespace Blipfoto\Tests;

use Blipfoto\Api\Client;
use Blipfoto\Exceptions\OAuthException;
use Blipfoto\Storage\MemoryStore;
use Blipfoto\Tests\Support\TestServerTrait;
use PHPUnit\Framework\TestCase;
use Blipfoto\Api\OAuth;

final class OAuthTest extends TestCase
{
    use TestServerTrait;

    protected function tearDown(): void
    {
        unset($_GET['code'], $_GET['state'], $_GET['error']);
        $_SESSION = [];
    }

    public function testGetAuthorizeUriBuildsTheExpectedUrlAndStoresStateInSession(): void
    {
        $client = $this->makeClient();
        $oauth = $client->oauth();

        $uri = $oauth->getAuthorizeUri('https://example.test/callback', Client::SCOPE_READ);

        $this->assertStringStartsWith($client->authorizationEndpoint() . '?', $uri);
        parse_str(parse_url($uri, PHP_URL_QUERY), $params);

        $this->assertSame('code', $params['response_type']);
        $this->assertSame($client->id(), $params['client_id']);
        $this->assertSame($client->secret(), $params['client_secret']);
        $this->assertSame('https://example.test/callback', $params['redirect_uri']);
        $this->assertSame(Client::SCOPE_READ, $params['scope']);
        $this->assertNotEmpty($params['state']);

        $this->assertSame(
            $params['state'],
            $_SESSION[Client::SESSION_PREFIX . 'params']['state']
        );
    }

    public function testGetAuthorizeUriDefaultsToReadScope(): void
    {
        $client = $this->makeClient();
        $uri = $client->oauth()->getAuthorizeUri('https://example.test/callback');

        parse_str(parse_url($uri, PHP_URL_QUERY), $params);
        $this->assertSame(Client::SCOPE_READ, $params['scope']);
    }

    public function testGetAuthorizationCodeThrowsWhenGetErrorIsPresent(): void
    {
        $_GET['error'] = 'access_denied';

        $this->expectException(OAuthException::class);
        $this->expectExceptionCode(1);
        $this->makeClient()->oauth()->getAuthorizationCode();
    }

    public function testGetAuthorizationCodeThrowsWhenCodeOrStateMissing(): void
    {
        $this->expectException(OAuthException::class);
        $this->expectExceptionCode(2);
        $this->makeClient()->oauth()->getAuthorizationCode();
    }

    public function testGetAuthorizationCodeThrowsWhenNoStateStoredInSession(): void
    {
        $_GET['code'] = 'abc';
        $_GET['state'] = 'xyz';

        $this->expectException(OAuthException::class);
        $this->expectExceptionCode(3);
        $this->makeClient()->oauth()->getAuthorizationCode();
    }

    public function testGetAuthorizationCodeThrowsWhenStateDoesNotMatchSession(): void
    {
        $client = $this->makeClient();
        $client->oauth()->getAuthorizeUri('https://example.test/callback');

        $_GET['code'] = 'abc';
        $_GET['state'] = 'does-not-match';

        $this->expectException(OAuthException::class);
        $this->expectExceptionCode(4);
        $client->oauth()->getAuthorizationCode();
    }

    public function testGetAuthorizationCodeReturnsCodeWhenStateMatches(): void
    {
        $client = $this->makeClient();
        $uri = $client->oauth()->getAuthorizeUri('https://example.test/callback');
        parse_str(parse_url($uri, PHP_URL_QUERY), $params);

        $_GET['code'] = 'the-auth-code';
        $_GET['state'] = $params['state'];

        $this->assertSame('the-auth-code', $client->oauth()->getAuthorizationCode());
    }

    public function testGetTokenExchangesAnExplicitAuthorizationCode(): void
    {
        $client = $this->makeClient();
        // getToken() reads scope/redirect_uri out of the session, which is
        // normally populated by a prior getAuthorizeUri() call.
        $client->oauth()->getAuthorizeUri('https://example.test/callback', Client::SCOPE_READ_WRITE);

        $token = $client->oauth()->getToken('an-explicit-code');

        $this->assertSame('test-access-token', $token['access_token']);
        $this->assertSame(Client::SCOPE_READ_WRITE, $token['scope']);
    }

    public function testGetTokenClearsTheStoredSessionParamsAfterUse(): void
    {
        $client = $this->makeClient();
        $client->oauth()->getAuthorizeUri('https://example.test/callback');

        $client->oauth()->getToken('an-explicit-code');

        $this->assertArrayNotHasKey(Client::SESSION_PREFIX . 'params', $_SESSION);
    }

    public function testGetTokenFallsBackToGetAuthorizationCodeWhenNoneGiven(): void
    {
        $client = $this->makeClient();
        $uri = $client->oauth()->getAuthorizeUri('https://example.test/callback');
        parse_str(parse_url($uri, PHP_URL_QUERY), $params);

        $_GET['code'] = 'from-the-query-string';
        $_GET['state'] = $params['state'];

        $token = $client->oauth()->getToken();

        $this->assertSame('test-access-token', $token['access_token']);
    }

    public function testGetTokenThrowsWhenNoStateWasStored(): void
    {
        $this->expectException(OAuthException::class);
        $this->expectExceptionCode(3);
        $this->makeClient()->oauth()->getToken('an-explicit-code');
    }

    public function testGetAuthorizationCodeRejectsNonStringState(): void
    {
        $client = $this->makeClient();
        $client->oauth()->getAuthorizeUri('https://example.test/callback');

        $_GET['code'] = 'abc';
        $_GET['state'] = ['not', 'a', 'string'];

        $this->expectException(OAuthException::class);
        $this->expectExceptionCode(2);
        $client->oauth()->getAuthorizationCode();
    }

    public function testCustomStateStoreIsUsedInsteadOfTheSession(): void
    {
        $store = new MemoryStore();
        $client = $this->makeClient();
        $oauth = new OAuth($client, $store);

        $uri = $oauth->getAuthorizeUri('https://example.test/callback');
        parse_str(parse_url($uri, PHP_URL_QUERY), $params);

        $this->assertArrayNotHasKey(Client::SESSION_PREFIX . 'params', $_SESSION);
        $this->assertSame($params['state'], $store->get(Client::SESSION_PREFIX . 'params')['state']);

        $_GET['code'] = 'the-auth-code';
        $_GET['state'] = $params['state'];
        $token = $oauth->getToken();

        $this->assertSame('test-access-token', $token['access_token']);
        $this->assertNull($store->get(Client::SESSION_PREFIX . 'params'));
    }

    /**
     * The existing Blipfoto API can't be changed, so the authorize URL must
     * carry exactly the parameters the original SDK sent, in the same order.
     */
    public function testAuthorizeUriParametersMatchTheOriginalSdk(): void
    {
        $uri = $this->makeClient()->oauth()->getAuthorizeUri('https://example.test/callback', Client::SCOPE_READ_WRITE);
        parse_str(parse_url($uri, PHP_URL_QUERY), $params);

        $this->assertSame(
            ['response_type', 'client_id', 'client_secret', 'redirect_uri', 'scope', 'state'],
            array_keys($params)
        );
        $this->assertSame(40, strlen($params['state']));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $params['state']);
    }

    /**
     * The token exchange must send exactly the fields the original SDK sent.
     */
    public function testTokenRequestMatchesTheOriginalSdk(): void
    {
        $client = $this->makeClient();
        $captured = null;
        $client->before(function ($request) use (&$captured) {
            curl_setopt($request->curl(), CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($request->curl(), CURLOPT_SSL_VERIFYHOST, false);
            $captured = [
                'method' => $request->method(),
                'resource' => $request->resource(),
                'params' => $request->params(),
            ];
        });

        $client->oauth()->getAuthorizeUri('https://example.test/callback', Client::SCOPE_READ_WRITE);
        $client->oauth()->getToken('an-explicit-code');

        $this->assertSame('POST', $captured['method']);
        $this->assertSame('oauth/token', $captured['resource']);
        $this->assertSame([
            'client_id' => $client->id(),
            'grant_type' => 'authorization_code',
            'code' => 'an-explicit-code',
            'scope' => Client::SCOPE_READ_WRITE,
            'redirect_uri' => 'https://example.test/callback',
        ], $captured['params']);
    }
}