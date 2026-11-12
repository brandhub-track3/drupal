<?php

namespace Drupal\brandhub_sync\Resync;

use Drupal\brandhub_sync\Mapper\NodeToUnifiedContentMapperInterface;
use Drupal\brandhub_sync\Moderation\ModerationStatusResolver;
use Drupal\brandhub_sync\Publisher\ContentChangedEventFactory;
use Drupal\brandhub_sync\Publisher\PublisherInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Resends supported, currently published nodes in bounded batches.
 */
final class ContentResyncService {

  /**
   * Supported node bundles.
   *
   * @var list<string>
   */
  private const BUNDLES = ['product', 'article', 'faq'];

  /**
   * Constructs the resync service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly NodeToUnifiedContentMapperInterface $mapper,
    private readonly ModerationStatusResolver $statusResolver,
    private readonly ContentChangedEventFactory $eventFactory,
    private readonly PublisherInterface $publisher,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->logger = $logger_factory->get('brandhub_sync');
  }

  /**
   * Logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private readonly LoggerInterface $logger;

  /**
   * Re-emits published content.changed events.
   *
   * @param string|null $bundle
   *   Optional bundle filter.
   * @param int $batch_size
   *   Maximum node IDs loaded per batch.
   * @param int|null $limit
   *   Optional maximum number of eligible nodes attempted.
   * @param bool $dry_run
   *   Whether to map and report without publishing.
   * @param callable(string): void|null $report
   *   Optional callback for safe per-node progress output.
   *
   * @return array{processed: int, sent: int, skipped: int, failed: int}
   *   Counts for this run.
   */
  public function resync(
    ?string $bundle,
    int $batch_size,
    ?int $limit,
    bool $dry_run,
    ?callable $report = NULL,
  ): array {
    if ($bundle !== NULL && !in_array($bundle, self::BUNDLES, TRUE)) {
      throw new \InvalidArgumentException(
        'Bundle must be product, article, or faq.',
      );
    }
    if ($batch_size < 1 || $batch_size > 1000) {
      throw new \InvalidArgumentException(
        'Batch size must be between 1 and 1000.',
      );
    }
    if ($limit !== NULL && $limit < 1) {
      throw new \InvalidArgumentException('Limit must be greater than zero.');
    }

    $summary = [
      'processed' => 0,
      'sent' => 0,
      'skipped' => $this->countExcludedNodes($bundle),
      'failed' => 0,
    ];
    $storage = $this->nodeStorage();
    $offset = 0;
    $attempted = 0;
    $bundles = $bundle === NULL ? self::BUNDLES : [$bundle];

    while ($limit === NULL || $attempted < $limit) {
      $length = $limit === NULL
        ? $batch_size
        : min($batch_size, $limit - $attempted);
      $query = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->condition('type', $bundles, 'IN')
        ->sort('nid')
        ->range($offset, $length);
      $entity_ids = array_values($query->execute());
      if ($entity_ids === []) {
        break;
      }

      $entities = $storage->loadMultiple($entity_ids);
      foreach ($entity_ids as $entity_id) {
        $attempted++;
        $entity = $entities[$entity_id] ?? NULL;
        if (
          !$entity instanceof NodeInterface
          || !$this->mapper->supports($entity)
          || $this->statusResolver->resolve($entity) !== 'published'
        ) {
          $summary['skipped']++;
          continue;
        }

        $summary['processed']++;
        try {
          $content = $this->mapper->map($entity, 'published');
          if ($dry_run) {
            $message = sprintf(
              'Would publish %s version=%d hash=%s',
              $content['id'],
              $content['version'],
              $content['hash'],
            );
            $this->logger->info($message);
            if ($report !== NULL) {
              $report($message);
            }
            continue;
          }

          $this->publisher->publish($this->eventFactory->create($content));
          $summary['sent']++;
          $this->logger->info(
            'Resync published entity {entity_id}.',
            ['entity_id' => $entity_id],
          );
          if ($report !== NULL) {
            $report(sprintf('Sent dr:%s', $entity_id));
          }
        }
        catch (\Throwable $exception) {
          $summary['failed']++;
          $error_type = (new \ReflectionClass($exception))->getShortName();
          $this->logger->error(
            'Resync failed for entity {entity_id} ({error_type}).',
            [
              'entity_id' => $entity_id,
              'error_type' => $error_type,
            ],
          );
          if ($report !== NULL) {
            $report(sprintf(
              'Failed dr:%s (%s)',
              $entity_id,
              $error_type,
            ));
          }
        }
      }

      $loaded_count = count($entity_ids);
      $offset += $loaded_count;
      if ($loaded_count < $length) {
        break;
      }
    }

    return $summary;
  }

  /**
   * Counts excluded content without loading its entities.
   */
  private function countExcludedNodes(?string $bundle): int {
    $storage = $this->nodeStorage();
    $bundles = $bundle === NULL ? self::BUNDLES : [$bundle];
    $unpublished = (int) $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundles, 'IN')
      ->condition('status', 1, '<>')
      ->count()
      ->execute();

    if ($bundle !== NULL) {
      return $unpublished;
    }

    $unsupported = (int) $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::BUNDLES, 'NOT IN')
      ->count()
      ->execute();

    return $unpublished + $unsupported;
  }

  /**
   * Gets node storage.
   */
  private function nodeStorage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('node');
  }

}
