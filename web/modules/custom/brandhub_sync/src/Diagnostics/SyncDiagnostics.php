<?php

namespace Drupal\brandhub_sync\Diagnostics;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Writes credential-safe diagnostics for BrandHub synchronization.
 */
final class SyncDiagnostics {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Logs a diagnostic message when diagnostics are enabled.
   */
  public function log(string $message, array $context = []): void {
    if (!$this->configFactory->get('brandhub_sync.settings')->get('diagnostics_enabled')) {
      return;
    }

    $formatted_message = $message;
    if ($context !== []) {
      $formatted_message .= ' ' . json_encode(
        $context,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
      );
    }
    $this->loggerFactory->get('brandhub_sync')->info($formatted_message);
  }

}
