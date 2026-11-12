<?php

namespace Drupal\Tests\brandhub_sync\Unit\Mapper;

use Drupal\brandhub_sync\Mapper\ContentHasher;
use Drupal\Tests\UnitTestCase;

/**
 * Tests deterministic unified content hashing.
 *
 * @group brandhub_sync
 * @coversDefaultClass \Drupal\brandhub_sync\Mapper\ContentHasher
 */
class ContentHasherTest extends UnitTestCase {

  /**
   * Tests object key ordering does not affect the content digest.
   *
   * @covers ::hash
   */
  public function testHashIsDeterministicAcrossObjectKeyOrder(): void {
    $hasher = new ContentHasher();
    $first = [
      'title' => 'Example',
      'tags' => [
        ['name' => 'News', 'id' => 1],
      ],
      'author' => ['name' => 'Editor', 'id' => 2],
      'hash' => 'ignored',
    ];
    $reordered = [
      'author' => ['id' => 2, 'name' => 'Editor'],
      'tags' => [
        ['id' => 1, 'name' => 'News'],
      ],
      'hash' => NULL,
      'title' => 'Example',
    ];

    self::assertSame($hasher->hash($first), $hasher->hash($reordered));
    self::assertMatchesRegularExpression(
      '/^[a-f0-9]{64}$/',
      $hasher->hash($first),
    );
  }

  /**
   * Tests list order remains significant to the content digest.
   *
   * @covers ::hash
   */
  public function testHashPreservesListOrder(): void {
    $hasher = new ContentHasher();

    self::assertNotSame(
      $hasher->hash(['tags' => ['one', 'two']]),
      $hasher->hash(['tags' => ['two', 'one']]),
    );
  }

}
