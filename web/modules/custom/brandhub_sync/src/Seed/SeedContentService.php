<?php

namespace Drupal\brandhub_sync\Seed;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Psr\Log\LoggerInterface;

/**
 * Creates, updates, and safely removes repeatable BrandHub seed records.
 */
final class SeedContentService {

  /**
   * State key holding seed-owned node and term UUIDs.
   */
  private const REGISTRY_KEY = 'brandhub_sync.seed_registry';

  /**
   * Default requested dataset.
   *
   * @var array{products: int, articles: int, faqs: int}
   */
  private const DEFAULT_COUNTS = [
    'products' => 10,
    'articles' => 20,
    'faqs' => 15,
  ];

  /**
   * Reference fields used when checking whether an owned term is still used.
   *
   * @var array<string, list<string>>
   */
  private const TERM_REFERENCE_FIELDS = [
    'product' => ['field_product', 'field_a_product', 'field_faq_product'],
    'topic' => ['field_a_topic', 'field_faq_topic'],
    'tags' => ['field_tag', 'field_tags', 'field_faq_tags'],
  ];

  /**
   * Constructs the seed service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $fieldManager,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly StateInterface $state,
    private readonly SeedContentGenerator $generator,
    private readonly SeedExecutionContext $seedContext,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('brandhub_sync');
  }

  /**
   * Logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private readonly LoggerInterface $logger;

  /**
   * Applies the requested seed dataset or removes only registered seed data.
   *
   * @param array<string, mixed> $counts
   *   Requested counts. Unspecified counts use the project dataset defaults.
   * @param bool $publish
   *   Whether seeded nodes should be in the published moderation state.
   * @param bool $dry_run
   *   Whether to report planned actions without changing Drupal data.
   * @param bool $reset
   *   Whether to remove only data recorded in the seed registry.
   *
   * @return array{created: int, updated: int, skipped: int, failed: int, deleted: int}
   *   Operation counts.
   */
  public function run(
    array $counts = [],
    bool $publish = FALSE,
    bool $dry_run = FALSE,
    bool $reset = FALSE,
  ): array {
    $counts = $this->normalizeCounts($counts);
    $summary = [
      'created' => 0,
      'updated' => 0,
      'skipped' => 0,
      'failed' => 0,
      'deleted' => 0,
    ];

    if ($reset) {
      $this->reset($summary, $dry_run);
      return $summary;
    }

    $items = $this->generator->generate(
      $counts['products'],
      $counts['articles'],
      $counts['faqs'],
    );
    try {
      $terms = $this->ensureTerms($dry_run);
    }
    catch (\Throwable) {
      $summary['failed']++;
      return $summary;
    }
    $registry = $this->registry();

    foreach ($items as $item) {
      try {
        $node_storage = $this->entityTypeManager->getStorage('node');
        $existing = $this->loadNodeByUuid($node_storage, $item['uuid']);
        if ($existing !== NULL && $existing->bundle() !== $item['bundle']) {
          throw new \UnexpectedValueException(
            'A seed UUID is already used by an unexpected content bundle.',
          );
        }

        $is_new = $existing === NULL;
        $node = $existing ?? $node_storage->create([
          'type' => $item['bundle'],
          'uuid' => $item['uuid'],
          'title' => $item['title'],
        ]);
        $field_values = $this->resolveFieldValues($item, $terms);
        $changed = $this->applyNodeData(
          $node,
          $item['title'],
          $field_values,
          $publish,
        );
        $this->withAdministratorAccount(function () use (
          $node,
          $is_new,
          $changed,
          $dry_run,
        ): void {
          $this->validateRequiredFields($node);
          if (!$dry_run && ($is_new || $changed)) {
            $this->seedContext->run(static fn() => $node->save());
          }
        });

        if ($dry_run) {
          $summary[$is_new ? 'created' : ($changed ? 'updated' : 'skipped')]++;
        }
        elseif ($is_new || $changed) {
          $summary[$is_new ? 'created' : 'updated']++;
        }
        else {
          $summary['skipped']++;
        }

        $registry['nodes'][$item['uuid']] = $item['bundle'];
      }
      catch (\Throwable $exception) {
        $summary['failed']++;
        $this->logFailure('node', $item['uuid'], $exception);
      }
    }

    if (!$dry_run) {
      $this->state->set(self::REGISTRY_KEY, $registry);
    }

    return $summary;
  }

  /**
   * Validates requested counts and fills unspecified dataset defaults.
   *
   * @param array<string, mixed> $counts
   *   Requested counts.
   *
   * @return array{products: int, articles: int, faqs: int}
   *   Validated counts.
   */
  private function normalizeCounts(array $counts): array {
    $normalized = [];
    foreach (self::DEFAULT_COUNTS as $key => $maximum) {
      $value = $counts[$key] ?? $maximum;
      if (!is_int($value) || $value < 0 || $value > 1000) {
        throw new \InvalidArgumentException(sprintf(
          '%s must be an integer between 0 and 1000.',
          $key,
        ));
      }
      if ($value > $maximum) {
        throw new \InvalidArgumentException(sprintf(
          '%s cannot exceed the %d available seed records.',
          $key,
          $maximum,
        ));
      }
      $normalized[$key] = $value;
    }

    return $normalized;
  }

  /**
   * Gets or creates terms and returns IDs keyed by vocabulary and term name.
   *
   * @return array<string, array<string, int>>
   *   Taxonomy term IDs.
   */
  private function ensureTerms(bool $dry_run): array {
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $term_ids = [];
    $registry = $this->registry();
    foreach ($this->generator->taxonomyTerms() as $vocabulary => $names) {
      foreach ($names as $name) {
        $matches = $term_storage->loadByProperties([
          'vid' => $vocabulary,
          'name' => $name,
        ]);
        $term = reset($matches);
        if ($term instanceof TermInterface) {
          $term_ids[$vocabulary][$name] = (int) $term->id();
          $seed_uuid = $this->generator->uuid(
            'term:' . $vocabulary . ':' . mb_strtolower($name),
          );
          if ($term->uuid() === $seed_uuid) {
            $registry['terms'][$seed_uuid] = $vocabulary;
          }
          continue;
        }

        if ($dry_run) {
          continue;
        }

        try {
          $seed_uuid = $this->generator->uuid(
            'term:' . $vocabulary . ':' . mb_strtolower($name),
          );
          $term = $term_storage->create([
            'vid' => $vocabulary,
            'name' => $name,
            'uuid' => $seed_uuid,
          ]);
          $term->save();
          $term_ids[$vocabulary][$name] = (int) $term->id();
          $registry['terms'][$seed_uuid] = $vocabulary;
        }
        catch (\Throwable $exception) {
          $this->logFailure('term', $vocabulary . ':' . $name, $exception);
          throw $exception;
        }
      }
    }

    if (!$dry_run) {
      $this->state->set(self::REGISTRY_KEY, $registry);
    }

    return $term_ids;
  }

  /**
   * Finds one node by its stable seed UUID.
   */
  private function loadNodeByUuid(
    object $storage,
    string $uuid,
  ): ?NodeInterface {
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uuid', $uuid)
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }

    $node = $storage->load(reset($ids));
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Converts symbolic taxonomy references to Drupal target IDs.
   *
   * @param array<string, mixed> $item
   *   Generated seed item.
   * @param array<string, array<string, int>> $terms
   *   Resolved terms.
   *
   * @return array<string, list<array<string, mixed>>>
   *   Field values ready for a node entity.
   */
  private function resolveFieldValues(array $item, array $terms): array {
    $field_values = [];
    foreach ($item['fields'] as $field_name => $values) {
      foreach ($values as $value) {
        if (isset($value['term'])) {
          $vocabulary = $this->vocabularyForField($item['bundle'], $field_name);
          if (isset($terms[$vocabulary][$value['term']])) {
            $field_values[$field_name][] = [
              'target_id' => $terms[$vocabulary][$value['term']],
            ];
          }
          continue;
        }
        $field_values[$field_name][] = $value;
      }
    }

    return $field_values;
  }

  /**
   * Resolves the vocabulary configured for a mapped taxonomy field.
   */
  private function vocabularyForField(string $bundle, string $field_name): string {
    $vocabulary = match ($field_name) {
      'field_product', 'field_a_product', 'field_faq_product' => 'product',
      'field_a_topic', 'field_faq_topic' => 'topic',
      'field_tag', 'field_tags', 'field_faq_tags' => 'tags',
      default => NULL,
    };
    if ($vocabulary === NULL) {
      throw new \UnexpectedValueException(
        'No taxonomy vocabulary mapping exists for a generated field.',
      );
    }

    $definitions = $this->fieldManager->getFieldDefinitions('node', $bundle);
    $definition = $definitions[$field_name] ?? NULL;
    $allowed = $definition?->getSetting('handler_settings')['target_bundles'] ?? [];
    if (!isset($allowed[$vocabulary]) && !in_array($vocabulary, $allowed, TRUE)) {
      throw new \UnexpectedValueException(
        'The generated taxonomy mapping does not match the Drupal field.',
      );
    }

    return $vocabulary;
  }

  /**
   * Applies generated values and reports whether an existing node changed.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Node being seeded.
   * @param string $title
   *   Generated title.
   * @param array<string, list<array<string, mixed>>> $field_values
   *   Field values using the site's actual field machine names.
   * @param bool $publish
   *   Whether the node should be published.
   */
  private function applyNodeData(
    NodeInterface $node,
    string $title,
    array $field_values,
    bool $publish,
  ): bool {
    $changed = (string) $node->label() !== $title
      || $node->isPublished() !== $publish;
    $node->setTitle($title);

    foreach ($field_values as $field_name => $values) {
      if (!$node->hasField($field_name)) {
        throw new \UnexpectedValueException(
          sprintf('The configured %s field does not exist.', $field_name),
        );
      }
      if (
        !$this->fieldValuesMatch(
          $node->get($field_name)->getValue(),
          $values,
        )
      ) {
        $changed = TRUE;
      }
      $node->set($field_name, $values);
    }

    $moderation_state = $publish ? 'published' : 'draft';
    if ($node->hasField('moderation_state')) {
      $state_field = $node->get('moderation_state');
      $current_state = $state_field->isEmpty()
        ? NULL
        : $state_field->value;
      if ($current_state !== $moderation_state) {
        $changed = TRUE;
      }
      $node->set('moderation_state', $moderation_state);
    }
    if ($publish) {
      $node->setPublished();
    }
    else {
      $node->setUnpublished();
    }

    return $changed;
  }

  /**
   * Compares Drupal field values while ignoring storage-added defaults.
   *
   * @param array<int, array<string, mixed>> $current
   *   Stored field values.
   * @param array<int, array<string, mixed>> $expected
   *   Generated field values.
   */
  private function fieldValuesMatch(array $current, array $expected): bool {
    $normalize = static function (array $items): array {
      foreach ($items as &$item) {
        foreach ($item as &$value) {
          if (is_scalar($value)) {
            $value = (string) $value;
          }
        }
        unset($value);
        ksort($item);
      }
      unset($item);
      return $items;
    };

    return $normalize($current) === $normalize($expected);
  }

  /**
   * Validates all currently required bundle fields before an entity save.
   */
  private function validateRequiredFields(NodeInterface $node): void {
    $definitions = $this->fieldManager->getFieldDefinitions(
      'node',
      $node->bundle(),
    );
    foreach ($definitions as $name => $definition) {
      if (
        $definition->isRequired()
        && $node->hasField($name)
        && $node->get($name)->isEmpty()
      ) {
        throw new \UnexpectedValueException(sprintf(
          'Required Drupal field "%s" is empty.',
          $name,
        ));
      }
    }

    $violations = $node->validate();
    if ($violations->count() > 0) {
      $first_violation = $violations->get(0);
      throw new \UnexpectedValueException(sprintf(
        'Drupal validation failed for field "%s".',
        $first_violation->getPropertyPath(),
      ));
    }
  }

  /**
   * Deletes only UUIDs registered by this seed service.
   *
   * @param array $summary
   *   Operation counts updated in place.
   * @param bool $dry_run
   *   Whether to report without deleting.
   */
  private function reset(array &$summary, bool $dry_run): void {
    $registry = $this->registry();
    $remaining_registry = ['nodes' => [], 'terms' => []];
    $node_storage = $this->entityTypeManager->getStorage('node');
    foreach ($registry['nodes'] as $uuid => $bundle) {
      try {
        $node = $this->loadNodeByUuid($node_storage, $uuid);
        if ($node === NULL) {
          continue;
        }
        if ($node->bundle() !== $bundle) {
          $summary['skipped']++;
          $remaining_registry['nodes'][$uuid] = $bundle;
          continue;
        }
        if (!$dry_run) {
          $this->seedContext->run(static fn() => $node->delete());
        }
        $summary['deleted']++;
      }
      catch (\Throwable $exception) {
        $summary['failed']++;
        $this->logFailure('node', $uuid, $exception);
        $remaining_registry['nodes'][$uuid] = $bundle;
      }
    }

    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    foreach ($registry['terms'] as $uuid => $vocabulary) {
      try {
        $matches = $term_storage->loadByProperties(['uuid' => $uuid]);
        $term = reset($matches);
        if (!$term instanceof TermInterface) {
          continue;
        }
        if ($term->bundle() !== $vocabulary) {
          $summary['skipped']++;
          $remaining_registry['terms'][$uuid] = $vocabulary;
          continue;
        }
        if ($this->termIsReferenced(
          (int) $term->id(),
          $vocabulary,
          $dry_run ? array_keys($registry['nodes']) : [],
        )) {
          $summary['skipped']++;
          $remaining_registry['terms'][$uuid] = $vocabulary;
          continue;
        }
        if (!$dry_run) {
          $term->delete();
        }
        $summary['deleted']++;
      }
      catch (\Throwable $exception) {
        $summary['failed']++;
        $this->logFailure('term', $uuid, $exception);
        $remaining_registry['terms'][$uuid] = $vocabulary;
      }
    }

    if (!$dry_run) {
      $this->state->set(self::REGISTRY_KEY, $remaining_registry);
    }
  }

  /**
   * Checks references on supported content before deleting an owned term.
   */
  private function termIsReferenced(
    int $term_id,
    string $vocabulary,
    array $deleting_node_uuids = [],
  ): bool {
    $node_storage = $this->entityTypeManager->getStorage('node');
    foreach (['product', 'article', 'faq'] as $bundle) {
      $definitions = $this->fieldManager->getFieldDefinitions('node', $bundle);
      foreach (self::TERM_REFERENCE_FIELDS[$vocabulary] ?? [] as $field_name) {
        if (!isset($definitions[$field_name])) {
          continue;
        }
        $query = $node_storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('type', $bundle)
          ->condition($field_name, $term_id);
        $offset = 0;
        do {
          $ids = $query->range($offset, 50)->execute();
          foreach ($node_storage->loadMultiple($ids) as $node) {
            if (!in_array($node->uuid(), $deleting_node_uuids, TRUE)) {
              return TRUE;
            }
          }
          $offset += count($ids);
        } while (count($ids) === 50);
      }
    }

    return FALSE;
  }

  /**
   * Gets a normalized registry, tolerating an empty initial state.
   *
   * @return array{nodes: array<string, string>, terms: array<string, string>}
   *   Registered seed records.
   */
  private function registry(): array {
    $registry = $this->state->get(self::REGISTRY_KEY, []);
    return [
      'nodes' => is_array($registry['nodes'] ?? NULL)
        ? $registry['nodes']
        : [],
      'terms' => is_array($registry['terms'] ?? NULL)
        ? $registry['terms']
        : [],
    ];
  }

  /**
   * Logs a safe failure summary without exception messages or content.
   */
  private function logFailure(
    string $entity_type,
    string $identifier,
    \Throwable $exception,
  ): void {
    $this->logger->error(
      'Seed {entity_type} operation failed for {identifier} ({error_type}).',
      [
        'entity_type' => $entity_type,
        'identifier' => $identifier,
        'error_type' => (new \ReflectionClass($exception))->getShortName(),
      ],
    );
  }

  /**
   * Runs moderation validation and node writes as the site administrator.
   *
   * Content Moderation validates transitions against the active account. Drush
   * commands commonly run as UID 0 (anonymous), which cannot enter even the
   * initial Draft state.
   *
   * @param callable(): T $operation
   *   The privileged validation or save operation.
   *
   * @template T
   *
   *
   * @return T
   *   The operation result.
   */
  private function withAdministratorAccount(callable $operation): mixed {
    $administrator = $this->entityTypeManager
      ->getStorage('user')
      ->load(1);
    if ($administrator === NULL) {
      throw new \UnexpectedValueException(
        'The site administrator account is required for moderation validation.',
      );
    }

    $this->accountSwitcher->switchTo($administrator);
    try {
      return $operation();
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
  }

}
