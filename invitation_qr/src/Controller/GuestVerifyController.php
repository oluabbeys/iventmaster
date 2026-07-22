<?php

namespace Drupal\invitation_qr\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\invitation_qr\Service\InvitationQrService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Public guest verification + check-in page.
 */
class GuestVerifyController extends ControllerBase {

  protected InvitationQrService $qrService;

  public function __construct(InvitationQrService $qrService) {
    $this->qrService = $qrService;
  }

  public static function create(ContainerInterface $container): static {
    return new static($container->get('invitation_qr.qr_service'));
  }

  public function verify(Request $request): mixed {
    $raw   = $request->query->get('token', '');
    $token = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($raw)));

    if ($request->getMethod() === 'POST' && $request->query->get('action') === 'checkin') {
      return $this->handleCheckin($token);
    }

    $guest       = NULL;
    $valid       = FALSE;
    $checkedIn   = FALSE;
    $checkinTime = NULL;
    $canCheckin  = FALSE;
    $event       = NULL;

    if ($token) {
      $submission = $this->qrService->findSubmissionByToken($token);
      if ($submission) {
        $data      = $submission->getData();
        $valid     = TRUE;
        $checkedIn = $this->qrService->isCheckedIn($submission);

        $ts = \Drupal::database()->select('webform_submission_data', 'w')
          ->fields('w', ['value'])
          ->condition('sid', $submission->id())
          ->condition('name', 'checkin_time')
          ->execute()
          ->fetchField();
        $checkinTime = $ts ? \Drupal::service('date.formatter')->format((int) $ts, 'medium') : NULL;

        $currentUser = \Drupal::currentUser();
        $canCheckin  = $currentUser->hasPermission('checkin guests')
          || $currentUser->hasPermission('administer invitation qr')
          || $currentUser->hasPermission('administer site configuration');

        // Anyone who can check in can also see full PII — same permission.
        $canViewPii = $canCheckin;

        $guest = [
          'name'       => $data['name'] ?? '',
          'email'      => $canViewPii ? ($data['email'] ?? '') : '',
          'phone'      => $canViewPii ? ($data['phone_number'] ?? '') : '',
          'pii_masked' => !$canViewPii,
          'token'      => $token,
          'sid'        => $submission->id(),
        ];

        // Load event details from parent node.
        $node = $this->qrService->findParentNode($submission);
        if ($node) {
          $rawDate = $this->qrService->getNodeFieldValue($node, 'field_event_date');
          $event = [
            'title'     => $node->label(),
            'date'      => $this->qrService->formatEventDate($rawDate),
            'venue'     => $this->qrService->getNodeFieldValue($node, 'field_event_venue'),
            'time'      => $this->qrService->getNodeFieldValue($node, 'field_event_time'),
            'zoom_link' => $this->qrService->getNodeFieldValue($node, 'field_zoom_link'),
            'type'      => $this->qrService->getNodeFieldValue($node, 'field_event_type'),
          ];
        }
      }
    }

    return [
      '#theme'        => 'invitation_qr_guest_verify',
      '#guest'        => $guest,
      '#token'        => $token,
      '#valid'        => $valid,
      '#checked_in'   => $checkedIn,
      '#checkin_time' => $checkinTime,
      '#can_checkin'  => $canCheckin,
      '#event'        => $event,
      '#cache'        => ['max-age' => 0],
      '#attached'     => ['library' => ['invitation_qr/invitation-qr.verify']],
    ];
  }

  protected function handleCheckin(string $token): JsonResponse {
    if (!$token) {
      return new JsonResponse(['success' => FALSE, 'message' => 'No token provided.'], 400);
    }

    $checkinRole = \Drupal::config('invitation_qr.settings')->get('checkin_role') ?: 'checkin';
    $currentUser = \Drupal::currentUser();
    $canCheckin  = in_array($checkinRole, $currentUser->getRoles())
      || $currentUser->hasPermission('administer site configuration');

    if (!$canCheckin) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Access denied.'], 403);
    }

    $submission = $this->qrService->findSubmissionByToken($token);
    if (!$submission) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Invalid token.'], 404);
    }

    if ($this->qrService->isCheckedIn($submission)) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Already checked in.', 'already' => TRUE]);
    }

    $this->qrService->recordCheckin($submission);

    $data = $submission->getData();
    return new JsonResponse([
      'success'      => TRUE,
      'message'      => 'Checked in successfully.',
      'name'         => $data['name'] ?? '',
      'checkin_time' => \Drupal::service('date.formatter')->format(\Drupal::time()->getCurrentTime(), 'medium'),
    ]);
  }

}