<?php

namespace Drupal\brandhub_sync\Moderation;

use Drupal\node\NodeInterface;

/**
 * Resolves whether a node should be published to the unified content feed.
 */
final class ModerationStatusResolver {

  /**
   * Resolves the unified status for a node lifecycle event.
   *
   * Draft and in-review nodes are omitted. A deleted node is a tombstone only
   * if it was published immediately before deletion.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The Drupal node.
   * @param bool $is_deleted
   *   Whether the node is being deleted.
   *
   * @return string|null
   *   "published", "removed", or NULL when the node is not publishable.
   */
  public function resolve(
    NodeInterface $node,
    bool $is_deleted = FALSE,
  ): ?string {
    if ($node->hasField('moderation_state')) {
      $items = $node->get('moderation_state')->getValue();
      $moderation_state = $items[0]['value'] ?? NULL;
      if ($moderation_state !== NULL && $moderation_state !== 'published') {
        return NULL;
      }
    }

    if (!$node->isPublished()) {
      return NULL;
    }

    return $is_deleted ? 'removed' : 'published';
  }

}
