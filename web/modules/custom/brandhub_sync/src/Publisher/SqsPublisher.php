<?php

namespace Drupal\brandhub_sync\Publisher;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\brandhub_sync\Diagnostics\SyncDiagnostics;

/**
 * Publishes event messages to Amazon SQS.
 */
final class SqsPublisher implements PublisherInterface {

  /**
   * Constructs the SQS publisher.
   */
  public function __construct(
    private readonly SqsClientFactory $clientFactory,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly SyncDiagnostics $diagnostics,
  ) {}

  /**
   * {@inheritdoc}
   *
   * @throws \JsonException
   *   When the message cannot be encoded as JSON.
   * @throws \LogicException
   *   When the queue URL is not configured.
   */
  public function publish(array $message): void {
    $settings = $this->configFactory->get('brandhub_sync.settings');
    $configured_queue_url = $settings->get('sqs_queue_url');
    $environment_queue_url = getenv('SQS_QUEUE_URL');
    $queue_url = $configured_queue_url ?: $environment_queue_url;
    $configured_region = $settings->get('aws_region');
    $environment_region = getenv('AWS_REGION')
      ?: getenv('AWS_DEFAULT_REGION');
    $profile = $settings->get('aws_profile');
    $this->diagnostics->log(
      'SQS publication started.',
      [
        'queue_source' => $configured_queue_url
          ? 'drupal_config'
          : ($environment_queue_url ? 'environment' : 'missing'),
        'region_source' => $configured_region
          ? 'drupal_config'
          : ($environment_region ? 'environment' : 'missing'),
        'access_key_env_configured' => $this->isEnvironmentVariableSet(
          'AWS_ACCESS_KEY_ID',
        ),
        'secret_key_env_configured' => $this->isEnvironmentVariableSet(
          'AWS_SECRET_ACCESS_KEY',
        ),
        'profile_env_configured' => $this->isEnvironmentVariableSet(
          'AWS_PROFILE',
        ) || $this->isEnvironmentVariableSet('AWS_DEFAULT_PROFILE'),
        'profile_configured' => is_string($profile) && trim($profile) !== '',
        'web_identity_env_configured' => $this->isEnvironmentVariableSet(
          'AWS_WEB_IDENTITY_TOKEN_FILE',
        ),
        'container_role_env_configured' => $this->isEnvironmentVariableSet(
          'AWS_CONTAINER_CREDENTIALS_RELATIVE_URI',
        ) || $this->isEnvironmentVariableSet(
          'AWS_CONTAINER_CREDENTIALS_FULL_URI',
        ),
        'shared_credentials_file_readable' => $this->isSharedCredentialsFileReadable(),
        'event_type' => is_string($message['event'] ?? NULL)
          ? $message['event']
          : 'unknown',
      ],
    );
    if (!is_string($queue_url) || trim($queue_url) === '') {
      throw new \LogicException(
        'Configure the SQS queue URL using '
        . 'brandhub_sync.settings:sqs_queue_url or SQS_QUEUE_URL.',
      );
    }

    $body = json_encode(
      $message,
      JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
    try {
      $this->clientFactory->createClient()->sendMessage([
        'QueueUrl' => $queue_url,
        'MessageBody' => $body,
      ]);
    }
    catch (\Throwable $exception) {
      $this->diagnostics->log(
        'SQS publication failed.',
        [
          'exception' => get_class($exception),
          'event_type' => is_string($message['event'] ?? NULL)
            ? $message['event']
            : 'unknown',
        ],
      );
      throw $exception;
    }

    $this->diagnostics->log(
      'SQS publication succeeded.',
      [
        'event_type' => is_string($message['event'] ?? NULL)
          ? $message['event']
          : 'unknown',
      ],
    );
  }

  /**
   * Indicates whether a non-empty environment variable is configured.
   */
  private function isEnvironmentVariableSet(string $name): bool {
    $value = getenv($name);
    return is_string($value) && trim($value) !== '';
  }

  /**
   * Indicates whether the AWS shared credentials file can be read.
   */
  private function isSharedCredentialsFileReadable(): bool {
    $path = getenv('AWS_SHARED_CREDENTIALS_FILE');
    if (!is_string($path) || trim($path) === '') {
      $home = getenv('HOME') ?: getenv('USERPROFILE');
      if (!is_string($home) || trim($home) === '') {
        return FALSE;
      }
      $path = $home . DIRECTORY_SEPARATOR . '.aws'
        . DIRECTORY_SEPARATOR . 'credentials';
    }

    return is_readable($path);
  }

}
