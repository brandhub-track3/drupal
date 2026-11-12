<?php

namespace Drupal\brandhub_sync\Publisher;

/**
 * Publishes event messages through a configured transport.
 */
interface PublisherInterface {

  /**
   * Publishes an event or claim-check pointer.
   *
   * @param array<string, mixed> $message
   *   A serialized-event-compatible message.
   */
  public function publish(array $message): void;

}
