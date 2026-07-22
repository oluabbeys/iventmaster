<?php

namespace Drupal\invitation_qr\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\invitation_qr\Service\InvitationQrService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes queued invitation and access card sends off-request.
 *
 * Each queue item has:
 *   - type: 'invitation' | 'access'
 *   - sid:  webform submission ID
 *
 * Cron processing is intentionally DISABLED.
 * Sends are triggered ONLY by admin button clicks in the UI.
 * This prevents background re-sending of failed/invalid numbers.
 *
 * Run manually via drush only if needed:
 *   drush queue:run invitation_qr_sending
 *
 * @QueueWorker(
 *   id = "invitation_qr_sending",
 *   title = @Translation("Invitation QR — Send Cards")
 * )
 */
class InvitationQrSendWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  protected InvitationQrService $qrService;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, InvitationQrService $qrService) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->qrService = $qrService;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('invitation_qr.qr_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $this->qrService->processSendQueueItem((array) $data);
  }

}