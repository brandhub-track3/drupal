<?php

namespace Drupal\Tests\brandhub_sync\Unit\Publisher;

use Drupal\brandhub_sync\Publisher\ClaimCheckMessageProcessor;
use Drupal\brandhub_sync\Publisher\ClaimCheckStorageInterface;
use Drupal\brandhub_sync\Publisher\FakeClaimCheckStorage;
use Drupal\brandhub_sync\Publisher\FakePublisher;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Tests\UnitTestCase;

/**
 * Tests claim-check routing without making AWS requests.
 *
 * @group brandhub_sync
 */
class ClaimCheckMessageProcessorTest extends UnitTestCase {

  /**
   * Environment variables that can affect claim-check routing.
   *
   * @var array<string, string|false>
   */
  private array $originalEnvironment = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (
      [
        'BRANDHUB_SYNC_CLAIM_CHECK_ENABLED',
        'BRANDHUB_SYNC_CLAIM_CHECK_THRESHOLD_BYTES',
        'BRANDHUB_SYNC_ENVIRONMENT',
      ] as $name
    ) {
      $this->originalEnvironment[$name] = getenv($name);
      putenv($name);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->originalEnvironment as $name => $value) {
      if ($value === FALSE) {
        putenv($name);
      }
      else {
        putenv($name . '=' . $value);
      }
    }

    parent::tearDown();
  }

  /**
   * Tests payloads below the threshold remain inline.
   */
  public function testPayloadBelowThresholdStaysInline(): void {
    putenv('BRANDHUB_SYNC_CLAIM_CHECK_THRESHOLD_BYTES=1');
    $message = $this->messageWithSize(
      ClaimCheckMessageProcessor::MAX_MESSAGE_SIZE - 1,
    );
    [$processor, $transport, $storage] = $this->processor();

    $processor->publish($message);

    self::assertSame([$message], $transport->getMessages());
    self::assertSame([], $storage->getObjects());
  }

  /**
   * Tests payloads exactly at the threshold remain inline.
   */
  public function testPayloadAtThresholdStaysInline(): void {
    $message = $this->messageWithSize(
      ClaimCheckMessageProcessor::MAX_MESSAGE_SIZE,
    );
    [$processor, $transport, $storage] = $this->processor();

    $processor->publish($message);

    self::assertSame([$message], $transport->getMessages());
    self::assertSame([], $storage->getObjects());
  }

  /**
   * Tests payloads above the threshold upload and publish a pointer.
   */
  public function testPayloadAboveThresholdUsesClaimCheckPointer(): void {
    $message = $this->messageWithSize(
      ClaimCheckMessageProcessor::MAX_MESSAGE_SIZE + 1,
    );
    [$processor, $transport, $storage] = $this->processor();
    $expected_json = $this->encode($message);
    $expected_checksum = hash('sha256', $expected_json);

    $processor->publish($message);

    self::assertCount(1, $transport->getMessages());
    self::assertCount(1, $storage->getObjects());
    $pointer_message = $transport->getMessages()[0];
    self::assertArrayNotHasKey('content', $pointer_message);
    self::assertSame(
      'staging/drupal/42/12/' . $expected_checksum . '.json',
      $pointer_message['claimCheck']['objectKey'],
    );
    self::assertSame(
      'fake-claim-check',
      $pointer_message['claimCheck']['bucket'],
    );
    self::assertSame(
      'application/json',
      $pointer_message['claimCheck']['contentType'],
    );
    self::assertSame(
      ClaimCheckMessageProcessor::MAX_MESSAGE_SIZE + 1,
      $pointer_message['claimCheck']['originalPayloadSize'],
    );
    self::assertSame(
      $expected_checksum,
      $pointer_message['claimCheck']['sha256'],
    );
    self::assertSame('1.0', $pointer_message['claimCheck']['schemaVersion']);
    self::assertArrayHasKey('etag', $pointer_message['claimCheck']);
    self::assertSame(
      $expected_json,
      array_values($storage->getObjects())[0],
    );
  }

  /**
   * Tests repeated payloads produce the same checksum and object key.
   */
  public function testChecksumAndObjectKeyAreDeterministic(): void {
    $message = $this->messageWithSize(
      ClaimCheckMessageProcessor::MAX_MESSAGE_SIZE + 1,
    );
    [$first_processor, $first_transport] = $this->processor();
    [$second_processor, $second_transport] = $this->processor();

    $first_processor->publish($message);
    $second_processor->publish($message);

    self::assertSame(
      $first_transport->getMessages()[0]['claimCheck']['sha256'],
      $second_transport->getMessages()[0]['claimCheck']['sha256'],
    );
    self::assertSame(
      $first_transport->getMessages()[0]['claimCheck']['objectKey'],
      $second_transport->getMessages()[0]['claimCheck']['objectKey'],
    );
  }

  /**
   * Tests that a body over 500 KiB uses a claim-check pointer.
   */
  public function testLargeBodyFixtureUsesClaimCheck(): void {
    $body = require __DIR__ . '/../../../fixtures/large-content-body.php';
    self::assertGreaterThan(500 * 1024, strlen($body));
    $message = $this->message();
    $message['content']['bodyHtml'] = $body;
    [$processor, $transport, $storage] = $this->processor();

    $processor->publish($message);

    self::assertCount(1, $transport->getMessages());
    self::assertArrayNotHasKey('content', $transport->getMessages()[0]);
    self::assertGreaterThan(
      500 * 1024,
      $transport->getMessages()[0]['claimCheck']['originalPayloadSize'],
    );
    self::assertCount(1, $storage->getObjects());
  }

  /**
   * Tests removal events are stored intact with their removed status.
   */
  public function testRemovedMessageUsesClaimCheck(): void {
    $message = $this->messageWithSize(
      ClaimCheckMessageProcessor::MAX_MESSAGE_SIZE + 1,
      'removed',
    );
    [$processor, $transport, $storage] = $this->processor();

    $processor->publish($message);

    $pointer_message = $transport->getMessages()[0];
    self::assertSame('content.changed', $pointer_message['event']);
    self::assertSame('removed', json_decode(
      array_values($storage->getObjects())[0],
      TRUE,
      512,
      JSON_THROW_ON_ERROR,
    )['content']['status']);
    self::assertArrayNotHasKey('content', $pointer_message);
  }

  /**
   * Tests storage failure prevents any transport publish.
   */
  public function testStorageFailurePreventsSqsPublish(): void {
    $transport = new FakePublisher();
    $storage = new class implements ClaimCheckStorageInterface {

      /**
       * {@inheritdoc}
       */
      public function store(
        string $object_key,
        string $body,
        string $content_type,
      ): array {
        throw new \RuntimeException('Simulated storage failure.');
      }

    };
    $processor = $this->createProcessor($transport, $storage);

    try {
      $processor->publish($this->messageWithSize(
        ClaimCheckMessageProcessor::MAX_MESSAGE_SIZE + 1,
      ));
      self::fail('Expected storage failure.');
    }
    catch (\RuntimeException $exception) {
      self::assertSame('Simulated storage failure.', $exception->getMessage());
    }

    self::assertSame([], $transport->getMessages());
  }

  /**
   * Tests automated routing uses only in-memory fakes.
   */
  public function testUsesFakesWithoutAwsRequests(): void {
    [$processor, $transport, $storage] = $this->processor();

    $processor->publish($this->messageWithSize(
      ClaimCheckMessageProcessor::MAX_MESSAGE_SIZE + 1,
    ));

    self::assertInstanceOf(FakePublisher::class, $transport);
    self::assertInstanceOf(FakeClaimCheckStorage::class, $storage);
    self::assertCount(1, $transport->getMessages());
  }

  /**
   * Creates the processor and its in-memory dependencies.
   *
   * @return array{ClaimCheckMessageProcessor, FakePublisher,
   *   FakeClaimCheckStorage}
   *   Processor and fake dependencies.
   */
  private function processor(): array {
    $transport = new FakePublisher();
    $storage = new FakeClaimCheckStorage();
    return [
      $this->createProcessor($transport, $storage),
      $transport,
      $storage,
    ];
  }

  /**
   * Creates a processor using the production claim-check limit.
   */
  private function createProcessor(
    FakePublisher $transport,
    ClaimCheckStorageInterface $storage,
  ): ClaimCheckMessageProcessor {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      fn(string $name): mixed => match ($name) {
        'claim_check_enabled' => TRUE,
        'environment' => 'staging',
        default => NULL,
      },
    );
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('brandhub_sync.settings')
      ->willReturn($config);

    return new ClaimCheckMessageProcessor(
      $transport,
      $storage,
      $config_factory,
    );
  }

  /**
   * Creates a valid event whose JSON representation has the requested size.
   */
  private function messageWithSize(
    int $size,
    string $status = 'published',
  ): array {
    $message = $this->message($status);
    $base_size = strlen($this->encode($message));
    $padding_size = $size - $base_size;
    if ($padding_size < 0) {
      throw new \LogicException('Requested size is smaller than the event.');
    }
    $message['content']['summary'] = str_repeat('x', $padding_size);
    self::assertSame($size, strlen($this->encode($message)));

    return $message;
  }

  /**
   * Creates a safe complete event fixture.
   */
  private function message(string $status = 'published'): array {
    return [
      'event' => 'content.changed',
      'eventId' => '00000000-0000-4000-8000-000000000001',
      'occurredAt' => '2023-11-14T22:13:20+00:00',
      'correlationId' => '00000000-0000-4000-8000-000000000002',
      'schemaVersion' => '1.0',
      'source' => 'drupal',
      'content' => [
        'id' => 'dr:42',
        'url' => 'https://example.test/node/42',
        'source' => 'drupal',
        'type' => 'article',
        'title' => 'Example article',
        'summary' => '',
        'bodyHtml' => '<p>Safe example.</p>',
        'plainText' => 'Safe example.',
        'tags' => [],
        'product' => NULL,
        'author' => NULL,
        'heroImage' => NULL,
        'publishedAt' => '2023-11-14T22:13:20+00:00',
        'updatedAt' => '2023-11-14T22:13:20+00:00',
        'version' => 12,
        'hash' => str_repeat('a', 64),
        'status' => $status,
        'schemaVersion' => '1.0',
      ],
    ];
  }

  /**
   * Encodes a message using the publisher's JSON flags.
   */
  private function encode(array $message): string {
    return json_encode(
      $message,
      JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
  }

}
