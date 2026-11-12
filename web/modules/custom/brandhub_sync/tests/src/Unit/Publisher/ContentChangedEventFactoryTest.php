<?php

namespace Drupal\Tests\brandhub_sync\Unit\Publisher;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\brandhub_sync\Publisher\ContentChangedEventFactory;
use Drupal\Tests\UnitTestCase;

/**
 * Tests schema-complete changed-event envelopes.
 *
 * @group brandhub_sync
 */
class ContentChangedEventFactoryTest extends UnitTestCase {

  /**
   * Tests published and removed envelopes contain complete safe payloads.
   *
   * @covers ::create
   *
   * @dataProvider statusProvider
   */
  public function testCreatesSchemaCompleteEvents(string $status): void {
    $factory = new ContentChangedEventFactory(
      $this->uuidGenerator(),
      $this->clock(),
    );
    $event = $factory->create($this->content($status));

    self::assertSame('content.changed', $event['event']);
    self::assertSame('drupal', $event['source']);
    self::assertSame('1.0', $event['schemaVersion']);
    self::assertSame('2023-11-14T22:13:20+00:00', $event['occurredAt']);
    self::assertSame(
      '00000000-0000-4000-8000-000000000001',
      $event['eventId'],
    );
    self::assertSame(
      '00000000-0000-4000-8000-000000000002',
      $event['correlationId'],
    );
    self::assertSame($status, $event['content']['status']);
    self::assertSame(
      'https://example.test/node/42',
      $event['content']['url'],
    );
    self::assertSame(str_repeat('b', 64), $event['content']['hash']);
    self::assertSame(12, $event['content']['version']);
    self::assertSame(
      [
        'id',
        'url',
        'source',
        'type',
        'title',
        'summary',
        'bodyHtml',
        'plainText',
        'tags',
        'product',
        'author',
        'heroImage',
        'publishedAt',
        'updatedAt',
        'version',
        'hash',
        'status',
        'schemaVersion',
      ],
      array_keys($event['content']),
    );
    self::assertArrayNotHasKey('credentials', $event);
  }

  /**
   * Provides supported event statuses.
   *
   * @return array<string, array{string}>
   *   Status test cases.
   */
  public static function statusProvider(): array {
    return [
      'published' => ['published'],
      'removed' => ['removed'],
    ];
  }

  /**
   * Tests event IDs are separately generated for each event.
   *
   * @covers ::create
   */
  public function testEventIdsAreUnique(): void {
    $uuid = $this->createMock(UuidInterface::class);
    $uuid->expects(self::exactly(4))
      ->method('generate')
      ->willReturnOnConsecutiveCalls(
        '00000000-0000-4000-8000-000000000001',
        '00000000-0000-4000-8000-000000000002',
        '00000000-0000-4000-8000-000000000003',
        '00000000-0000-4000-8000-000000000004',
      );
    $factory = new ContentChangedEventFactory($uuid, $this->clock());

    $first = $factory->create($this->content('published'));
    $second = $factory->create($this->content('published'));

    self::assertNotSame($first['eventId'], $second['eventId']);
    self::assertNotSame($first['correlationId'], $second['correlationId']);
    self::assertSame(
      '00000000-0000-4000-8000-000000000002',
      $first['correlationId'],
    );
  }

  /**
   * Returns a complete sample record without personal data.
   *
   * @return array<string, mixed>
   *   A unified content record.
   */
  private function content(string $status): array {
    return [
      'id' => 'dr:42',
      'url' => 'https://example.test/node/42',
      'source' => 'drupal',
      'type' => 'article',
      'title' => 'Example article',
      'summary' => 'A safe example.',
      'bodyHtml' => '<p>A safe example.</p>',
      'plainText' => 'A safe example.',
      'tags' => [],
      'product' => NULL,
      'author' => NULL,
      'heroImage' => NULL,
      'publishedAt' => '2023-11-14T22:13:20+00:00',
      'updatedAt' => '2023-11-14T22:13:20+00:00',
      'version' => 12,
      'hash' => str_repeat('b', 64),
      'status' => $status,
      'schemaVersion' => '1.0',
    ];
  }

  /**
   * Creates a deterministic event clock.
   */
  private function clock(): TimeInterface {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(1_700_000_000);
    return $time;
  }

  /**
   * Creates deterministic event UUIDs for the test envelope.
   */
  private function uuidGenerator(): UuidInterface {
    $uuid = $this->createMock(UuidInterface::class);
    $sequence = 0;
    $uuid->method('generate')->willReturnCallback(
      static function () use (&$sequence): string {
        $sequence++;
        return sprintf('00000000-0000-4000-8000-%012d', $sequence);
      },
    );

    return $uuid;
  }

}
