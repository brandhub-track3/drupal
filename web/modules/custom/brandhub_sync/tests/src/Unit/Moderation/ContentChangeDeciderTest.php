<?php

namespace Drupal\Tests\brandhub_sync\Unit\Moderation;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\brandhub_sync\Handler\EntityChangeHandler;
use Drupal\brandhub_sync\Mapper\NodeToUnifiedContentMapperInterface;
use Drupal\brandhub_sync\Moderation\ContentChangeDecider;
use Drupal\brandhub_sync\Moderation\ModerationStatusResolver;
use Drupal\brandhub_sync\Publisher\ContentChangedEventFactory;
use Drupal\brandhub_sync\Publisher\FakePublisher;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests publication transitions and lifecycle hook behavior.
 *
 * @group brandhub_sync
 * @coversDefaultClass \Drupal\brandhub_sync\Moderation\ContentChangeDecider
 */
class ContentChangeDeciderTest extends UnitTestCase {

  /**
   * Tests creating a draft node does not publish a message.
   */
  public function testNewDraftDoesNotPublish(): void {
    $publisher = $this->runOperation(
      'insert',
      $this->node('product', FALSE, 'draft'),
    );

    self::assertSame([], $publisher->getMessages());
  }

  /**
   * Tests updating a draft node does not publish a message.
   */
  public function testDraftUpdateDoesNotPublish(): void {
    $publisher = $this->runOperation(
      'update',
      $this->node('article', FALSE, 'draft'),
      $this->node('article', FALSE, 'draft'),
    );

    self::assertSame([], $publisher->getMessages());
  }

  /**
   * Tests transitioning a draft node to published sends one changed event.
   */
  public function testDraftToPublishedSendsPublishedEvent(): void {
    $publisher = $this->runOperation(
      'update',
      $this->node('product', TRUE, 'published'),
      $this->node('product', FALSE, 'draft'),
    );

    $this->assertSingleEvent($publisher, 'published');
  }

  /**
   * Tests transitioning a node in review to published.
   */
  public function testInReviewToPublishedSendsPublishedEvent(): void {
    $publisher = $this->runOperation(
      'update',
      $this->node('faq', TRUE, 'published'),
      $this->node('faq', FALSE, 'in_review'),
    );

    $this->assertSingleEvent($publisher, 'published');
  }

  /**
   * Tests updating an already published node sends one changed event.
   */
  public function testPublishedUpdateSendsPublishedEvent(): void {
    $publisher = $this->runOperation(
      'update',
      $this->node('article', TRUE, 'published'),
      $this->node('article', TRUE, 'published'),
    );

    $this->assertSingleEvent($publisher, 'published');
  }

  /**
   * Tests unpublishing a previously published node sends a tombstone.
   */
  public function testPublishedToUnpublishedSendsRemovedEvent(): void {
    $publisher = $this->runOperation(
      'update',
      $this->node('product', FALSE, 'draft'),
      $this->node('product', TRUE, 'published'),
    );

    $this->assertSingleEvent($publisher, 'removed');
  }

  /**
   * Tests deleting a published node sends a tombstone.
   */
  public function testPublishedDeletionSendsRemovedEvent(): void {
    $publisher = $this->runOperation(
      'delete',
      $this->node('faq', TRUE, 'published'),
    );

    $this->assertSingleEvent($publisher, 'removed');
  }

  /**
   * Tests deleting a draft node does not publish a message.
   */
  public function testDraftDeletionDoesNotPublish(): void {
    $publisher = $this->runOperation(
      'delete',
      $this->node('faq', FALSE, 'draft'),
    );

    self::assertSame([], $publisher->getMessages());
  }

  /**
   * Tests unsupported bundles do not publish on any lifecycle operation.
   */
  public function testUnsupportedContentTypeDoesNotPublish(): void {
    $current = $this->node('page', TRUE, NULL);
    $original = $this->node('page', TRUE, NULL);
    $publisher = new FakePublisher();
    $handler = $this->createHandler($publisher);

    $handler->insert($current);
    $handler->update($current, $original);
    $handler->delete($current);

    self::assertSame([], $publisher->getMessages());
  }

  /**
   * Tests a non-default revision save does not publish an incomplete record.
   */
  public function testNonDefaultRevisionSaveDoesNotPublish(): void {
    $node = $this->node('article', TRUE, 'published', FALSE);
    $publisher = $this->runOperation(
      'update',
      $node,
      $this->node('article', TRUE, 'published'),
    );

    self::assertSame([], $publisher->getMessages());
  }

  /**
   * Tests duplicate update hook calls for one save publish only once.
   */
  public function testDuplicateHooksPublishOnlyOnce(): void {
    $current = $this->node('article', TRUE, 'published');
    $original = $this->node('article', TRUE, 'published');
    $publisher = new FakePublisher();
    $handler = $this->createHandler($publisher);

    $handler->update($current, $original);
    $handler->update($current, $original);

    $this->assertSingleEvent($publisher, 'published');
  }

  /**
   * Runs one node lifecycle operation with a fake publisher.
   */
  private function runOperation(
    string $operation,
    NodeInterface $current,
    ?NodeInterface $original = NULL,
  ): FakePublisher {
    $publisher = new FakePublisher();
    $handler = $this->createHandler($publisher);

    if ($operation === 'insert') {
      $handler->insert($current);
    }
    elseif ($operation === 'update') {
      $handler->update($current, $original);
    }
    else {
      $handler->delete($current);
    }

    return $publisher;
  }

  /**
   * Creates the handler with an AWS-independent mapper mock.
   */
  private function createHandler(
    FakePublisher $publisher,
  ): EntityChangeHandler {
    $mapper = $this->createMock(NodeToUnifiedContentMapperInterface::class);
    $mapper->method('supports')
      ->willReturnCallback(
        static fn(NodeInterface $node): bool => in_array(
          $node->getType(),
          ['product', 'article', 'faq'],
          TRUE,
        ),
      );
    $mapper->method('map')
      ->willReturnCallback(
        fn(NodeInterface $node, string $status): array => $this->contentRecord(
          $node,
          $status,
        ),
      );

    return new EntityChangeHandler(
      $mapper,
      new ContentChangeDecider(new ModerationStatusResolver()),
      new ContentChangedEventFactory(
        $this->uuidGenerator(),
        $this->clock(),
      ),
      $publisher,
    );
  }

  /**
   * Builds a complete, safe unified record for handler tests.
   *
   * @return array<string, mixed>
   *   A unified content record.
   */
  private function contentRecord(
    NodeInterface $node,
    string $status,
  ): array {
    return [
      'id' => 'dr:' . $node->id(),
      'url' => 'https://example.test/node/' . $node->id(),
      'source' => 'drupal',
      'type' => $node->getType(),
      'title' => 'Example content',
      'summary' => 'Example summary',
      'bodyHtml' => NULL,
      'plainText' => '',
      'tags' => [],
      'product' => NULL,
      'author' => NULL,
      'heroImage' => NULL,
      'publishedAt' => '2023-11-14T22:13:20+00:00',
      'updatedAt' => '2023-11-14T22:13:20+00:00',
      'version' => 3,
      'hash' => str_repeat('a', 64),
      'status' => $status,
      'schemaVersion' => '1.0',
    ];
  }

  /**
   * Creates deterministic event UUIDs without external services.
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

  /**
   * Creates a deterministic event clock.
   */
  private function clock(): TimeInterface {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(1_700_000_000);
    return $time;
  }

  /**
   * Creates a node mock with publication and moderation state.
   */
  private function node(
    string $bundle,
    bool $published,
    ?string $moderation_state,
    bool $default_revision = TRUE,
  ): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('getType')->willReturn($bundle);
    $node->method('id')->willReturn('42');
    $node->method('getRevisionId')->willReturn(3);
    $node->method('getChangedTime')->willReturn(123456);
    $node->method('isPublished')->willReturn($published);
    $node->method('isDefaultRevision')->willReturn($default_revision);
    $node->method('hasField')->willReturnCallback(
      static fn(string $field_name): bool => $field_name === 'moderation_state'
        && $moderation_state !== NULL,
    );

    if ($moderation_state !== NULL) {
      $field = $this->createMock(FieldItemListInterface::class);
      $field->method('getValue')->willReturn([
        ['value' => $moderation_state],
      ]);
      $node->method('get')
        ->with('moderation_state')
        ->willReturn($field);
    }

    return $node;
  }

  /**
   * Asserts exactly one content.changed event with the expected status.
   */
  private function assertSingleEvent(
    FakePublisher $publisher,
    string $status,
  ): void {
    self::assertCount(1, $publisher->getMessages());
    self::assertSame(
      'content.changed',
      $publisher->getMessages()[0]['event'],
    );
    $event = $publisher->getMessages()[0];
    self::assertSame($status, $event['content']['status']);
    self::assertSame('drupal', $event['source']);
    self::assertSame('1.0', $event['schemaVersion']);
    self::assertSame('content.changed', $event['event']);
    self::assertMatchesRegularExpression(
      '/^[a-f0-9]{64}$/',
      $event['content']['hash'],
    );
  }

}
