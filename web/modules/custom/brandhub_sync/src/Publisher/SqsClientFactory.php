<?php

namespace Drupal\brandhub_sync\Publisher;

use Aws\Sqs\SqsClient;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Creates configured AWS SQS clients.
 */
final class SqsClientFactory {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Creates the configured Amazon SQS client.
   */
  public function createClient(): object {
    if (!class_exists(SqsClient::class)) {
      throw new \LogicException(
        'The AWS SDK is required for SQS publishing. Install it with: '
        . 'composer require aws/aws-sdk-php',
      );
    }

    $settings = $this->configFactory->get('brandhub_sync.settings');
    $region = $settings->get('aws_region')
      ?: getenv('AWS_REGION')
      ?: getenv('AWS_DEFAULT_REGION');
    if (!is_string($region) || trim($region) === '') {
      throw new \LogicException(
        'Configure an AWS region using brandhub_sync.settings:aws_region, '
        . 'AWS_REGION, or AWS_DEFAULT_REGION.',
      );
    }

    $client_config = [
      'version' => 'latest',
      'region' => $region,
    ];
    $profile = $settings->get('aws_profile');
    if (is_string($profile) && trim($profile) !== '') {
      $client_config['profile'] = trim($profile);
    }

    return new SqsClient($client_config);
  }

}
