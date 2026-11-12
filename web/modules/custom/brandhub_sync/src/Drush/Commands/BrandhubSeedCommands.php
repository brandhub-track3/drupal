<?php

namespace Drupal\brandhub_sync\Drush\Commands;

use Drupal\brandhub_sync\Seed\SeedContentService;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Command\Command;


final class BrandhubSeedCommands extends DrushCommands {


  public function __construct(
    private readonly SeedContentService $seedService,
  ) {
    parent::__construct();
  }


  #[CLI\Command(name: 'brandhub:seed')]
  #[CLI\Option(
    name: 'products',
    description: 'Number of Product nodes (0-10).',
  )]
  #[CLI\Option(
    name: 'articles',
    description: 'Number of Article nodes (0-20).',
  )]
  #[CLI\Option(
    name: 'faqs',
    description: 'Number of FAQ nodes (0-15).',
  )]
  #[CLI\Option(
    name: 'publish',
    description: 'Create or update seed nodes as published.',
  )]
  #[CLI\Option(
    name: 'dry-run',
    description: 'Show planned changes without saving.',
  )]
  #[CLI\Option(
    name: 'reset',
    description: 'Delete only records registered by this seed command.',
  )]
  #[CLI\Usage(
    name: 'drush brandhub:seed --products=1 --articles=1 --faqs=1',
    description: 'Create one draft of each content type.',
  )]
  #[CLI\Usage(
    name: 'drush brandhub:seed',
    description: 'Create the default 10 Product, 20 Article, and 15 FAQ dataset as drafts.',
  )]
  public function seed(
    $options = [
      'products' => NULL,
      'articles' => NULL,
      'faqs' => NULL,
      'publish' => FALSE,
      'dry-run' => FALSE,
      'reset' => FALSE,
    ],
  ): int {
    $counts = [];
    foreach (['products', 'articles', 'faqs'] as $name) {
      $raw = $options[$name] ?? NULL;
      if ($raw === NULL) {
        continue;
      }
      $count = filter_var($raw, FILTER_VALIDATE_INT);
      if ($count === FALSE) {
        $this->io()->error(sprintf('--%s must be an integer.', $name));
        return Command::INVALID;
      }
      $counts[$name] = $count;
    }

    try {
      $summary = $this->seedService->run(
        $counts,
        (bool) ($options['publish'] ?? FALSE),
        (bool) ($options['dry-run'] ?? FALSE),
        (bool) ($options['reset'] ?? FALSE),
      );
    }
    catch (\InvalidArgumentException $exception) {
      $this->io()->error($exception->getMessage());
      return Command::INVALID;
    }

    $this->io()->section(
      ($options['dry-run'] ?? FALSE) ? 'Seed dry-run summary' : 'Seed summary',
    );
    $this->io()->listing([
      'Created: ' . $summary['created'],
      'Updated: ' . $summary['updated'],
      'Skipped: ' . $summary['skipped'],
      'Failed: ' . $summary['failed'],
      'Deleted: ' . $summary['deleted'],
    ]);

    return $summary['failed'] > 0
      ? Command::FAILURE
      : Command::SUCCESS;
  }

}
