<?php

namespace Drupal\Tests\brandhub_sync\Kernel;

use Drupal\brandhub_sync\Publisher\FakeClaimCheckStorage;
use Drupal\brandhub_sync\Publisher\FakePublisher;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the mapper and lifecycle hooks with real Drupal entities.
 *
 * @group brandhub_sync
 */
class BrandhubSyncKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * @var array
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'taxonomy',
    'brandhub_sync',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    if (!getenv('SIMPLETEST_DB')) {
      putenv('SIMPLETEST_DB=sqlite://localhost/:memory:');
    }

    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installConfig([
      'system',
      'user',
      'filter',
      'node',
      'taxonomy',
      'brandhub_sync',
    ]);
    $admin = User::create([
      'uid' => 1,
      'name' => 'kernel-test-admin',
      'status' => 1,
    ]);
    $admin->save();
    $this->container->get('current_user')->setAccount($admin);
    FilterFormat::create([
      'format' => 'basic_html',
      'name' => 'Basic HTML',
      'filters' => [],
    ])->save();

    foreach (['product', 'article', 'faq', 'page'] as $bundle) {
      NodeType::create([
        'type' => $bundle,
        'name' => ucfirst($bundle),
      ])->save();
    }
    foreach (['product', 'topic', 'tags'] as $vocabulary) {
      Vocabulary::create([
        'vid' => $vocabulary,
        'name' => ucfirst($vocabulary),
      ])->save();
    }

    $this->createField('product', 'field_summary', 'string');
    $this->createField('product', 'field_body', 'text_long');
    $this->createField('product', 'field_published_date', 'timestamp', [], TRUE);
    $this->createField('product', 'moderation_state', 'string');
    $this->createReferenceField('product', 'field_product', ['product']);
    $this->createReferenceField('product', 'field_tag', ['tags'], -1);

    $this->createField('article', 'field_a_summary', 'string');
    $this->createField('article', 'body', 'text_with_summary');
    $this->createReferenceField('article', 'field_a_product', ['product']);
    $this->createReferenceField('article', 'field_a_topic', ['topic']);
    $this->createReferenceField('article', 'field_tags', ['tags'], -1);

    $this->createField('faq', 'field_answer', 'text_long');
    $this->createReferenceField('faq', 'field_faq_product', ['product']);
    $this->createReferenceField('faq', 'field_faq_topic', ['topic']);
    $this->createReferenceField('faq', 'field_faq_tags', ['tags']);

    foreach (['product', 'article', 'faq'] as $bundle) {
      $display = $this->container
        ->get('entity_display.repository')
        ->getViewDisplay('node', $bundle, 'default');
      foreach (
        $this->container
          ->get('entity_field.manager')
          ->getFieldDefinitions('node', $bundle) as $name => $definition
      ) {
        if (!$definition instanceof FieldConfig) {
          continue;
        }
        $formatter = match ($definition->getType()) {
          'text_long', 'text_with_summary' => 'text_default',
          'entity_reference' => 'entity_reference_label',
          default => 'string',
        };
        $display->setComponent($name, ['type' => $formatter]);
      }
      $display->save();
    }

    $this->container->set(
      'brandhub_sync.claim_check_storage.s3',
      new FakeClaimCheckStorage(),
    );
    $this->container->set(
      'brandhub_sync.publisher.sqs',
      $this->container->get('brandhub_sync.publisher.fake'),
    );
  }

  /**
   * Tests mapped output for each bundle, defaults, references, dates, and hash.
   */
  public function testProductArticleAndFaqMapToUnifiedContent(): void {
    $product_term = $this->term('product', 'Aurora Earbuds');
    $tag = $this->term('tags', 'Audio');
    $topic = $this->term('topic', 'Buying guides');
    $mapper = $this->container->get('brandhub_sync.mapper');

    $product = Node::create([
      'type' => 'product',
      'title' => 'Aurora Wireless Earbuds',
      'status' => 1,
      'created' => 1_700_000_000,
      'changed' => 1_700_000_100,
      'field_summary' => 'Comfortable audio for everyday listening.',
      'field_body' => [
        'value' => '<p>Clear calls and reliable sound.</p>',
        'format' => 'basic_html',
      ],
      'field_published_date' => 1_700_000_000,
      'field_product' => ['target_id' => $product_term->id()],
      'field_tag' => [['target_id' => $tag->id()]],
    ]);
    $product->save();
    $product_record = $mapper->map($product, 'published');

    self::assertSame('product', $product_record['type']);
    self::assertSame('dr:' . $product->id(), $product_record['id']);
    self::assertSame(
      $product->toUrl('canonical', ['absolute' => TRUE])->toString(),
      $product_record['url'],
    );
    self::assertSame(
      ['id' => (int) $product_term->id(), 'name' => 'Aurora Earbuds'],
      $product_record['product'],
    );
    self::assertSame(
      [['id' => (int) $tag->id(), 'name' => 'Audio']],
      $product_record['tags'],
    );
    self::assertSame('2023-11-14T22:13:20+00:00', $product_record['publishedAt']);
    self::assertSame((int) $product->getRevisionId(), $product_record['version']);
    self::assertSame($this->expectedHash($product_record), $product_record['hash']);

    $article = Node::create([
      'type' => 'article',
      'title' => 'A practical audio setup',
      'status' => 1,
      'created' => 1_700_000_000,
      'changed' => 1_700_000_200,
      'field_a_summary' => 'A short setup guide.',
      'body' => [
        'value' => '<p>Place the microphone near the speaker.</p>',
        'summary' => 'A short setup guide.',
        'format' => 'basic_html',
      ],
      'field_a_product' => ['target_id' => $product_term->id()],
      'field_a_topic' => ['target_id' => $topic->id()],
      'field_tags' => [['target_id' => $tag->id()]],
    ]);
    $article->save();
    $article_record = $mapper->map($article, 'published');

    self::assertSame('article', $article_record['type']);
    self::assertSame(
      $article->toUrl('canonical', ['absolute' => TRUE])->toString(),
      $article_record['url'],
    );
    self::assertSame('A short setup guide.', $article_record['summary']);
    self::assertSame(
      ['id' => (int) $product_term->id(), 'name' => 'Aurora Earbuds'],
      $article_record['product'],
    );
    self::assertSame(
      [['id' => (int) $tag->id(), 'name' => 'Audio']],
      $article_record['tags'],
    );
    self::assertSame('2023-11-14T22:16:40+00:00', $article_record['updatedAt']);
    self::assertSame($this->expectedHash($article_record), $article_record['hash']);

    $faq = Node::create([
      'type' => 'faq',
      'title' => 'How do I position my earbuds?',
      'status' => 1,
      'created' => 1_700_000_000,
      'changed' => 1_700_000_300,
      'field_answer' => [
        'value' => '<p>Choose a comfortable fit.</p>',
        'format' => 'basic_html',
      ],
      'field_faq_product' => ['target_id' => $product_term->id()],
      'field_faq_topic' => ['target_id' => $topic->id()],
      'field_faq_tags' => ['target_id' => $tag->id()],
    ]);
    $faq->save();
    $faq_record = $mapper->map($faq, 'published');

    self::assertSame('faq', $faq_record['type']);
    self::assertSame(
      $faq->toUrl('canonical', ['absolute' => TRUE])->toString(),
      $faq_record['url'],
    );
    self::assertNotNull($faq_record['bodyHtml']);
    self::assertSame('Choose a comfortable fit.', $faq_record['summary']);
    self::assertNull($faq_record['heroImage']);
    self::assertSame(
      ['id' => (int) $product_term->id(), 'name' => 'Aurora Earbuds'],
      $faq_record['product'],
    );
    self::assertSame($this->expectedHash($faq_record), $faq_record['hash']);
    self::assertSame($faq_record['hash'], $mapper->map($faq, 'published')['hash']);

    $empty_faq = Node::create([
      'type' => 'faq',
      'title' => 'An FAQ with optional fields omitted',
      'status' => 0,
    ]);
    $empty_faq->save();
    $empty_record = $mapper->map($empty_faq, 'removed');
    self::assertNull($empty_record['bodyHtml']);
    self::assertSame('', $empty_record['plainText']);
    self::assertSame('', $empty_record['summary']);
    self::assertSame([], $empty_record['tags']);
    self::assertNull($empty_record['product']);
    self::assertNull($empty_record['heroImage']);
  }

  /**
   * Tests actual Drupal save hooks publish only eligible node transitions.
   */
  public function testLifecycleHooksPublishOnlyExpectedTransitions(): void {
    $transport = $this->container->get('brandhub_sync.publisher.fake');
    self::assertInstanceOf(FakePublisher::class, $transport);
    $this->container->set('brandhub_sync.publisher.transport', $transport);

    $node = Node::create([
      'type' => 'product',
      'title' => 'Draft product',
      'status' => 0,
      'field_summary' => 'A useful product summary.',
      'field_published_date' => 1_700_000_000,
    ]);
    $node->save();
    self::assertCount(0, $transport->getMessages());

    $node->setTitle('Updated draft product');
    $node->save();
    self::assertCount(0, $transport->getMessages());

    $node->setPublished();
    $node->save();
    self::assertCount(1, $transport->getMessages());
    self::assertSame(
      'published',
      $transport->getMessages()[0]['content']['status'],
    );

    $in_review = Node::create([
      'type' => 'product',
      'title' => 'Product in review',
      'status' => 0,
      'field_summary' => 'A reviewed product summary.',
      'field_published_date' => 1_700_000_000,
      'moderation_state' => 'in_review',
    ]);
    $in_review->save();
    self::assertCount(1, $transport->getMessages());
    $in_review->setPublished();
    $in_review->set('moderation_state', 'published');
    $in_review->save();
    self::assertCount(2, $transport->getMessages());

    $original = clone $node;
    $node->setTitle('Published product update');
    $node->save();
    self::assertCount(3, $transport->getMessages());
    $this->container
      ->get('brandhub_sync.entity_change_handler')
      ->update($node, $original);
    self::assertCount(3, $transport->getMessages());

    $node->setUnpublished();
    $node->save();
    self::assertCount(4, $transport->getMessages());
    self::assertSame(
      'removed',
      $transport->getMessages()[3]['content']['status'],
    );

    $draft = Node::create([
      'type' => 'product',
      'title' => 'Never-published product',
      'status' => 0,
      'field_summary' => 'A draft summary.',
      'field_published_date' => 1_700_000_000,
    ]);
    $draft->save();
    $draft->delete();
    self::assertCount(4, $transport->getMessages());

    $unsupported = Node::create([
      'type' => 'page',
      'title' => 'Unsupported page',
      'status' => 1,
    ]);
    $unsupported->save();
    self::assertCount(4, $transport->getMessages());

    $published = Node::create([
      'type' => 'product',
      'title' => 'Published product to delete',
      'status' => 1,
      'field_summary' => 'A published product summary.',
      'field_published_date' => 1_700_000_000,
    ]);
    $published->save();
    self::assertCount(5, $transport->getMessages());
    $published->delete();
    self::assertCount(6, $transport->getMessages());
    self::assertSame(
      'removed',
      $transport->getMessages()[5]['content']['status'],
    );
  }

  /**
   * Creates and attaches a field to a node bundle.
   *
   * @param string $bundle
   *   Node bundle.
   * @param string $name
   *   Field machine name.
   * @param string $type
   *   Field type.
   * @param array<string, mixed> $settings
   *   Field storage settings.
   * @param bool $required
   *   Whether the field is required.
   */
  private function createField(
    string $bundle,
    string $name,
    string $type,
    array $settings = [],
    bool $required = FALSE,
  ): void {
    $storage = FieldStorageConfig::loadByName('node', $name);
    if ($storage === NULL) {
      $storage = FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => $type,
        'settings' => $settings,
      ]);
      $storage->save();
    }

    if (FieldConfig::loadByName('node', $bundle, $name) === NULL) {
      FieldConfig::create([
        'field_storage' => $storage,
        'bundle' => $bundle,
        'label' => $name,
        'required' => $required,
      ])->save();
    }
  }

  /**
   * Creates an entity reference field targeting selected taxonomies.
   *
   * @param string $bundle
   *   Node bundle.
   * @param string $name
   *   Field machine name.
   * @param list<string> $target_bundles
   *   Taxonomy vocabulary IDs.
   * @param int $cardinality
   *   Field cardinality.
   */
  private function createReferenceField(
    string $bundle,
    string $name,
    array $target_bundles,
    int $cardinality = 1,
  ): void {
    $storage = FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => $cardinality,
      'settings' => ['target_type' => 'taxonomy_term'],
    ]);
    $storage->save();

    $allowed = array_combine($target_bundles, $target_bundles);
    FieldConfig::create([
      'field_storage' => $storage,
      'bundle' => $bundle,
      'label' => $name,
      'settings' => [
        'handler' => 'default',
        'handler_settings' => ['target_bundles' => $allowed],
      ],
    ])->save();
  }

  /**
   * Creates and saves a taxonomy term.
   */
  private function term(string $vocabulary, string $name): Term {
    $term = Term::create([
      'vid' => $vocabulary,
      'name' => $name,
    ]);
    $term->save();
    return $term;
  }

  /**
   * Calculates the expected deterministic hash for a mapped record.
   *
   * @param array<string, mixed> $record
   *   Unified record.
   */
  private function expectedHash(array $record): string {
    unset($record['hash']);
    return $this->container
      ->get('brandhub_sync.content_hasher')
      ->hash($record);
  }

}
