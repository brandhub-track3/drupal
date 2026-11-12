<?php

namespace Drupal\brandhub_sync\Moderation;

use Drupal\node\NodeInterface;


final class ContentChangeDecider {

  public function __construct(
    private readonly ModerationStatusResolver $statusResolver,
  ) {}

  public function onInsert(NodeInterface $current): ?string {
    return $this->statusResolver->resolve($current);
  }


  public function onUpdate(
    NodeInterface $current,
    ?NodeInterface $original,
  ): ?string {
    if ($original === NULL) {
      return NULL;
    }

    $was_published = $this->statusResolver->resolve($original) === 'published';
    $is_published = $this->statusResolver->resolve($current) === 'published';

    if ($is_published) {
      return 'published';
    }

    return $was_published ? 'removed' : NULL;
  }

  /**
   * Determines whether deleting a node should publish a tombstone.
   */
  public function onDelete(NodeInterface $node): ?string {
    return $this->statusResolver->resolve($node) === 'published'
      ? 'removed'
      : NULL;
  }

}
