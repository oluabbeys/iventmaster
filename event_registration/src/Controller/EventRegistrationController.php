<?php

namespace Drupal\event_registration\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mobile-app-facing registration API.
 *
 * Architecture notes (read this before changing anything):
 *  - Every Event node has its OWN dedicated webform (the "Webform1" field,
 *    machine name field_webform1, e.g. "webform_1273" for node 1273). That
 *    per-event webform — NOT the site-wide "Payment"/"Transaction Fees"
 *    webforms and NOT the separate "invitation_webform" used by the
 *    invitation_qr module for its own WhatsApp/VIP-invite pipeline — is the
 *    real attendee-facing registration form for that event.
 *  - Different events' webforms are NOT identically shaped (some ask for
 *    Organization, some don't; some use a custom composite element for
 *    name/email/phone, some don't). schema() below walks whatever elements
 *    the event's actual webform has and returns a sanitised, app-renderable
 *    description of them, rather than assuming a fixed field set.
 *  - v1 scope is FREE events only. Paid events (field_free_or_paid = paid)
 *    go through a Paystack subaccount / split-payment flow on the website
 *    that this API does not attempt to replicate — schema() reports
 *    enabled=false with reason=paid_not_supported for those, and the app
 *    should send the user to the website instead of showing a form.
 *  - A created submission is owned by the authenticated app user (uid) and
 *    tied to the event node as its Webform "source entity" — this is what
 *    lets My Events show a real ticket and, later, lets chat build its
 *    per-event participant roster from real registrants instead of demo
 *    data.
 *  - The ticket/QR content deliberately reuses the site's own existing
 *    convention ("{nid}/{serial}", the same token the webform's built-in QR
 *    Code element already prints on the website's confirmation page) rather
 *    than inventing a new format, so anything staff already do with that
 *    QR (or any future scanner) keeps working for app-made registrations
 *    too.
 */
class EventRegistrationController extends ControllerBase {

  /**
   * Element types that are never shown to the attendee as an input —
   * display-only, computed, or otherwise not something we accept from the
   * app in v1 (file uploads are a phase-2 item: mobile multipart upload
   * needs its own handling this endpoint doesn't attempt yet).
   */
  protected const SKIP_TYPES = [
    'markup', 'webform_actions', 'webform_wizard_page', 'container',
    'fieldset', 'details', 'webform_computed_twig', 'webform_computed_token',
    'processed_text', 'qr_code', 'webform_image_file', 'webform_document_file',
    'managed_file', 'hidden', 'value',
  ];

  /**
   * Element types we know how to render as a simple text-ish input.
   */
  protected const TEXT_TYPES = [
    'textfield', 'email', 'tel', 'number', 'textarea', 'date',
  ];

  /**
   * Element types we know how to render as a single choice.
   */
  protected const CHOICE_TYPES = ['select', 'radios'];

  public static function create(ContainerInterface $container): static {
    return new static();
  }

  /**
   * GET /api/event-registration/{node}/schema
   */
  public function schema(NodeInterface $node): JsonResponse {
    if ($node->bundle() !== 'event') {
      return new JsonResponse(['error' => 'Not an event.'], 404);
    }

    $webform = $this->loadEventWebform($node);
    $freeOrPaid = $this->fieldValue($node, 'field_free_or_paid');
    $enabled = (bool) $this->fieldValue($node, 'field_enable_registration');

    $base = [
      'nid' => $node->id(),
      'title' => $node->label(),
      'free_or_paid' => $freeOrPaid ?: 'free',
    ];

    if (!$enabled) {
      return new JsonResponse($base + ['enabled' => FALSE, 'reason' => 'registration_disabled']);
    }
    if ($freeOrPaid && $freeOrPaid !== 'free') {
      return new JsonResponse($base + ['enabled' => FALSE, 'reason' => 'paid_not_supported']);
    }
    if (!$webform) {
      return new JsonResponse($base + ['enabled' => FALSE, 'reason' => 'no_webform']);
    }

    $alreadyRegistered = $this->findOwnSubmission($webform->id(), $node->id());
    if (!$alreadyRegistered) {
      // Someone may have filled this event's webform on the attendee's
      // behalf (staff at registration, a colleague's shared/admin session,
      // or a registration made before this API existed) -- that
      // submission's uid won't be this account, but the email they typed
      // in will be theirs. See findSubmissionByEmail() for why email is the
      // more reliable "already registered" signal.
      $email = $this->currentUserEmail();
      if ($email) {
        $alreadyRegistered = $this->findSubmissionByEmail($webform, $node->id(), $email);
      }
    }

    return new JsonResponse($base + [
      'enabled' => TRUE,
      'webform_id' => $webform->id(),
      'already_registered' => $alreadyRegistered ? [
        'sid' => (int) $alreadyRegistered->id(),
        'serial' => (int) $alreadyRegistered->serial(),
        'qr_content' => $node->id() . '/' . $alreadyRegistered->serial(),
      ] : NULL,
      'fields' => $this->buildFieldSchema($webform),
    ]);
  }

  /**
   * POST /api/event-registration/{node}/submit
   */
  public function submit(Request $request, NodeInterface $node): JsonResponse {
    if ($node->bundle() !== 'event') {
      return new JsonResponse(['error' => 'Not an event.'], 404);
    }
    if (!$this->fieldValue($node, 'field_enable_registration')) {
      return new JsonResponse(['error' => 'Registration is not open for this event.'], 403);
    }
    $freeOrPaid = $this->fieldValue($node, 'field_free_or_paid');
    if ($freeOrPaid && $freeOrPaid !== 'free') {
      return new JsonResponse(['error' => 'This event requires payment on the website.'], 409);
    }

    $webform = $this->loadEventWebform($node);
    if (!$webform) {
      return new JsonResponse(['error' => 'This event has no registration form configured.'], 404);
    }

    $uid = (int) $this->currentUser()->id();
    if ($uid <= 0) {
      return new JsonResponse(['error' => 'Sign-in required.'], 401);
    }

    // Idempotency: never let one attendee create two tickets for the same
    // event from the app. Checked by uid first, then by email (see
    // findSubmissionByEmail()) so someone already registered under a
    // different session -- staff at the desk, a shared/admin login --
    // doesn't get a second, duplicate ticket just because it's their first
    // time registering through the app.
    $existing = $this->findOwnSubmission($webform->id(), $node->id(), $uid);
    if (!$existing) {
      $email = $this->currentUserEmail();
      if ($email) {
        $existing = $this->findSubmissionByEmail($webform, $node->id(), $email);
      }
    }
    if ($existing) {
      return new JsonResponse([
        'success' => TRUE,
        'already_registered' => TRUE,
        'sid' => (int) $existing->id(),
        'serial' => (int) $existing->serial(),
        'qr_content' => $node->id() . '/' . $existing->serial(),
      ]);
    }

    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload)) {
      return new JsonResponse(['error' => 'Invalid JSON body.'], 400);
    }

    $schema = $this->buildFieldSchema($webform);
    [$data, $missing] = $this->reconcileSubmissionData($schema, $payload);
    if ($missing) {
      return new JsonResponse(['error' => 'Missing required field(s).', 'missing' => $missing], 422);
    }

    try {
      /** @var \Drupal\webform\Entity\WebformSubmission $submission */
      $submission = WebformSubmission::create([
        'webform_id' => $webform->id(),
        'entity_type' => 'node',
        'entity_id' => $node->id(),
        'uid' => $uid,
        'data' => $data,
      ]);
      $submission->save();
    }
    catch (\Throwable $e) {
      $this->getLogger('event_registration')->error('Registration failed for node @nid / uid @uid: @msg', [
        '@nid' => $node->id(),
        '@uid' => $uid,
        '@msg' => $e->getMessage(),
      ]);
      return new JsonResponse(['error' => 'Could not save registration.'], 500);
    }

    return new JsonResponse([
      'success' => TRUE,
      'already_registered' => FALSE,
      'sid' => (int) $submission->id(),
      'serial' => (int) $submission->serial(),
      'qr_content' => $node->id() . '/' . $submission->serial(),
      'event_title' => $node->label(),
    ], 201);
  }

  /**
   * POST /api/event-registration/{node}/login-link
   *
   * Mints a single-use, 2-minute link that logs the already-OAuth-authenticated
   * app user into a normal Drupal session and lands them on the event's page —
   * used so the "Register" button can hand off to the real website (which
   * already knows how to render this event's specific webform AND, for paid
   * events, its Paystack checkout) without the attendee having to sign in
   * again or retype their details. See event_registration.module's
   * hook_form_alter() for the prefill half of this.
   */
  public function mobileLoginLink(NodeInterface $node): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    if ($uid <= 0) {
      return new JsonResponse(['error' => 'Sign-in required.'], 401);
    }

    $token = bin2hex(random_bytes(32));
    \Drupal::keyValueExpirable('event_registration_login_tokens')->setWithExpire($token, $uid, 120);

    $destination = $node->toUrl()->toString();
    $url = Url::fromRoute('event_registration.mobile_login', ['token' => $token], [
      'absolute' => TRUE,
      'query' => ['destination' => $destination],
    ])->toString();

    return new JsonResponse(['url' => $url]);
  }

  /**
   * GET /mobile-login/{token} — public route (the whole point is the app
   * user isn't in a browser session yet). Single-use and short-lived: the
   * token is deleted the moment it's read, valid or not, so a link can never
   * be replayed.
   */
  public function mobileLogin(Request $request, string $token): Response {
    $store = \Drupal::keyValueExpirable('event_registration_login_tokens');
    $uid = $store->get($token);
    $store->delete($token);

    $destination = $request->query->get('destination', '/');
    // Never redirect off-site with a route that just authenticated someone —
    // only allow a same-site path, never a protocol-relative or absolute URL.
    if (!is_string($destination) || $destination === '' || $destination[0] !== '/' || str_starts_with($destination, '//')) {
      $destination = '/';
    }

    if (!$uid) {
      // Expired or already-used link — send them to ordinary login rather
      // than silently landing them on the event page still signed out.
      return new RedirectResponse(Url::fromRoute('user.login', [], [
        'query' => ['destination' => $destination],
      ])->toString());
    }

    $account = $this->entityTypeManager()->getStorage('user')->load($uid);
    if (!$account || !$account->isActive()) {
      return new RedirectResponse(Url::fromRoute('user.login', [], [
        'query' => ['destination' => $destination],
      ])->toString());
    }

    user_login_finalize($account);
    // Read by event_registration.module's hook_form_alter() to prefill the
    // webform on the page this redirects to. Short-lived on purpose (it's a
    // per-session flag, not per-request) — it only ever pre-fills fields, so
    // there's no harm if it's still set for a few minutes of browsing.
    \Drupal::service('tempstore.private')->get('event_registration')->set('app_prefill', TRUE);

    return new RedirectResponse(Url::fromUserInput($destination)->toString());
  }

  /**
   * GET /api/me
   *
   * The app's own "who am I" call, used for the post-login welcome
   * greeting. Deliberately returns only the same safe subset of account
   * fields the webform prefill hook reads (event_registration.module) —
   * never BVN, NIN, CAC Certificate or Address, which this account also
   * happens to carry. No new OAuth scope needed: _user_is_logged_in just
   * checks the token is authenticated at all, it doesn't call
   * hasPermission(), so it isn't affected by Simple OAuth's per-scope
   * permission restriction the way a JSON:API user lookup would be.
   */
  public function me(): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    if ($uid <= 0) {
      return new JsonResponse(['error' => 'Sign-in required.'], 401);
    }

    $account = $this->entityTypeManager()->getStorage('user')->load($uid);
    if (!$account) {
      return new JsonResponse(['error' => 'Account not found.'], 404);
    }

    $safe = static function (string $field) use ($account): ?string {
      if (!$account->hasField($field) || $account->get($field)->isEmpty()) {
        return NULL;
      }
      $value = $account->get($field)->value;
      return is_string($value) && $value !== '' ? $value : NULL;
    };

    return new JsonResponse([
      'name' => $this->resolveDisplayName($account),
      'email' => $safe('field_email') ?? $account->getEmail(),
      'organization' => $safe('field_name_of_establishment'),
    ]);
  }

  /**
   * The Drupal account's own name field is a login username (often an
   * email address or an arbitrary handle picked at signup), never a real
   * first name -- there is no first-name field on the User entity at all.
   * The user's REAL name was captured once already, in whichever event
   * registration webform they filled in through the app, so this looks
   * up that user's own most recent submission (any event, any webform)
   * and extracts a name from it with the same nameElementKeys() /
   * extractName() logic already used for the participants list. Falls
   * back to the account's display name when no submission has a usable
   * name, so this never returns an empty string.
   */
  protected function resolveDisplayName(UserInterface $account): string {
    $fallback = $account->getDisplayName();

    $submission = $this->mostRecentOwnSubmission((int) $account->id());
    if (!$submission) {
      return $fallback;
    }

    $webform = $submission->getWebform();
    if (!$webform) {
      return $fallback;
    }

    $nameKeys = $this->nameElementKeys($webform);
    $extracted = $this->extractName($submission->getData(), $nameKeys);

    return $extracted ?? $fallback;
  }

  /**
   * This account's own most recent webform submission, across ANY event
   * or webform -- unlike findOwnSubmission()/findSubmissionByEmail(),
   * which are both scoped to one event's webform, this is a general
   * "what did this signed-in user last submit" lookup, used to recover a
   * real name for the welcome greeting.
   */
  protected function mostRecentOwnSubmission(int $uid): ?WebformSubmission {
    if ($uid <= 0) {
      return NULL;
    }
    $sids = $this->entityTypeManager()->getStorage('webform_submission')
      ->getQuery()
      ->condition('uid', $uid)
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();
    if (!$sids) {
      return NULL;
    }
    $submission = $this->entityTypeManager()->getStorage('webform_submission')->load(reset($sids));
    return $submission instanceof WebformSubmission ? $submission : NULL;
  }

  /**
   * GET /api/my-event-registrations
   *
   * The app manages several events at once (Discover lists many, and an
   * attendee can be registered for more than one) — this returns ALL of the
   * signed-in user's real registrations in one call, across every event's
   * own per-event webform, so My Events never has to guess.
   */
  public function myRegistrations(): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    if ($uid <= 0) {
      return new JsonResponse(['error' => 'Sign-in required.'], 401);
    }

    // Every 'event' node that has a per-event webform wired up -- via
    // EITHER of the two places loadEventWebform() itself knows to look
    // (the Webform-reference field, or the plain-text id field some
    // events use instead; see loadEventWebform()'s own doc comment).
    // Filtering on field_webform1 alone silently dropped any event that
    // only has field_webform_id set, so an attendee registered for one
    // of THOSE events never saw it in My Events even though schema()/
    // participants() on that same node worked fine.
    $nodeQuery = $this->entityTypeManager()->getStorage('node')
      ->getQuery()
      ->condition('type', 'event')
      ->accessCheck(FALSE);
    $nodeQuery->condition(
      $nodeQuery->orConditionGroup()
        ->exists('field_webform1')
        ->exists('field_webform_id')
    );
    $nids = $nodeQuery->execute();

    if (!$nids) {
      return new JsonResponse(['registrations' => []]);
    }

    $nodes = $this->entityTypeManager()->getStorage('node')->loadMultiple($nids);
    $webformIdToNode = [];
    foreach ($nodes as $node) {
      $webform = $this->loadEventWebform($node);
      if ($webform) {
        $webformIdToNode[$webform->id()] = $node;
      }
    }
    if (!$webformIdToNode) {
      return new JsonResponse(['registrations' => []]);
    }

    $sids = $this->entityTypeManager()->getStorage('webform_submission')
      ->getQuery()
      ->condition('webform_id', array_keys($webformIdToNode), 'IN')
      ->condition('uid', $uid)
      ->condition('entity_type', 'node')
      ->accessCheck(FALSE)
      ->execute();

    $registrations = [];
    $matchedNids = [];
    if ($sids) {
      $submissions = $this->entityTypeManager()->getStorage('webform_submission')->loadMultiple($sids);
      foreach ($submissions as $submission) {
        $node = $webformIdToNode[$submission->getWebform()->id()] ?? NULL;
        if (!$node || !$submission instanceof WebformSubmission) {
          continue;
        }
        $registrations[] = $this->buildRegistrationEntry($node, $submission);
        $matchedNids[(int) $node->id()] = TRUE;
      }
    }

    // Second pass, by email: someone may have filled an event's webform on
    // the attendee's behalf (staff at registration, a colleague's
    // shared/admin session, or a registration made before this API
    // existed) -- that submission's uid won't be this account, but the
    // email typed into it will be theirs. Bounded to events not already
    // matched above, one event at a time, so this stays cheap relative to
    // the uid-based bulk query.
    $email = $this->currentUserEmail();
    if ($email) {
      foreach ($webformIdToNode as $webformId => $node) {
        $nid = (int) $node->id();
        if (isset($matchedNids[$nid])) {
          continue;
        }
        $webform = Webform::load($webformId);
        if (!$webform) {
          continue;
        }
        $byEmail = $this->findSubmissionByEmail($webform, $nid, $email);
        if ($byEmail) {
          $registrations[] = $this->buildRegistrationEntry($node, $byEmail);
          $matchedNids[$nid] = TRUE;
        }
      }
    }

    return new JsonResponse(['registrations' => $registrations]);
  }

  /**
   * Shapes one registration entry for /api/my-event-registrations, shared
   * by the uid-matched pass and the email-matched top-up pass above so
   * both produce identically-shaped results.
   */
  protected function buildRegistrationEntry(NodeInterface $node, WebformSubmission $submission): array {
    return [
      'nid' => (int) $node->id(),
      'event_title' => $node->label(),
      'sid' => (int) $submission->id(),
      'serial' => (int) $submission->serial(),
      'qr_content' => $node->id() . '/' . $submission->serial(),
      'registered_at' => (int) $submission->getCreatedTime(),
      // So the app can split My Events into Upcoming/Past without a
      // second round trip per event -- same two fields Discover already
      // reads (see IventEvent.fromJsonApi on the Flutter side).
      'event_start' => $this->fieldValue($node, 'field_event_start'),
      'event_end' => $this->fieldValue($node, 'field_event_date'),
    ];
  }

  // ── Helpers ─────────────────────────────────────────────────────────────

  /**
   * GET /api/event-registration/{node}/participants
   *
   * The event's attendee roster -- name and organisation only, for
   * in-person networking (see chat's per-event roster on the plan doc).
   * Deliberately never returns the sensitive identity fields (BVN, NIN,
   * CAC Certificate, Address) or contact details (email, phone) any of
   * these accounts might also carry -- same "only what's needed" rule
   * event_registration.module's prefill hook already follows. Visible to
   * any authenticated app user, same access as the event's own schema().
   */
  public function participants(Request $request, NodeInterface $node): JsonResponse {
    if ($node->bundle() !== 'event') {
      return new JsonResponse(['error' => 'Not an event.'], 404);
    }

    // Paginated -- a popular event's roster can run into the hundreds, and
    // the app asks for it a page at a time (see MyRegistration... no, see
    // EventParticipant/eventParticipantsProvider on the Flutter side).
    $limit = max(1, min(100, (int) ($request->query->get('limit') ?? 30)));
    $offset = max(0, (int) ($request->query->get('offset') ?? 0));

    $webform = $this->loadEventWebform($node);
    if (!$webform) {
      return new JsonResponse(['participants' => [], 'total' => 0, 'has_more' => FALSE]);
    }

    $sids = $this->entityTypeManager()->getStorage('webform_submission')
      ->getQuery()
      ->condition('webform_id', $webform->id())
      ->condition('entity_type', 'node')
      ->condition('entity_id', $node->id())
      ->accessCheck(FALSE)
      ->execute();

    if (!$sids) {
      return new JsonResponse(['participants' => [], 'total' => 0, 'has_more' => FALSE]);
    }

    $total = count($sids);

    $nameKeys = $this->nameElementKeys($webform);
    $orgKeys = $this->organizationElementKeys($webform);

    // Search mode: a name/organization filter, checked against every
    // registrant for this event rather than one page at a time. Per-event
    // rosters run to the low hundreds at most, so filtering in PHP with
    // the exact same extractName()/extractFirstValue() helpers already
    // trusted for plain pagination is simpler and safer than a second,
    // untested query path against webform_submission_data's composite-
    // element storage. Search results aren't paginated -- capped at 200
    // matches, newest-registered first, with has_more always FALSE.
    $query = trim((string) ($request->query->get('q') ?? ''));
    if ($query !== '') {
      $needle = mb_strtolower($query);
      $allIds = $sids;
      rsort($allIds);
      $candidates = $this->entityTypeManager()->getStorage('webform_submission')->loadMultiple($allIds);
      $matches = [];
      foreach ($allIds as $sid) {
        $submission = $candidates[$sid] ?? NULL;
        if (!$submission instanceof WebformSubmission) {
          continue;
        }
        $data = $submission->getData();
        $name = $this->extractName($data, $nameKeys) ?? 'Registered attendee';
        $organization = $this->extractFirstValue($data, $orgKeys);
        $haystack = mb_strtolower($name . ' ' . ($organization ?? ''));
        if (!str_contains($haystack, $needle)) {
          continue;
        }
        $matches[] = [
          'sid' => (int) $submission->id(),
          'name' => $name,
          'organization' => $organization,
          'registered_at' => (int) $submission->getCreatedTime(),
        ];
        if (count($matches) >= 200) {
          break;
        }
      }
      return new JsonResponse([
        'participants' => $matches,
        'total' => count($matches),
        'has_more' => FALSE,
      ]);
    }

    // Newest-registered first. A submission id is assigned in registration
    // order, so sorting ids descending gives the same order as sorting by
    // registered_at descending, without loading every submission just to
    // sort them -- and it lets us slice the ONE page we need before
    // loading anything.
    rsort($sids);
    $pageIds = array_slice($sids, $offset, $limit);

    $submissions = $this->entityTypeManager()->getStorage('webform_submission')->loadMultiple($pageIds);
    $participants = [];
    foreach ($pageIds as $sid) {
      $submission = $submissions[$sid] ?? NULL;
      if (!$submission instanceof WebformSubmission) {
        continue;
      }
      $data = $submission->getData();
      $participants[] = [
        'sid' => (int) $submission->id(),
        'name' => $this->extractName($data, $nameKeys) ?? 'Registered attendee',
        'organization' => $this->extractFirstValue($data, $orgKeys),
        'registered_at' => (int) $submission->getCreatedTime(),
      ];
    }

    return new JsonResponse([
      'participants' => $participants,
      'total' => $total,
      'has_more' => ($offset + count($pageIds)) < $total,
    ]);
  }

  /**
   * Resolves the Event's actual per-event registration webform, whichever
   * of the two places it's recorded in currently holds a value (the
   * Webform-reference field is the normal path; field_webform_id is a
   * plain-text mirror of the same id kept on some events — fall back to it
   * so a node with only one of the two populated still works).
   */
  protected function loadEventWebform(NodeInterface $node): ?Webform {
    if ($node->hasField('field_webform1') && !$node->get('field_webform1')->isEmpty()) {
      $webform = $node->get('field_webform1')->entity;
      if ($webform instanceof Webform) {
        return $webform;
      }
    }
    if ($node->hasField('field_webform_id') && !$node->get('field_webform_id')->isEmpty()) {
      $id = (string) $node->get('field_webform_id')->value;
      $webform = $id ? Webform::load($id) : NULL;
      if ($webform instanceof Webform) {
        return $webform;
      }
    }
    return NULL;
  }

  protected function fieldValue(NodeInterface $node, string $field) {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return NULL;
    }
    return $node->get($field)->value;
  }

  protected function findOwnSubmission(string $webformId, int $nid, ?int $uid = NULL): ?WebformSubmission {
    $uid = $uid ?? (int) $this->currentUser()->id();
    if ($uid <= 0) {
      return NULL;
    }
    $sids = $this->entityTypeManager()->getStorage('webform_submission')
      ->getQuery()
      ->condition('webform_id', $webformId)
      ->condition('entity_type', 'node')
      ->condition('entity_id', $nid)
      ->condition('uid', $uid)
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();
    if (!$sids) {
      return NULL;
    }
    $submission = $this->entityTypeManager()->getStorage('webform_submission')->load(reset($sids));
    return $submission instanceof WebformSubmission ? $submission : NULL;
  }

  /**
   * The signed-in app user's own email address -- the same safe lookup
   * me() and event_registration.module's prefill hook use (field_email
   * first, falling back to the Drupal account email), never any of the
   * account's other identity fields.
   */
  protected function currentUserEmail(): ?string {
    $uid = (int) $this->currentUser()->id();
    if ($uid <= 0) {
      return NULL;
    }
    $account = $this->entityTypeManager()->getStorage('user')->load($uid);
    if (!$account) {
      return NULL;
    }
    if ($account->hasField('field_email') && !$account->get('field_email')->isEmpty()) {
      $value = $account->get('field_email')->value;
      if (is_string($value) && $value !== '') {
        return $value;
      }
    }
    $email = $account->getEmail();
    return is_string($email) && $email !== '' ? $email : NULL;
  }

  /**
   * Finds a submission of this event's webform whose registrant EMAIL
   * matches, regardless of which Drupal account (uid) happened to be
   * logged in when it was submitted.
   *
   * This matters because a registration can be entered by someone other
   * than the attendee it's for -- staff filling the public webform at a
   * front desk, a colleague registering someone on a shared/admin session,
   * or simply a registration made before this API and its account-owned
   * flow existed. In every one of those cases the submission's uid is
   * whoever's Drupal session was active, not the actual attendee -- but
   * the email they typed into the form is theirs. Email is therefore a
   * more reliable "is this person registered" signal than uid alone, and
   * this is the one place that reconciles the two.
   */
  protected function findSubmissionByEmail(Webform $webform, int $nid, string $email): ?WebformSubmission {
    $email = mb_strtolower(trim($email));
    if ($email === '') {
      return NULL;
    }

    $emailKeys = $this->emailElementKeys($webform);
    if (!$emailKeys) {
      return NULL;
    }

    $sids = $this->entityTypeManager()->getStorage('webform_submission')
      ->getQuery()
      ->condition('webform_id', $webform->id())
      ->condition('entity_type', 'node')
      ->condition('entity_id', $nid)
      ->accessCheck(FALSE)
      ->execute();
    if (!$sids) {
      return NULL;
    }

    // Newest first: if the same email somehow ended up on more than one
    // submission for this event, surface the most recent as "the" ticket.
    rsort($sids);

    $submissions = $this->entityTypeManager()->getStorage('webform_submission')->loadMultiple($sids);
    foreach ($submissions as $submission) {
      if (!$submission instanceof WebformSubmission) {
        continue;
      }
      $data = $submission->getData();
      foreach ($emailKeys as $key) {
        $value = is_array($key) ? ($data[$key[0]][$key[1]] ?? NULL) : ($data[$key] ?? NULL);
        if (is_string($value) && mb_strtolower(trim($value)) === $email) {
          return $submission;
        }
      }
    }

    return NULL;
  }

  /**
   * Element keys on this webform whose type is "email" -- a bare key for a
   * top-level email element, or [composite_key, sub_key] for an email
   * subfield inside a composite (e.g. "reg_one_composite"'s "email").
   * Discovered generically from the same schema buildFieldSchema() already
   * produces, so a differently-shaped webform or composite needs no code
   * change here.
   */
  protected function emailElementKeys(Webform $webform): array {
    $keys = [];
    foreach ($this->buildFieldSchema($webform) as $field) {
      if ($field['type'] === 'email') {
        $keys[] = $field['key'];
        continue;
      }
      if ($field['type'] === 'composite') {
        foreach ($field['subfields'] as $sub) {
          if ($sub['type'] === 'email') {
            $keys[] = [$field['key'], $sub['key']];
          }
        }
      }
    }
    return $keys;
  }

  /**
   * Element keys on this webform that look like a person's name -- either
   * one "full name" key, or a [first, last] pair to join. Classified by
   * key name (first_name/last_name/surname/name), same generic
   * buildFieldSchema() walk as emailElementKeys(), so a differently-shaped
   * webform needs no code change here.
   */
  protected function nameElementKeys(Webform $webform): array {
    $first = NULL;
    $last = NULL;
    $full = NULL;

    $consider = function ($path, string $key, string $type) use (&$first, &$last, &$full) {
      if (!in_array($type, ['text', 'multiline'], TRUE)) {
        return;
      }
      $lower = mb_strtolower($key);
      if ($full === NULL && in_array($lower, ['name', 'full_name', 'fullname'], TRUE)) {
        $full = $path;
      }
      elseif ($first === NULL && (str_contains($lower, 'first_name') || $lower === 'firstname')) {
        $first = $path;
      }
      elseif ($last === NULL && (str_contains($lower, 'last_name') || str_contains($lower, 'surname') || $lower === 'lastname')) {
        $last = $path;
      }
    };

    foreach ($this->buildFieldSchema($webform) as $field) {
      $consider($field['key'], $field['key'], $field['type']);
      if ($field['type'] === 'composite') {
        foreach ($field['subfields'] as $sub) {
          $consider([$field['key'], $sub['key']], $sub['key'], $sub['type']);
        }
      }
    }

    return ['first' => $first, 'last' => $last, 'full' => $full];
  }

  /**
   * Element keys on this webform that look like an organisation/company
   * name, top-level or inside a composite -- same pattern as
   * nameElementKeys() and emailElementKeys().
   */
  protected function organizationElementKeys(Webform $webform): array {
    $looksLikeOrganization = static function (string $key): bool {
      $key = mb_strtolower($key);
      return str_contains($key, 'organi') || str_contains($key, 'company') || str_contains($key, 'establishment');
    };

    $keys = [];
    foreach ($this->buildFieldSchema($webform) as $field) {
      if (in_array($field['type'], ['text', 'multiline'], TRUE) && $looksLikeOrganization($field['key'])) {
        $keys[] = $field['key'];
      }
      if ($field['type'] === 'composite') {
        foreach ($field['subfields'] as $sub) {
          if (in_array($sub['type'], ['text', 'multiline'], TRUE) && $looksLikeOrganization($sub['key'])) {
            $keys[] = [$field['key'], $sub['key']];
          }
        }
      }
    }
    return $keys;
  }

  /**
   * First non-empty string value found at any of these element keys in a
   * submission's data -- a bare key for a top-level element, or
   * [composite_key, sub_key] for a subfield. Same key-path shape
   * findSubmissionByEmail() already uses.
   */
  protected function extractFirstValue(array $data, array $keys): ?string {
    foreach ($keys as $key) {
      $value = is_array($key) ? ($data[$key[0]][$key[1]] ?? NULL) : ($data[$key] ?? NULL);
      if (is_string($value) && trim($value) !== '') {
        return trim($value);
      }
    }
    return NULL;
  }

  /**
   * Renders a submission's registrant name from whatever nameElementKeys()
   * found: the "full name" key if there is one, otherwise first + last
   * joined. Returns NULL (never an empty string) when nothing was found,
   * so callers can fall back to a placeholder.
   */
  protected function extractName(array $data, array $nameKeys): ?string {
    if ($nameKeys['full']) {
      $value = $this->extractFirstValue($data, [$nameKeys['full']]);
      if ($value) {
        return $value;
      }
    }
    $first = $nameKeys['first'] ? $this->extractFirstValue($data, [$nameKeys['first']]) : NULL;
    $last = $nameKeys['last'] ? $this->extractFirstValue($data, [$nameKeys['last']]) : NULL;
    $combined = trim(($first ?? '') . ' ' . ($last ?? ''));
    return $combined !== '' ? $combined : NULL;
  }

  /**
   * Walks a webform's element tree into a flat, app-renderable field list.
   * Composite elements (e.g. the site's custom "Reg One Composite") are
   * expanded generically via the Webform module's own composite element
   * API, so a new composite type doesn't need code changes here.
   */
  protected function buildFieldSchema(Webform $webform): array {
    $elements = $webform->getElementsInitializedAndFlattened();
    /** @var \Drupal\webform\Plugin\WebformElementManagerInterface $elementManager */
    $elementManager = \Drupal::service('plugin.manager.webform.element');

    $fields = [];
    foreach ($elements as $key => $element) {
      if (empty($element['#type'])) {
        continue;
      }
      $type = $element['#type'];
      if (($element['#access'] ?? TRUE) === FALSE) {
        continue;
      }
      if (in_array($type, self::SKIP_TYPES, TRUE)) {
        continue;
      }

      try {
        $plugin = $elementManager->getElementInstance($element);
      }
      catch (\Throwable $e) {
        $plugin = NULL;
      }

      if ($plugin && $plugin->isComposite()) {
        $sub = [];
        try {
          $compositeElements = method_exists($plugin, 'getCompositeElements')
            ? $plugin->getCompositeElements()
            : [];
        }
        catch (\Throwable $e) {
          $compositeElements = [];
          \Drupal::logger('event_registration')->warning('Could not introspect composite element "@key" (@type) on webform @wid: @msg', [
            '@key' => $key,
            '@type' => $type,
            '@wid' => $webform->id(),
            '@msg' => $e->getMessage(),
          ]);
        }
        foreach ($compositeElements as $subKey => $subElement) {
          if (empty($subElement['#type']) || in_array($subElement['#type'], self::SKIP_TYPES, TRUE)) {
            continue;
          }
          $sub[] = [
            'key' => $subKey,
            'type' => $this->mapType($subElement['#type']),
            'title' => (string) ($element['#' . $subKey . '__title'] ?? $subElement['#title'] ?? $subKey),
            'required' => (bool) ($element['#' . $subKey . '__required'] ?? $subElement['#required'] ?? FALSE),
            'options' => $this->extractOptions($subElement),
          ];
        }
        if ($sub) {
          $fields[] = [
            'key' => $key,
            'type' => 'composite',
            'title' => (string) ($element['#title'] ?? $key),
            'required' => FALSE,
            'subfields' => $sub,
          ];
        }
        continue;
      }

      if (!in_array($type, self::TEXT_TYPES, TRUE) && !in_array($type, self::CHOICE_TYPES, TRUE)) {
        // Unrecognised element type — skip rather than guess. Logged so we
        // can see, from real usage, which types actually show up across
        // events and decide whether to add support for them.
        \Drupal::logger('event_registration')->notice('Skipped unsupported element type "@type" (key @key) on webform @wid.', [
          '@type' => $type,
          '@key' => $key,
          '@wid' => $webform->id(),
        ]);
        continue;
      }

      $fields[] = [
        'key' => $key,
        'type' => $this->mapType($type),
        'title' => (string) ($element['#title'] ?? $key),
        'required' => (bool) ($element['#required'] ?? FALSE),
        'options' => $this->extractOptions($element),
      ];
    }

    return $fields;
  }

  protected function mapType(string $webformType): string {
    return match ($webformType) {
      'email' => 'email',
      'tel' => 'phone',
      'number' => 'number',
      'date' => 'date',
      'textarea' => 'multiline',
      'select', 'radios' => 'choice',
      default => 'text',
    };
  }

  protected function extractOptions(array $element): ?array {
    if (empty($element['#options']) || !is_array($element['#options'])) {
      return NULL;
    }
    $options = [];
    foreach ($element['#options'] as $value => $label) {
      $options[] = ['value' => (string) $value, 'label' => (string) $label];
    }
    return $options;
  }

  /**
   * Matches the incoming JSON payload against the schema: fills $data in
   * the shape WebformSubmission::create() expects (composites nested,
   * everything else flat), and reports which required fields were missing
   * or blank rather than silently dropping them.
   *
   * @return array{0: array, 1: string[]}
   */
  protected function reconcileSubmissionData(array $schema, array $payload): array {
    $data = [];
    $missing = [];

    foreach ($schema as $field) {
      if ($field['type'] === 'composite') {
        $group = is_array($payload[$field['key']] ?? NULL) ? $payload[$field['key']] : [];
        $groupData = [];
        foreach ($field['subfields'] as $sub) {
          $value = $group[$sub['key']] ?? NULL;
          if ($sub['required'] && ($value === NULL || $value === '')) {
            $missing[] = $field['key'] . '.' . $sub['key'];
          }
          if ($value !== NULL && $value !== '') {
            $groupData[$sub['key']] = $value;
          }
        }
        if ($groupData) {
          $data[$field['key']] = $groupData;
        }
        continue;
      }

      $value = $payload[$field['key']] ?? NULL;
      if ($field['required'] && ($value === NULL || $value === '')) {
        $missing[] = $field['key'];
      }
      if ($value !== NULL && $value !== '') {
        $data[$field['key']] = $value;
      }
    }

    return [$data, $missing];
  }

}
