<?php

namespace App\Services\Storage;

use RuntimeException;

/**
 * Thrown when MongoBlobStore::withLock exhausts its optimistic-lock retries.
 * Distinct from "not found" RuntimeExceptions so callers can map it to 409,
 * not 404.
 */
final class ConcurrencyException extends RuntimeException {}
