<?php

namespace Drupal\brandhub_sync\Handler;

use Drupal\brandhub_sync\Mapper\NodeToUnifiedContentMapperInterface;
use Drupal\brandhub_sync\Moderation\ContentChangeDecider;
use Drupal\brandhub_sync\Publisher\ContentChangedEventFactory;
use Drupal\brandhub_sync\Publisher\PublisherInterface;
use Drupal\node\NodeInterface;

final class EntityChangeHandler {

  private \WeakMap $processed;

  public function __construct(
    private readonly NodeToUnifiedContentMapperInterface $mapper,
    private readonly ContentChangeDecider $changeDecider,
    private readonly ContentChangedEventFactory $eventFactory,
    private readonly PublisherInterface $publisher,
  ) {
    $this->processed = new \WeakMap();
  }


  public function insert(NodeInterface $node): void {
    $this->publish($node, 'insert', $this->changeDecider->onInsert($node));
  }

  public function update(NodeInterface $node, ?NodeInterface $original): void {
    if (!$node->isDefaultRevision()) {
      return;
    }

    $this->publish(
      $node,
      'update',
      $this->changeDecider->onUpdate($node, $original),
    );
  }

  /**
   * Handles a deleted node, publishing a tombstone only if it was published.
   */
  public function delete(NodeInterface $node): void {
    $this->publish($node, 'delete', $this->changeDecider->onDelete($node));
  }

  /**
   * Publishes one content.changed event for an eligible lifecycle hook.
   */
  private function publish(
    NodeInterface $node,
    string $operation,
    ?string $status,
  ): void {
    if (!$this->mapper->supports($node)) {
      return;
    }
    if ($status === NULL) {
      return;
    }

    $record = $this->mapper->map($node, $status);
    $signature = implode(':', [
      $operation,
      (string) $node->id(),
      (string) $node->getRevisionId(),
      (string) $node->getChangedTime(),
      $status,
      $record['hash'],
    ]);
    $processed = $this->processed[$node] ?? [];
    if (isset($processed[$signature])) {
      return;
    }

    $event = $this->eventFactory->create($record);
    $this->publisher->publish($event);

    $processed[$signature] = TRUE;
    $this->processed[$node] = $processed;
  }

}
