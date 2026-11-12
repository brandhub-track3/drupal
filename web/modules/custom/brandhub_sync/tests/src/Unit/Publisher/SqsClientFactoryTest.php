<?php

namespace Drupal\Tests\brandhub_sync\Unit\Publisher;

use Aws\Sqs\SqsClient;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\brandhub_sync\Publisher\SqsClientFactory;
use Drupal\Tests\UnitTestCase;

/**
 * Tests AWS SQS client configuration.
 *
 * @group brandhub_sync
 */
class SqsClientFactoryTest extends UnitTestCase {

  /**
   * Tests that a configured shared credentials profile is selected.
   */
  public function testConfiguredProfileIsPassedToAwsClient(): void {
    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnCallback(
      static fn(string $name): mixed => match ($name) {
        'aws_region' => 'ap-south-1',
        'aws_profile' => 'brandhub-test',
        default => NULL,
      },
    );
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('brandhub_sync.settings')
      ->willReturn($settings);

    $client = (new SqsClientFactory($config_factory))->createClient();

    self::assertInstanceOf(SqsClient::class, $client);
    self::assertSame('brandhub-test', $client->getConfig('profile'));
  }

}
