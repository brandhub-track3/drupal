<?php

namespace Drupal\brandhub_sync\Publisher;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Routes large content.changed events through S3 claim-check storage.
 */
final class ClaimCheckMessageProcessor implements PublisherInterface {

  /**
   * Maximum permitted inline/pointer message size, in bytes.
   */
  public const MAX_MESSAGE_SIZE = 204800;

  /**
   * JSON encoding flags shared with the SQS publisher.
   */
  private const JSON_FLAGS =
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

  /**
   * Constructs the message processor.
   */
  public function __construct(
    private readonly PublisherInterface $transport,
    private readonly ClaimCheckStorageInterface $storage,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Serializes a message and sends it inline or as a claim-check pointer.
   *
   * @throws \JsonException
   *   When the message cannot be encoded as JSON.
   * @throws \InvalidArgumentException
   *   When an oversized message lacks entity metadata for a stable key.
   * @throws \LogicException
   *   When claim-check is disabled or a pointer cannot be safely published.
   */
  public function publish(array $message): void {
    $body = $this->encode($message);
    $payload_size = strlen($body);
    $settings = $this->configFactory->get('brandhub_sync.settings');

    if ($payload_size <= self::MAX_MESSAGE_SIZE) {
      $this->transport->publish($message);
      return;
    }

    if (!$this->isEnabled($settings->get('claim_check_enabled'))) {
      throw new \LogicException(
        'The serialized event exceeds the inline limit and claim-check is '
        . 'disabled; no message was published.',
      );
    }

    $content = $message['content'] ?? NULL;
    if (!is_array($content)) {
      throw new \InvalidArgumentException(
        'An oversized event must contain unified content metadata.',
      );
    }

    $source = $content['source'] ?? NULL;
    $content_id = $content['id'] ?? NULL;
    $version = $content['version'] ?? NULL;
    $schema_version = $message['schemaVersion'] ?? NULL;
    foreach (
      [
        'event',
        'eventId',
        'occurredAt',
        'correlationId',
        'schemaVersion',
        'source',
      ] as $field
    ) {
      if (
        !isset($message[$field])
        || !is_string($message[$field])
        || $message[$field] === ''
      ) {
        throw new \InvalidArgumentException(
          'Oversized event envelope metadata is incomplete.',
        );
      }
    }
    if (
      !is_string($source)
      || !preg_match('/^[A-Za-z0-9_-]+$/', $source)
      || !is_string($content_id)
      || !preg_match('/^dr:([1-9][0-9]*)$/', $content_id, $matches)
      || $message['event'] !== 'content.changed'
      || $message['source'] !== 'drupal'
      || $source !== $message['source']
      || $schema_version !== ($content['schemaVersion'] ?? NULL)
    ) {
      throw new \InvalidArgumentException(
        'Oversized event entity metadata is incomplete or invalid.',
      );
    }
    if (
      !is_int($version)
      || $version < 1
      || $schema_version === ''
    ) {
      throw new \InvalidArgumentException(
        'Oversized event entity metadata is incomplete or invalid.',
      );
    }

    $environment = $this->environment(
      $settings->get('environment'),
    );
    $checksum = hash('sha256', $body);
    $object_key = implode('/', [
      $environment,
      $source,
      $matches[1],
      (string) $version,
      $checksum . '.json',
    ]);

    $stored = $this->storage->store(
      $object_key,
      $body,
      'application/json',
    );
    $pointer = [
      'bucket' => $stored['bucket'],
      'objectKey' => $stored['objectKey'],
      'contentType' => 'application/json',
      'originalPayloadSize' => $payload_size,
      'sha256' => $checksum,
      'schemaVersion' => $schema_version,
    ];
    foreach (['versionId', 'etag'] as $identifier) {
      if (
        isset($stored[$identifier])
        && $stored[$identifier] !== ''
      ) {
        $pointer[$identifier] = $stored[$identifier];
      }
    }

    if (
      $pointer['bucket'] === ''
      || $pointer['objectKey'] !== $object_key
    ) {
      throw new \LogicException(
        'Claim-check storage returned invalid object metadata; no message '
        . 'was published.',
      );
    }

    $pointer_message = [];
    foreach (
      [
        'event',
        'eventId',
        'occurredAt',
        'correlationId',
        'schemaVersion',
        'source',
      ] as $field
    ) {
      if (array_key_exists($field, $message)) {
        $pointer_message[$field] = $message[$field];
      }
    }
    $pointer_message['claimCheck'] = $pointer;

    if (
      strlen($this->encode($pointer_message))
      > self::MAX_MESSAGE_SIZE
    ) {
      throw new \LogicException(
        'The claim-check pointer exceeds the maximum SQS message size; no '
        . 'message was published.',
      );
    }

    $this->transport->publish($pointer_message);
  }

  /**
   * Encodes a message using the exact representation sent to SQS.
   *
   * @throws \JsonException
   *   When the message cannot be encoded as JSON.
   */
  private function encode(array $message): string {
    $json = json_encode($message, self::JSON_FLAGS);
    if (!is_string($json)) {
      throw new \LogicException('The event message could not be serialized.');
    }

    return $json;
  }

  /**
   * Resolves whether claim-check handling is enabled.
   */
  private function isEnabled(mixed $configured): bool {
    $environment = getenv('BRANDHUB_SYNC_CLAIM_CHECK_ENABLED');
    if ($environment === FALSE || $environment === '') {
      return (bool) ($configured ?? FALSE);
    }

    $enabled = filter_var(
      $environment,
      FILTER_VALIDATE_BOOLEAN,
      FILTER_NULL_ON_FAILURE,
    );
    if ($enabled === NULL) {
      throw new \InvalidArgumentException(
        'BRANDHUB_SYNC_CLAIM_CHECK_ENABLED must be a boolean value.',
      );
    }

    return $enabled;
  }

  /**
   * Resolves and validates the environment path segment.
   */
  private function environment(mixed $configured): string {
    $environment_value = getenv('BRANDHUB_SYNC_ENVIRONMENT');
    $environment = $environment_value !== FALSE && $environment_value !== ''
      ? $environment_value
      : ($configured ?: 'local');
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $environment)) {
      throw new \InvalidArgumentException(
        'The claim-check environment must be a simple path segment.',
      );
    }

    return $environment;
  }

}
