<?php

namespace Drupal\Tests\brandhub_sync\Unit\Resync;

use Drupal\brandhub_sync\Mapper\NodeToUnifiedContentMapperInterface;
use Drupal\brandhub_sync\Moderation\ModerationStatusResolver;
use Drupal\brandhub_sync\Publisher\ContentChangedEventFactory;
use Drupal\brandhub_sync\Publisher\FakePublisher;
use Drupal\brandhub_sync\Publisher\PublisherInterface;
use Drupal\brandhub_sync\Resync\ContentResyncService;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests published content resync behavior.
 *
 * @group brandhub_sync
 */
class ContentResyncServiceTest extends UnitTestCase {

  /**
   * Builds a resync service and its test doubles.
   *
   * @param array<int, \Drupal\node\NodeInterface> $nodes
   *   Published nodes available to the fake paged storage.
   * @param int $unpublished_count
   *   Count returned for excluded unpublished nodes.
   * @param int $unsupported_count
   *   Count returned for unsupported bundles.
   * @param int|null $fail_id
   *   Optional ID whose publishing should throw.
   *
   * @return array{
   *   \Drupal\brandhub_sync\Resync\ContentResyncService,
   *   \Drupal\brandhub_sync\Publisher\FakePublisher,
   *   \Drupal\Core\Entity\EntityStorageInterface,
   *   \Drupal\brandhub_sync\Mapper\NodeToUnifiedContentMapperInterface
   *   }
   *   Service and collaborators.
   */
  private function buildService(
    array $nodes,
    int $unpublished_count = 0,
    int $unsupported_count = 0,
    ?int $fail_id = NULL,
  ): array {
    $nodes_by_id = [];
    foreach ($nodes as $node) {
      $nodes_by_id[$node->id()] = $node;
    }

    $query_number = 0;
    $count_number = 0;
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturnCallback(
      function () use (
        &$query_number,
        &$count_number,
        $nodes_by_id,
        $unpublished_count,
        $unsupported_count,
      ): QueryInterface {
        $query_number++;
        $is_count = FALSE;
        $offset = 0;
        $length = NULL;
        $query = $this->createMock(QueryInterface::class);
        $query->method('accessCheck')->willReturnSelf();
        $query->method('condition')->willReturnSelf();
        $query->method('sort')->willReturnSelf();
        $query->method('range')->willReturnCallback(
          static function (?int $start, ?int $size) use (
            &$offset,
            &$length,
            $query,
          ): QueryInterface {
            $offset = $start ?? 0;
            $length = $size;
            return $query;
          },
        );
        $query->method('count')->willReturnCallback(
          static function () use (&$is_count, $query): QueryInterface {
            $is_count = TRUE;
            return $query;
          },
        );
        $query->method('execute')->willReturnCallback(
          static function () use (
            &$is_count,
            &$count_number,
            &$offset,
            &$length,
            $nodes_by_id,
            $unpublished_count,
            $unsupported_count,
          ): array|int {
            if ($is_count) {
              $count_number++;
              return $count_number === 1
                ? $unpublished_count
                : $unsupported_count;
            }

            $ids = array_keys($nodes_by_id);
            return array_slice($ids, $offset, $length);
          },
        );

        return $query;
      },
    );
    $storage->method('loadMultiple')->willReturnCallback(
      static fn(array $ids): array => array_intersect_key(
        $nodes_by_id,
        array_fill_keys($ids, TRUE),
      ),
    );

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('node')
      ->willReturn($storage);

    $mapper = $this->createMock(NodeToUnifiedContentMapperInterface::class);
    $mapper->method('supports')->willReturnCallback(
      static fn(NodeInterface $node): bool => in_array(
        $node->getType(),
        ['product', 'article', 'faq'],
        TRUE,
      ),
    );
    $mapper->method('map')->willReturnCallback(
      static fn(NodeInterface $node, string $status): array => [
        'id' => 'dr:' . $node->id(),
        'url' => 'https://example.test/node/' . $node->id(),
        'source' => 'drupal',
        'type' => $node->getType(),
        'title' => 'Example content',
        'summary' => '',
        'bodyHtml' => NULL,
        'plainText' => '',
        'tags' => [],
        'product' => NULL,
        'author' => NULL,
        'heroImage' => NULL,
        'publishedAt' => '2023-11-14T22:13:20+00:00',
        'updatedAt' => '2023-11-14T22:13:20+00:00',
        'version' => (int) $node->getRevisionId(),
        'hash' => hash('sha256', 'content-' . $node->id()),
        'status' => $status,
        'schemaVersion' => '1.0',
      ],
    );

    $publisher = new FakePublisher();
    $publish_target = $fail_id === NULL
      ? $publisher
      : new class($publisher, $fail_id) implements PublisherInterface {

        public function __construct(
          private readonly FakePublisher $publisher,
          private readonly int $failId,
        ) {}

        /**
         * {@inheritdoc}
         */
        public function publish(array $message): void {
          if ($message['content']['id'] === 'dr:' . $this->failId) {
            throw new \RuntimeException('Deliberate test failure.');
          }
          $this->publisher->publish($message);
        }

      };

    $uuid = $this->createMock(UuidInterface::class);
    $uuid_sequence = 0;
    $uuid->method('generate')->willReturnCallback(
      static function () use (&$uuid_sequence): string {
        $uuid_sequence++;
        return sprintf('00000000-0000-4000-8000-%012d', $uuid_sequence);
      },
    );
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(1_700_000_000);
    $event_factory = new ContentChangedEventFactory($uuid, $time);

    $logger = $this->createMock(LoggerInterface::class);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->with('brandhub_sync')->willReturn($logger);

    return [
      new ContentResyncService(
        $entity_type_manager,
        $mapper,
        new ModerationStatusResolver(),
        $event_factory,
        $publish_target,
        $logger_factory,
      ),
      $publisher,
      $storage,
      $mapper,
    ];
  }

  /**
   * Creates a supported published or unpublished node.
   */
  private function node(
    int $id,
    string $bundle = 'article',
    bool $published = TRUE,
  ): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn((string) $id);
    $node->method('getType')->willReturn($bundle);
    $node->method('getRevisionId')->willReturn($id + 100);
    $node->method('isPublished')->willReturn($published);
    $node->method('hasField')->willReturn(FALSE);
    return $node;
  }

  /**
   * Tests only eligible published supported nodes are emitted.
   */
  public function testOnlyPublishedSupportedNodesAreProcessed(): void {
    [$service, $publisher] = $this->buildService(
      [$this->node(1), $this->node(2, 'product')],
      1,
      1,
    );

    $summary = $service->resync(NULL, 10, NULL, FALSE);

    self::assertSame(
      ['processed' => 2, 'sent' => 2, 'skipped' => 2, 'failed' => 0],
      $summary,
    );
    self::assertCount(2, $publisher->getMessages());
  }

  /**
   * Tests a node that became draft after its query is skipped.
   */
  public function testDraftsAreSkipped(): void {
    [$service, $publisher] = $this->buildService([
      $this->node(1, 'article', FALSE),
    ]);

    $summary = $service->resync('article', 10, NULL, FALSE);

    self::assertSame(0, $summary['sent']);
    self::assertSame(1, $summary['skipped']);
    self::assertSame([], $publisher->getMessages());
  }

  /**
   * Tests unsupported bundles are represented as skipped, not sent.
   */
  public function testUnsupportedBundlesAreSkipped(): void {
    [$service, $publisher] = $this->buildService([
      $this->node(1, 'page'),
    ]);

    $summary = $service->resync(NULL, 10, NULL, FALSE);

    self::assertSame(0, $summary['sent']);
    self::assertSame(1, $summary['skipped']);
    self::assertSame([], $publisher->getMessages());
  }

  /**
   * Tests dry-run maps eligible content without publishing.
   */
  public function testDryRunPublishesNothing(): void {
    [$service, $publisher] = $this->buildService([$this->node(1)]);
    $reported = [];

    $summary = $service->resync(
      NULL,
      10,
      NULL,
      TRUE,
      static function (string $message) use (&$reported): void {
        $reported[] = $message;
      },
    );

    self::assertSame(1, $summary['processed']);
    self::assertSame(0, $summary['sent']);
    self::assertSame([], $publisher->getMessages());
    self::assertStringContainsString('hash=', $reported[0]);
  }

  /**
   * Tests bundle filtering and batch ranges are applied.
   */
  public function testBundleFilterAndBatchProcessing(): void {
    [$service, $publisher, $storage] = $this->buildService([
      $this->node(1, 'product'),
      $this->node(2, 'product'),
      $this->node(3, 'product'),
    ]);
    $storage->expects(self::exactly(2))->method('loadMultiple')
      ->willReturnOnConsecutiveCalls(
        [1 => $this->node(1, 'product'), 2 => $this->node(2, 'product')],
        [3 => $this->node(3, 'product')],
      );

    $summary = $service->resync('product', 2, NULL, FALSE);

    self::assertSame(3, $summary['sent']);
    self::assertCount(3, $publisher->getMessages());
  }

  /**
   * Tests one failed item does not prevent later sends or final counts.
   */
  public function testFailureDoesNotHideFinalCounts(): void {
    [$service, $publisher] = $this->buildService(
      [$this->node(1), $this->node(2)],
      fail_id: 1,
    );

    $summary = $service->resync(NULL, 10, NULL, FALSE);

    self::assertSame(
      ['processed' => 2, 'sent' => 1, 'skipped' => 0, 'failed' => 1],
      $summary,
    );
    self::assertCount(1, $publisher->getMessages());
  }

  /**
   * Tests content IDs, revisions, and hashes remain stable on repeated runs.
   */
  public function testRepeatedRunsKeepContentIdentityVersionAndHash(): void {
    [$service, $publisher] = $this->buildService([$this->node(1)]);

    $service->resync(NULL, 10, NULL, FALSE);
    $service->resync(NULL, 10, NULL, FALSE);
    $messages = $publisher->getMessages();

    self::assertSame(
      $messages[0]['content']['id'],
      $messages[1]['content']['id'],
    );
    self::assertSame(
      $messages[0]['content']['version'],
      $messages[1]['content']['version'],
    );
    self::assertSame(
      $messages[0]['content']['hash'],
      $messages[1]['content']['hash'],
    );
  }

}
