<?php
namespace Drupal\invitation_qr\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Drupal\invitation_qr\Service\InvitationQrService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes queued QR stamping jobs.
 *
 * Cron processing is intentionally disabled. Jobs must be triggered manually:
 *   - drush queue:run invitation_qr_stamping
 *   - Admin page: /admin/invitation-qr/submissions/{nid}
 *
 * Each item in the queue contains: ['submission_id' => int]
 *
 * @QueueWorker(
 *   id = "invitation_qr_stamping",
 *   title = @Translation("Invitation QR Stamping")
 * )
 */
class InvitationQrWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  protected InvitationQrService $qrService;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected $logger;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    InvitationQrService $qrService,
    EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->qrService         = $qrService;
    $this->entityTypeManager = $entityTypeManager;
    $this->logger            = $loggerFactory->get('invitation_qr');
  }

  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('invitation_qr.qr_service'),
      $container->get('entity_type.manager'),
      $container->get('logger.factory')
    );
  }

  /**
   * Processes a single queue item.
   *
   * If processing fails with a transient error (e.g. file system busy),
   * throwing RequeueException puts the item back in the queue for retry.
   * Any other exception causes the item to be deleted (dead-lettered).
   */
  public function processItem($data): void {
    $submissionId = (int) ($data['submission_id'] ?? 0);

    if (!$submissionId) {
      $this->logger->warning('Queue item missing submission_id — skipping.');
      return;
    }

    /** @var \Drupal\webform\WebformSubmissionInterface|null $submission */
    $submission = $this->entityTypeManager
      ->getStorage('webform_submission')
      ->load($submissionId);

    if (!$submission) {
      // Submission was deleted — silently discard the queue item.
      $this->logger->notice(
        'Submission @sid no longer exists — discarding queue item.',
        ['@sid' => $submissionId]
      );
      return;
    }

    try {
      $this->qrService->processSubmission($submission);
    }
    catch (\RuntimeException $e) {
      // Permanent failure (missing card, bad image format, etc.) — log and discard.
      $this->logger->error(
        'Permanent QR failure for submission @sid: @msg',
        ['@sid' => $submissionId, '@msg' => $e->getMessage()]
      );
      // Do NOT rethrow — item is removed from queue.
    }
    catch (\Throwable $e) {
      // Transient failure — requeue for retry on next manual run.
      $this->logger->warning(
        'Transient QR failure for submission @sid, requeueing: @msg',
        ['@sid' => $submissionId, '@msg' => $e->getMessage()]
      );
      throw new RequeueException($e->getMessage(), 0, $e);
    }
  }

}