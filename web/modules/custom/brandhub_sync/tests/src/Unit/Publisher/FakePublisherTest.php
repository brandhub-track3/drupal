<?php

namespace Drupal\Tests\brandhub_sync\Unit\Publisher;

use Drupal\brandhub_sync\Publisher\FakePublisher;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the in-memory publisher.
 *
 * @group brandhub_sync
 * @coversDefaultClass \Drupal\brandhub_sync\Publisher\FakePublisher
 */
class FakePublisherTest extends UnitTestCase {

  /**
   * Tests that published records are collected and can be cleared.
   *
   * @covers ::publish
   * @covers ::getMessages
   * @covers ::clear
   */
  public function testCollectsMessages(): void {
    $publisher = new FakePublisher();
    $message = ['id' => 'dr:1', 'status' => 'published'];

    $publisher->publish($message);
    self::assertSame([$message], $publisher->getMessages());

    $publisher->clear();
    self::assertSame([], $publisher->getMessages());
  }

}
