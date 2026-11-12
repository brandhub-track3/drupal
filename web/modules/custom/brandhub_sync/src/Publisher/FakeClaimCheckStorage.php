<?php

namespace Drupal\brandhub_sync\Publisher;

/**
 * In-memory claim-check storage for tests and local dry runs.
 */
final class FakeClaimCheckStorage implements ClaimCheckStorageInterface {

  /**
   * Stored object bodies, keyed by object key.
   *
   * @var array<string, string>
   */
  private array $objects = [];

  /**
   * Constructs the fake storage.
   */
  public function __construct(
    private readonly string $bucket = 'fake-claim-check',
  ) {}

  /**
   * {@inheritdoc}
   */
  public function store(
    string $object_key,
    string $body,
    string $content_type,
  ): array {
    $this->objects[$object_key] = $body;

    return [
      'bucket' => $this->bucket,
      'objectKey' => $object_key,
      'etag' => '"' . md5($body) . '"',
    ];
  }

  /**
   * Gets the fake stored objects.
   *
   * @return array<string, string>
   *   Object bodies keyed by object key.
   */
  public function getObjects(): array {
    return $this->objects;
  }

}
