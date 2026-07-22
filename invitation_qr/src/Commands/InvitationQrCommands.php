<?php

namespace Drupal\invitation_qr\Commands;

use Drush\Commands\DrushCommands;
use Drupal\invitation_qr\Service\InvitationQrService;

/**
 * Drush commands for Invitation QR module.
 *
 * Usage:
 *   drush invitation-qr:process-all
 *   drush invitation-qr:send-twilio <submission_id>
 */
class InvitationQrCommands extends DrushCommands {

  protected InvitationQrService $qrService;

  public function __construct(InvitationQrService $qrService) {
    $this->qrService = $qrService;
  }

  /**
   * Queue all submissions without a token (handles bulk CSV imports).
   *
   * @command invitation-qr:process-all
   * @aliases iqr-all
   * @option webform-id Webform machine name (overrides config)
   * @usage drush invitation-qr:process-all
   * @usage drush invitation-qr:process-all --webform-id=my_webform
   */
  public function processAll(array $options = ['webform-id' => NULL]): void {
    $webformId = $options['webform-id']
      ?? \Drupal::config('invitation_qr.settings')->get('webform_id');

    $this->logger()->notice('Scanning for unprocessed submissions on webform: {wid}', ['wid' => $webformId]);

    $count = $this->qrService->queueAllUnprocessed($webformId);

    $this->logger()->success('Queued {count} submissions. Run: drush queue:run invitation_qr_stamping', ['count' => $count]);
  }

  /**
   * Manually send a Twilio message for a specific submission.
   *
   * @command invitation-qr:send-twilio
   * @aliases iqr-send
   * @argument sid Webform submission ID
   * @usage drush invitation-qr:send-twilio 123
   */
  public function sendTwilio(int $sid): void {
    $submission = \Drupal::entityTypeManager()
      ->getStorage('webform_submission')
      ->load($sid);

    if (!$submission) {
      $this->logger()->error('Submission {sid} not found.', ['sid' => $sid]);
      return;
    }

    $data   = $submission->getData();
    $fid    = $data['stamped_card_fid'] ?? NULL;
    $phone  = $data['phone_number'] ?? '';
    $name   = $data['name'] ?? '';

    if (!$fid) {
      $this->logger()->error('Submission {sid} has no stamped card yet.', ['sid' => $sid]);
      return;
    }

    $file = \Drupal::entityTypeManager()->getStorage('file')->load($fid);
    if (!$file) {
      $this->logger()->error('File {fid} not found.', ['fid' => $fid]);
      return;
    }

    $config     = \Drupal::config('invitation_qr.settings');
    $cardUrl    = \Drupal::request()->getSchemeAndHttpHost()
      . '/' . \Drupal::service('file_url_generator')->generateString($file->getFileUri());

    $this->qrService->sendViaTwilio($phone, $name, $cardUrl, $config);
    $this->logger()->success('Twilio send attempted for submission {sid} to {phone}.', ['sid' => $sid, 'phone' => $phone]);
  }

}
