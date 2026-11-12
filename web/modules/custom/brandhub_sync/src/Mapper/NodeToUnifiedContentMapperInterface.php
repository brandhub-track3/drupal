<?php

namespace Drupal\brandhub_sync\Mapper;

use Drupal\node\NodeInterface;

/**
 * Maps supported nodes to unified content records.
 */
interface NodeToUnifiedContentMapperInterface {

  /**
   * Checks whether a node bundle has a unified-content mapping.
   */
  public function supports(NodeInterface $node): bool;

  /**
   * Maps a supported node to the unified schema.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The Drupal node to map.
   * @param string $status
   *   The unified publication status.
   *
   * @return array<string, mixed>
   *   A unified content record.
   */
  public function map(NodeInterface $node, string $status): array;

}
