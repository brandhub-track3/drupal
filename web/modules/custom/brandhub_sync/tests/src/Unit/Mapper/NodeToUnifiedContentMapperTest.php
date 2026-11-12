<?php

namespace Drupal\Tests\brandhub_sync\Unit\Mapper;

use Drupal\brandhub_sync\Mapper\ContentHasher;
use Drupal\brandhub_sync\Mapper\NodeToUnifiedContentMapper;
use Drupal\Core\Url;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;

/**
 * Tests mapping Drupal nodes to unified content.
 *
 * @group brandhub_sync
 * @coversDefaultClass \Drupal\brandhub_sync\Mapper\NodeToUnifiedContentMapper
 */
class NodeToUnifiedContentMapperTest extends UnitTestCase {

  /**
   * Tests FAQ mapping and its missing summary and image defaults.
   *
   * @covers ::map
   */
  public function testMapsFaqWithPlainTextSummaryFallback(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('getType')->willReturn('faq');
    $node->method('id')->willReturn('303');
    $node->method('label')->willReturn('Example FAQ');
    $node->method('isPublished')->willReturn(TRUE);
    $node->method('getCreatedTime')->willReturn(100);
    $node->method('getChangedTime')->willReturn(200);
    $node->method('getRevisionId')->willReturn(7);
    $node->method('getOwnerId')->willReturn(1);
    $url = $this->createMock(Url::class);
    $url->method('toString')->willReturn('https://example.test/node/303');
    $node->expects(self::once())
      ->method('toUrl')
      ->with('canonical', ['absolute' => TRUE])
      ->willReturn($url);
    $node->method('hasField')->willReturnCallback(
      static fn(string $field_name): bool => $field_name === 'field_answer',
    );

    $answer = $this->createMock(FieldItemListInterface::class);
    $answer->method('isEmpty')->willReturn(FALSE);
    $answer->method('view')->willReturn(['#markup' => 'not rendered directly']);
    $node->method('get')->with('field_answer')->willReturn($answer);

    $renderer = $this->createMock(RendererInterface::class);
    $renderer->expects(self::once())
      ->method('renderInIsolation')
      ->willReturn(Markup::create('<p> The answer is here. </p>'));

    $user = $this->createMock(UserInterface::class);
    $user->method('getDisplayName')->willReturn('site-editor');
    $user_storage = $this->createMock(EntityStorageInterface::class);
    $user_storage->method('load')->with(1)->willReturn($user);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects(self::once())
      ->method('getStorage')
      ->with('user')
      ->willReturn($user_storage);

    $mapper = new NodeToUnifiedContentMapper(
      $renderer,
      $this->createMock(FileUrlGeneratorInterface::class),
      $entity_type_manager,
      new ContentHasher(),
    );

    $record = $mapper->map($node, 'published');

    self::assertSame('dr:303', $record['id']);
    self::assertSame('https://example.test/node/303', $record['url']);
    self::assertSame('faq', $record['type']);
    self::assertSame('The answer is here.', $record['summary']);
    self::assertSame('<p> The answer is here. </p>', $record['bodyHtml']);
    self::assertSame('The answer is here.', $record['plainText']);
    self::assertSame([], $record['tags']);
    self::assertNull($record['product']);
    self::assertSame(['id' => 1, 'name' => 'site-editor'], $record['author']);
    self::assertNull($record['heroImage']);
    self::assertSame('1970-01-01T00:01:40+00:00', $record['publishedAt']);
    self::assertSame('1970-01-01T00:03:20+00:00', $record['updatedAt']);
    self::assertSame(7, $record['version']);
    self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $record['hash']);
    self::assertSame('published', $record['status']);
    self::assertSame('1.0', $record['schemaVersion']);
  }

  /**
   * Tests that unsupported bundles are rejected.
   *
   * @covers ::map
   */
  public function testRejectsUnsupportedBundle(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('getType')->willReturn('page');
    $mapper = new NodeToUnifiedContentMapper(
      $this->createMock(RendererInterface::class),
      $this->createMock(FileUrlGeneratorInterface::class),
      $this->createMock(EntityTypeManagerInterface::class),
      new ContentHasher(),
    );

    $this->expectException(\InvalidArgumentException::class);
    $mapper->map($node, 'published');
  }

}
