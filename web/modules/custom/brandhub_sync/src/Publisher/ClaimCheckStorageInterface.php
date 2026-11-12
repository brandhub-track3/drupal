<?php

namespace Drupal\brandhub_sync\Publisher;

/**
 * Stores serialized content payloads for claim-check delivery.
 */
interface ClaimCheckStorageInterface {

  /**
   * Stores a serialized payload and returns object location metadata.
   *
   * @param string $object_key
   *   Deterministic object key.
   * @param string $body
   *   Exact UTF-8 JSON payload bytes.
   * @param string $content_type
   *   Content type for the stored object.
   *
   * @return array{bucket: string, objectKey: string, versionId?: string,
   *   etag?: string}
   *   Stored-object metadata. Version ID and ETag are returned when available.
   */
  public function store(
    string $object_key,
    string $body,
    string $content_type,
  ): array;

}
