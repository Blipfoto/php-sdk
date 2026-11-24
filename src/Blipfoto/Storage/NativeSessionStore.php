<?php

namespace Blipfoto\Storage;

use Blipfoto\Exceptions\OAuthException;

/**
 * Stores OAuth state in PHP's native $_SESSION.
 *
 * The session is only started when state is actually read or written, never
 * on construction.
 */
class NativeSessionStore implements StateStore {

	public function put(string $key, array $value): void {
		$this->start();
		$_SESSION[$key] = $value;
	}

	public function get(string $key): ?array {
		$this->start();
		$value = $_SESSION[$key] ?? null;
		return is_array($value) ? $value : null;
	}

	public function forget(string $key): void {
		$this->start();
		unset($_SESSION[$key]);
	}

	/**
	 * Make sure a session is active.
	 *
	 * @throws OAuthException
	 */
	protected function start(): void {
		if (session_status() === PHP_SESSION_ACTIVE) {
			return;
		}
		if (headers_sent($file, $line)) {
			throw new OAuthException(sprintf(
				'Cannot start a session to store OAuth state: output already started at %s:%d. '
				. 'Start the session before any output, or pass a different StateStore to OAuth.',
				$file, $line
			), 5);
		}
		session_start();
	}

}