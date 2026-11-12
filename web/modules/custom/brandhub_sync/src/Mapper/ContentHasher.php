<?php

namespace Drupal\brandhub_sync\Mapper;

/**
 * Produces deterministic SHA-256 hashes for unified content records.
 */
final class ContentHasher {

  /**
   * Hashes a content record after recursively sorting object keys.
   *
   * The existing hash value is omitted so hashing an already-hashed record
   * produces the same digest.
   *
   * @param array<string, mixed> $content
   *   A unified content record.
   *
   * @return string
   *   A lowercase SHA-256 digest.
   *
   * @throws \JsonException
   *   When the content cannot be encoded as JSON.
   */
  public function hash(array $content): string {
    unset($content['hash']);
    $canonical_json = json_encode(
      $this->canonicalize($content),
      JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );

    return hash('sha256', $canonical_json);
  }

  /**
   * Sorts object keys recursively while preserving list order.
   *
   * @param mixed $value
   *   A JSON-compatible value.
   *
   * @return mixed
   *   The canonicalized value.
   */
  private function canonicalize(mixed $value): mixed {
    if (!is_array($value)) {
      return $value;
    }

    if (array_is_list($value)) {
      return array_map(
        fn(mixed $item): mixed => $this->canonicalize($item),
        $value,
      );
    }

    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
      $value[$key] = $this->canonicalize($item);
    }

    return $value;
  }

}
