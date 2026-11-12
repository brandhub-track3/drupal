<?php

namespace Drupal\brandhub_sync\Publisher;

/**
 * Collects messages without contacting an external service.
 */
final class FakePublisher implements PublisherInterface {

  /**
   * Messages collected by this publisher.
   *
   * @var array<int, array<string, mixed>>
   */
  private array $messages = [];

  /**
   * {@inheritdoc}
   */
  public function publish(array $message): void {
    $this->messages[] = $message;
  }

  /**
   * Gets all collected messages.
   *
   * @return array<int, array<string, mixed>>
   *   The collected event messages.
   */
  public function getMessages(): array {
    return $this->messages;
  }

  /**
   * Clears all collected messages.
   */
  public function clear(): void {
    $this->messages = [];
  }

}
