<?php

declare(strict_types=1);

namespace Blipfoto\Tests;

use Blipfoto\Api\Response;
use Blipfoto\Exceptions\ApiResponseException;
use Blipfoto\Exceptions\InvalidResponseException;
use Blipfoto\Exceptions\OAuthException;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testConstructThrowsOnNonSuccessHttpStatus(): void
    {
        $this->expectException(InvalidResponseException::class);
        new Response('{}', 404);
    }

    public function testConstructThrowsOnMalformedJson(): void
    {
        $this->expectException(InvalidResponseException::class);
        new Response('not json at all', 200);
    }

    public function testConstructThrowsApiResponseExceptionForGenericErrorCode(): void
    {
        $body = json_encode(['error' => ['code' => 100, 'message' => 'Something broke']]);

        $this->expectException(ApiResponseException::class);
        $this->expectExceptionMessage('Something broke');
        $this->expectExceptionCode(100);
        new Response($body, 200);
    }

    /**
     * Error codes 30-35 (inclusive) are treated as OAuth errors, per the
     * original Response::__construct implementation.
     */
    public function testConstructThrowsOAuthExceptionForCodesInOAuthRange(): void
    {
        foreach ([30, 31, 35] as $code) {
            $body = json_encode(['error' => ['code' => $code, 'message' => 'oauth broke']]);
            try {
                new Response($body, 200);
                $this->fail('Expected OAuthException to be thrown.');
            } catch (OAuthException $e) {
                $this->assertSame($code, $e->getCode());
            }
        }
    }

    public function testConstructDoesNotThrowWhenNoErrorPresent(): void
    {
        $response = new Response(json_encode(['data' => ['foo' => 'bar']]), 200);
        $this->assertInstanceOf(Response::class, $response);
    }

    public function testHttpStatusReturnsConstructorValue(): void
    {
        $response = new Response(json_encode(['data' => []]), 200);
        $this->assertSame(200, $response->httpStatus());
    }

    public function testDataReturnsWholeDataArrayWithoutKey(): void
    {
        $response = new Response(json_encode(['data' => ['foo' => 'bar', 'baz' => 1]]), 200);
        $this->assertSame(['foo' => 'bar', 'baz' => 1], $response->data());
    }

    public function testDataReturnsTopLevelKey(): void
    {
        $response = new Response(json_encode(['data' => ['username' => 'arya']]), 200);
        $this->assertSame('arya', $response->data('username'));
    }

    public function testDataReturnsNestedDotSeparatedKey(): void
    {
        $response = new Response(json_encode(['data' => ['user' => ['username' => 'arya']]]), 200);
        $this->assertSame('arya', $response->data('user.username'));
    }

    public function testDataReturnsNullForMissingKey(): void
    {
        $response = new Response(json_encode(['data' => ['user' => ['username' => 'arya']]]), 200);
        $this->assertNull($response->data('user.email'));
        $this->assertNull($response->data('nonexistent'));
    }

    public function testRateLimitReturnsWholeArrayWithoutKey(): void
    {
        $response = new Response(json_encode(['data' => []]), 200, ['Limit' => 500, 'Remaining' => 499]);
        $this->assertSame(['Limit' => 500, 'Remaining' => 499], $response->rateLimit());
    }

    public function testRateLimitReturnsSpecificKey(): void
    {
        $response = new Response(json_encode(['data' => []]), 200, ['Limit' => 500, 'Remaining' => 499]);
        $this->assertSame(499, $response->rateLimit('Remaining'));
    }

    public function testRateLimitReturnsNullForMissingKey(): void
    {
        $response = new Response(json_encode(['data' => []]), 200, ['Limit' => 500]);
        $this->assertNull($response->rateLimit('Remaining'));
    }

    public function testRateLimitDefaultsToEmptyArray(): void
    {
        $response = new Response(json_encode(['data' => []]), 200);
        $this->assertSame([], $response->rateLimit());
    }
}
