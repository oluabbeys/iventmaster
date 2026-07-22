<?php

namespace Drupal\invitation_qr\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\invitation_qr\Service\InvitationQrService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles inbound Twilio WhatsApp/SMS messages for RSVP.
 *
 * Intent resolution order:
 *  1. ButtonPayload parameter (Quick Reply button ID — most reliable)
 *  2. ButtonText parameter (button display text)
 *  3. Body text keyword matching (free-form replies)
 */
class TwilioWebhookController extends ControllerBase {

  protected InvitationQrService $qrService;
  protected string $logPath;

  public function __construct(InvitationQrService $qrService) {
    $this->qrService = $qrService;
    $this->logPath   = DRUPAL_ROOT . '/sites/default/files/invitation_qr_webhook.log';
  }

  public static function create(ContainerInterface $container): static {
    return new static($container->get('invitation_qr.qr_service'));
  }

  public function incoming(Request $request): Response {
    if ($request->getMethod() !== 'POST') {
      return new Response(
        '<html><body><h2>Invitation QR — Twilio Webhook</h2><p>Endpoint is active.</p><p>Time: ' . date('Y-m-d H:i:s') . '</p></body></html>',
        200,
        ['Content-Type' => 'text/html', 'Cache-Control' => 'no-store']
      );
    }

    // ── Status callback from Twilio ─────────────────────────────────────────
    // Twilio sends MessageStatus when reporting delivery updates.
    // This is separate from inbound RSVP messages.
    $messageStatus = $request->request->get('MessageStatus', '');
    $messageSid    = $request->request->get('MessageSid', '');

    if ($messageStatus && $messageSid) {
      return $this->handleStatusCallback($messageSid, $messageStatus, $request);
    }

    $ts            = date('Y-m-d H:i:s');
    $from          = $request->request->get('From', '(none)');
    $body          = $request->request->get('Body', '');
    $buttonPayload = $request->request->get('ButtonPayload', '');
    $buttonText    = $request->request->get('ButtonText', '');
    $waId          = $request->request->get('WaId', '');
    $ip            = $request->getClientIp();

    $this->log("=== POST RECEIVED [$ts] from_ip=$ip From=$from WaId=$waId Body=$body ButtonPayload=$buttonPayload ButtonText=$buttonText");

    try {
      $config = \Drupal::config('invitation_qr.settings');
      $this->log("config loaded OK, rsvp_enabled=" . ($config->get('rsvp_enabled') ? 'true' : 'false'));
    }
    catch (\Throwable $e) {
      $this->log("FATAL: could not load config: " . $e->getMessage());
      return $this->twiml('');
    }

    $authToken = $config->get('twilio_auth_token');
    if ($authToken) {
      if (!$this->validateSignature($request, $authToken)) {
        $this->log("BLOCKED: invalid Twilio signature");
        return $this->twiml('');
      }
      $this->log("signature OK");
    }

    if (!$config->get('rsvp_enabled')) {
      $this->log("BLOCKED: RSVP is disabled in settings");
      return $this->twiml('');
    }

    $phone = $this->normalisePhone($from, $waId);
    $this->log("normalised phone=$phone");

    if (!$phone) {
      $this->log("ABORT: could not parse phone number");
      return $this->twiml('Sorry, we could not process your message. Please contact the organiser.');
    }

    $webformId = $config->get('webform_id') ?: 'invitation_webform';
    $this->log("looking up webform_id=$webformId");

    try {
      $submission = $this->findSubmissionByPhone($phone, $webformId);
    }
    catch (\Throwable $e) {
      $this->log("FATAL in findSubmissionByPhone: " . $e->getMessage());
      return $this->twiml('Sorry, a system error occurred. Please try again later.');
    }

    if (!$submission) {
      $this->log("NO MATCH: phone=$phone not found");
      return $this->twiml('Sorry, we could not find your invitation. Please contact the event organiser.');
    }

    $data = $submission->getData();
    $name = $data['name'] ?? '';
    $this->log("MATCHED: sid={$submission->id()} name=$name");

    // Resolve intent — check ButtonPayload first (most reliable for Quick Reply).
    $intent = $this->resolveIntent($buttonPayload, $buttonText, $body, $config);
    $this->log("intent=$intent (buttonPayload=$buttonPayload buttonText=$buttonText body=$body)");

    // If RSVP already recorded, do not process again — just acknowledge silently.
    // This prevents the reply loop where every inbound message triggers a new reply.
    $existingRsvp = \Drupal::database()->select('webform_submission_data', 'w')
      ->fields('w', ['value'])
      ->condition('sid', $submission->id())
      ->condition('name', 'rsvp')
      ->condition('property', '')
      ->condition('delta', 0)
      ->execute()
      ->fetchField();

    if ($existingRsvp && !in_array($intent, ['yes', 'no'])) {
      // Guest already replied and this new message is not a clear yes/no.
      // Silently ignore — do not send another RSVP confirmation.
      $this->log("RSVP already recorded ($existingRsvp) and intent is not yes/no — ignoring inbound message.");
      return $this->twiml('');
    }

    if ($existingRsvp && in_array($intent, ['yes', 'no']) && $existingRsvp === $intent) {
      // Guest replied the same answer again — just acknowledge once, no state change.
      $this->log("RSVP already recorded ($existingRsvp) and same intent received — sending silent ack.");
      return $this->twiml('');
    }

    $reply = '';
    try {
      switch ($intent) {
        case 'yes':
          $this->log("saving rsvp=yes to sid={$submission->id()}");
          $this->qrService->saveSubmissionField($submission, 'rsvp', 'yes');
          $this->log("rsvp=yes saved");
          $this->qrService->saveSubmissionField($submission, 'rsvp_time', (string) \Drupal::time()->getCurrentTime());
          $this->log("rsvp_time saved");
          $this->incrementReplyCount($submission);
          $this->log("reply_count incremented");
          $reply = $this->personalise($config->get('rsvp_reply_yes') ?: 'Thank you @name! We look forward to seeing you!', $name);
          $this->log("RSVP saved: YES for $name");
          break;

        case 'no':
          $this->log("saving rsvp=no to sid={$submission->id()}");
          $this->qrService->saveSubmissionField($submission, 'rsvp', 'no');
          $this->log("rsvp=no saved");
          $this->qrService->saveSubmissionField($submission, 'rsvp_time', (string) \Drupal::time()->getCurrentTime());
          $this->log("rsvp_time saved");
          $this->incrementReplyCount($submission);
          $this->log("reply_count incremented");
          $reply = $this->personalise($config->get('rsvp_reply_no') ?: 'Thank you @name. We will miss you!', $name);
          $this->log("RSVP saved: NO for $name");
          break;

        default:
          $reply = $this->personalise(
            $config->get('rsvp_reply_unknown') ?: 'Hi @name! Please reply YES to confirm or NO to decline.',
            $name
          );
          $this->log("RSVP: unknown intent, sent prompt reply");
          break;
      }
    }
    catch (\Throwable $e) {
      $this->log("FATAL saving RSVP: " . $e->getMessage() . " in " . $e->getFile() . " line " . $e->getLine());
      $reply = $this->personalise(
        $config->get('rsvp_reply_yes') ?: 'Thank you @name! We look forward to seeing you!',
        $name
      );
    }

    $this->log("reply sent: $reply");
    return $this->twiml($reply);
  }

  /**
   * Resolves RSVP intent from available parameters.
   *
   * Priority:
   *  1. ButtonPayload — the button ID set in Twilio template (e.g. "yes", "no")
   *     Most reliable — set by developer, not user.
   *  2. ButtonText — the button display text (e.g. "Yes, I'll attend")
   *     Check against keywords in case ButtonPayload is empty.
   *  3. Body — free-form text reply keyword matching.
   */
  protected function resolveIntent(string $buttonPayload, string $buttonText, string $body, $config): string {
    $yesKws = array_filter(array_map('trim', explode(',',
      $config->get('rsvp_yes_keywords') ?: 'yes,yeah,yep,coming,confirm,attending')));
    $noKws  = array_filter(array_map('trim', explode(',',
      $config->get('rsvp_no_keywords') ?: 'no,nope,cannot,cancel,decline')));

    // 1. Check ButtonPayload first (exact match on button ID).
    if (!empty($buttonPayload)) {
      $bp = strtolower(trim($buttonPayload));
      foreach ($yesKws as $kw) {
        if ($kw && ($bp === $kw || str_contains($bp, $kw))) return 'yes';
      }
      foreach ($noKws as $kw) {
        if ($kw && ($bp === $kw || str_contains($bp, $kw))) return 'no';
      }
      // If ButtonPayload exists but doesn't match keywords, still try to resolve
      // as yes/no based on common button IDs.
      if (in_array($bp, ['yes', 'y', '1', 'confirm', 'attending', 'attend'])) return 'yes';
      if (in_array($bp, ['no', 'n', '0', 'decline', 'cancel', 'cant', "can't"])) return 'no';
    }

    // 2. Check ButtonText (display label of the button).
    if (!empty($buttonText)) {
      $bt = strtolower(trim($buttonText));
      foreach ($yesKws as $kw) {
        if ($kw && str_contains($bt, $kw)) return 'yes';
      }
      foreach ($noKws as $kw) {
        if ($kw && str_contains($bt, $kw)) return 'no';
      }
      // Common button text patterns.
      if (str_contains($bt, "i'll attend") || str_contains($bt, 'i will') || str_contains($bt, 'attending')) return 'yes';
      if (str_contains($bt, "can't make") || str_contains($bt, 'cannot make') || str_contains($bt, 'not coming')) return 'no';
    }

    // 3. Fall back to Body text keyword matching.
    if (!empty($body)) {
      $lower = strtolower(trim($body));
      foreach ($yesKws as $kw) {
        if ($kw && str_contains($lower, $kw)) return 'yes';
      }
      foreach ($noKws as $kw) {
        if ($kw && str_contains($lower, $kw)) return 'no';
      }
    }

    return 'unknown';
  }

  /**
   * Handles Twilio status callbacks.
   *
   * Twilio POSTs to this same endpoint with MessageSid + MessageStatus.
   * We look up the submission by MessageSid and update the delivery status field.
   *
   * Possible statuses from Twilio:
   *  queued      → passed to Twilio (we set this ourselves on send)
   *  sent        → Twilio passed to WhatsApp/carrier
   *  delivered   → confirmed delivered to the device
   *  read        → recipient opened/read it (WhatsApp only)
   *  failed      → invalid number, blocked, or Twilio error
   *  undelivered → carrier rejected or phone unreachable
   */
  protected function handleStatusCallback(string $messageSid, string $messageStatus, Request $request): Response {
    // Respond to Twilio IMMEDIATELY — before any DB work.
    // Twilio times out at 15 seconds. Any processing after this point
    // does not affect the response Twilio receives.
    // We use output buffering to flush the 204 response first.
    if (function_exists('fastcgi_finish_request')) {
      // LiteSpeed / PHP-FPM: send response to Twilio now, keep processing.
      http_response_code(204);
      header('Content-Type: text/plain');
      header('Content-Length: 0');
      fastcgi_finish_request();
    }

    // Now do the DB work — Twilio already has its 204 response.
    $db = \Drupal::database();

    // Only log final statuses — skip intermediate 'queued'/'sent' to reduce
    // log noise and DB writes during high-volume sends.
    $finalStatuses = ['delivered', 'read', 'failed', 'undelivered'];
    $logAll        = in_array($messageStatus, $finalStatuses);

    if ($logAll) {
      $this->log("STATUS CALLBACK: SID=$messageSid status=$messageStatus");
    }

    // Look up by invitation message SID.
    $sid = $db->select('webform_submission_data', 'w')
      ->fields('w', ['sid'])
      ->condition('name', 'inv_twilio_message_sid')
      ->condition('value', $messageSid)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if ($sid) {
      $sub = \Drupal::entityTypeManager()->getStorage('webform_submission')->load($sid);
      if ($sub) {
        $this->qrService->saveSubmissionField($sub, 'inv_delivery_status', $messageStatus);
        if ($logAll) {
          $this->log("STATUS saved: inv_delivery_status=$messageStatus for sid=$sid");
        }
      }
      return new Response('', 204);
    }

    // Look up by access card message SID.
    $sid = $db->select('webform_submission_data', 'w')
      ->fields('w', ['sid'])
      ->condition('name', 'access_twilio_message_sid')
      ->condition('value', $messageSid)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if ($sid) {
      $sub = \Drupal::entityTypeManager()->getStorage('webform_submission')->load($sid);
      if ($sub) {
        $this->qrService->saveSubmissionField($sub, 'access_delivery_status', $messageStatus);
        if ($logAll) {
          $this->log("STATUS saved: access_delivery_status=$messageStatus for sid=$sid");
        }
      }
      return new Response('', 204);
    }

    return new Response('', 204);
  }

  protected function log(string $message): void {
    try {
      file_put_contents(
        $this->logPath,
        '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
        FILE_APPEND | LOCK_EX
      );
    }
    catch (\Throwable $e) {
      error_log('invitation_qr webhook log failed: ' . $e->getMessage());
    }
  }

  protected function normalisePhone(string $from, string $waId = ''): string {
    if ($waId) {
      return '+' . preg_replace('/[^0-9]/', '', $waId);
    }
    $phone = str_ireplace('whatsapp:', '', $from);
    $phone = preg_replace('/[^0-9+]/', '', trim($phone));
    if ($phone && !str_starts_with($phone, '+')) {
      $phone = '+' . $phone;
    }
    return $phone;
  }

  protected function findSubmissionByPhone(string $phone, string $webformId): ?object {
    $db     = \Drupal::database();
    $digits = preg_replace('/[^0-9]/', '', $phone);

    $variants = array_unique(array_filter([
      $phone,
      $digits,
      ltrim($phone, '+'),
      (str_starts_with($digits, '234') && strlen($digits) === 13)
        ? '0' . substr($digits, 3) : '',
      (str_starts_with($digits, '0') && strlen($digits) === 11)
        ? '+234' . substr($digits, 1) : '',
      strlen($digits) > 10 ? substr($digits, -10) : '',
    ]));

    $this->log("phone variants: " . implode(', ', $variants));

    foreach ($variants as $variant) {
      $sid = $db->select('webform_submission_data', 'wsd')
        ->fields('wsd', ['sid'])
        ->condition('wsd.name', 'phone_number')
        ->condition('wsd.value', '%' . $db->escapeLike($variant) . '%', 'LIKE')
        ->range(0, 1)
        ->execute()
        ->fetchField();

      if ($sid) {
        $sub = \Drupal::entityTypeManager()
          ->getStorage('webform_submission')
          ->load($sid);
        if ($sub && $sub->getWebform()->id() === $webformId) {
          $this->log("variant '$variant' matched sid=$sid");
          return $sub;
        }
      }
    }

    $stored = $db->select('webform_submission_data', 'wsd')
      ->fields('wsd', ['sid', 'value'])
      ->condition('wsd.name', 'phone_number')
      ->range(0, 20)
      ->execute()
      ->fetchAllKeyed();
    $this->log("no match found. stored numbers: " . json_encode($stored));

    return NULL;
  }

  protected function personalise(string $tpl, string $name): string {
    return str_replace(['@name', '{{Guest name}}'], [$name, $name], $tpl);
  }

  protected function incrementReplyCount(object $submission): void {
    $current = (int) (\Drupal::database()
      ->select('webform_submission_data', 'w')
      ->fields('w', ['value'])
      ->condition('sid', $submission->id())
      ->condition('name', 'rsvp_reply_count')
      ->condition('property', '')
      ->condition('delta', 0)
      ->execute()
      ->fetchField() ?: 0);
    $this->qrService->saveSubmissionField($submission, 'rsvp_reply_count', $current + 1);
  }

  protected function validateSignature(Request $request, string $authToken): bool {
    $signature = $request->headers->get('X-Twilio-Signature', '');
    if (!$signature) return TRUE;
    $url    = $request->getSchemeAndHttpHost() . $request->getRequestUri();
    $params = $request->request->all();
    ksort($params);
    $str = $url;
    foreach ($params as $k => $v) { $str .= $k . $v; }
    return hash_equals(
      base64_encode(hash_hmac('sha1', $str, $authToken, TRUE)),
      $signature
    );
  }

  protected function twiml(string $message): Response {
    $safe = htmlspecialchars($message, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $xml  = '<?xml version="1.0" encoding="UTF-8"?><Response>';
    if ($message !== '') $xml .= '<Message>' . $safe . '</Message>';
    $xml .= '</Response>';
    return new Response($xml, 200, [
      'Content-Type'  => 'text/xml',
      'Cache-Control' => 'no-store, no-cache, must-revalidate',
    ]);
  }

}