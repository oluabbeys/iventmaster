<?php

namespace Drupal\invitation_qr\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\webform\WebformSubmissionInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

/**
 * Core service for Invitation QR module.
 *
 * Twilio sending strategy (WhatsApp Card templates):
 *  1. Send approved Card template via MessagingServiceSid + ContentSid + ContentVariables.
 *  2. Immediately send stamped card image as a free-form MediaUrl message
 *     (works because template just opened the 24hr session window).
 *
 * Key points:
 *  - Card templates MUST use MessagingServiceSid, not From.
 *  - No Body parameter when sending ContentSid (WhatsApp rejects it).
 *  - ContentVariables JSON manually URL-encoded (not double-encoded).
 *  - Empty variable values replaced with '-'.
 *  - saveSubmissionField always includes property='' and delta=0.
 *  - Date formatted as DD-MM-YYYY.
 */
class InvitationQrService {

  const QUEUE_NAME      = 'invitation_qr_stamping';
  const SEND_QUEUE_NAME = 'invitation_qr_sending';

  protected FileRepositoryInterface $fileRepository;
  protected FileSystemInterface $fileSystem;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected ConfigFactoryInterface $configFactory;
  protected $logger;
  protected QueueFactory $queueFactory;

  public function __construct(
    FileRepositoryInterface $fileRepository,
    FileSystemInterface $fileSystem,
    EntityTypeManagerInterface $entityTypeManager,
    ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface $loggerFactory,
    QueueFactory $queueFactory
  ) {
    $this->fileRepository    = $fileRepository;
    $this->fileSystem        = $fileSystem;
    $this->entityTypeManager = $entityTypeManager;
    $this->configFactory     = $configFactory;
    $this->logger            = $loggerFactory->get('invitation_qr');
    $this->queueFactory      = $queueFactory;
  }

  // ── Queue ──────────────────────────────────────────────────────────────────

  public function queueSubmission(int $submissionId): void {
    $queue = $this->queueFactory->get(self::QUEUE_NAME);
    $queue->createItem(['submission_id' => $submissionId]);
    $this->logger->info('Queued QR job for submission @sid.', ['@sid' => $submissionId]);
  }

  public function queueAllForNode(int $nodeId): int {
    $config    = $this->configFactory->get('invitation_qr.settings');
    $webformId = $config->get('webform_id');

    $sids = $this->entityTypeManager->getStorage('webform_submission')
      ->getQuery()
      ->condition('webform_id', $webformId)
      ->condition('entity_type', 'node')
      ->condition('entity_id', $nodeId)
      ->accessCheck(FALSE)
      ->execute();

    $count = 0;
    foreach ($sids as $sid) {
      $this->queueSubmission((int) $sid);
      $count++;
    }
    return $count;
  }

  public function queueAllUnprocessed(string $webformId): int {
    $processed = \Drupal::database()->select('webform_submission_data', 'wsd')
      ->fields('wsd', ['sid'])
      ->condition('wsd.name', 'guest_token')
      ->condition('wsd.value', '', '<>')
      ->execute()
      ->fetchCol();

    $query = $this->entityTypeManager->getStorage('webform_submission')
      ->getQuery()
      ->condition('webform_id', $webformId)
      ->accessCheck(FALSE);

    if (!empty($processed)) {
      $query->condition('sid', $processed, 'NOT IN');
    }

    $sids  = $query->execute();
    $count = 0;
    foreach ($sids as $sid) {
      $this->queueSubmission((int) $sid);
      $count++;
    }
    return $count;
  }

  // ── Main processing pipeline ───────────────────────────────────────────────

  public function processSubmission(WebformSubmissionInterface $submission): void {
    $config = $this->configFactory->get('invitation_qr.settings');
    $data   = $submission->getData();

    // 1. Token.
    $token = !empty($data['guest_token'])
      ? $data['guest_token']
      : $this->generateToken($data['phone_number'] ?? '', $submission->uuid());
    $this->saveSubmissionField($submission, 'guest_token', $token);

    // 2. QR PNG.
    $verifyUrl = \Drupal::request()->getSchemeAndHttpHost() . '/verify-guest?token=' . $token;
    $qrSize    = (int) ($config->get('qr_size') ?: 150);
    $qrPngData = $this->generateQrPng($verifyUrl, $qrSize);

    $qrDir = 'public://invitation-qrcodes';
    $this->fileSystem->prepareDirectory($qrDir, FileSystemInterface::CREATE_DIRECTORY);
    $qrFile = $this->fileRepository->writeData(
      $qrPngData,
      $qrDir . '/qr-' . $token . '.png',
      FileSystemInterface::EXISTS_REPLACE
    );

    // 3. Parent node.
    $node = $this->findParentNode($submission);
    if (!$node) {
      throw new \RuntimeException('No parent Invitation node found for submission ' . $submission->id());
    }

    // 4. Stamp INVITATION CARD (name only).
    // Guard: if already sent (twilio_sent=yes), skip re-stamping to protect
    // against the node invitation card image being changed after sending.
    $alreadySent = \Drupal::database()->select('webform_submission_data', 'w')
      ->fields('w', ['value'])
      ->condition('sid', $submission->id())
      ->condition('name', 'twilio_sent')
      ->condition('property', '')
      ->condition('delta', 0)
      ->execute()
      ->fetchField();

    $invCardField = $config->get('invitation_card_field') ?: 'field_invitation_card';
    if (!$alreadySent && $node->hasField($invCardField) && !$node->get($invCardField)->isEmpty()) {
      $invCardFile = $node->get($invCardField)->entity;
      if ($invCardFile) {
        $stampedInvData = $this->stampInvitationCard(
          $invCardFile->getFileUri(),
          $data['name'] ?? '',
          $config
        );

        $stampedDir = 'public://invitation-stamped';
        $this->fileSystem->prepareDirectory($stampedDir, FileSystemInterface::CREATE_DIRECTORY);
        // Include timestamp in filename so re-stamps are never served from
        // browser or CDN cache — each re-stamp produces a unique file URL.
        // (Mirrors the same fix already applied to access cards below.)
        $invFilename = 'stamped-inv-' . $token . '-' . time() . '.png';
        $stampedInvFile = $this->fileRepository->writeData(
          $stampedInvData,
          $stampedDir . '/' . $invFilename,
          FileSystemInterface::EXISTS_REPLACE
        );
        $stampedInvFile->setPermanent();
        $stampedInvFile->save();
        $this->saveSubmissionField($submission, 'stamped_card_fid', $stampedInvFile->id());

        $this->logger->info('Invitation card stamped for submission @sid.', ['@sid' => $submission->id()]);

        // Auto-send if enabled in settings (checkbox).
        // Uses the queue-based idempotent send so:
        //  - State is set to 'sending' before the Twilio call
        //  - Double-send is blocked if queue runs twice
        //  - Submissions table shows correct three-state badge
        //  - Failed sends reset to 'unsent' for manual retry
        if ($config->get('twilio_enabled')) {
          $phone = $data['phone_number'] ?? '';
          if ($phone) {
            $queued = $this->queueInvitationSend((int) $submission->id());
            if ($queued) {
              // Process immediately — we are already inside a queue worker
              // (stamping queue), so we call sendInvitationCard directly here
              // rather than nesting a second queue drain.
              $dataWithSid = array_merge($data, ['sid' => $submission->id()]);
              $sent = $this->sendInvitationCard(
                $phone,
                $data['name'] ?? '',
                $this->getAbsoluteFileUrl($stampedInvFile->getFileUri()),
                $node,
                $config,
                $dataWithSid
              );
              if ($sent) {
                $this->saveSubmissionField($submission, 'twilio_sent', 'yes');
                // Clear the sending lock — DB flag is now ground truth.
                \Drupal::state()->delete($this->invSendStateKey((int) $submission->id()));
                $this->logger->info('Auto-send: invitation sent for sid=@sid.', ['@sid' => $submission->id()]);
              }
              else {
                // Reset to unsent so admin can retry manually from the table.
                $this->resetInvSendState((int) $submission->id());
                $this->logger->error('Auto-send: invitation failed for sid=@sid — reset to unsent.', ['@sid' => $submission->id()]);
              }
            }
            else {
              // Already sent or sending — skip silently (idempotency).
              $this->logger->info('Auto-send: skipped for sid=@sid (already sent or in progress).', ['@sid' => $submission->id()]);
            }
          }
        }
      }
    }
    elseif ($alreadySent) {
      $this->logger->info('Invitation card already sent for sid=@sid — skipping re-stamp to preserve original.', ['@sid' => $submission->id()]);
    }

    // 5. Stamp ACCESS CARD (QR only). Never auto-sent.
    // Guard: if access_card_fid already exists on this submission, skip
    // re-stamping. This protects against the node's access card image being
    // changed after initial stamping — re-running the queue would otherwise
    // overwrite the original stamped file with the new design, causing the
    // wrong card to be sent when admin triggers Send Access.
    $existingAccessFid = \Drupal::database()->select('webform_submission_data', 'w')
      ->fields('w', ['value'])
      ->condition('sid', $submission->id())
      ->condition('name', 'access_card_fid')
      ->condition('property', '')
      ->condition('delta', 0)
      ->execute()
      ->fetchField();

    $accessCardField = $config->get('access_card_field') ?: 'field_access_card';
    if (!$existingAccessFid && $node->hasField($accessCardField) && !$node->get($accessCardField)->isEmpty()) {
      $accessCardFile = $node->get($accessCardField)->entity;
      if ($accessCardFile) {
        $stampedAccessData = $this->stampAccessCard(
          $accessCardFile->getFileUri(),
          $qrFile->getFileUri(),
          $config->get('qr_position') ?: 'bottom-right',
          (int) ($config->get('qr_margin') ?: 20)
        );

        $accessDir  = 'public://invitation-access-cards';
        $this->fileSystem->prepareDirectory($accessDir, FileSystemInterface::CREATE_DIRECTORY);
        // Include timestamp in filename so re-stamps are never served from
        // browser or CDN cache — each re-stamp produces a unique file URL.
        $accessFilename = 'access-' . $token . '-' . time() . '.png';
        $stampedAccessFile = $this->fileRepository->writeData(
          $stampedAccessData,
          $accessDir . '/' . $accessFilename,
          FileSystemInterface::EXISTS_REPLACE
        );
        $stampedAccessFile->setPermanent();
        $stampedAccessFile->save();
        $this->saveSubmissionField($submission, 'access_card_fid', $stampedAccessFile->id());

        $this->logger->info('Access card stamped for submission @sid.', ['@sid' => $submission->id()]);
      }
    }
    elseif ($existingAccessFid) {
      $this->logger->info('Access card already stamped for submission @sid — skipping re-stamp.', ['@sid' => $submission->id()]);
    }
  }

  // ── Image stamping ────────────────────────────────────────────────────────

  public function stampInvitationCard(string $cardUri, string $guestName, $config): string {
    $cardPath = $this->fileSystem->realpath($cardUri);
    if (!$cardPath || !file_exists($cardPath)) {
      throw new \RuntimeException("Invitation card image not found: $cardUri");
    }
    $info = getimagesize($cardPath);
    if (!$info) throw new \RuntimeException("Cannot read image: $cardPath");

    if ($info[2] === IMAGETYPE_JPEG)      $cardImg = imagecreatefromjpeg($cardPath);
    elseif ($info[2] === IMAGETYPE_PNG)  $cardImg = imagecreatefrompng($cardPath);
    elseif ($info[2] === IMAGETYPE_WEBP) $cardImg = imagecreatefromwebp($cardPath);
    else throw new \RuntimeException("Unsupported image type: " . $info['mime']);

    $cardW = imagesx($cardImg);
    $cardH = imagesy($cardImg);
    imagealphablending($cardImg, TRUE);
    imagesavealpha($cardImg, TRUE);

    if ($config->get('name_enabled') && !empty($guestName)) {
      $this->overlayName($cardImg, $guestName, $cardW, $cardH, $config);
    }

    ob_start();
    imagepng($cardImg);
    $pngData = ob_get_clean();
    imagedestroy($cardImg);

    if (!$pngData) throw new \RuntimeException('imagepng() produced no output for invitation card.');
    return $pngData;
  }

  public function stampAccessCard(string $cardUri, string $qrUri, string $qrPosition, int $qrMargin): string {
    $cardPath = $this->fileSystem->realpath($cardUri);
    $qrPath   = $this->fileSystem->realpath($qrUri);

    if (!$cardPath || !file_exists($cardPath)) {
      throw new \RuntimeException("Access card image not found: $cardUri");
    }
    $info = getimagesize($cardPath);
    if (!$info) throw new \RuntimeException("Cannot read access card image: $cardPath");

    if ($info[2] === IMAGETYPE_JPEG)      $cardImg = imagecreatefromjpeg($cardPath);
    elseif ($info[2] === IMAGETYPE_PNG)  $cardImg = imagecreatefrompng($cardPath);
    elseif ($info[2] === IMAGETYPE_WEBP) $cardImg = imagecreatefromwebp($cardPath);
    else throw new \RuntimeException("Unsupported image type: " . $info['mime']);

    $cardW = imagesx($cardImg);
    $cardH = imagesy($cardImg);
    imagealphablending($cardImg, TRUE);
    imagesavealpha($cardImg, TRUE);

    if ($qrPath && file_exists($qrPath)) {
      $qrImg = imagecreatefrompng($qrPath);
      if ($qrImg) {
        $qrW = imagesx($qrImg);
        $qrH = imagesy($qrImg);
        [$dx, $dy] = $this->calculatePosition($qrPosition, $cardW, $cardH, $qrW, $qrH, $qrMargin);
        imagecopy($cardImg, $qrImg, $dx, $dy, 0, 0, $qrW, $qrH);
        imagedestroy($qrImg);
      }
    }

    ob_start();
    imagepng($cardImg);
    $pngData = ob_get_clean();
    imagedestroy($cardImg);

    if (!$pngData) throw new \RuntimeException('imagepng() produced no output for access card.');
    return $pngData;
  }

  public function stampCardWithQrAndName(string $cardUri, string $qrUri, string $qrPosition, int $qrMargin, string $guestName, $config): string {
    return $this->stampInvitationCard($cardUri, $guestName, $config);
  }

  // ── Name overlay ──────────────────────────────────────────────────────────

  protected function overlayName($cardImg, string $name, int $cardW, int $cardH, $config): void {
    $r        = (int) ($config->get('name_color_r') ?? 255);
    $g        = (int) ($config->get('name_color_g') ?? 255);
    $b        = (int) ($config->get('name_color_b') ?? 255);
    $color    = imagecolorallocate($cardImg, $r, $g, $b);
    $fontSize = (int) ($config->get('name_font_size') ?? 36);
    $fontPath = $config->get('name_font_path') ?? '';
    $position = $config->get('name_position') ?? 'center';
    $offsetX  = (int) ($config->get('name_offset_x') ?? 0);
    $offsetY  = (int) ($config->get('name_offset_y') ?? 0);

    if ($fontPath && file_exists($fontPath)) {
      $bbox  = imagettfbbox($fontSize, 0, $fontPath, $name);
      $textW = abs($bbox[4] - $bbox[0]);
      $textH = abs($bbox[5] - $bbox[1]);
      [$bx, $by] = $this->namePosition($position, $cardW, $cardH, $textW, $textH);
      $bx += $offsetX;
      $by += $offsetY;
      $shadow = imagecolorallocatealpha($cardImg, 0, 0, 0, 80);
      imagettftext($cardImg, $fontSize, 0, $bx + 2, $by + 2, $shadow, $fontPath, $name);
      imagettftext($cardImg, $fontSize, 0, $bx, $by, $color, $fontPath, $name);
    }
    else {
      $gdFont = 5;
      $textW  = strlen($name) * imagefontwidth($gdFont);
      $textH  = imagefontheight($gdFont);
      [$bx, $by] = $this->namePosition($position, $cardW, $cardH, $textW, $textH);
      $bx += $offsetX;
      $by += $offsetY;
      imagestring($cardImg, $gdFont, $bx, $by, $name, $color);
    }
  }

  protected function namePosition(string $pos, int $cW, int $cH, int $tW, int $tH): array {
    if ($pos === 'top-left')      return [40, $tH + 40];
    if ($pos === 'top-center')    return [($cW - $tW) / 2, $tH + 40];
    if ($pos === 'top-right')     return [$cW - $tW - 40, $tH + 40];
    if ($pos === 'middle-left')   return [40, $cH / 2];
    if ($pos === 'middle-right')  return [$cW - $tW - 40, $cH / 2];
    if ($pos === 'bottom-left')   return [40, $cH - 60];
    if ($pos === 'bottom-right')  return [$cW - $tW - 40, $cH - 60];
    if ($pos === 'bottom-center') return [($cW - $tW) / 2, $cH - 60];
    return [($cW - $tW) / 2, $cH / 2];
  }

  // ── Date formatter ────────────────────────────────────────────────────────

  public function formatEventDate(string $value): string {
    if (empty($value)) return '';
    $ts = is_numeric($value) ? (int) $value : strtotime($value);
    if ($ts && $ts > 0) {
      return date('d-m-Y', $ts);
    }
    return $value;
  }

  // ── Field value helpers ────────────────────────────────────────────────────

  public function getNodeFieldValue(object $node, string $fieldName): string {
    if (!$node->hasField($fieldName) || $node->get($fieldName)->isEmpty()) {
      return '';
    }
    $item = $node->get($fieldName)->first();
    if (!$item) return '';
    $val = $item->uri ?? $item->value ?? null;
    if ($val === null) $val = $item->getString();
    return trim((string) $val);
  }

  public function resolveToken(string $token, string $guestName, object $node, array $submissionData = []): string {
    if ($token === 'guest_name')        return $guestName;
    if ($token === 'bride_father_name') return $this->getNodeFieldValue($node, 'field_bride_father_name');
    if ($token === 'groom_father_name') return $this->getNodeFieldValue($node, 'field_groom_father_name');
    if ($token === 'bride_name')        return $this->getNodeFieldValue($node, 'field_bride_name');
    if ($token === 'groom_name')        return $this->getNodeFieldValue($node, 'field_groom_name');
    if ($token === 'event_date')        return $this->formatEventDate($this->getNodeFieldValue($node, 'field_event_date'));
    if ($token === 'event_venue')       return $this->getNodeFieldValue($node, 'field_event_venue');
    if ($token === 'event_time')        return $this->getNodeFieldValue($node, 'field_event_time');
    if ($token === 'event_title')       return $node->label();
    if ($token === 'zoom_link')         return $this->getNodeFieldValue($node, 'field_zoom_link');

    // Stamped card URLs — loaded directly from DB because getData() does not
    // return fields saved via direct DB insert (saveSubmissionField).
    //
    // IMPORTANT: Twilio Media URL field is set to "https://iventmaster.com/{{9}}"
    // so we must return only the PATH portion (without domain) so Twilio
    // constructs the correct full URL: https://iventmaster.com/PATH.
    if ($token === 'stamped_invitation_card_url') {
      $fid = $submissionData['stamped_card_fid'] ?? NULL;
      if (!$fid && !empty($submissionData['sid'])) {
        $fid = \Drupal::database()->select('webform_submission_data', 'w')
          ->fields('w', ['value'])
          ->condition('sid', $submissionData['sid'])
          ->condition('name', 'stamped_card_fid')
          ->condition('property', '')
          ->condition('delta', 0)
          ->execute()
          ->fetchField();
      }
      if ($fid) {
        $file = \Drupal::entityTypeManager()->getStorage('file')->load((int) $fid);
        if ($file) {
          $fullUrl  = $this->getAbsoluteFileUrl($file->getFileUri());
          $baseUrl  = \Drupal::request()->getSchemeAndHttpHost();
          // Strip domain — return path only so Twilio appends to its base URL.
          $path = ltrim(str_replace($baseUrl, '', $fullUrl), '/');
          $this->logger->info('stamped_invitation_card_url resolved path: @path (full: @url)', [
            '@path' => $path,
            '@url'  => $fullUrl,
          ]);
          return $path;
        }
      }
      $this->logger->warning('stamped_invitation_card_url: no fid found. submissionData keys: @data', [
        '@data' => json_encode(array_keys($submissionData)),
      ]);
      return '';
    }

    if ($token === 'stamped_access_card_url') {
      $fid = $submissionData['access_card_fid'] ?? NULL;
      if (!$fid && !empty($submissionData['sid'])) {
        $fid = \Drupal::database()->select('webform_submission_data', 'w')
          ->fields('w', ['value'])
          ->condition('sid', $submissionData['sid'])
          ->condition('name', 'access_card_fid')
          ->condition('property', '')
          ->condition('delta', 0)
          ->execute()
          ->fetchField();
      }
      if ($fid) {
        $file = \Drupal::entityTypeManager()->getStorage('file')->load((int) $fid);
        if ($file) {
          $fullUrl  = $this->getAbsoluteFileUrl($file->getFileUri());
          $baseUrl  = \Drupal::request()->getSchemeAndHttpHost();
          $path = ltrim(str_replace($baseUrl, '', $fullUrl), '/');
          $this->logger->info('stamped_access_card_url resolved path: @path (full: @url)', [
            '@path' => $path,
            '@url'  => $fullUrl,
          ]);
          return $path;
        }
      }
      $this->logger->warning('stamped_access_card_url: no fid found. submissionData keys: @data', [
        '@data' => json_encode(array_keys($submissionData)),
      ]);
      return '';
    }

    return '';
  }

  /**
   * Builds ContentVariables JSON for a Twilio template.
   * All values must be non-empty strings — Twilio rejects null/''.
   */
  public function buildContentVariables(array $templateVariables, string $guestName, object $node, array $submissionData = []): string {
    $vars = [];
    foreach ($templateVariables as $num => $token) {
      $resolved = $this->resolveToken($token, $guestName, $node, $submissionData);
      $resolved = preg_replace('/[\r\n\t]/', ' ', $resolved);
      $resolved = preg_replace('/\s{5,}/', '    ', $resolved);
      $resolved = trim($resolved);
      if ($resolved === '') {
        $resolved = '-';
      }
      $vars[(string) $num] = $resolved;
    }
    $json = json_encode($vars, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $this->logger->info('ContentVariables built: @json', ['@json' => $json]);
    return $json;
  }

  // ── Twilio senders ─────────────────────────────────────────────────────────

  /**
   * Sends INVITATION card.
   * Step 1: Card template via MessagingServiceSid + ContentSid (opens session).
   * Step 2: Stamped card image via MediaUrl (session now open).
   *
   * Template resolution:
   *  1. field_invitation_template on node (per-event override).
   *  2. default_invitation_template from module config.
   *  3. First available template (auto-fallback).
   *  4. Free-form fallback (only within existing 24hr session).
   */
  public function sendInvitationCard(string $phone, string $guestName, string $cardUrl, object $node, $config, array $submissionData = []): bool {
    $templates = \Drupal\invitation_qr\Controller\TemplateManagerController::getTemplates();

    // Resolve template ID.
    $templateId = trim($this->getNodeFieldValue($node, 'field_invitation_template'));
    $this->logger->info('sendInvitationCard: node field_invitation_template="@tid"', ['@tid' => $templateId]);

    if (empty($templateId) || !isset($templates[$templateId])) {
      $templateId = trim((string) ($config->get('default_invitation_template') ?: ''));
      $this->logger->info('sendInvitationCard: using default_invitation_template="@tid"', ['@tid' => $templateId]);
    }

    if ((empty($templateId) || !isset($templates[$templateId])) && !empty($templates)) {
      $templateId = array_key_first($templates);
      $this->logger->info('sendInvitationCard: auto-picked first template="@tid"', ['@tid' => $templateId]);
    }

    $template = $templates[$templateId] ?? NULL;

    $this->logger->info('sendInvitationCard: final templateId="@tid" found=@found', [
      '@tid'   => $templateId,
      '@found' => $template ? 'yes' : 'no',
    ]);

    if ($template && !empty($template['sid'])) {
      // Pass submission data so stamped_invitation_card_url token can be resolved.
      $contentVars = $this->buildContentVariables(
        $template['variables'] ?? [],
        $guestName,
        $node,
        array_merge($submissionData, ['card_type' => 'invitation'])
      );

      $sent = $this->sendViaContentSid($phone, $template['sid'], $contentVars, $config,
        array_merge($submissionData, ['card_type' => 'invitation']));

      if (!$sent) {
        $this->logger->error('Template send failed for @phone.', ['@phone' => $phone]);
        return FALSE;
      }

      return TRUE;
    }

    // No template — free-form fallback (sends text + image separately).
    $this->logger->warning('No template found — free-form fallback for @phone (node @nid).', [
      '@phone' => $phone,
      '@nid'   => $node->id(),
    ]);
    return $this->sendViaTwilio($phone, $guestName, $cardUrl, $config);
  }

  /**
   * Sends ACCESS CARD (manual only).
   */
  public function sendAccessCard(string $phone, string $guestName, string $cardUrl, object $node, $config, array $submissionData = []): bool {
    $templates  = \Drupal\invitation_qr\Controller\TemplateManagerController::getTemplates();
    $templateId = trim((string) ($config->get('default_access_template') ?: ''));

    if ((empty($templateId) || !isset($templates[$templateId])) && !empty($templates)) {
      $templateId = array_key_first($templates);
    }

    $template = $templates[$templateId] ?? NULL;

    if ($template && !empty($template['sid'])) {
      $contentVars = $this->buildContentVariables(
        $template['variables'] ?? [],
        $guestName,
        $node,
        array_merge($submissionData, ['card_type' => 'access'])
      );
      return $this->sendViaContentSid($phone, $template['sid'], $contentVars, $config,
        array_merge($submissionData, ['card_type' => 'access']));
    }

    $messageBody = $config->get('access_card_message')
      ?: 'Dear {{Guest name}}, please find your access card attached. Present this at the event entrance. 🎟️';
    $messageBody = str_replace('{{Guest name}}', $guestName, $messageBody);
    return $this->sendViaTwilio($phone, $guestName, $cardUrl, $config, $messageBody,
      array_merge($submissionData, ['card_type' => 'access']));
  }

  /**
   * Sends a Twilio approved Card template via MessagingServiceSid + ContentSid.
   *
   * CRITICAL RULES for WhatsApp Card templates:
   *  - MUST use MessagingServiceSid instead of From.
   *  - NO Body parameter (WhatsApp rejects ContentSid messages with Body).
   *  - ContentVariables JSON manually URL-encoded (not double-encoded).
   *  - To number must include whatsapp: prefix.
   */
  public function sendViaContentSid(string $phone, string $contentSid, string $contentVariables, $config, array $submissionData = []): bool {
    $accountSid        = $config->get('twilio_account_sid');
    $authToken         = $config->get('twilio_auth_token');
    $messagingService  = $config->get('twilio_messaging_service_sid') ?: 'MG398bc30d6886496c58ce1d2bfc6547be';
    $channel           = $config->get('twilio_channel') ?: 'whatsapp';

    if (!$accountSid || !$authToken) {
      $this->logger->warning('Twilio not fully configured — skipping send.');
      return FALSE;
    }

    $phone = preg_replace('/[^0-9+]/', '', $phone);
    if (!str_starts_with($phone, '+')) $phone = '+' . $phone;

    $toNumber    = $channel === 'whatsapp' ? 'whatsapp:' . $phone : $phone;
    $callbackUrl = \Drupal::request()->getSchemeAndHttpHost() . '/invitation-qr/twilio-webhook';

    $postFields = 'MessagingServiceSid=' . urlencode($messagingService)
      . '&To=' . urlencode($toNumber)
      . '&ContentSid=' . urlencode($contentSid)
      . '&ContentVariables=' . urlencode($contentVariables)
      . '&StatusCallback=' . urlencode($callbackUrl);

    $this->logger->info('Twilio ContentSid POST — phone=@phone SID=@sid MsgSvc=@svc vars=@vars', [
      '@phone' => $phone,
      '@sid'   => $contentSid,
      '@svc'   => $messagingService,
      '@vars'  => $contentVariables,
    ]);

    $apiUrl = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json";
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_POST           => TRUE,
      CURLOPT_POSTFIELDS     => $postFields,
      CURLOPT_USERPWD        => "$accountSid:$authToken",
      CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded',
      ],
      CURLOPT_TIMEOUT        => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
      $this->logger->error('Twilio ContentSid cURL error for @phone: @err', ['@phone' => $phone, '@err' => $curlErr]);
      return FALSE;
    }

    $decoded = json_decode($response, TRUE);
    if ($httpCode >= 200 && $httpCode < 300) {
      $messageSid = $decoded['sid'] ?? '';
      $this->logger->info('Twilio template sent to @phone (SID: @sid).', [
        '@phone' => $phone,
        '@sid'   => $messageSid,
      ]);
      // Save the Twilio Message SID so the status callback can match it.
      if ($messageSid && !empty($submissionData['sid'])) {
        $sub = $this->entityTypeManager->getStorage('webform_submission')->load((int) $submissionData['sid']);
        if ($sub) {
          $field = ($submissionData['card_type'] ?? 'invitation') === 'access'
            ? 'access_twilio_message_sid'
            : 'inv_twilio_message_sid';
          $this->saveSubmissionField($sub, $field, $messageSid);
        }
      }
      return TRUE;
    }

    $this->logger->error('Twilio ContentSid failed for @phone (HTTP @code): @msg | Full response: @resp', [
      '@phone' => $phone,
      '@code'  => $httpCode,
      '@msg'   => $decoded['message'] ?? '',
      '@resp'  => $response,
    ]);
    return FALSE;
  }

  /**
   * Sends stamped card image as free-form message with MediaUrl.
   * Must be called AFTER sendViaContentSid() opens the 24hr session.
   * Uses From number directly (not MessagingServiceSid) for image send.
   */
  public function sendCardImage(string $phone, string $cardUrl, $config): bool {
    $accountSid = $config->get('twilio_account_sid');
    $authToken  = $config->get('twilio_auth_token');
    $from       = $config->get('twilio_from');
    $channel    = $config->get('twilio_channel') ?: 'whatsapp';

    if (!$accountSid || !$authToken || !$from) {
      $this->logger->warning('Twilio not configured for image send.');
      return FALSE;
    }

    $phone = preg_replace('/[^0-9+]/', '', $phone);
    if (!str_starts_with($phone, '+')) $phone = '+' . $phone;

    $toNumber   = $channel === 'whatsapp' ? 'whatsapp:' . $phone : $phone;
    $fromNumber = $channel === 'whatsapp' ? 'whatsapp:' . $from  : $from;

    $postFields = 'From=' . urlencode($fromNumber)
      . '&To=' . urlencode($toNumber)
      . '&MediaUrl=' . urlencode($cardUrl);

    $apiUrl = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json";
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_POST           => TRUE,
      CURLOPT_POSTFIELDS     => $postFields,
      CURLOPT_USERPWD        => "$accountSid:$authToken",
      CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded',
      ],
      CURLOPT_TIMEOUT        => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($response, TRUE);
    if ($httpCode >= 200 && $httpCode < 300) {
      $this->logger->info('Card image sent to @phone.', ['@phone' => $phone]);
      return TRUE;
    }

    $this->logger->warning('Card image send failed for @phone: @msg', [
      '@phone' => $phone,
      '@msg'   => $decoded['message'] ?? $response,
    ]);
    return FALSE;
  }

  /**
   * Legacy free-form send — used for RSVP reminders and fallback.
   */
  public function sendViaTwilio(string $phone, string $name, string $cardUrl, $config, string $messageBody = '', array $submissionData = []): bool {
    $accountSid = $config->get('twilio_account_sid');
    $authToken  = $config->get('twilio_auth_token');
    $from       = $config->get('twilio_from');
    $channel    = $config->get('twilio_channel') ?: 'whatsapp';

    if (!$accountSid || !$authToken || !$from) {
      $this->logger->warning('Twilio not fully configured — skipping send.');
      return FALSE;
    }

    if (empty($messageBody)) {
      $template    = $config->get('twilio_message') ?: 'Hello @name, your invitation is ready.';
      $messageBody = trim(preg_replace('/\s+/', ' ', str_replace(
        ['@name', '@url', '{{Guest name}}'],
        [$name, '', $name],
        $template
      )));
    }

    $phone = preg_replace('/[^0-9+]/', '', $phone);
    if (!str_starts_with($phone, '+')) $phone = '+' . $phone;

    $toNumber    = $channel === 'whatsapp' ? 'whatsapp:' . $phone : $phone;
    $fromNumber  = $channel === 'whatsapp' ? 'whatsapp:' . $from  : $from;
    $callbackUrl = \Drupal::request()->getSchemeAndHttpHost() . '/invitation-qr/twilio-webhook';

    $postFields = 'From=' . urlencode($fromNumber)
      . '&To=' . urlencode($toNumber)
      . '&Body=' . urlencode($messageBody)
      . '&StatusCallback=' . urlencode($callbackUrl);
    if ($cardUrl) {
      $postFields .= '&MediaUrl=' . urlencode($cardUrl);
    }

    $apiUrl = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json";
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_POST           => TRUE,
      CURLOPT_POSTFIELDS     => $postFields,
      CURLOPT_USERPWD        => "$accountSid:$authToken",
      CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded',
      ],
      CURLOPT_TIMEOUT        => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
      $this->logger->error('Twilio cURL error for @phone: @err', ['@phone' => $phone, '@err' => $curlErr]);
      return FALSE;
    }

    $decoded = json_decode($response, TRUE);
    if ($httpCode >= 200 && $httpCode < 300) {
      $messageSid = $decoded['sid'] ?? '';
      $this->logger->info('Twilio sent to @phone (SID: @sid).', [
        '@phone' => $phone,
        '@sid'   => $messageSid,
      ]);
      // Save Message SID for status callback matching.
      if ($messageSid && !empty($submissionData['sid'])) {
        $sub = $this->entityTypeManager->getStorage('webform_submission')->load((int) $submissionData['sid']);
        if ($sub) {
          $field = ($submissionData['card_type'] ?? 'invitation') === 'access'
            ? 'access_twilio_message_sid'
            : 'inv_twilio_message_sid';
          $this->saveSubmissionField($sub, $field, $messageSid);
        }
      }
      return TRUE;
    }

    $this->logger->error('Twilio failed for @phone (HTTP @code): @msg', [
      '@phone' => $phone,
      '@code'  => $httpCode,
      '@msg'   => $decoded['message'] ?? $response,
    ]);
    return FALSE;
  }

  // ── Send state (three states: unsent → sending → sent) ───────────────────
  //
  // We use Drupal State (key/value store) as a lightweight lock so that:
  //  - Clicking Send twice only queues one job (idempotency)
  //  - A server crash mid-send leaves state='sending' so the UI shows
  //    "In Progress" rather than "Unsent", preventing automatic re-sends
  //  - Only an explicit admin action (Retry) can move sending → unsent

  /**
   * State key for invitation send lock.
   */
  protected function invSendStateKey(int $sid): string {
    return 'invitation_qr.inv_send.' . $sid;
  }

  /**
   * State key for access card send lock.
   */
  protected function accessSendStateKey(int $sid): string {
    return 'invitation_qr.access_send.' . $sid;
  }

  /**
   * Get invitation send state for a submission.
   * Returns: 'unsent' | 'sending' | 'sent'
   */
  public function getInvSendState(int $sid): string {
    $sent = \Drupal::database()->select('webform_submission_data', 'w')
      ->fields('w', ['value'])
      ->condition('sid', $sid)
      ->condition('name', 'twilio_sent')
      ->condition('property', '')
      ->condition('delta', 0)
      ->execute()
      ->fetchField();
    // Both 'yes' and 'failed' are terminal — neither gets re-queued.
    if ($sent === 'yes') return 'sent';
    if ($sent === 'failed') return 'failed';

    $lock = \Drupal::state()->get($this->invSendStateKey($sid), 'unsent');
    return $lock;
  }

  /**
   * Get access card send state for a submission.
   * Returns: 'unsent' | 'sending' | 'sent'
   */
  public function getAccessSendState(int $sid): string {
    $sent = \Drupal::database()->select('webform_submission_data', 'w')
      ->fields('w', ['value'])
      ->condition('sid', $sid)
      ->condition('name', 'access_card_sent')
      ->condition('property', '')
      ->condition('delta', 0)
      ->execute()
      ->fetchField();
    // Both 'yes' and 'failed' are terminal — neither gets re-queued.
    if ($sent === 'yes') return 'sent';
    if ($sent === 'failed') return 'failed';

    $lock = \Drupal::state()->get($this->accessSendStateKey($sid), 'unsent');
    return $lock;
  }

  /**
   * Reset invitation send state back to 'unsent' (admin retry action).
   */
  public function resetInvSendState(int $sid): void {
    \Drupal::state()->delete($this->invSendStateKey($sid));
  }

  /**
   * Reset access card send state back to 'unsent' (admin retry action).
   */
  public function resetAccessSendState(int $sid): void {
    \Drupal::state()->delete($this->accessSendStateKey($sid));
  }

  /**
   * Clear the DB twilio_sent flag so a resend is permitted.
   * Only used for deliberate manual resend — not called automatically.
   */
  public function clearInvSentFlag(WebformSubmissionInterface $submission): void {
    \Drupal::database()->delete('webform_submission_data')
      ->condition('sid', $submission->id())
      ->condition('name', 'twilio_sent')
      ->condition('property', '')
      ->condition('delta', 0)
      ->execute();
    $this->logger->info('Invitation sent flag cleared for sid=@sid (manual resend).', [
      '@sid' => $submission->id(),
    ]);
  }

  /**
   * Clear the DB access_card_sent flag so a resend is permitted.
   * Only used for deliberate manual resend — not called automatically.
   */
  public function clearAccessSentFlag(WebformSubmissionInterface $submission): void {
    \Drupal::database()->delete('webform_submission_data')
      ->condition('sid', $submission->id())
      ->condition('name', 'access_card_sent')
      ->condition('property', '')
      ->condition('delta', 0)
      ->execute();
    $this->logger->info('Access card sent flag cleared for sid=@sid (manual resend).', [
      '@sid' => $submission->id(),
    ]);
  }

  /**
   * Force-resend an invitation regardless of current state.
   * Clears both the DB sent flag and the State lock, then queues a fresh job.
   * Returns FALSE only if the submission or card file is missing.
   */
  public function resendInvitation(int $sid): bool {
    $sub = $this->entityTypeManager->getStorage('webform_submission')->load($sid);
    if (!$sub) return FALSE;

    // Clear DB sent flag and State lock so queueInvitationSend sees 'unsent'.
    $this->clearInvSentFlag($sub);
    $this->resetInvSendState($sid);

    return $this->queueInvitationSend($sid);
  }

  /**
   * Force-resend an access card regardless of current state.
   * Clears both the DB sent flag and the State lock, then queues a fresh job.
   */
  public function resendAccessCard(int $sid): bool {
    $sub = $this->entityTypeManager->getStorage('webform_submission')->load($sid);
    if (!$sub) return FALSE;

    $this->clearAccessSentFlag($sub);
    $this->resetAccessSendState($sid);

    return $this->queueAccessCardSend($sid);
  }

  /**
   * Validates a phone number before sending.
   *
   * Rules:
   * - Empty or just '+' → invalid
   * - Nigerian numbers (start with 234) must be exactly 13 digits
   * - US/Canada numbers (start with 1, 11 digits) → skipped (94% failure rate)
   * - International numbers → must be 11–15 digits total
   *   (country code + subscriber number, minimum 11 digits)
   */
  protected function isValidPhone(string $phone): bool {
    $phone  = trim($phone);
    $digits = preg_replace('/[^0-9]/', '', $phone);

    // Empty or no digits at all.
    if (empty($digits)) return FALSE;

    // Nigerian numbers must be exactly 13 digits (234 + 10 digit number).
    if (str_starts_with($digits, '234')) {
      return strlen($digits) === 13;
    }

    // US/Canada numbers — skip entirely (WhatsApp delivery fails ~94%).
    if (str_starts_with($digits, '1') && strlen($digits) === 11) {
      return FALSE;
    }

    // All other international numbers must be 11–15 digits total.
    // 11 minimum: 2-digit country code + 9-digit subscriber number.
    // 15 maximum: ITU-T E.164 standard limit.
    return strlen($digits) >= 11 && strlen($digits) <= 15;
  }

  /**
   * Queue a single invitation send job.
   * Idempotent — if state is already 'sending' or 'sent', does nothing.
   * Returns FALSE if job was skipped (already queued or sent).
   */
  public function queueInvitationSend(int $sid): bool {
    // Validate phone number before queuing — blocks empty/invalid numbers.
    $phone = \Drupal::database()->select('webform_submission_data', 'w')
      ->fields('w', ['value'])
      ->condition('sid', $sid)
      ->condition('name', 'phone_number')
      ->condition('property', '')
      ->condition('delta', 0)
      ->execute()
      ->fetchField() ?: '';

    if (!$this->isValidPhone($phone)) {
      $this->logger->warning(
        'Inv send skipped for sid=@sid — invalid/empty phone number: "@phone".',
        ['@sid' => $sid, '@phone' => $phone]
      );
      return FALSE;
    }

    $state = $this->getInvSendState($sid);
    if ($state !== 'unsent') {
      $this->logger->info('Inv send skipped for sid=@sid (state=@state).', [
        '@sid'   => $sid,
        '@state' => $state,
      ]);
      return FALSE;
    }
    // Mark as 'sending' BEFORE queuing so a second click is blocked immediately.
    \Drupal::state()->set($this->invSendStateKey($sid), 'sending');
    $this->queueFactory->get(self::SEND_QUEUE_NAME)->createItem([
      'type' => 'invitation',
      'sid'  => $sid,
    ]);
    $this->logger->info('Invitation send queued for sid=@sid.', ['@sid' => $sid]);
    return TRUE;
  }

  /**
   * Queue a single access card send job.
   * Idempotent — if state is already 'sending' or 'sent', does nothing.
   */
  public function queueAccessCardSend(int $sid): bool {
    // Validate phone number before queuing — blocks empty/invalid numbers.
    $phone = \Drupal::database()->select('webform_submission_data', 'w')
      ->fields('w', ['value'])
      ->condition('sid', $sid)
      ->condition('name', 'phone_number')
      ->condition('property', '')
      ->condition('delta', 0)
      ->execute()
      ->fetchField() ?: '';

    if (!$this->isValidPhone($phone)) {
      $this->logger->warning(
        'Access send skipped for sid=@sid — invalid/empty phone number: "@phone".',
        ['@sid' => $sid, '@phone' => $phone]
      );
      return FALSE;
    }

    $state = $this->getAccessSendState($sid);
    if ($state !== 'unsent') {
      $this->logger->info('Access send skipped for sid=@sid (state=@state).', [
        '@sid'   => $sid,
        '@state' => $state,
      ]);
      return FALSE;
    }
    \Drupal::state()->set($this->accessSendStateKey($sid), 'sending');
    $this->queueFactory->get(self::SEND_QUEUE_NAME)->createItem([
      'type' => 'access',
      'sid'  => $sid,
    ]);
    $this->logger->info('Access card send queued for sid=@sid.', ['@sid' => $sid]);
    return TRUE;
  }

  /**
   * Process a single send queue item.
   * Called by the InvitationQrSendWorker QueueWorker plugin.
   */
  public function processSendQueueItem(array $item): void {
    $sid  = (int) ($item['sid'] ?? 0);
    $type = $item['type'] ?? '';

    if (!$sid || !in_array($type, ['invitation', 'access'])) {
      $this->logger->error('Invalid send queue item: @data', ['@data' => json_encode($item)]);
      return;
    }

    $sub = $this->entityTypeManager->getStorage('webform_submission')->load($sid);
    if (!$sub) {
      $this->logger->error('Send queue: submission @sid not found.', ['@sid' => $sid]);
      // Submission deleted — clear lock so it does not block forever.
      if ($type === 'invitation') $this->resetInvSendState($sid);
      else $this->resetAccessSendState($sid);
      return;
    }

    // Double-check DB sent flag before sending — catches the case where
    // the item was queued twice (e.g. crash before deleteItem() was called
    // but after Twilio API succeeded and wrote the sent flag).
    if ($type === 'invitation') {
      $alreadySent = \Drupal::database()->select('webform_submission_data', 'w')
        ->fields('w', ['value'])
        ->condition('sid', $sid)
        ->condition('name', 'twilio_sent')
        ->condition('property', '')->condition('delta', 0)
        ->execute()->fetchField();
      if ($alreadySent === 'yes') {
        $this->logger->info('Send queue: invitation already sent for sid=@sid — skipping duplicate.', ['@sid' => $sid]);
        \Drupal::state()->delete($this->invSendStateKey($sid));
        return;
      }
    }

    if ($type === 'access') {
      $alreadySent = \Drupal::database()->select('webform_submission_data', 'w')
        ->fields('w', ['value'])
        ->condition('sid', $sid)
        ->condition('name', 'access_card_sent')
        ->condition('property', '')->condition('delta', 0)
        ->execute()->fetchField();
      if ($alreadySent === 'yes') {
        $this->logger->info('Send queue: access card already sent for sid=@sid — skipping duplicate.', ['@sid' => $sid]);
        \Drupal::state()->delete($this->accessSendStateKey($sid));
        return;
      }
    }

    $config = $this->configFactory->get('invitation_qr.settings');
    $data   = $sub->getData();
    $node   = $this->findParentNode($sub);

    if ($type === 'invitation') {
      $fid = $data['stamped_card_fid'] ?? NULL;
      if (!$fid) {
        $this->logger->warning('Send queue: no invitation card fid for sid=@sid.', ['@sid' => $sid]);
        // Card not ready — reset so admin can retry after stamping.
        $this->resetInvSendState($sid);
        return;
      }
      $file = $this->entityTypeManager->getStorage('file')->load($fid);
      if (!$file) {
        $this->logger->error('Send queue: invitation card file missing for sid=@sid.', ['@sid' => $sid]);
        $this->resetInvSendState($sid);
        return;
      }
      $dataWithSid = array_merge($data, ['sid' => $sid]);
      $ok = $node
        ? $this->sendInvitationCard(
            $data['phone_number'] ?? '',
            $data['name'] ?? '',
            $this->getAbsoluteFileUrl($file->getFileUri()),
            $node, $config, $dataWithSid
          )
        : $this->sendViaTwilio(
            $data['phone_number'] ?? '',
            $data['name'] ?? '',
            $this->getAbsoluteFileUrl($file->getFileUri()),
            $config
          );

      if ($ok) {
        // Write DB flag FIRST before clearing state lock.
        $this->saveSubmissionField($sub, 'twilio_sent', 'yes');
        \Drupal::state()->delete($this->invSendStateKey($sid));
        $this->logger->info('Invitation sent and marked for sid=@sid.', ['@sid' => $sid]);
      }
      else {
        // Send failed — write 'failed' as the sent flag so this submission
        // is NEVER re-queued automatically on future batch clicks.
        // Admin must use Resend button (which clears this flag) to retry.
        $this->saveSubmissionField($sub, 'twilio_sent', 'failed');
        \Drupal::state()->delete($this->invSendStateKey($sid));
        $this->logger->error('Invitation send failed for sid=@sid — marked as failed to prevent re-queue.', ['@sid' => $sid]);
      }
    }

    if ($type === 'access') {
      $accessFid = \Drupal::database()->select('webform_submission_data', 'w')
        ->fields('w', ['value'])
        ->condition('sid', $sid)
        ->condition('name', 'access_card_fid')
        ->condition('property', '')->condition('delta', 0)
        ->execute()->fetchField();

      if (!$accessFid) {
        $this->logger->warning('Send queue: no access card fid for sid=@sid.', ['@sid' => $sid]);
        $this->resetAccessSendState($sid);
        return;
      }
      $file = $this->entityTypeManager->getStorage('file')->load((int) $accessFid);
      if (!$file) {
        $this->logger->error('Send queue: access card file missing for sid=@sid.', ['@sid' => $sid]);
        $this->resetAccessSendState($sid);
        return;
      }
      $dataWithSid = array_merge($data, ['sid' => $sid]);
      $ok = $node
        ? $this->sendAccessCard(
            $data['phone_number'] ?? '',
            $data['name'] ?? '',
            $this->getAbsoluteFileUrl($file->getFileUri()),
            $node, $config, $dataWithSid
          )
        : $this->sendViaTwilio(
            $data['phone_number'] ?? '',
            $data['name'] ?? '',
            $this->getAbsoluteFileUrl($file->getFileUri()),
            $config
          );

      if ($ok) {
        // Write DB flag FIRST — ground truth for duplicate detection.
        $this->saveSubmissionField($sub, 'access_card_sent', 'yes');
        \Drupal::state()->delete($this->accessSendStateKey($sid));
        $this->logger->info('Access card sent and marked for sid=@sid.', ['@sid' => $sid]);
      }
      else {
        // Send failed — write 'failed' as the sent flag so this submission
        // is NEVER re-queued automatically on future batch clicks.
        // Admin must use Resend button (which clears this flag) to retry.
        $this->saveSubmissionField($sub, 'access_card_sent', 'failed');
        \Drupal::state()->delete($this->accessSendStateKey($sid));
        $this->logger->error('Access card send failed for sid=@sid — marked as failed to prevent re-queue.', ['@sid' => $sid]);
      }
    }
  }

  // ── Check-in ───────────────────────────────────────────────────────────────

  public function recordCheckin(WebformSubmissionInterface $submission): void {
    $this->saveSubmissionField($submission, 'checkin', 'yes');
    $this->saveSubmissionField($submission, 'checkin_time', (string) \Drupal::time()->getCurrentTime());
    $this->logger->info('Guest checked in for submission @sid.', ['@sid' => $submission->id()]);
  }

  public function isCheckedIn(WebformSubmissionInterface $submission): bool {
    $val = \Drupal::database()->select('webform_submission_data', 'w')
      ->fields('w', ['value'])
      ->condition('sid', $submission->id())
      ->condition('name', 'checkin')
      ->condition('property', '')
      ->condition('delta', 0)
      ->execute()
      ->fetchField();
    return $val === 'yes';
  }

  // ── URL helper ─────────────────────────────────────────────────────────────

  public function getAbsoluteFileUrl(string $fileUri): string {
    return \Drupal::service('file_url_generator')->generateAbsoluteString($fileUri);
  }

  // ── DB helpers ─────────────────────────────────────────────────────────────

  public function saveSubmissionField(WebformSubmissionInterface $submission, string $fieldName, $value): void {
    $db = \Drupal::database();

    try {
      $exists = $db->select('webform_submission_data', 'w')
        ->fields('w', ['sid'])
        ->condition('sid', $submission->id())
        ->condition('name', $fieldName)
        ->condition('property', '')
        ->condition('delta', 0)
        ->execute()
        ->fetchField();

      if ($exists) {
        $db->update('webform_submission_data')
          ->fields(['value' => (string) $value])
          ->condition('sid', $submission->id())
          ->condition('name', $fieldName)
          ->condition('property', '')
          ->condition('delta', 0)
          ->execute();
      }
      else {
        $db->insert('webform_submission_data')
          ->fields([
            'webform_id' => $submission->getWebform()->id(),
            'sid'        => $submission->id(),
            'name'       => $fieldName,
            'property'   => '',
            'delta'      => 0,
            'value'      => (string) $value,
          ])
          ->execute();
      }
    }
    catch (\Throwable $e) {
      $this->logger->error(
        'saveSubmissionField failed for sid=@sid field=@field: @msg',
        ['@sid' => $submission->id(), '@field' => $fieldName, '@msg' => $e->getMessage()]
      );
      throw $e;
    }
  }

  // ── QR helpers ─────────────────────────────────────────────────────────────

  public function generateToken(string $phone, string $uuid): string {
    return strtoupper(substr(hash('sha256', $phone . $uuid), 0, 12));
  }

  public function generateQrPng(string $content, int $size = 150): string {
    $builder = new Builder(
      writer: new PngWriter(),
      data: $content,
      encoding: new Encoding('UTF-8'),
      errorCorrectionLevel: ErrorCorrectionLevel::High,
      size: $size,
      margin: 10,
      roundBlockSizeMode: RoundBlockSizeMode::Margin,
    );
    $result = $builder->build();
    return $result->getString();
  }

  public function calculatePosition(string $pos, int $cW, int $cH, int $qW, int $qH, int $margin): array {
    if ($pos === 'top-left')    return [$margin, $margin];
    if ($pos === 'top-right')   return [$cW - $qW - $margin, $margin];
    if ($pos === 'bottom-left') return [$margin, $cH - $qH - $margin];
    if ($pos === 'center')      return [intdiv($cW - $qW, 2), intdiv($cH - $qH, 2)];
    return [$cW - $qW - $margin, $cH - $qH - $margin];
  }

  // ── Lookup helpers ─────────────────────────────────────────────────────────

  public function findSubmissionByToken(string $token): ?WebformSubmissionInterface {
    $sid = \Drupal::database()->select('webform_submission_data', 'wsd')
      ->fields('wsd', ['sid'])
      ->condition('wsd.name', 'guest_token')
      ->condition('wsd.value', $token)
      ->condition('wsd.property', '')
      ->condition('wsd.delta', 0)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    return $sid
      ? $this->entityTypeManager->getStorage('webform_submission')->load($sid)
      : NULL;
  }

  public function findParentNode(WebformSubmissionInterface $submission): ?object {
    $sourceEntity = $submission->getSourceEntity();
    if ($sourceEntity && $sourceEntity->getEntityTypeId() === 'node') {
      return $sourceEntity;
    }

    $config   = $this->configFactory->get('invitation_qr.settings');
    $nodeType = $config->get('node_type') ?: 'invitation';

    $nids = $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->condition('type', $nodeType)
      ->accessCheck(FALSE)
      ->execute();

    return $nids
      ? $this->entityTypeManager->getStorage('node')->load(reset($nids))
      : NULL;
  }

  public function getSubmissionsForNode(int $nodeId): array {
    $config    = $this->configFactory->get('invitation_qr.settings');
    $webformId = $config->get('webform_id');

    $sids = $this->entityTypeManager->getStorage('webform_submission')
      ->getQuery()
      ->condition('webform_id', $webformId)
      ->condition('entity_type', 'node')
      ->condition('entity_id', $nodeId)
      ->sort('created', 'ASC')
      ->accessCheck(FALSE)
      ->execute();

    return $sids
      ? $this->entityTypeManager->getStorage('webform_submission')->loadMultiple($sids)
      : [];
  }

}