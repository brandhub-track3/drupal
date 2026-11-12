<?php

namespace Drupal\brandhub_sync\Drush\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Command\Command;

/**
 * Provides safe checks for media offload configuration.
 */
final class BrandhubMediaConfigCommands extends DrushCommands {

  /**
   * Constructs the media configuration command.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct();
  }

  /**
   * Checks required S3FS configuration without contacting AWS.
   */
  #[CLI\Command(name: 'brandhub:media-config')]
  #[CLI\Usage(
    name: 'drush brandhub:media-config',
    description: 'Check media offload configuration without AWS requests.',
  )]
  public function validate(): int {
    $enabled_value = getenv('BRANDHUB_MEDIA_S3_ENABLED');
    $enabled = $enabled_value === FALSE || $enabled_value === ''
      ? FALSE
      : filter_var(
        $enabled_value,
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE,
      );

    if ($enabled === NULL) {
      $this->io()->error(
        'BRANDHUB_MEDIA_S3_ENABLED must be a boolean value.',
      );
      return Command::INVALID;
    }

    if (!$enabled) {
      $this->io()->success(
        'S3 media storage is disabled; Drupal will use local public files.',
      );
      $this->io()->text('No AWS requests were made.');
      return Command::SUCCESS;
    }

    $config = $this->configFactory->get('s3fs.settings');
    $required = [
      'BRANDHUB_MEDIA_S3_BUCKET' => $config->get('bucket')
        ?: getenv('BRANDHUB_MEDIA_S3_BUCKET'),
      'BRANDHUB_MEDIA_S3_REGION' => $config->get('region')
        ?: getenv('BRANDHUB_MEDIA_S3_REGION')
        ?: getenv('AWS_REGION')
        ?: getenv('AWS_DEFAULT_REGION'),
      'BRANDHUB_MEDIA_CLOUDFRONT_DOMAIN' => $config->get('domain')
        ?: getenv('BRANDHUB_MEDIA_CLOUDFRONT_DOMAIN'),
    ];
    $missing = [];
    foreach ($required as $name => $value) {
      if (!is_string($value) || trim($value) === '') {
        $missing[] = $name;
      }
    }

    $domain = $required['BRANDHUB_MEDIA_CLOUDFRONT_DOMAIN'];
    if (
      is_string($domain)
      && $domain !== ''
      && preg_match('/^[A-Za-z0-9.-]+(?::[0-9]+)?$/', $domain) !== 1
    ) {
      $missing[] = 'BRANDHUB_MEDIA_CLOUDFRONT_DOMAIN (invalid hostname)';
    }

    $endpoint = $config->get('hostname')
      ?: getenv('BRANDHUB_MEDIA_S3_ENDPOINT');
    if (is_string($endpoint) && $endpoint !== '') {
      $environment = strtolower(
        trim(getenv('BRANDHUB_MEDIA_ENVIRONMENT') ?: ''),
      );
      if (!in_array($environment, ['local', 'test'], TRUE)) {
        $missing[] = 'BRANDHUB_MEDIA_ENVIRONMENT (must be local or test for endpoint override)';
      }

      $parts = parse_url($endpoint);
      if (
        $parts === FALSE
        || !in_array($parts['scheme'] ?? '', ['http', 'https'], TRUE)
        || empty($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
      ) {
        $missing[] = 'BRANDHUB_MEDIA_S3_ENDPOINT (invalid URL)';
      }
    }

    if ($missing !== []) {
      $this->io()->error(
        'S3 media storage was requested but required configuration is missing.',
      );
      $this->io()->listing($missing);
      $this->io()->text(
        'Public S3 storage remains disabled; no AWS requests were made.',
      );
      return Command::FAILURE;
    }

    if (!Settings::get('s3fs.use_s3_for_public', FALSE)) {
      $this->io()->error(
        'S3 values are present but the public S3 stream wrapper is not active.',
      );
      $this->io()->text('No AWS requests were made.');
      return Command::FAILURE;
    }

    $this->io()->success(
      'Required S3FS values are present and public S3 storage is enabled.',
    );
    $this->io()->text(
      'Credentials and AWS permissions were not verified; no AWS requests were made.',
    );
    return Command::SUCCESS;
  }

}
