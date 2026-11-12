<?php

namespace Drupal\brandhub_sync\Publisher;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Stores claim-check payloads in a private S3-compatible bucket.
 */
final class S3ClaimCheckStorage implements ClaimCheckStorageInterface {

  /**
   * Constructs the S3 storage adapter.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   *
   * @throws \LogicException
   *   When the bucket or AWS region is not configured.
   * @throws \RuntimeException
   *   When S3 rejects the upload.
   */
  public function store(
    string $object_key,
    string $body,
    string $content_type,
  ): array {
    if (!class_exists(S3Client::class)) {
      throw new \LogicException(
        'The AWS SDK is required for S3 claim-check storage. Install it with: '
        . 'composer require aws/aws-sdk-php',
      );
    }

    $settings = $this->configFactory->get('brandhub_sync.settings');
    $bucket = $settings->get('s3_bucket')
      ?: getenv('BRANDHUB_SYNC_S3_BUCKET');
    $region = $settings->get('s3_region')
      ?: getenv('BRANDHUB_SYNC_S3_REGION')
      ?: getenv('AWS_REGION')
      ?: getenv('AWS_DEFAULT_REGION');
    $endpoint = $settings->get('s3_endpoint')
      ?: getenv('BRANDHUB_SYNC_S3_ENDPOINT');

    if (!is_string($bucket) || trim($bucket) === '') {
      throw new \LogicException(
        'Configure the claim-check bucket using Drupal configuration or '
        . 'BRANDHUB_SYNC_S3_BUCKET.',
      );
    }
    if (!is_string($region) || trim($region) === '') {
      throw new \LogicException(
        'Configure an S3 region using Drupal configuration, '
        . 'BRANDHUB_SYNC_S3_REGION, AWS_REGION, or AWS_DEFAULT_REGION.',
      );
    }

    $client_options = [
      'version' => 'latest',
      'region' => $region,
    ];
    if (is_string($endpoint) && trim($endpoint) !== '') {
      $client_options['endpoint'] = $endpoint;
      $client_options['use_path_style_endpoint'] = TRUE;
    }

    $client = new S3Client($client_options);
    try {
      $result = $client->putObject([
        'Bucket' => $bucket,
        'Key' => $object_key,
        'Body' => $body,
        'ContentType' => $content_type,
      ]);
    }
    catch (AwsException) {
      throw new \RuntimeException(
        'S3 claim-check upload failed; SQS publication was skipped.',
      );
    }

    $metadata = [
      'bucket' => $bucket,
      'objectKey' => $object_key,
    ];
    $version_id = $result->get('VersionId');
    if (is_string($version_id) && $version_id !== '') {
      $metadata['versionId'] = $version_id;
    }
    $etag = $result->get('ETag');
    if (is_string($etag) && $etag !== '') {
      $metadata['etag'] = $etag;
    }

    return $metadata;
  }

}
