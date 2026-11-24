<?php

namespace Blipfoto\Storage;

/**
 * Somewhere to keep OAuth state between the authorize redirect and the callback.
 *
 * Implement this to plug the SDK into your framework's session, a cache, a
 * database, etc.
 */
interface StateStore {

	/**
	 * Store a value.
	 *
	 * @param string $key
	 * @param array $value
	 */
	public function put(string $key, array $value): void;

	/**
	 * Retrieve a value, or null if nothing is stored under the key.
	 *
	 * @param string $key
	 * @return array|null
	 */
	public function get(string $key): ?array;

	/**
	 * Remove a value.
	 *
	 * @param string $key
	 */
	public function forget(string $key): void;

}