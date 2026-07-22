<?php

namespace Drupal\invitation_qr\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Grants unconditional access to the Twilio webhook endpoint.
 *
 * This is intentionally open — security is handled inside the controller
 * via X-Twilio-Signature validation, not Drupal's access system.
 */
class TwilioWebhookAccess implements AccessInterface {

  public function access(AccountInterface $account): AccessResult {
    return AccessResult::allowed()->setCacheMaxAge(0);
  }

}
