<?php

namespace Drupal\brandhub_sync\Mapper;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\node\NodeInterface;

/**
 * Maps supported Drupal nodes to unified content records.
 */
final class NodeToUnifiedContentMapper implements NodeToUnifiedContentMapperInterface {

  /**
   * Drupal field machine names for the supported bundles.
   *
   * @var array<string, array<string, string|null>>
   */
  private const FIELD_MAP = [
    'product' => [
      'summary' => 'field_summary',
      'body' => 'field_body',
      'tags' => 'field_tag',
      'product' => 'field_product',
      'hero_image' => 'field_hero_image',
    ],
    'article' => [
      'summary' => 'field_a_summary',
      'body' => 'body',
      'tags' => 'field_tags',
      'product' => 'field_a_product',
      'hero_image' => 'field_a_hero_image',
    ],
    'faq' => [
      'summary' => NULL,
      'body' => 'field_answer',
      'tags' => 'field_faq_tags',
      'product' => 'field_faq_product',
      'hero_image' => NULL,
    ],
  ];

  /**
   * Constructs the node mapper.
   */
  public function __construct(
    private readonly RendererInterface $renderer,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ContentHasher $contentHasher,
  ) {}

  /**
   * Checks whether a node bundle has a unified-content mapping.
   */
  public function supports(NodeInterface $node): bool {
    return isset(self::FIELD_MAP[$node->getType()]);
  }

  /**
   * Maps a supported node to the unified schema.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The Drupal node to map.
   * @param string $status
   *   The unified publication status. Deletion hooks may explicitly pass
   *   "removed".
   *
   * @return array<string, mixed>
   *   A unified content record.
   *
   * @throws \InvalidArgumentException
   *   When the bundle or status is not supported.
   * @throws \UnexpectedValueException
   *   When a positive node ID or changed timestamp is unavailable.
   */
  public function map(NodeInterface $node, string $status): array {
    $bundle = $node->getType();
    if (!$this->supports($node)) {
      throw new \InvalidArgumentException(sprintf(
        'Unsupported node bundle "%s".',
        $bundle,
      ));
    }
    if (!in_array($status, ['published', 'removed'], TRUE)) {
      throw new \InvalidArgumentException(sprintf(
        'Unsupported unified content status "%s".',
        $status,
      ));
    }

    $node_id = (int) $node->id();
    if ($node_id < 1) {
      throw new \UnexpectedValueException(
        'A positive Drupal node ID is required.',
      );
    }

    $fields = self::FIELD_MAP[$bundle];
    $body_html = $this->renderField($node, $fields['body']);
    $plain_text = $body_html === NULL
      ? ''
      : trim(Html::decodeEntities(strip_tags($body_html)));
    $summary = $this->readStringField($node, $fields['summary']);
    $changed = $node->getChangedTime();
    $created = $node->getCreatedTime();
    $is_published = $status === 'published' && $node->isPublished();

    $content = [
      'id' => 'dr:' . $node_id,
      'url' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
      'source' => 'drupal',
      'type' => $bundle,
      'title' => (string) $node->label(),
      'summary' => $summary ?? $plain_text,
      'bodyHtml' => $body_html,
      'plainText' => $plain_text,
      'tags' => $this->mapTerms($node, $fields['tags']),
      'product' => $this->mapFirstTerm($node, $fields['product']),
      'author' => $this->mapAuthor($node),
      'heroImage' => $this->mapHeroImage($node, $fields['hero_image']),
      'publishedAt' => $is_published && $created !== NULL
        ? $this->formatTimestamp($created)
        : NULL,
      'updatedAt' => $this->formatTimestamp($changed),
      'version' => (int) $node->getRevisionId(),
      'hash' => NULL,
      'status' => $status,
      'schemaVersion' => '1.0',
    ];
    $content['hash'] = $this->contentHasher->hash($content);

    return $content;
  }

  /**
   * Renders a body field, returning NULL when it is absent or empty.
   */
  private function renderField(
    NodeInterface $node,
    ?string $field_name,
  ): ?string {
    if ($field_name === NULL || !$node->hasField($field_name)) {
      return NULL;
    }

    $field = $node->get($field_name);
    if ($field->isEmpty()) {
      return NULL;
    }

    $build = $field->view([
      'label' => 'hidden',
    ]);
    $html = trim((string) $this->renderer->renderInIsolation($build));
    return $html === '' ? NULL : $html;
  }

  /**
   * Reads a trimmed string field, returning NULL when absent or empty.
   */
  private function readStringField(
    NodeInterface $node,
    ?string $field_name,
  ): ?string {
    if ($field_name === NULL || !$node->hasField($field_name)) {
      return NULL;
    }

    $field = $node->get($field_name);
    if ($field->isEmpty()) {
      return NULL;
    }

    $items = $field->getValue();
    $value = trim((string) ($items[0]['value'] ?? ''));
    return $value === '' ? NULL : $value;
  }

  /**
   * Maps all taxonomy terms referenced by a field.
   *
   * @return array<int, array{id: int, name: string}>
   *   The referenced terms, or an empty array when the field is missing.
   */
  private function mapTerms(NodeInterface $node, ?string $field_name): array {
    if ($field_name === NULL || !$node->hasField($field_name)) {
      return [];
    }

    $terms = [];
    foreach ($node->get($field_name)->referencedEntities() as $term) {
      $terms[] = [
        'id' => (int) $term->id(),
        'name' => (string) $term->label(),
      ];
    }
    return $terms;
  }

  /**
   * Maps the first product taxonomy term referenced by a field.
   *
   * @return array{id: int, name: string}|null
   *   The referenced product term, if any.
   */
  private function mapFirstTerm(
    NodeInterface $node,
    ?string $field_name,
  ): ?array {
    $terms = $this->mapTerms($node, $field_name);
    return $terms[0] ?? NULL;
  }

  /**
   * Maps the node author to a unified author record.
   *
   * @return array{id: int, name: string}|null
   *   The author, or NULL when no resolvable owner exists.
   */
  private function mapAuthor(NodeInterface $node): ?array {
    $author_id = (int) $node->getOwnerId();
    if ($author_id < 1) {
      return NULL;
    }

    $author = $this->entityTypeManager->getStorage('user')->load($author_id);
    if ($author === NULL) {
      return NULL;
    }

    return [
      'id' => $author_id,
      'name' => (string) $author->getDisplayName(),
    ];
  }

  /**
   * Maps the first image file to an absolute URL.
   */
  private function mapHeroImage(
    NodeInterface $node,
    ?string $field_name,
  ): ?string {
    if ($field_name === NULL || !$node->hasField($field_name)) {
      return NULL;
    }

    $files = $node->get($field_name)->referencedEntities();
    $file = reset($files);
    if ($file === FALSE) {
      return NULL;
    }

    return $this->fileUrlGenerator->generateAbsoluteString(
      $file->getFileUri(),
    );
  }

  /**
   * Formats a Unix timestamp as an ISO 8601 UTC date-time.
   */
  private function formatTimestamp(int $timestamp): string {
    return (new \DateTimeImmutable('@' . $timestamp))->format(DATE_ATOM);
  }

}
