<?php

namespace Blipfoto\Api;

use Blipfoto\Api\Client;
use Blipfoto\Exceptions\OAuthException;
use Blipfoto\Storage\NativeSessionStore;
use Blipfoto\Storage\StateStore;

class OAuth {

	protected $client;
	protected $store;
	protected $oauth_key;

	/**
	 * Construct a new oauth instance.
	 *
	 * @param Client $client
	 * @param StateStore|null $store (optional) where to keep state between the redirect
	 *                               and the callback; defaults to PHP's native session
	 */
	public function __construct(Client $client, ?StateStore $store = null) {
		$this->client = $client;
		$this->store = $store ?? new NativeSessionStore();
		$this->oauth_key = Client::SESSION_PREFIX . 'params';
	}

	/**
	 * Begin authorization.
	 *
	 * @param string $redirect_uri
	 * @param string $scope (optional)
	 * @redirect
	 */
	public function authorize($redirect_uri, $scope = Client::SCOPE_READ) {
		header('Location: ' . $this->getAuthorizeUri($redirect_uri, $scope));
		exit;
	}

	/**
	 * Generate and return the authorization URI.
	 *
	 * @param string $redirect_uri
	 * @param string $scope (optional)
	 * @return string
	 * @throws OAuthException
	 */
	public function getAuthorizeUri($redirect_uri, $scope = Client::SCOPE_READ) {

		$state = bin2hex(random_bytes(20));

		$this->store->put($this->oauth_key, [
			'redirect_uri'	=> $redirect_uri,
			'scope'			=> $scope,
			'state'			=> $state,
		]);

		// NOTE: client_secret is sent here because the existing Blipfoto API
		// expects the same parameters the original SDK sent. This URL goes to
		// the user's browser, so the secret is exposed to them.
		return $this->client->authorizationEndpoint() . '?' . http_build_query([
			'response_type'	=> 'code',
			'client_id'		=> $this->client->id(),
			'client_secret'	=> $this->client->secret(),
			'redirect_uri'	=> $redirect_uri,
			'scope'			=> $scope,
			'state'			=> $state,
		]);
	}

	/**
	 * Obtain an authorization code.
	 *
	 * @return string
	 * @throws OAuthException
	 */
	public function getAuthorizationCode() {

		if (isset($_GET['error'])) {
			throw new OAuthException((string) $_GET['error'], 1);
		}

		if (!isset($_GET['code'], $_GET['state']) || !is_string($_GET['code']) || !is_string($_GET['state'])) {
			throw new OAuthException('Invalid parameters', 2);
		}

		$params = $this->store->get($this->oauth_key);
		if (!isset($params['state']) || !is_string($params['state'])) {
			throw new OAuthException('No state found', 3);
		}

		// Constant-time, strict comparison of the returned state.
		if (!hash_equals($params['state'], $_GET['state'])) {
			throw new OAuthException('State invalid', 4);
		}

		return $_GET['code'];
	}

	/**
	 * Swap an authorization code for a token.
	 *
	 * @param string $authorization_code (optional)
	 * @return array
	 * @throws OAuthException
	 */
	public function getToken($authorization_code = null) {

		if ($authorization_code === null) {
			$authorization_code = $this->getAuthorizationCode();
		}

		$params = $this->store->get($this->oauth_key);
		if ($params === null) {
			throw new OAuthException('No state found', 3);
		}

		// State is single-use: clear it before exchanging the code.
		$this->store->forget($this->oauth_key);

		$response = $this->client->post('oauth/token', [
			'client_id'		=> $this->client->id(),
			'grant_type'	=> 'authorization_code',
			'code'			=> $authorization_code,
			'scope'			=> $params['scope'],
			'redirect_uri'	=> $params['redirect_uri'],
		]);
		return $response->data('token');
	}

}