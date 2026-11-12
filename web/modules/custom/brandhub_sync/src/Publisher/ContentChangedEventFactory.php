<?php

namespace Drupal\brandhub_sync\Publisher;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;

/**
 * Creates schema-complete content.changed event envelopes.
 */
final class ContentChangedEventFactory {

  /**
   * Constructs the event factory.
   */
  public function __construct(
    private readonly UuidInterface $uuid,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Creates a content.changed event for a unified content record.
   *
   * @param array<string, mixed> $content
   *   A unified content record.
   *
   * @return array<string, mixed>
   *   The complete event envelope.
   *
   * @throws \InvalidArgumentException
   *   When the content record is incomplete or invalid.
   */
  public function create(array $content): array {
    $required = [
      'id',
      'url',
      'source',
      'type',
      'title',
      'summary',
      'bodyHtml',
      'plainText',
      'tags',
      'product',
      'author',
      'heroImage',
      'publishedAt',
      'updatedAt',
      'version',
      'hash',
      'status',
      'schemaVersion',
    ];
    if (array_diff($required, array_keys($content))) {
      throw new \InvalidArgumentException(
        'The unified content record is missing required schema fields.',
      );
    }

    if (
      $content['source'] !== 'drupal'
      || !is_string($content['id'])
      || !preg_match('/^dr:[1-9][0-9]*$/', $content['id'])
      || !is_string($content['url'])
      || filter_var($content['url'], FILTER_VALIDATE_URL) === FALSE
      || !in_array($content['type'], ['product', 'article', 'faq'], TRUE)
      || !in_array($content['status'], ['published', 'removed'], TRUE)
      || $content['schemaVersion'] !== '1.0'
      || !is_string($content['hash'])
      || !preg_match('/^[a-f0-9]{64}$/', $content['hash'])
      || !is_int($content['version'])
      || $content['version'] < 1
    ) {
      throw new \InvalidArgumentException(
        'The unified content record is not ready for event publication.',
      );
    }

    return [
      'event' => 'content.changed',
      'eventId' => $this->uuid->generate(),
      'occurredAt' => (new \DateTimeImmutable(
        '@' . $this->time->getCurrentTime(),
      ))->format(DATE_ATOM),
      'correlationId' => $this->uuid->generate(),
      'schemaVersion' => '1.0',
      'source' => 'drupal',
      'content' => $content,
    ];
  }

}
