<?php

namespace Drupal\brandhub_sync\Drush\Commands;

use Drupal\brandhub_sync\Resync\ContentResyncService;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Command\Command;

/**
 * Provides commands for resending BrandHub content.
 */
final class BrandhubResyncCommands extends DrushCommands {

  /**
   * Constructs the Drush command class.
   */
  public function __construct(
    private readonly ContentResyncService $resyncService,
  ) {
    parent::__construct();
  }

  /**
   * Resends published Product, Article, and FAQ content.
   *
   * @param array<string, mixed> $options
   *   Command options.
   *
   * @return int
   *   A Symfony command exit code.
   */
  #[CLI\Command(name: 'brandhub:resync')]
  #[CLI\Option(
    name: 'bundle',
    description: 'Only resync product, article, or faq.',
  )]
  #[CLI\Option(
    name: 'batch-size',
    description: 'Number of nodes loaded per batch.',
  )]
  #[CLI\Option(
    name: 'dry-run',
    description: 'Map and report nodes without publishing.',
  )]
  #[CLI\Option(
    name: 'limit',
    description: 'Maximum number of published nodes to process.',
  )]
  #[CLI\Usage(
    name: 'drush brandhub:resync --dry-run --limit=10',
    description: 'Preview the first 10 published nodes without publishing.',
  )]
  #[CLI\Usage(
    name: 'drush brandhub:resync --bundle=product --batch-size=50',
    description: 'Resync published Product nodes in batches of 50.',
  )]
  public function resync(
    $options = [
      'bundle' => NULL,
      'batch-size' => 100,
      'dry-run' => FALSE,
      'limit' => NULL,
    ],
  ): int {
    $bundle = $options['bundle'] ?? NULL;
    $batch_size = filter_var(
      $options['batch-size'] ?? 100,
      FILTER_VALIDATE_INT,
    );
    $limit = ($options['limit'] ?? NULL) === NULL
      ? NULL
      : filter_var($options['limit'], FILTER_VALIDATE_INT);
    if (
      ($batch_size === FALSE)
      || ($limit === FALSE)
      || (isset($bundle) && !is_string($bundle))
    ) {
      $this->io()->error('Invalid resync options.');
      return Command::INVALID;
    }

    try {
      $summary = $this->resyncService->resync(
        $bundle,
        $batch_size,
        $limit,
        (bool) ($options['dry-run'] ?? FALSE),
        fn(string $line) => $this->io()->text($line),
      );
    }
    catch (\InvalidArgumentException $exception) {
      $this->io()->error($exception->getMessage());
      return Command::INVALID;
    }

    $this->io()->section('Resync summary');
    $this->io()->listing([
      'Processed: ' . $summary['processed'],
      'Sent: ' . $summary['sent'],
      'Skipped: ' . $summary['skipped'],
      'Failed: ' . $summary['failed'],
    ]);

    return $summary['failed'] > 0
      ? Command::FAILURE
      : Command::SUCCESS;
  }

}
