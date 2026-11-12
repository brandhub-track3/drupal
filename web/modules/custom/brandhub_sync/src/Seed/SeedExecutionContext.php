<?php

namespace Drupal\brandhub_sync\Seed;

/**
 * Marks entity saves and deletes initiated by the seed command.
 */
final class SeedExecutionContext {

  /**
   * Whether the current request is applying seed changes.
   */
  private bool $active = FALSE;

  /**
   * Runs an operation with outbound lifecycle publishing suppressed.
   *
   * @param callable(): T $operation
   *   The entity storage operation.
   *
   * @template T
   *
   * @return T
   *   The operation result.
   */
  public function run(callable $operation): mixed {
    $was_active = $this->active;
    $this->active = TRUE;
    try {
      return $operation();
    }
    finally {
      $this->active = $was_active;
    }
  }

  /**
   * Checks whether seed changes should bypass outbound lifecycle publishing.
   */
  public function isActive(): bool {
    return $this->active;
  }

}
