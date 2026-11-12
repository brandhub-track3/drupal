<?php

namespace Drupal\Tests\brandhub_sync\Unit\Seed;

use Drupal\brandhub_sync\Seed\SeedContentGenerator;
use Drupal\Tests\UnitTestCase;

/**
 * Tests repeatable BrandHub seed content.
 *
 * @group brandhub_sync
 */
class SeedContentGeneratorTest extends UnitTestCase {

  /**
   * Tests default dataset counts and realistic content.
   */
  public function testDefaultDatasetHasExpectedCountsAndContent(): void {
    $generator = new SeedContentGenerator();
    $items = $generator->generate(10, 20, 15);
    $counts = array_count_values(array_column($items, 'bundle'));

    self::assertSame(
      ['product' => 10, 'article' => 20, 'faq' => 15],
      $counts,
    );
    self::assertStringContainsString('Wireless Earbuds', $items[0]['title']);
    self::assertStringNotContainsString('Lorem ipsum', implode(
      ' ',
      array_column($items, 'title'),
    ));
  }

  /**
   * Tests repeated runs produce the same unique node identity set.
   */
  public function testRepeatedRunsCannotGenerateDuplicateSeedIdentities(): void {
    $generator = new SeedContentGenerator();
    $first_run = $generator->generate(2, 3, 2);
    $second_run = $generator->generate(2, 3, 2);
    $first_uuids = array_column($first_run, 'uuid');
    $second_uuids = array_column($second_run, 'uuid');

    self::assertSame($first_run, $second_run);
    self::assertSame($first_uuids, $second_uuids);
    self::assertCount(count(array_unique($first_uuids)), $first_uuids);
    self::assertSame(
      5,
      (int) substr($generator->uuid('node:product:1'), 14, 1),
    );
    self::assertSame(
      $generator->uuid('node:product:1'),
      $generator->uuid('node:product:1'),
    );
  }

  /**
   * Tests generated field names match the actual content type mappings.
   */
  public function testGeneratedFieldsUseExistingDrupalMachineNames(): void {
    $generator = new SeedContentGenerator();
    $items = $generator->generate(1, 1, 1);

    self::assertSame(
      [
        'field_summary',
        'field_body',
        'field_product',
        'field_published_date',
        'field_tag',
      ],
      array_keys($items[0]['fields']),
    );
    self::assertSame(
      [
        'body',
        'field_a_summary',
        'field_a_product',
        'field_a_topic',
        'field_tags',
      ],
      array_keys($items[1]['fields']),
    );
    self::assertSame(
      [
        'field_answer',
        'field_faq_product',
        'field_faq_topic',
        'field_faq_tags',
      ],
      array_keys($items[2]['fields']),
    );
  }

}
