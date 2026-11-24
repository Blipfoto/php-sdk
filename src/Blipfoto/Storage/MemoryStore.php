<?php

namespace Blipfoto\Storage;

/**
 * Keeps OAuth state in memory for the lifetime of this object.
 *
 * Useful for tests and CLI tools. It will NOT survive a browser redirect,
 * so don't use it for a real web authorization flow.
 */
class MemoryStore implements StateStore {

	protected $values = [];

	public function put(string $key, array $value): void {
		$this->values[$key] = $value;
	}

	public function get(string $key): ?array {
		return $this->values[$key] ?? null;
	}

	public function forget(string $key): void {
		unset($this->values[$key]);
	}

}