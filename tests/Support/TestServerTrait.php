<?php

declare(strict_types=1);

namespace Blipfoto\Tests\Support;

use Blipfoto\Api\Client;

/**
 * Boots a local, self-signed HTTPS fixture server (tests/Support/server.php)
 * once per test class, and provides a helper to build a Client wired to
 * talk to it via the SDK's own before()/endpoint() extension points.
 *
 * No SDK source is modified or bypassed: we simply point Client::endpoint()
 * at our local server and use the documented before() hook to disable TLS
 * verification for the self-signed certificate, exactly as the README's
 * "Custom requests" section describes.
 */
trait TestServerTrait
{
    private static $serverProcess;
    private static ?int $serverPort = null;
    private static string $certPath;
    private static string $keyPath;
    private static string $readyFile;

    public static function setUpBeforeClass(): void
    {
        $dir = sys_get_temp_dir() . '/blipfoto-sdk-tests-' . getmypid();
        @mkdir($dir);

        self::$certPath = $dir . '/cert.pem';
        self::$keyPath = $dir . '/key.pem';
        self::$readyFile = $dir . '/ready';
        @unlink(self::$readyFile);

        self::generateSelfSignedCertificate(self::$certPath, self::$keyPath);

        self::$serverPort = self::findFreePort();

        $script = __DIR__ . '/server.php';
        $cmd = [
            PHP_BINARY,
            $script,
            (string) self::$serverPort,
            self::$certPath,
            self::$keyPath,
            self::$readyFile,
        ];

        self::$serverProcess = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        // Wait for the server to signal it's listening (max ~3 seconds).
        $waited = 0;
        while (!file_exists(self::$readyFile) && $waited < 3_000_000) {
            usleep(10_000);
            $waited += 10_000;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }
    }

    /**
     * Build a Client pointed at the local fixture server, with TLS
     * verification relaxed for the self-signed certificate.
     */
    protected function makeClient(string $id = 'test-id', string $secret = 'test-secret', ?string $accessToken = null): Client
    {
        $client = new Client($id, $secret, $accessToken);
        $client->endpoint('https://127.0.0.1:' . self::$serverPort . '/');
        $client->authorizationEndpoint('https://127.0.0.1:' . self::$serverPort . '/oauth/authorize/');
        $client->before(function ($request) {
            curl_setopt($request->curl(), CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($request->curl(), CURLOPT_SSL_VERIFYHOST, false);
        });

        return $client;
    }

    private static function findFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private static function generateSelfSignedCertificate(string $certPath, string $keyPath): void
    {
        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $privateKey);
        $cert = openssl_csr_sign($csr, null, $privateKey, 3);

        openssl_x509_export($cert, $certOut);
        openssl_pkey_export($privateKey, $keyOut);

        file_put_contents($certPath, $certOut);
        file_put_contents($keyPath, $keyOut);
    }
}
