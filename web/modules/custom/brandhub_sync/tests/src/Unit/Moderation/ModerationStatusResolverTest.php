<?php

namespace Drupal\Tests\brandhub_sync\Unit\Moderation;

use Drupal\brandhub_sync\Moderation\ModerationStatusResolver;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests moderation-aware publication status resolution.
 *
 * @group brandhub_sync
 * @coversDefaultClass \Drupal\brandhub_sync\Moderation\ModerationStatusResolver
 */
class ModerationStatusResolverTest extends UnitTestCase {

  /**
   * Tests that a published node is publishable.
   *
   * @covers ::resolve
   */
  public function testPublishedNodeIsPublishable(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')
      ->with('moderation_state')
      ->willReturn(FALSE);
    $node->method('isPublished')->willReturn(TRUE);

    self::assertSame(
      'published',
      (new ModerationStatusResolver())->resolve($node),
    );
  }

  /**
   * Tests that a node in review is not published to the feed.
   *
   * @covers ::resolve
   */
  public function testInReviewNodeIsNotPublishable(): void {
    $moderation_state = $this->createMock(FieldItemListInterface::class);
    $moderation_state->method('getValue')->willReturn([
      ['value' => 'in_review'],
    ]);
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')
      ->with('moderation_state')
      ->willReturn(TRUE);
    $node->method('get')
      ->with('moderation_state')
      ->willReturn($moderation_state);
    $node->expects(self::never())->method('isPublished');

    self::assertNull((new ModerationStatusResolver())->resolve($node));
  }

  /**
   * Tests that deletion produces a tombstone regardless of moderation state.
   *
   * @covers ::resolve
   */
  public function testDeletedNodeProducesRemovedStatus(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->expects($this->once())
      ->method('hasField')
      ->with('moderation_state')
      ->willReturn(FALSE);
    $node->method('isPublished')->willReturn(TRUE);

    self::assertSame(
      'removed',
      (new ModerationStatusResolver())->resolve($node, TRUE),
    );
  }

}
