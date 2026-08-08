<?php

namespace Drupal\invitation_qr\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\invitation_qr\Service\InvitationQrService;
use Drupal\invitation_qr\Service\InvitationZipService;
use Drupal\Core\Render\Markup;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin UI controller for Invitation QR.
 */
class SubmissionsListController extends ControllerBase {

  protected InvitationQrService $qrService;
  protected InvitationZipService $zipService;

  public function __construct(InvitationQrService $qrService, InvitationZipService $zipService) {
    $this->qrService  = $qrService;
    $this->zipService = $zipService;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('invitation_qr.qr_service'),
      $container->get('invitation_qr.zip_service')
    );
  }

  // ══════════════════════════════════════════════════════════════════════════
  // All Events list
  // ══════════════════════════════════════════════════════════════════════════

  public function allEvents(Request $request): array {
    $config   = $this->config('invitation_qr.settings');
    $nodeType = $config->get('node_type') ?: 'invitation';
    $search   = trim($request->query->get('search', ''));

    $query = $this->entityTypeManager()->getStorage('node')
      ->getQuery()
      ->condition('type', $nodeType)
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->accessCheck(TRUE);

    if ($search) {
      $query->condition('title', '%' . $search . '%', 'LIKE');
    }

    $nids  = $query->execute();
    $nodes = $nids ? $this->entityTypeManager()->getStorage('node')->loadMultiple($nids) : [];

    $build = [];
    $build['search_form'] = $this->buildSearchForm(
      Url::fromRoute('invitation_qr.all_events'),
      $search,
      $this->t('Filter by event name…')
    );

    $build['blocklist_link'] = [
      '#type'       => 'link',
      '#title'      => $this->t('🚫 Global Blocklist'),
      '#url'        => Url::fromRoute('invitation_qr.blocklist'),
      '#attributes' => ['class' => ['button']],
    ];

    $rows = [];
    foreach ($nodes as $node) {
      $submissions = $this->qrService->getSubmissionsForNode($node->id());
      $total = count($submissions);
      $stamped = $sent = $accessReady = $accessSent = 0;
      foreach ($submissions as $sub) {
        $d = $sub->getData();
        if (!empty($d['stamped_card_fid'])) $stamped++;
        if (!empty($d['twilio_sent'])) $sent++;
        if (!empty($d['access_card_fid'])) $accessReady++;
        if (!empty($d['access_card_sent'])) $accessSent++;
      }

      // Show event type badge.
      $eventType = '';
      if ($node->hasField('field_event_type') && !$node->get('field_event_type')->isEmpty()) {
        $eventType = ucfirst(strtolower((string) ($node->get('field_event_type')->value ?? '')));
      }

      $rows[] = [
        ['data' => ['#type'=>'link','#title'=>$node->label(),'#url'=>Url::fromRoute('invitation_qr.submissions_list',['node'=>$node->id()])]],
        $eventType ?: '—',
        $total,
        $stamped . ' / ' . $total,
        $sent . ' / ' . $total,
        $accessReady . ' / ' . $total,
        $accessSent . ' / ' . $total,
        \Drupal::service('date.formatter')->format($node->getCreatedTime(), 'short'),
        ['data' => ['#type'=>'link','#title'=>$this->t('Manage →'),'#url'=>Url::fromRoute('invitation_qr.submissions_list',['node'=>$node->id()]),'#attributes'=>['class'=>['button','button--small']]]],
      ];
    }

    $build['table'] = [
      '#type'   => 'table',
      '#header' => [
        $this->t('Event Name'),
        $this->t('Type'),
        $this->t('Guests'),
        $this->t('Inv. Stamped'),
        $this->t('Inv. Sent'),
        $this->t('Access Stamped'),
        $this->t('Access Sent'),
        $this->t('Created'),
        $this->t('Actions'),
      ],
      '#rows'   => $rows,
      '#empty'  => $search ? $this->t('No events found matching "@s".', ['@s'=>$search]) : $this->t('No invitation events found.'),
      '#attributes' => ['class' => ['iqr-events-table']],
    ];

    $build['#attached']['library'][] = 'invitation_qr/invitation-qr.admin';
    return $build;
  }

  // ══════════════════════════════════════════════════════════════════════════
  // Global blocklist
  // ══════════════════════════════════════════════════════════════════════════

  /**
   * Global (cross-event) list of numbers that had a genuine delivery
   * failure and are now skipped on every future Twilio send. Numbers that
   * only ever hit our own sending-rate limit are never added here.
   */
  public function blocklist(Request $request): array {
    $search  = trim($request->query->get('search', ''));
    $entries = $this->qrService->getBlocklist();

    if ($search !== '') {
      $needle = strtolower($search);
      $entries = array_filter($entries, function ($entry) use ($needle) {
        $hay = strtolower(($entry['phone'] ?? '') . ' ' . ($entry['reason'] ?? '') . ' ' . ($entry['error_code'] ?? '') . ' ' . ($entry['status'] ?? ''));
        return strpos($hay, $needle) !== FALSE;
      });
    }

    $rows = [];
    foreach ($entries as $entry) {
      $phone = $entry['phone'] ?? '';
      $rows[] = [
        $phone ?: '—',
        $entry['reason'] ?? '—',
        $entry['error_code'] ?: '—',
        $entry['status'] ?: '—',
        $entry['fail_count'] ?? 1,
        !empty($entry['first_seen']) ? \Drupal::service('date.formatter')->format((int) $entry['first_seen'], 'short') : '—',
        !empty($entry['last_seen']) ? \Drupal::service('date.formatter')->format((int) $entry['last_seen'], 'short') : '—',
        [
          'data' => [
            '#type'  => 'html_tag',
            '#tag'   => 'form',
            '#attributes' => ['method' => 'post', 'action' => Url::fromRoute('invitation_qr.blocklist_remove')->toString(), 'class' => ['iqr-blocklist-remove-form']],
            'token' => [
              '#type' => 'html_tag',
              '#tag'  => 'input',
              '#attributes' => ['type' => 'hidden', 'name' => 'form_token', 'value' => \Drupal::csrfToken()->get('iqr-blocklist')],
            ],
            'phone' => [
              '#type' => 'html_tag',
              '#tag'  => 'input',
              '#attributes' => ['type' => 'hidden', 'name' => 'phone', 'value' => $phone],
            ],
            'submit' => [
              '#type' => 'html_tag',
              '#tag'  => 'button',
              '#attributes' => ['type' => 'submit', 'class' => ['button', 'button--small']],
              '#value' => $this->t('Unblock'),
            ],
          ],
        ],
      ];
    }

    $build = [];
    $build['back'] = [
      '#type'       => 'link',
      '#title'      => $this->t('← Back to All Events'),
      '#url'        => Url::fromRoute('invitation_qr.all_events'),
      '#attributes' => ['class' => ['iqr-back-link']],
    ];

    $build['intro'] = [
      '#type'  => 'html_tag',
      '#tag'   => 'p',
      '#value' => $this->t('Numbers below failed to deliver for a real reason (invalid number, blocked, opted out, etc.) and are now skipped automatically on every future send — single, bulk, or adhoc. Numbers that only ever failed because our own sending rate limit was exceeded are never listed here; those get retried normally.'),
    ];

    $build['toolbar'] = [
      '#type'       => 'container',
      '#attributes' => ['class' => ['iqr-blocklist-toolbar'], 'style' => 'display:flex;gap:1rem;align-items:center;flex-wrap:wrap;'],
      'search' => $this->buildSearchForm(
        Url::fromRoute('invitation_qr.blocklist'),
        $search,
        $this->t('Search phone, reason, error code…')
      ),
      'export' => [
        '#type'       => 'link',
        '#title'      => $this->t('⬇ Export CSV'),
        '#url'        => Url::fromRoute('invitation_qr.blocklist_export', [], ['query' => $search !== '' ? ['search' => $search] : []]),
        '#attributes' => ['class' => ['button']],
      ],
    ];

    $build['table'] = [
      '#type'   => 'table',
      '#header' => [
        $this->t('Phone'),
        $this->t('Reason'),
        $this->t('Twilio Error Code'),
        $this->t('Last Status'),
        $this->t('Fail Count'),
        $this->t('First Failed'),
        $this->t('Last Failed'),
        $this->t('Action'),
      ],
      '#rows'   => $rows,
      '#empty'  => $this->t('No numbers are blocklisted.'),
      '#attributes' => ['class' => ['iqr-blocklist-table']],
    ];

    $build['#attached']['library'][] = 'invitation_qr/invitation-qr.admin';
    return $build;
  }

  public function blocklistRemove(Request $request): RedirectResponse {
    $token = $request->request->get('form_token', '');
    if (!\Drupal::csrfToken()->validate($token, 'iqr-blocklist')) {
      $this->messenger()->addError($this->t('Security token invalid — please try again.'));
      return $this->redirect('invitation_qr.blocklist');
    }

    $phone = trim($request->request->get('phone', ''));
    if ($phone) {
      $this->qrService->removeFromBlocklist($phone);
      $this->messenger()->addStatus($this->t('@phone removed from the blocklist — it will be included in future sends again.', ['@phone' => $phone]));
    }

    return $this->redirect('invitation_qr.blocklist');
  }

  public function blocklistExport(Request $request): StreamedResponse {
    $search  = trim($request->query->get('search', ''));
    $entries = $this->qrService->getBlocklist();

    if ($search !== '') {
      $needle = strtolower($search);
      $entries = array_filter($entries, function ($entry) use ($needle) {
        $hay = strtolower(($entry['phone'] ?? '') . ' ' . ($entry['reason'] ?? '') . ' ' . ($entry['error_code'] ?? '') . ' ' . ($entry['status'] ?? ''));
        return strpos($hay, $needle) !== FALSE;
      });
    }

    $headers = ['Phone', 'Reason', 'Twilio Error Code', 'Last Status', 'Fail Count', 'First Failed', 'Last Failed'];
    $csvRows = [];
    foreach ($entries as $entry) {
      $csvRows[] = [
        $entry['phone']      ?? '',
        $entry['reason']     ?? '',
        $entry['error_code'] ?? '',
        $entry['status']     ?? '',
        $entry['fail_count'] ?? 1,
        !empty($entry['first_seen']) ? \Drupal::service('date.formatter')->format((int) $entry['first_seen'], 'custom', 'Y-m-d H:i') : '',
        !empty($entry['last_seen'])  ? \Drupal::service('date.formatter')->format((int) $entry['last_seen'],  'custom', 'Y-m-d H:i') : '',
      ];
    }

    $response = new StreamedResponse(function () use ($headers, $csvRows) {
      $handle = fopen('php://output', 'w');
      fwrite($handle, "\xEF\xBB\xBF");
      fputcsv($handle, $headers);
      foreach ($csvRows as $row) {
        fputcsv($handle, $row);
      }
      fclose($handle);
    });

    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="invitation-qr-blocklist.csv"');
    $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');

    return $response;
  }

  // ══════════════════════════════════════════════════════════════════════════
  // Per-event submissions list
  // ══════════════════════════════════════════════════════════════════════════

  public function list(NodeInterface $node, Request $request): array {
    $config      = $this->config('invitation_qr.settings');
    $submissions = $this->qrService->getSubmissionsForNode($node->id());
    $search      = trim($request->query->get('search', ''));

    $total = count($submissions);
    $stamped = $unstamped = $unsent = $accessReady = $accessUnsent = 0;
    foreach ($submissions as $sub) {
      $d = $sub->getData();
      if (!empty($d['stamped_card_fid'])) {
        $stamped++;
        if (empty($d['twilio_sent'])) $unsent++;
      }
      else {
        $unstamped++;
      }
      if (!empty($d['access_card_fid'])) {
        $accessReady++;
        if (empty($d['access_card_sent'])) $accessUnsent++;
      }
    }

    // Get checkin count with a single DB query — much faster than per-row isCheckedIn().
    $sids = array_keys(iterator_to_array(
      $this->entityTypeManager()->getStorage('webform_submission')
        ->getQuery()
        ->condition('webform_id', $this->config('invitation_qr.settings')->get('webform_id'))
        ->condition('entity_type', 'node')
        ->condition('entity_id', $node->id())
        ->accessCheck(FALSE)
        ->execute()
    ));

    $checkedIn = 0;
    if (!empty($sids)) {
      $checkedIn = (int) \Drupal::database()->select('webform_submission_data', 'w')
        ->condition('w.sid', $sids, 'IN')
        ->condition('w.name', 'checkin')
        ->condition('w.value', 'yes')
        ->condition('w.property', '')
        ->condition('w.delta', 0)
        ->countQuery()
        ->execute()
        ->fetchField();
    }

    $twilioEnabled = (bool) $config->get('twilio_enabled');
    $build = [];

    // Back link.
    $build['back'] = ['#type'=>'link','#title'=>$this->t('← All Events'),'#url'=>Url::fromRoute('invitation_qr.all_events'),'#attributes'=>['class'=>['iqr-back-link']]];

    // Event meta (type, date, venue, time).
    $build['event_meta'] = $this->buildEventMeta($node);

    // Stats bar.
    $build['stats'] = [
      '#type' => 'html_tag', '#tag' => 'div', '#attributes' => ['class' => ['iqr-stats-bar']],
      'total'         => $this->statChip($this->t('Total Guests'),        $total,        'gray'),
      'stamped'       => $this->statChip($this->t('Inv. Stamped'),        $stamped,      'green'),
      'unstamped'     => $this->statChip($this->t('Not Stamped'),         $unstamped,    'orange'),
      'unsent'        => $this->statChip($this->t('Inv. Unsent'),         $unsent,       'blue'),
      'accessReady'   => $this->statChip($this->t('Access Stamped'),      $accessReady,  'green'),
      'accessUnsent'  => $this->statChip($this->t('Access Unsent'),       $accessUnsent, 'purple'),
      'checkedIn'     => $this->statChip($this->t('Checked In'),          $checkedIn,    'teal'),
      'notCheckedIn'  => $this->statChip($this->t('Not Checked In'),      $total - $checkedIn, 'orange'),
    ];

    // Top action toolbar.
    // canAdminister = full access (administer invitation qr)
    // canAccess     = limited access (access invitation qr submissions / view guest pii)
    $canAdminister = \Drupal::currentUser()->hasPermission('administer invitation qr')
      || \Drupal::currentUser()->hasPermission('administer site configuration');

    // Check-in staff can see PII but not bulk actions.
    $canCheckin = \Drupal::currentUser()->hasPermission('checkin guests') || $canAdminister;

    $build['actions'] = ['#type' => 'container', '#attributes' => ['class' => ['iqr-actions']]];

    // Admin only buttons.
    if ($canAdminister) {
      if ($unstamped > 0) {
        $build['actions']['process'] = ['#type'=>'link','#title'=>$this->t('⚙ Process @n Unstamped',['@n'=>$unstamped]),'#url'=>Url::fromRoute('invitation_qr.process_unstamped',['node'=>$node->id()]),'#attributes'=>['class'=>['button','button--primary']]];
      }
      $build['actions']['regen'] = ['#type'=>'link','#title'=>$this->t('🔄 Re-Generate All QRs'),'#url'=>Url::fromRoute('invitation_qr.generate_all',['node'=>$node->id()]),'#attributes'=>['class'=>['button']]];
      if ($twilioEnabled && $unsent > 0) {
        $build['actions']['send_all'] = ['#type'=>'link','#title'=>$this->t('📲 Send @n Unsent Invitations',['@n'=>$unsent]),'#url'=>Url::fromRoute('invitation_qr.send_all_twilio',['node'=>$node->id()]),'#attributes'=>['class'=>['button','button--primary']]];
      }
      $build['actions']['zip'] = ['#type'=>'link','#title'=>$this->t('⬇ Download Invitation ZIP'),'#url'=>Url::fromRoute('invitation_qr.download_zip',['node'=>$node->id()]),'#attributes'=>['class'=>['button']]];
      $build['actions']['access_zip'] = ['#type'=>'link','#title'=>$this->t('⬇ Download Access Card ZIP'),'#url'=>Url::fromRoute('invitation_qr.download_access_zip',['node'=>$node->id()]),'#attributes'=>['class'=>['button']]];
      if ($twilioEnabled && $accessUnsent > 0) {
        $build['actions']['send_all_access'] = [
          '#type'       => 'link',
          '#title'      => $this->t('🎟 Send @n Unsent Access Cards', ['@n' => $accessUnsent]),
          '#url'        => Url::fromRoute('invitation_qr.send_all_access_cards', ['node' => $node->id()]),
          '#attributes' => ['class' => ['button', 'button--primary', 'button--access']],
        ];
      }
      $build['actions']['restamp_access'] = [
        '#type'       => 'link',
        '#title'      => $this->t('🔁 Re-stamp Access Cards'),
        '#url'        => Url::fromRoute('invitation_qr.restamp_access_cards', ['node' => $node->id()]),
        '#attributes' => ['class' => ['button', 'button--warning']],
      ];
      if ($twilioEnabled) {
        $build['adhoc'] = $this->buildAdhocForm($node);
      }
    }

    // Buttons visible to all roles with access.
    $unreadReplies = $this->countUnreadReplies($submissions, $node->id());
    $build['actions']['rsvp'] = [
      '#type'       => 'link',
      '#title'      => $unreadReplies > 0
        ? $this->t('📊 RSVP Dashboard 🔴 @n new', ['@n' => $unreadReplies])
        : $this->t('📊 RSVP Dashboard'),
      '#url'        => Url::fromRoute('invitation_qr.rsvp_dashboard', ['node' => $node->id()]),
      '#attributes' => ['class' => ['button']],
    ];

    // ── Filters ───────────────────────────────────────────────────────────────
    $filterInvSent    = (string) ($request->query->get('inv_sent') ?? '');
    $filterInvStatus  = (string) ($request->query->get('inv_status') ?? '');
    $filterAccSent    = (string) ($request->query->get('acc_sent') ?? '');
    $filterAccStatus  = (string) ($request->query->get('acc_status') ?? '');
    $filterRsvp       = (string) ($request->query->get('rsvp_filter') ?? '');
    $filterCheckin    = (string) ($request->query->get('checkin_filter') ?? '');
    $sortOrder        = (string) ($request->query->get('sort_order') ?? 'desc');
    $perPageParam     = (string) ($request->query->get('per_page') ?? '100');

    // Guest search.
    $build['search_form'] = $this->buildSearchForm(
      Url::fromRoute('invitation_qr.submissions_list', ['node' => $node->id()]),
      $search,
      $this->t('Filter by name, phone or email…')
    );

    // Filter dropdowns.
    $filterUrl = Url::fromRoute('invitation_qr.submissions_list', ['node' => $node->id()])->toString();
    $build['filters'] = [
      '#type'   => 'html_tag',
      '#tag'    => 'form',
      '#attributes' => [
        'method' => 'get',
        'action' => $filterUrl,
        'class'  => ['iqr-filters'],
      ],
      // Preserve search query.
      'search_hidden' => [
        '#type'       => 'html_tag',
        '#tag'        => 'input',
        '#attributes' => ['type' => 'hidden', 'name' => 'search', 'value' => $search],
      ],
      'inv_sent' => $this->buildFilterSelect('inv_sent', $this->t('Inv. Sent'), [
        ''       => $this->t('— All —'),
        'yes'    => $this->t('✅ Sent'),
        'failed' => $this->t('❌ Failed'),
        'unsent' => $this->t('⏳ Unsent'),
      ], $filterInvSent),
      'inv_status' => $this->buildFilterSelect('inv_status', $this->t('Inv. Status'), [
        ''            => $this->t('— All —'),
        'delivered'   => $this->t('✅ Delivered'),
        'read'        => $this->t('👁 Read'),
        'sent'        => $this->t('📤 Sent'),
        'undelivered' => $this->t('⚠️ Undelivered'),
        'failed'      => $this->t('❌ Failed'),
        'none'        => $this->t('— No status —'),
      ], $filterInvStatus),
      'acc_sent' => $this->buildFilterSelect('acc_sent', $this->t('Access Sent'), [
        ''       => $this->t('— All —'),
        'yes'    => $this->t('✅ Sent'),
        'failed' => $this->t('❌ Failed'),
        'unsent' => $this->t('⏳ Unsent'),
      ], $filterAccSent),
      'acc_status' => $this->buildFilterSelect('acc_status', $this->t('Access Status'), [
        ''            => $this->t('— All —'),
        'delivered'   => $this->t('✅ Delivered'),
        'read'        => $this->t('👁 Read'),
        'sent'        => $this->t('📤 Sent'),
        'undelivered' => $this->t('⚠️ Undelivered'),
        'failed'      => $this->t('❌ Failed'),
        'none'        => $this->t('— No status —'),
      ], $filterAccStatus),
      'rsvp_filter' => $this->buildFilterSelect('rsvp_filter', $this->t('RSVP'), [
        ''        => $this->t('— All —'),
        'yes'     => $this->t('✅ Coming'),
        'no'      => $this->t('❌ Declined'),
        'pending' => $this->t('⏳ No reply'),
      ], $filterRsvp),
      'checkin_filter' => $this->buildFilterSelect('checkin_filter', $this->t('Checked In'), [
        ''    => $this->t('— All —'),
        'yes' => $this->t('✅ Checked In'),
        'no'  => $this->t('❌ Not Checked In'),
      ], $filterCheckin),
      'sort_order' => $this->buildFilterSelect('sort_order', $this->t('Sort by Date'), [
        'desc' => $this->t('⬇ Newest first'),
        'asc'  => $this->t('⬆ Oldest first'),
      ], $sortOrder),
      'per_page' => $this->buildFilterSelect('per_page', $this->t('Per page'), [
        '25'  => '25',
        '50'  => '50',
        '100' => '100',
        '250' => '250',
        '500' => '500',
        'all' => $this->t('All'),
      ], $perPageParam),
      'submit' => [
        '#type'       => 'html_tag',
        '#tag'        => 'button',
        '#attributes' => ['type' => 'submit', 'class' => ['button']],
        '#value'      => $this->t('Filter'),
      ],
      'clear' => [
        '#type'       => 'html_tag',
        '#tag'        => 'a',
        '#attributes' => ['href' => $filterUrl, 'class' => ['button']],
        '#value'      => $this->t('Clear'),
      ],
      'csv' => [
        '#type'       => 'html_tag',
        '#tag'        => 'a',
        '#attributes' => [
          'href'  => Url::fromRoute('invitation_qr.download_csv', ['node' => $node->id()], [
            'query' => array_filter([
              'search'         => $search,
              'inv_sent'       => $filterInvSent,
              'inv_status'     => $filterInvStatus,
              'acc_sent'       => $filterAccSent,
              'acc_status'     => $filterAccStatus,
              'rsvp_filter'    => $filterRsvp,
              'checkin_filter' => $filterCheckin,
              'sort_order'     => $sortOrder,
            ]),
          ])->toString(),
          'class' => ['button', 'button--primary'],
          'title' => $this->t('Download current filter results as CSV'),
        ],
        '#value' => $this->t('⬇ Download CSV'),
      ],
    ];

    // Bulk send form wrapping the table.
    $bulkActionUrl = Url::fromRoute('invitation_qr.send_bulk_twilio', ['node' => $node->id()]);
    $token         = \Drupal::csrfToken()->get('invitation_qr_bulk_' . $node->id());
    $tokenField    = '<input type="hidden" name="iqr_bulk_token" value="' . htmlspecialchars($token) . '">';

    // ── Pagination ────────────────────────────────────────────────────────────
    $showAll  = ($perPageParam === 'all');
    $perPageOptions = ['25', '50', '100', '250', '500', 'all'];
    if (!in_array($perPageParam, $perPageOptions)) {
      $perPage = 100;
      $perPageParam = '100';
      $showAll = FALSE;
    }
    else {
      $perPage = $showAll ? 99999 : (int) $perPageParam;
    }
    $currentPage = max(0, (int) ($request->query->get('page') ?? 0));

    // Sort submissions by created date.
    $submissionsArray = iterator_to_array($submissions);
    usort($submissionsArray, function ($a, $b) use ($sortOrder) {
      return $sortOrder === 'asc'
        ? $a->getCreatedTime() <=> $b->getCreatedTime()
        : $b->getCreatedTime() <=> $a->getCreatedTime();
    });
    $submissions = $submissionsArray;

    $rows        = [];
    $allRows     = []; // collect all filtered rows first for accurate count
    $serialStart = ($currentPage * $perPage) + 1;
    $serialNum   = $serialStart;

    foreach ($submissions as $submission) {
      $data            = $submission->getData();
      $hasInvFid       = !empty($data['stamped_card_fid']);
      $hasAccessFid    = !empty($data['access_card_fid']);
      $isSent          = !empty($data['twilio_sent']);
      $isAccessSent    = !empty($data['access_card_sent']);
      $checkedIn       = $this->qrService->isCheckedIn($submission);
      $rsvp            = $data['rsvp'] ?? '';
      $rsvpBadge       = match($rsvp) {
        'yes'   => '<span class="iqr-rsvp-yes">✅ Coming</span>',
        'no'    => '<span class="iqr-rsvp-no">❌ Declined</span>',
        default => '<span class="iqr-pending">⏳ No reply</span>',
      };

      if ($search) {
        $hay = strtolower(($data['name']??'').' '.($data['phone_number']??'').' '.($data['email']??''));
        if (strpos($hay, strtolower($search)) === FALSE) continue;
      }

      // ── Apply dropdown filters ──────────────────────────────────────────────
      $invState    = $this->qrService->getInvSendState((int) $submission->id());
      $accessState = $this->qrService->getAccessSendState((int) $submission->id());

      $invDeliveryRaw    = \Drupal::database()->select('webform_submission_data', 'w')
        ->fields('w', ['value'])
        ->condition('sid', $submission->id())
        ->condition('name', 'inv_delivery_status')
        ->condition('property', '')->condition('delta', 0)
        ->execute()->fetchField() ?: '';

      $accessDeliveryRaw = \Drupal::database()->select('webform_submission_data', 'w')
        ->fields('w', ['value'])
        ->condition('sid', $submission->id())
        ->condition('name', 'access_delivery_status')
        ->condition('property', '')->condition('delta', 0)
        ->execute()->fetchField() ?: '';

      if ($filterInvSent !== '') {
        if ($filterInvSent === 'unsent' && $invState !== 'unsent') continue;
        if ($filterInvSent === 'yes'    && $invState !== 'sent')   continue;
        if ($filterInvSent === 'failed' && $invState !== 'failed') continue;
      }
      if ($filterInvStatus !== '') {
        if ($filterInvStatus === 'none' && $invDeliveryRaw !== '') continue;
        if ($filterInvStatus !== 'none' && $invDeliveryRaw !== $filterInvStatus) continue;
      }
      if ($filterAccSent !== '') {
        if ($filterAccSent === 'unsent' && $accessState !== 'unsent') continue;
        if ($filterAccSent === 'yes'    && $accessState !== 'sent')   continue;
        if ($filterAccSent === 'failed' && $accessState !== 'failed') continue;
      }
      if ($filterAccStatus !== '') {
        if ($filterAccStatus === 'none' && $accessDeliveryRaw !== '') continue;
        if ($filterAccStatus !== 'none' && $accessDeliveryRaw !== $filterAccStatus) continue;
      }
      if ($filterRsvp !== '') {
        if ($filterRsvp === 'pending' && !empty($rsvp)) continue;
        if ($filterRsvp !== 'pending' && $rsvp !== $filterRsvp) continue;
      }

      // Filter: Checked In — must be in first loop so $filteredTotal is correct.
      if ($filterCheckin !== '') {
        $isCheckedInCheck = $this->qrService->isCheckedIn($submission);
        if ($filterCheckin === 'yes' && !$isCheckedInCheck) continue;
        if ($filterCheckin === 'no'  &&  $isCheckedInCheck) continue;
      }

      // Collect all filtered sids for total count.
      $allRows[] = $submission->id();
    }

    // Total filtered count for pagination.
    $filteredTotal = count($allRows);
    $totalPages    = (int) ceil($filteredTotal / $perPage);
    $currentPage   = min($currentPage, max(0, $totalPages - 1));
    $pageSlice     = array_slice($allRows, $currentPage * $perPage, $perPage);
    $pageSliceSet  = array_flip($pageSlice);
    $serialNum     = ($currentPage * $perPage) + 1;

    // Now build rows only for current page submissions.
    foreach ($submissions as $submission) {
      if (!isset($pageSliceSet[$submission->id()])) continue;

      $data         = $submission->getData();
      $hasInvFid    = !empty($data['stamped_card_fid']);
      $hasAccessFid = !empty($data['access_card_fid']);
      $checkedIn    = $this->qrService->isCheckedIn($submission);
      $rsvp         = $data['rsvp'] ?? '';
      $rsvpBadge    = match($rsvp) {
        'yes'   => '<span class="iqr-rsvp-yes">✅ Coming</span>',
        'no'    => '<span class="iqr-rsvp-no">❌ Declined</span>',
        default => '<span class="iqr-pending">⏳ No reply</span>',
      };

      $invState    = $this->qrService->getInvSendState((int) $submission->id());
      $accessState = $this->qrService->getAccessSendState((int) $submission->id());

      $invDeliveryRaw    = \Drupal::database()->select('webform_submission_data', 'w')
        ->fields('w', ['value'])
        ->condition('sid', $submission->id())
        ->condition('name', 'inv_delivery_status')
        ->condition('property', '')->condition('delta', 0)
        ->execute()->fetchField() ?: '';

      $accessDeliveryRaw = \Drupal::database()->select('webform_submission_data', 'w')
        ->fields('w', ['value'])
        ->condition('sid', $submission->id())
        ->condition('name', 'access_delivery_status')
        ->condition('property', '')->condition('delta', 0)
        ->execute()->fetchField() ?: '';

      // Filter: Inv. Sent
      if ($filterInvSent !== '') {
        if ($filterInvSent === 'unsent' && $invState !== 'unsent') continue;
        if ($filterInvSent === 'yes' && $invState !== 'sent') continue;
        if ($filterInvSent === 'failed' && $invState !== 'failed') continue;
      }

      // Filter: Inv. Status
      if ($filterInvStatus !== '') {
        if ($filterInvStatus === 'none' && $invDeliveryRaw !== '') continue;
        if ($filterInvStatus !== 'none' && $invDeliveryRaw !== $filterInvStatus) continue;
      }

      // Filter: Access Sent
      if ($filterAccSent !== '') {
        if ($filterAccSent === 'unsent' && $accessState !== 'unsent') continue;
        if ($filterAccSent === 'yes' && $accessState !== 'sent') continue;
        if ($filterAccSent === 'failed' && $accessState !== 'failed') continue;
      }

      // Filter: Access Status
      if ($filterAccStatus !== '') {
        if ($filterAccStatus === 'none' && $accessDeliveryRaw !== '') continue;
        if ($filterAccStatus !== 'none' && $accessDeliveryRaw !== $filterAccStatus) continue;
      }

      // Filter: RSVP
      if ($filterRsvp !== '') {
        if ($filterRsvp === 'pending' && !empty($rsvp)) continue;
        if ($filterRsvp !== 'pending' && $rsvp !== $filterRsvp) continue;
      }

      // Filter: Checked In
      if ($filterCheckin !== '') {
        $isCheckedIn = $this->qrService->isCheckedIn($submission);
        if ($filterCheckin === 'yes' && !$isCheckedIn) continue;
        if ($filterCheckin === 'no'  &&  $isCheckedIn) continue;
      }

      // Checkbox for bulk select (invitation card).
      $checkCell = $hasInvFid
        ? ['data' => ['#type'=>'html_tag','#tag'=>'input','#attributes'=>['type'=>'checkbox','name'=>'sids[]','value'=>$submission->id(),'class'=>['iqr-select-cb']]]]
        : ['data' => ['#markup' => '—']];

      // Invitation card download.
      $invDownloadCell = $hasInvFid
        ? ['data' => ['#type'=>'link','#title'=>$this->t('⬇ Inv.'),'#url'=>Url::fromRoute('invitation_qr.download_single',['submission'=>$submission->id()]),'#attributes'=>['class'=>['button','button--small']]]]
        : ['data' => ['#markup' => '<span class="iqr-pending">⏳</span>']];

      // Access card download.
      $accessDownloadCell = $hasAccessFid
        ? ['data' => ['#type'=>'link','#title'=>$this->t('⬇ Access'),'#url'=>Url::fromRoute('invitation_qr.download_access_single',['submission'=>$submission->id()]),'#attributes'=>['class'=>['button','button--small']]]]
        : ['data' => ['#markup' => '<span class="iqr-pending">⏳</span>']];

      // ── Three-state send cells ────────────────────────────────────────────
      // States: unsent → sending (queued, button disabled) → sent
      // Idempotent: clicking again while 'sending' does nothing.

      // Invitation send cell — visible to all with access.
      $invSendCell = ['data' => ['#markup' => '—']];
      if ($twilioEnabled && $hasInvFid) {
        if ($invState === 'sent') {
          $invSendCell = ['data' => ['#type' => 'link',
            '#title'      => $this->t('🔁 Resend Inv.'),
            '#url'        => Url::fromRoute('invitation_qr.send_single_twilio', ['submission' => $submission->id()], ['query' => ['resend' => '1']]),
            '#attributes' => ['class' => ['button', 'button--small', 'button--resend'],
                              'title' => $this->t('Invitation already sent. Click to resend.')],
          ]];
        }
        elseif ($invState === 'sending') {
          // In progress — disabled, no link.
          $invSendCell = ['data' => ['#markup' => '<span class="iqr-sending button button--small button--disabled" title="Queued for sending…">⏳ Sending…</span>']];
        }
        else {
          // unsent — active send button.
          $invSendCell = ['data' => ['#type' => 'link',
            '#title'      => $this->t('📩 Send Inv.'),
            '#url'        => Url::fromRoute('invitation_qr.send_single_twilio', ['submission' => $submission->id()]),
            '#attributes' => ['class' => ['button', 'button--small', 'button--primary'],
                              'title' => $this->t('Send invitation card (1–2 months before event).')],
          ]];
        }
      }
      elseif ($twilioEnabled && !$hasInvFid) {
        $invSendCell = ['data' => ['#markup' => '<span class="iqr-pending">Not ready</span>']];
      }

      // Access card send cell — visible to all with access.
      $accessSendCell = ['data' => ['#markup' => '—']];
      if ($twilioEnabled && $hasAccessFid) {
        // Guest declined — never show send button.
        if (($data['rsvp'] ?? '') === 'no') {
          $accessSendCell = ['data' => ['#markup' => '<span class="iqr-declined" title="Guest declined RSVP — access card blocked.">❌ Declined</span>']];
        }
        // Invitation never sent — block access card send.
        elseif ($invState === 'unsent') {
          $accessSendCell = ['data' => ['#markup' => '<span class="iqr-pending" title="Send invitation first before sending access card.">⏸ Send Inv. First</span>']];
        }
        elseif ($accessState === 'sent') {
          $accessSendCell = ['data' => ['#type' => 'link',
            '#title'      => $this->t('🔁 Resend Access'),
            '#url'        => Url::fromRoute('invitation_qr.send_single_access_card', ['submission' => $submission->id()], ['query' => ['resend' => '1']]),
            '#attributes' => ['class' => ['button', 'button--small', 'button--resend'],
                              'title' => $this->t('Access card already sent. Click to resend.')],
          ]];
        }
        elseif ($accessState === 'sending') {
          $accessSendCell = ['data' => ['#markup' => '<span class="iqr-sending button button--small button--disabled" title="Queued for sending…">⏳ Sending…</span>']];
        }
        else {
          $accessSendCell = ['data' => ['#type' => 'link',
            '#title'      => $this->t('🎟 Send Access'),
            '#url'        => Url::fromRoute('invitation_qr.send_single_access_card', ['submission' => $submission->id()]),
            '#attributes' => ['class' => ['button', 'button--small', 'button--access'],
                              'title' => $this->t('Send access card (7 days before event).')],
          ]];
        }
      }
      elseif ($twilioEnabled && !$hasAccessFid) {
        $accessSendCell = ['data' => ['#markup' => '<span class="iqr-pending">Not ready</span>']];
      }

      // Status badges reflecting three states.
      $invSentBadge = match($invState) {
        'sent'    => '<span class="iqr-sent">✅ Sent</span>',
        'sending' => '<span class="iqr-sending">⏳ Sending</span>',
        'failed'  => '<span class="iqr-failed">❌ Failed</span>',
        default   => '<span class="iqr-pending">Unsent</span>',
      };
      $accessSentBadge = match($accessState) {
        'sent'    => '<span class="iqr-sent">✅ Sent</span>',
        'sending' => '<span class="iqr-sending">⏳ Sending</span>',
        'failed'  => '<span class="iqr-failed">❌ Failed</span>',
        default   => '<span class="iqr-pending">Unsent</span>',
      };

      // Delivery status from Twilio status callback.
      $invDeliveryBadge    = $this->deliveryStatusBadge($invDeliveryRaw);
      $accessDeliveryBadge = $this->deliveryStatusBadge($accessDeliveryRaw);

      $row = [
        ['data' => ['#markup' => '<span class="iqr-serial">' . $serialNum++ . '</span>']],
        $checkCell,
        $data['name']         ?? '—',
        $data['phone_number'] ?? '—',
        $data['email']        ?? '—',
        $data['guest_token']  ?? '—',
        // Invitation card status.
        $hasInvFid
          ? ['data' => ['#markup' => '<span class="iqr-ready">✅ Ready</span>']]
          : ['data' => ['#markup' => '<span class="iqr-pending">⏳ Queued</span>']],
        // Access card status.
        $hasAccessFid
          ? ['data' => ['#markup' => '<span class="iqr-ready">✅ Ready</span>']]
          : ['data' => ['#markup' => '<span class="iqr-pending">⏳ Queued</span>']],
        // Check-in.
        $checkedIn
          ? ['data' => ['#markup' => '<span class="iqr-ready">✅ In</span>']]
          : ['data' => ['#markup' => '<span class="iqr-pending">—</span>']],
        ['data' => ['#markup' => $rsvpBadge]],
        $invDownloadCell,
        $accessDownloadCell,
      ];

      if ($twilioEnabled) {
        $row[] = ['data' => ['#markup' => $invSentBadge]];
        $row[] = ['data' => ['#markup' => $invDeliveryBadge]];
        $row[] = $invSendCell;
        $row[] = ['data' => ['#markup' => $accessSentBadge]];
        $row[] = ['data' => ['#markup' => $accessDeliveryBadge]];
        $row[] = $accessSendCell;
      }

      $row[] = \Drupal::service('date.formatter')->format($submission->getCreatedTime(), 'short');

      // Edit button — links to webform submission edit page.
      $row[] = ['data' => ['#type' => 'link',
        '#title'      => $this->t('✏️ Edit'),
        '#url'        => Url::fromRoute('entity.webform_submission.edit_form', [
          'webform'            => 'invitation_webform',
          'webform_submission' => $submission->id(),
        ]),
        '#attributes' => ['class' => ['button', 'button--small']],
      ]];

      // Delete button — links to webform submission delete page.
      // Only show to admins — deleting is irreversible.
      if ($canAdminister) {
        $row[] = ['data' => ['#type' => 'link',
          '#title'      => $this->t('🗑 Delete'),
          '#url'        => Url::fromRoute('entity.webform_submission.delete_form', [
            'webform'            => 'invitation_webform',
            'webform_submission' => $submission->id(),
          ]),
          '#attributes' => [
            'class' => ['button', 'button--small', 'button--danger'],
            'onclick' => "return confirm('Delete submission for " . addslashes($data['name'] ?? 'this guest') . "? This cannot be undone.');",
          ],
        ]];
      }
      else {
        $row[] = ['data' => ['#markup' => '—']];
      }

      $rows[] = $row;
    }

    $headers = [
      '#',
      ['data' => ['#type'=>'html_tag','#tag'=>'input','#attributes'=>['type'=>'checkbox','id'=>'iqr-select-all','title'=>$this->t('Select all')]]],
      $this->t('Name'),
      $this->t('Phone'),
      $this->t('Email'),
      $this->t('Token'),
      $this->t('Inv. Card'),
      $this->t('Access Card'),
      $this->t('Check-In'),
      $this->t('RSVP'),
      $this->t('⬇ Inv.'),
      $this->t('⬇ Access'),
    ];
    if ($twilioEnabled) {
      $headers[] = $this->t('Inv. Sent');
      $headers[] = $this->t('Inv. Status');
      $headers[] = $this->t('📩 Send Inv.');
      $headers[] = $this->t('Access Sent');
      $headers[] = $this->t('Access Status');
      $headers[] = $this->t('🎟 Send Access');
    }
    $headers[] = $this->t('Submitted');
    $headers[] = $this->t('✏️ Edit');
    $headers[] = $this->t('🗑 Delete');

    $build['table'] = [
      '#type'       => 'table',
      '#caption'    => $this->t('"@title" — showing @from–@to of @filtered guests (total: @total)', [
        '@title'    => $node->label(),
        '@from'     => $filteredTotal ? ($currentPage * $perPage) + 1 : 0,
        '@to'       => min(($currentPage + 1) * $perPage, $filteredTotal),
        '@filtered' => $filteredTotal,
        '@total'    => $total,
      ]),
      '#header'     => $headers,
      '#rows'       => $rows,
      '#empty'      => $this->t('No guests found.'),
      '#attributes' => ['class' => ['iqr-submissions-table']],
    ];

    // ── Pagination links ───────────────────────────────────────────────────
    if ($showAll) {
      $build['pager'] = [
        '#markup' => Markup::create(
          '<div class="iqr-pager"><span class="iqr-pager-info">Showing all ' . $filteredTotal . ' guests</span></div>'
        ),
      ];
    }
    elseif ($totalPages > 1) {
      $pagerItems = [];
      $queryBase  = array_filter([
        'search'         => $search,
        'inv_sent'       => $filterInvSent,
        'inv_status'     => $filterInvStatus,
        'acc_sent'       => $filterAccSent,
        'acc_status'     => $filterAccStatus,
        'rsvp_filter'    => $filterRsvp,
        'checkin_filter' => $filterCheckin,
        'sort_order'     => $sortOrder !== 'desc' ? $sortOrder : '',
        'per_page'       => $perPageParam !== '100' ? $perPageParam : '',
      ]);

      $pageUrl = function(int $p) use ($node, $queryBase): string {
        return Url::fromRoute('invitation_qr.submissions_list', ['node' => $node->id()], [
          'query' => $queryBase + ['page' => $p],
        ])->toString();
      };

      // First — only show when NOT on page 1.
      if ($currentPage > 0) {
        $pagerItems[] = ['#markup' => '<a href="' . $pageUrl(0) . '" class="button button--small" title="First page">« First</a>'];
      }

      // Previous — only show when NOT on page 1.
      if ($currentPage > 0) {
        $pagerItems[] = ['#markup' => '<a href="' . $pageUrl($currentPage - 1) . '" class="button button--small">‹ Prev</a>'];
      }

      // 9 page numbers centred around current page.
      $numLinks = 9;
      $half     = (int) floor($numLinks / 2);
      $start    = max(0, $currentPage - $half);
      $end      = min($totalPages - 1, $start + $numLinks - 1);
      // Adjust start if we are near the end.
      if ($end - $start < $numLinks - 1) {
        $start = max(0, $end - $numLinks + 1);
      }

      for ($p = $start; $p <= $end; $p++) {
        $label = (string) ($p + 1);
        if ($p === $currentPage) {
          $pagerItems[] = ['#markup' => '<span class="button button--small button--primary iqr-pager-current">' . $label . '</span>'];
        }
        else {
          $pagerItems[] = ['#markup' => '<a href="' . $pageUrl($p) . '" class="button button--small">' . $label . '</a>'];
        }
      }

      // Next — only show when NOT on last page.
      if ($currentPage < $totalPages - 1) {
        $pagerItems[] = ['#markup' => '<a href="' . $pageUrl($currentPage + 1) . '" class="button button--small">Next ›</a>'];
      }

      // Last — only show when NOT on last page.
      if ($currentPage < $totalPages - 1) {
        $pagerItems[] = ['#markup' => '<a href="' . $pageUrl($totalPages - 1) . '" class="button button--small" title="Last page">Last »</a>'];
      }

      $build['pager'] = [
        '#type'       => 'container',
        '#attributes' => ['class' => ['iqr-pager']],
        'info'  => ['#markup' => '<span class="iqr-pager-info">Page ' . ($currentPage + 1) . ' of ' . $totalPages . ' &nbsp;·&nbsp; ' . $filteredTotal . ' guests total</span>'],
        'links' => ['#theme' => 'item_list', '#items' => $pagerItems, '#attributes' => ['class' => ['iqr-pager-links']]],
      ];
    }

    // Bulk action bar — uses JS to build GET URL with selected SIDs.
    $bulkBarHtml = '';
    if ($twilioEnabled && $canAdminister) {
      $bulkBaseUrl = Url::fromRoute('invitation_qr.send_bulk_twilio', ['node' => $node->id()])->toString();
      $bulkBarHtml = '
        <div class="iqr-bulk-bar">
          <span class="iqr-bulk-label">' . $this->t('With selected guests:') . '</span>
          <label class="iqr-bulk-type-label">' . $this->t('Message type') . '
            <select id="iqr-bulk-type" class="iqr-filter-select">
              <option value="invitation">📩 ' . $this->t('Send/Resend Invitation') . '</option>
              <option value="reminder">🔔 ' . $this->t('RSVP Reminder') . '</option>
            </select>
          </label>
          <button type="button" class="button button--primary" id="iqr-bulk-send-btn"
            data-url="' . $bulkBaseUrl . '">
            📲 ' . $this->t('Send to Selected') . '
          </button>
          <span class="iqr-bulk-info" id="iqr-selected-count">0 ' . $this->t('selected') . '</span>
        </div>';
    }
    $build['bulk_actions_bar'] = ['#markup' => Markup::create($bulkBarHtml)];

    $build['#cache'] = ['max-age' => 0];
    $build['#attached']['library'][] = 'invitation_qr/invitation-qr.admin';
    $build['#attached']['html_head'][] = [[
      '#type'     => 'html_tag',
      '#tag'      => 'style',
      '#attributes' => [],
      '#children' => Markup::create('
        .iqr-filters { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; padding:12px 16px; background:#f5f5f5; border:1px solid #ddd; border-radius:4px; margin-bottom:16px; }
        .iqr-filter-label { display:flex; flex-direction:column; font-size:11px; font-weight:700; color:#555; gap:3px; text-transform:uppercase; letter-spacing:.4px; }
        .iqr-filter-select { padding:6px 8px; border:1px solid #ccc; border-radius:3px; font-size:13px; min-width:140px; background:#fff; }
        .iqr-filters .button { align-self:flex-end; margin-bottom:1px; }
        .iqr-pager { margin:16px 0; display:flex; flex-direction:column; gap:8px; }
        .iqr-pager-info { font-size:13px; color:#555; }
        .iqr-pager-links { list-style:none; margin:0; padding:0; display:flex; flex-wrap:wrap; gap:4px; }
        .iqr-pager-links li { margin:0; }
        .iqr-serial { font-weight:600; color:#888; font-size:12px; }
        .button--disabled { opacity:0.4; cursor:default; pointer-events:none; }
        .iqr-pager-current { cursor:default; pointer-events:none; }
        .button--danger { background:#c0392b !important; color:#fff !important; border-color:#a93226 !important; }
        .button--danger:hover { background:#a93226 !important; }
        .iqr-stat-chip--teal { background:#e0f5f5; border-color:#00897b; color:#00695c; }
        .iqr-submissions-table { border-collapse: collapse; width: 100%; }
        .iqr-submissions-table thead th {
          position: sticky;
          top: 0;
          z-index: 10;
          background: #1f1f1f;
          color: #fff;
          padding: 10px 8px;
          white-space: nowrap;
          box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        /* When Drupal toolbar is present, offset sticky header below it */
        body.toolbar-fixed .iqr-submissions-table thead th {
          top: 39px;
        }
        body.toolbar-fixed.toolbar-tray-open .iqr-submissions-table thead th {
          top: 79px;
        }
        .iqr-submissions-table thead th:first-child { border-radius: 4px 0 0 0; }
        .iqr-submissions-table thead th:last-child  { border-radius: 0 4px 0 0; }
        .iqr-bulk-bar { display:flex; flex-wrap:wrap; gap:12px; align-items:center; padding:12px 16px; background:#fff3cd; border:1px solid #ffc107; border-radius:4px; margin-top:12px; }
        .iqr-bulk-label { font-weight:600; font-size:13px; }
        .iqr-bulk-type-label { display:flex; flex-direction:column; font-size:11px; font-weight:700; color:#555; gap:3px; text-transform:uppercase; }
        .iqr-bulk-info { font-size:13px; color:#888; font-style:italic; margin-left:8px; }
        .iqr-bulk-info.has-selection { color:#1a73e8; font-weight:600; font-style:normal; }
      '),
    ], 'iqr_filter_styles'];

    $build['#attached']['html_head'][] = [[
      '#type'     => 'html_tag',
      '#tag'      => 'script',
      '#attributes' => [],
      '#children' => Markup::create("
        document.addEventListener('DOMContentLoaded', function() {
          var countEl   = document.getElementById('iqr-selected-count');
          var selectAll = document.getElementById('iqr-select-all');
          var sendBtn   = document.getElementById('iqr-bulk-send-btn');

          function getCheckboxes() {
            return document.querySelectorAll('input[name=\"sids[]\"]');
          }
          function getChecked() {
            return document.querySelectorAll('input[name=\"sids[]\"]:checked');
          }
          function updateCount() {
            var n = getChecked().length;
            if (countEl) {
              countEl.textContent = n + ' guest' + (n !== 1 ? 's' : '') + ' selected';
              countEl.classList.toggle('has-selection', n > 0);
            }
          }

          if (selectAll) {
            selectAll.addEventListener('change', function() {
              getCheckboxes().forEach(function(cb) { cb.checked = selectAll.checked; });
              updateCount();
            });
          }

          document.addEventListener('change', function(e) {
            if (e.target && e.target.name === 'sids[]') {
              updateCount();
              if (selectAll && !e.target.checked) selectAll.checked = false;
              if (selectAll) {
                var all = getCheckboxes();
                if (all.length > 0 && all.length === getChecked().length) selectAll.checked = true;
              }
            }
          });

          if (sendBtn) {
            sendBtn.addEventListener('click', function() {
              var checked = getChecked();
              if (checked.length === 0) {
                alert('Please select at least one guest first.');
                return;
              }
              var typeEl = document.getElementById('iqr-bulk-type');
              var type   = typeEl ? typeEl.value : 'invitation';
              var label  = type === 'reminder' ? 'RSVP reminder' : 'invitation';
              if (!confirm('Send ' + label + ' to ' + checked.length + ' selected guest(s)?')) {
                return;
              }
              // Build GET URL with all selected SIDs and message type.
              var baseUrl = sendBtn.getAttribute('data-url');
              var params  = 'type=' + encodeURIComponent(type);
              checked.forEach(function(cb) {
                params += '&sids[]=' + encodeURIComponent(cb.value);
              });
              window.location.href = baseUrl + '?' + params;
            });
          }
        });
      "),
    ], 'iqr_bulk_js'];

    return $build;
  }

  protected function buildEventMeta(NodeInterface $node): array {
    $items = [];

    $fieldLabels = [
      'field_event_type'  => 'Event Type',
      'field_event_date'  => 'Date',
      'field_event_venue' => 'Venue',
      'field_event_time'  => 'Time',
    ];

    foreach ($fieldLabels as $field => $label) {
      if ($node->hasField($field) && !$node->get($field)->isEmpty()) {
        $rawVal = (string) ($node->get($field)->first()->value ?? $node->get($field)->getString());
        // Format date fields as DD-MM-YYYY.
        $val = ($field === 'field_event_date') ? $this->qrService->formatEventDate($rawVal) : $rawVal;
        if ($val) {
          $items[] = '<strong>' . $label . ':</strong> ' . htmlspecialchars($val);
        }
      }
    }

    // Wedding-specific fields.
    $eventType = '';
    if ($node->hasField('field_event_type') && !$node->get('field_event_type')->isEmpty()) {
      $eventType = strtolower((string) ($node->get('field_event_type')->value ?? ''));
    }

    if ($eventType === 'wedding') {
      $weddingFields = [
        'field_bride_name'        => 'Bride',
        'field_groom_name'        => 'Groom',
        'field_bride_father_name' => "Bride's Father",
        'field_groom_mother_name' => "Groom's Mother",
      ];
      foreach ($weddingFields as $field => $label) {
        if ($node->hasField($field) && !$node->get($field)->isEmpty()) {
          $rawVal = (string) ($node->get($field)->first()->value ?? $node->get($field)->getString());
        // Format date fields as DD-MM-YYYY.
        $val = ($field === 'field_event_date') ? $this->qrService->formatEventDate($rawVal) : $rawVal;
          if ($val) {
            $items[] = '<strong>' . $label . ':</strong> ' . htmlspecialchars($val);
          }
        }
      }
    }

    if (empty($items)) {
      return [];
    }

    return [
      '#type'       => 'html_tag',
      '#tag'        => 'div',
      '#attributes' => ['class' => ['iqr-event-meta']],
      '#value'      => implode(' &nbsp;|&nbsp; ', $items),
    ];
  }

  // ══════════════════════════════════════════════════════════════════════════
  // Bulk send invitation (existing)
  // ══════════════════════════════════════════════════════════════════════════

  public function sendBulkTwilio(NodeInterface $node, Request $request): RedirectResponse {
    $config      = $this->config('invitation_qr.settings');
    $sids        = $request->query->all('sids') ?: [];
    $messageType = (string) ($request->query->get('type') ?? 'invitation');

    if (empty($sids)) {
      $this->messenger()->addWarning($this->t('No guests selected. Please tick the checkboxes next to the guests you want to message.'));
      return $this->redirect('invitation_qr.submissions_list', ['node' => $node->id()]);
    }

    $sent = $failed = $skipped = 0;
    $sentNames = [];
    $failedNames = [];

    foreach ($sids as $sid) {
      $sub = $this->entityTypeManager()->getStorage('webform_submission')->load((int) $sid);
      if (!$sub) { $skipped++; continue; }

      $data = $sub->getData();
      $name  = $data['name'] ?? 'Guest';
      $phone = $data['phone_number'] ?? '';

      if (!$phone) { $skipped++; continue; }

      if ($messageType === 'reminder') {
        // Send RSVP reminder — free-form message.
        $reminderTemplate = $config->get('rsvp_reminder_message')
          ?: 'Hi @name, please reply YES or NO to confirm your attendance at the event.';
        $message = str_replace('@name', $name, $reminderTemplate);

        $tempConfig = new class($config, $message) {
          private $cfg; private $msg;
          public function __construct($cfg, $msg) { $this->cfg = $cfg; $this->msg = $msg; }
          public function get($k) { return $k === 'twilio_message' ? $this->msg : $this->cfg->get($k); }
        };

        $ok = $this->qrService->sendViaTwilio($phone, $name, '', $tempConfig);
      }
      else {
        // Send/resend invitation card.
        $fid  = $data['stamped_card_fid'] ?? NULL;
        if (!$fid) { $skipped++; continue; }

        $file = $this->entityTypeManager()->getStorage('file')->load($fid);
        if (!$file) { $skipped++; continue; }

        $parentNode = $this->qrService->findParentNode($sub);
        $dataWithSid = array_merge($data, ['sid' => (int) $sid]);

        $ok = $parentNode
          ? $this->qrService->sendInvitationCard(
              $phone, $name,
              $this->qrService->getAbsoluteFileUrl($file->getFileUri()),
              $parentNode, $config, $dataWithSid
            )
          : $this->qrService->sendViaTwilio($phone, $name,
              $this->qrService->getAbsoluteFileUrl($file->getFileUri()),
              $config
            );

        if ($ok) {
          $this->qrService->saveSubmissionField($sub, 'twilio_sent', 'yes');
        }
      }

      if ($ok) {
        $sent++;
        $sentNames[] = $name . ' (' . $phone . ')';
      }
      else {
        $failed++;
        $failedNames[] = $name . ' (' . $phone . ')';
      }
    }

    // Show exactly who was sent to.
    if ($sent > 0) {
      $typeLabel = $messageType === 'reminder' ? 'RSVP reminder' : 'invitation';
      $this->messenger()->addStatus($this->t(
        '@count @type sent successfully to: @names',
        [
          '@count' => $sent,
          '@type'  => $typeLabel,
          '@names' => implode(', ', $sentNames),
        ]
      ));
    }
    if ($failed > 0) {
      $this->messenger()->addError($this->t(
        '@count failed: @names — check logs for details.',
        ['@count' => $failed, '@names' => implode(', ', $failedNames)]
      ));
    }
    if ($skipped > 0) {
      $this->messenger()->addWarning($this->t(
        '@count skipped (missing card or phone number).', ['@count' => $skipped]
      ));
    }

    return $this->redirect('invitation_qr.submissions_list', ['node' => $node->id()]);
  }

  // ══════════════════════════════════════════════════════════════════════════
  // Send ALL access cards (manual trigger)
  // ══════════════════════════════════════════════════════════════════════════

  public function sendAllAccessCards(NodeInterface $node): RedirectResponse {
    $queue    = \Drupal::queue(InvitationQrService::SEND_QUEUE_NAME);
    $stateKey = 'invitation_qr.send_access_total_' . $node->id();
    $doneKey  = 'invitation_qr.send_access_done_' . $node->id();
    $stored   = \Drupal::state()->get($stateKey, 0);

    // Only queue if no send batch is already in progress.
    if ($stored === 0) {
      $submissions = $this->qrService->getSubmissionsForNode($node->id());
      $queued = $skipped = $declined = 0;

      // Clear stale queue items first so counter is accurate.
      $queue->deleteQueue();

      foreach ($submissions as $sub) {
        $sid = (int) $sub->id();
        $db  = \Drupal::database();

        // Skip declined guests.
        $rsvp = $sub->getData()['rsvp'] ?? '';
        if ($rsvp === 'no') { $declined++; continue; }

        $fid = $db->select('webform_submission_data', 'w')
          ->fields('w', ['value'])
          ->condition('sid', $sid)
          ->condition('name', 'access_card_fid')
          ->condition('property', '')->condition('delta', 0)
          ->execute()->fetchField();

        if (!$fid) { $skipped++; continue; }

        $this->qrService->queueAccessCardSend($sid) ? $queued++ : $skipped++;
      }

      \Drupal::state()->set($stateKey, $queued);
      \Drupal::state()->set($doneKey, 0);
      \Drupal::state()->set($stateKey . '_declined', $declined);
      $stored = $queued;

      if ($queued === 0) {
        $this->messenger()->addWarning($this->t(
          'No access cards to send. @skipped skipped. @declined declined.',
          ['@skipped' => $skipped, '@declined' => $declined]
        ));
        return $this->redirect('invitation_qr.submissions_list', ['node' => $node->id()]);
      }

      $this->qrService->registerActiveSendBatch($node->id(), 'access', $queued);
    }

    // Process next batch of 30 — measure exactly how many were processed.
    $beforeCount = $queue->numberOfItems();
    $this->processSendQueueNow(30);
    $afterCount = $queue->numberOfItems();
    $batchDone  = $beforeCount - $afterCount;

    // Increment running done counter in State.
    $totalDone = \Drupal::state()->get($doneKey, 0) + $batchDone;
    \Drupal::state()->set($doneKey, $totalDone);

    $remaining = $afterCount;
    $declined  = \Drupal::state()->get($stateKey . '_declined', 0);

    if ($remaining > 0) {
      $this->messenger()->addWarning($this->t(
        'Sent @done of @total access cards. @remaining still queued — click Send Access Cards again to continue, or set up the Auto-Resume URL in Settings so this finishes on its own. @declined excluded (declined).',
        ['@done' => $totalDone, '@total' => $stored, '@remaining' => $remaining, '@declined' => $declined]
      ));
    }
    else {
      \Drupal::state()->delete($stateKey);
      \Drupal::state()->delete($doneKey);
      \Drupal::state()->delete($stateKey . '_declined');
      $this->qrService->clearActiveSendBatch($node->id(), 'access');
      $this->qrService->notifySendCampaignComplete($node, 'access', $stored);
      $this->messenger()->addStatus($this->t(
        'All @total access card(s) sent. @declined excluded (RSVP declined).',
        ['@total' => $stored, '@declined' => $declined]
      ));
    }

    return $this->redirect('invitation_qr.submissions_list', ['node' => $node->id()]);
  }

  // ══════════════════════════════════════════════════════════════════════════
  // Send single access card (manual)
  // ══════════════════════════════════════════════════════════════════════════

  public function sendSingleAccessCard(int $submission, Request $request): RedirectResponse {
    $sub = $this->entityTypeManager()->getStorage('webform_submission')->load($submission);
    if (!$sub) {
      $this->messenger()->addError($this->t('Submission not found.'));
      return $this->redirect('invitation_qr.all_events');
    }

    // Block access card send if guest declined RSVP.
    $data = $sub->getData();
    if (($data['rsvp'] ?? '') === 'no') {
      $this->messenger()->addWarning($this->t(
        '@name has declined the invitation — access card not sent.',
        ['@name' => $data['name'] ?? 'This guest']
      ));
      $node   = $this->qrService->findParentNode($sub);
      $route  = $node ? 'invitation_qr.submissions_list' : 'invitation_qr.all_events';
      $params = $node ? ['node' => $node->id()] : [];
      return $this->redirect($route, $params);
    }

    // If ?resend=1, clear the sent flag so the idempotency check allows re-send.
    if ($request->query->get('resend') === '1') {
      $this->qrService->clearAccessSentFlag($sub);
      $this->qrService->resetAccessSendState($submission);
    }

    $queued = $this->qrService->queueAccessCardSend($submission);

    if ($queued) {
      // Process immediately — user clicked the button, they expect instant action.
      $this->processSendQueueNow();
      $data = $sub->getData();
      $this->messenger()->addStatus($this->t(
        'Access card sent to @name (@phone).',
        ['@name' => $data['name'] ?? '', '@phone' => $data['phone_number'] ?? '']
      ));
    }
    else {
      $state = $this->qrService->getAccessSendState($submission);
      if ($state === 'sending') {
        $this->messenger()->addWarning($this->t('Access card is already being sent — please wait.'));
      }
      else {
        $this->messenger()->addWarning($this->t('Access card already sent. Use Resend to send again.'));
      }
    }

    $node   = $this->qrService->findParentNode($sub);
    $route  = $node ? 'invitation_qr.submissions_list' : 'invitation_qr.all_events';
    $params = $node ? ['node' => $node->id()] : [];
    return $this->redirect($route, $params);
  }

  // ══════════════════════════════════════════════════════════════════════════
  // Ad-hoc send to arbitrary number
  // ══════════════════════════════════════════════════════════════════════════

  public function sendAdhocTwilio(NodeInterface $node, Request $request): RedirectResponse {
    $config  = $this->config('invitation_qr.settings');
    $phone   = trim($request->request->get('adhoc_phone', ''));
    $sid     = (int) $request->request->get('adhoc_sid', 0);
    $message = trim($request->request->get('adhoc_message', ''));

    if (!$phone) {
      $this->messenger()->addError($this->t('Phone number is required.'));
      return $this->redirect('invitation_qr.submissions_list', ['node' => $node->id()]);
    }

    $cardUrl = '';
    $name    = '';
    if ($sid) {
      $sub  = $this->entityTypeManager()->getStorage('webform_submission')->load($sid);
      if ($sub) {
        $data    = $sub->getData();
        $name    = $data['name'] ?? '';
        $fid     = $data['stamped_card_fid'] ?? NULL;
        $file    = $fid ? $this->entityTypeManager()->getStorage('file')->load($fid) : NULL;
        $cardUrl = $file ? $this->qrService->getAbsoluteFileUrl($file->getFileUri()) : '';
      }
    }

    // If staff typed a custom message, send that literal text instead of the
    // configured invitation/access-card template. Leave blank to keep the
    // old behaviour (template message, optionally with the card attached).
    $ok = $this->qrService->sendViaTwilio($phone, $name, $cardUrl, $config, $message);

    if ($ok) {
      $this->messenger()->addStatus($this->t('Message sent to @phone.', ['@phone' => $phone]));
    }
    else {
      $this->messenger()->addError($this->t('Failed to send to @phone — check logs.', ['@phone' => $phone]));
    }

    return $this->redirect('invitation_qr.submissions_list', ['node' => $node->id()]);
  }

  // ══════════════════════════════════════════════════════════════════════════
  // Existing actions
  // ══════════════════════════════════════════════════════════════════════════

  /**
   * Re-stamps access cards for all guests in a node.
   * Clears access_card_fid so processSubmission() re-stamps with current
   * node access card design and QR position. Never touches invitation card
   * or twilio_sent — no sends are triggered.
   */
  public function restampAccessCards(NodeInterface $node): RedirectResponse {
    $queue       = \Drupal::queue(InvitationQrService::QUEUE_NAME);
    $stateKey    = 'invitation_qr.restamp_total_' . $node->id();
    $storedTotal = \Drupal::state()->get($stateKey, 0);

    // Only clear DB flags and re-queue if no restamp is in progress.
    if ($storedTotal === 0) {
      $submissions = $this->qrService->getSubmissionsForNode($node->id());
      $total       = count($submissions);

      // Clear the queue first to remove any stale leftover items.
      $queue->deleteQueue();

      foreach ($submissions as $sub) {
        $sid = (int) $sub->id();

        // Load and delete old access card file from disk.
        $oldFid = \Drupal::database()->select('webform_submission_data', 'w')
          ->fields('w', ['value'])
          ->condition('sid', $sid)
          ->condition('name', 'access_card_fid')
          ->condition('property', '')
          ->condition('delta', 0)
          ->execute()
          ->fetchField();

        if ($oldFid) {
          $oldFile = $this->entityTypeManager()->getStorage('file')->load((int) $oldFid);
          if ($oldFile) {
            $oldFile->delete();
          }
        }

        // Clear access_card_fid so processSubmission() guard allows re-stamp.
        \Drupal::database()->delete('webform_submission_data')
          ->condition('sid', $sid)
          ->condition('name', 'access_card_fid')
          ->condition('property', '')
          ->condition('delta', 0)
          ->execute();

        // Clear static entity cache so processSubmission reads fresh data.
        $this->entityTypeManager()->getStorage('webform_submission')->resetCache([$sid]);

        // Queue for re-stamping.
        $this->qrService->queueSubmission($sid);
      }

      // Store total BEFORE processing so subsequent clicks have correct number.
      \Drupal::state()->set($stateKey, $total);
      $storedTotal = $total;
    }

    // Process next batch.
    $this->processQueueInBatches(30);
    $remaining = $queue->numberOfItems();
    $done      = $storedTotal - $remaining;

    if ($remaining > 0) {
      $this->messenger()->addWarning($this->t(
        'Re-stamped @done of @total guests. @remaining still queued — click Re-stamp Access Cards again to continue.',
        ['@done' => $done, '@total' => $storedTotal, '@remaining' => $remaining]
      ));
    }
    else {
      \Drupal::state()->delete($stateKey);
      $this->messenger()->addStatus($this->t(
        'All @n access card(s) re-stamped with new position/design. No invitations were affected.',
        ['@n' => $storedTotal]
      ));
    }

    return $this->redirect('invitation_qr.submissions_list', ['node' => $node->id()]);
  }

  public function processUnstamped(NodeInterface $node): RedirectResponse {
    $queue       = \Drupal::queue(InvitationQrService::QUEUE_NAME);
    $stateKey    = 'invitation_qr.unstamped_total_' . $node->id();
    $doneKey     = 'invitation_qr.unstamped_done_' . $node->id();
    $storedTotal = \Drupal::state()->get($stateKey, 0);

    // Only scan and queue on the FIRST click — subsequent clicks just process.
    if ($storedTotal === 0) {
      $submissions = $this->qrService->getSubmissionsForNode($node->id());
      $count = 0;

      // Clear any stale queue items from a previous incomplete run.
      $queue->deleteQueue();

      foreach ($submissions as $sub) {
        $sid = (int) $sub->id();
        $db  = \Drupal::database();

        $hasInvCard = !empty($sub->getData()['stamped_card_fid']);
        $hasAccessCard = (bool) $db->select('webform_submission_data', 'w')
          ->fields('w', ['value'])
          ->condition('sid', $sid)
          ->condition('name', 'access_card_fid')
          ->condition('property', '')->condition('delta', 0)
          ->execute()->fetchField();

        if (!$hasInvCard || !$hasAccessCard) {
          $this->qrService->queueSubmission($sid);
          $count++;
        }
      }

      if ($count === 0) {
        $this->messenger()->addStatus($this->t('All cards are already stamped — nothing to process.'));
        return $this->redirect('invitation_qr.submissions_list', ['node' => $node->id()]);
      }

      \Drupal::state()->set($stateKey, $count);
      \Drupal::state()->set($doneKey, 0);
      $storedTotal = $count;
    }

    // Process next batch of 30.
    $beforeCount = $queue->numberOfItems();
    $processed   = $this->processQueueInBatches(30);
    $afterCount  = $queue->numberOfItems();
    $totalDone   = \Drupal::state()->get($doneKey, 0) + ($beforeCount - $afterCount);
    \Drupal::state()->set($doneKey, $totalDone);

    $remaining = $afterCount;

    if ($remaining > 0) {
      $this->messenger()->addWarning($this->t(
        'Processed @done of @total. @remaining still queued — click Process Unstamped again to continue.',
        ['@done' => $totalDone, '@total' => $storedTotal, '@remaining' => $remaining]
      ));
    }
    else {
      \Drupal::state()->delete($stateKey);
      \Drupal::state()->delete($doneKey);
      $this->messenger()->addStatus($this->t(
        'All @n unstamped guest card(s) processed successfully.', ['@n' => $storedTotal]
      ));
    }

    return $this->redirect('invitation_qr.submissions_list', ['node' => $node->id()]);
  }

  public function generateAll(NodeInterface $node): RedirectResponse {
    $queue       = \Drupal::queue(InvitationQrService::QUEUE_NAME);
    $stateKey    = 'invitation_qr.genall_total_' . $node->id();
    $doneKey     = 'invitation_qr.genall_done_' . $node->id();
    $storedTotal = \Drupal::state()->get($stateKey, 0);

    if ($storedTotal === 0) {
      $queue->deleteQueue();
      $count = $this->qrService->queueAllForNode($node->id());
      if ($count === 0) {
        $this->messenger()->addStatus($this->t('No guests found for this event.'));
        return $this->redirect('invitation_qr.submissions_list', ['node' => $node->id()]);
      }
      \Drupal::state()->set($stateKey, $count);
      \Drupal::state()->set($doneKey, 0);
      $storedTotal = $count;
    }

    $beforeCount = $queue->numberOfItems();
    $this->processQueueInBatches(30);
    $afterCount = $queue->numberOfItems();
    $totalDone  = \Drupal::state()->get($doneKey, 0) + ($beforeCount - $afterCount);
    \Drupal::state()->set($doneKey, $totalDone);
    $remaining = $afterCount;

    if ($remaining > 0) {
      $this->messenger()->addWarning($this->t(
        'Processed @done of @total. @remaining still queued — click Re-Generate again to continue.',
        ['@done' => $totalDone, '@total' => $storedTotal, '@remaining' => $remaining]
      ));
    }
    else {
      \Drupal::state()->delete($stateKey);
      \Drupal::state()->delete($doneKey);
      $this->messenger()->addStatus($this->t('@n QR(s) re-generated.', ['@n' => $storedTotal]));
    }
    return $this->redirect('invitation_qr.submissions_list', ['node' => $node->id()]);
  }

  public function sendAllTwilio(NodeInterface $node): RedirectResponse {
    $queue    = \Drupal::queue(InvitationQrService::SEND_QUEUE_NAME);
    $stateKey = 'invitation_qr.send_inv_total_' . $node->id();
    $stored   = \Drupal::state()->get($stateKey, 0);

    // Only queue if no send batch is already in progress.
    if ($stored === 0) {
      $submissions = $this->qrService->getSubmissionsForNode($node->id());
      $queued = $skipped = 0;

      // Clear stale queue items first.
      $queue->deleteQueue();

      foreach ($submissions as $sub) {
        $data = $sub->getData();
        if (empty($data['stamped_card_fid'])) { $skipped++; continue; }
        $this->qrService->queueInvitationSend((int) $sub->id()) ? $queued++ : $skipped++;
      }

      \Drupal::state()->set($stateKey, $queued);
      $stored = $queued;

      if ($queued === 0) {
        $this->messenger()->addWarning($this->t(
          'No invitations to send. @skipped skipped (already sent or not ready).',
          ['@skipped' => $skipped]
        ));
        return $this->redirect('invitation_qr.submissions_list', ['node' => $node->id()]);
      }

      $this->qrService->registerActiveSendBatch($node->id(), 'invitation', $queued);
    }

    // Process next batch of 30.
    $this->processSendQueueNow(30);
    $remaining = $queue->numberOfItems();
    $done      = $stored - $remaining;

    if ($remaining > 0) {
      $this->messenger()->addWarning($this->t(
        'Sent @done of @total invitations. @remaining still queued — click Send Invitations again to continue, or set up the Auto-Resume URL in Settings so this finishes on its own.',
        ['@done' => $done, '@total' => $stored, '@remaining' => $remaining]
      ));
    }
    else {
      \Drupal::state()->delete($stateKey);
      $this->qrService->clearActiveSendBatch($node->id(), 'invitation');
      $this->qrService->notifySendCampaignComplete($node, 'invitation', $stored);
      $this->messenger()->addStatus($this->t(
        'All @total invitation(s) sent successfully.',
        ['@total' => $stored]
      ));
    }

    return $this->redirect('invitation_qr.submissions_list', ['node' => $node->id()]);
  }

  public function sendSingleTwilio(int $submission, Request $request): RedirectResponse {
    $sub = $this->entityTypeManager()->getStorage('webform_submission')->load($submission);
    if (!$sub) {
      $this->messenger()->addError($this->t('Submission not found.'));
      return $this->redirect('invitation_qr.all_events');
    }

    // If ?resend=1, clear the sent flag so the idempotency check allows re-send.
    if ($request->query->get('resend') === '1') {
      $this->qrService->clearInvSentFlag($sub);
      $this->qrService->resetInvSendState($submission);
    }

    $queued = $this->qrService->queueInvitationSend($submission);

    if ($queued) {
      // Process immediately — user clicked the button, they expect instant action.
      $this->processSendQueueNow();
      $data = $sub->getData();
      $this->messenger()->addStatus($this->t(
        'Invitation sent to @name (@phone).',
        ['@name' => $data['name'] ?? '', '@phone' => $data['phone_number'] ?? '']
      ));
    }
    else {
      $state = $this->qrService->getInvSendState($submission);
      if ($state === 'sending') {
        $this->messenger()->addWarning($this->t('Invitation is already being sent — please wait.'));
      }
      else {
        $this->messenger()->addWarning($this->t('Invitation already sent. Use Resend to send again.'));
      }
    }

    $node   = $this->qrService->findParentNode($sub);
    $route  = $node ? 'invitation_qr.submissions_list' : 'invitation_qr.all_events';
    $params = $node ? ['node' => $node->id()] : [];
    return $this->redirect($route, $params);
  }

  // ══════════════════════════════════════════════════════════════════════════
  // RSVP Dashboard
  // ══════════════════════════════════════════════════════════════════════════

  /**
   * Counts guests whose last free-text reply arrived after this node's RSVP
   * Dashboard was last opened — drives the "🔴 N new" badge on the guest
   * list page so a new reply doesn't sit unnoticed.
   */
  protected function countUnreadReplies(iterable $submissions, int $nodeId): int {
    $lastViewed = (int) \Drupal::state()->get('invitation_qr.rsvp_last_viewed_' . $nodeId, 0);
    $count = 0;
    foreach ($submissions as $sub) {
      $d = $sub->getData();
      $replyTime = (int) ($d['last_reply_time'] ?? 0);
      if ($replyTime > $lastViewed) {
        $count++;
      }
    }
    return $count;
  }

  /**
   * Maps a sortable column key to a comparable value from a submission's data.
   */
  protected function rsvpSortValue(array $d, string $key) {
    switch ($key) {
      case 'name':         return mb_strtolower((string) ($d['name'] ?? ''));
      case 'phone':        return (string) ($d['phone_number'] ?? '');
      case 'email':        return mb_strtolower((string) ($d['email'] ?? ''));
      case 'replies':      return (int) ($d['rsvp_reply_count'] ?? 0);
      case 'rsvp_time':    return (int) ($d['rsvp_time'] ?? 0);
      case 'message':      return mb_strtolower((string) ($d['last_reply_body'] ?? ''));
      case 'message_time': return (int) ($d['last_reply_time'] ?? 0);
      default:             return 0;
    }
  }

  /**
   * Sorts an array of webform submissions by one of the rsvpSortValue() keys.
   */
  protected function sortSubmissionsBy(array $subs, string $key, string $order): array {
    usort($subs, function ($a, $b) use ($key, $order) {
      $va = $this->rsvpSortValue($a->getData(), $key);
      $vb = $this->rsvpSortValue($b->getData(), $key);
      if ($va === $vb) {
        return 0;
      }
      $cmp = ($va < $vb) ? -1 : 1;
      return $order === 'desc' ? -$cmp : $cmp;
    });
    return $subs;
  }

  /**
   * Builds a clickable column header that toggles sort order, preserving
   * every other query param so switching sort doesn't reset your page/filter.
   */
  protected function rsvpSortLink($label, ?string $key, string $currentSort, string $currentOrder, NodeInterface $node, Request $request) {
    if ($key === NULL) {
      return $label;
    }
    $nextOrder = ($currentSort === $key && $currentOrder === 'asc') ? 'desc' : 'asc';
    $arrow     = $currentSort === $key ? ($currentOrder === 'asc' ? ' ▲' : ' ▼') : '';
    $query     = $request->query->all();
    unset($query['page']);
    $query['sort']  = $key;
    $query['order'] = $nextOrder;

    return [
      'data' => [
        '#type'  => 'link',
        '#title' => $label . $arrow,
        '#url'   => Url::fromRoute('invitation_qr.rsvp_dashboard', ['node' => $node->id()], ['query' => $query]),
      ],
    ];
  }

  public function rsvpDashboard(NodeInterface $node, Request $request): array {
    $submissions = $this->qrService->getSubmissionsForNode($node->id());

    // Viewing the dashboard clears the "new reply" badge on the guest list.
    \Drupal::state()->set('invitation_qr.rsvp_last_viewed_' . $node->id(), \Drupal::time()->getCurrentTime());

    // Default sort: most recently-replied guests first, so new free-text
    // messages are easy to spot without scrolling — click any column header
    // to change it.
    $allowedSortKeys = ['name', 'phone', 'email', 'replies', 'rsvp_time', 'message', 'message_time'];
    $sortKey = (string) $request->query->get('sort', 'message_time');
    if (!in_array($sortKey, $allowedSortKeys, TRUE)) {
      $sortKey = 'message_time';
    }
    $sortOrder = $request->query->get('order') === 'asc' ? 'asc' : 'desc';
    $pageSize  = 50;

    $confirmed = $declined = $pending = [];
    foreach ($submissions as $sub) {
      $d    = $sub->getData();
      $rsvp = $d['rsvp'] ?? '';
      if ($rsvp === 'yes')    $confirmed[] = $sub;
      elseif ($rsvp === 'no') $declined[]  = $sub;
      else                    $pending[]   = $sub;
    }

    $confirmed = $this->sortSubmissionsBy($confirmed, $sortKey, $sortOrder);
    $declined  = $this->sortSubmissionsBy($declined,  $sortKey, $sortOrder);
    $pending   = $this->sortSubmissionsBy($pending,   $sortKey, $sortOrder);

    $total = count($submissions);
    $build = [];

    $build['back'] = [
      '#type'       => 'link',
      '#title'      => $this->t('← Back to Guest List'),
      '#url'        => Url::fromRoute('invitation_qr.submissions_list', ['node' => $node->id()]),
      '#attributes' => ['class' => ['iqr-back-link']],
    ];

    $build['stats'] = [
      '#type'       => 'html_tag',
      '#tag'        => 'div',
      '#attributes' => ['class' => ['iqr-stats-bar']],
      'total'     => $this->statChip($this->t('Total'),       $total,              'gray'),
      'confirmed' => $this->statChip($this->t('✅ Confirmed'), count($confirmed),   'green'),
      'declined'  => $this->statChip($this->t('❌ Declined'),  count($declined),    'orange'),
      'pending'   => $this->statChip($this->t('⏳ No Reply'),  count($pending),     'blue'),
    ];

    if (!empty($pending)) {
      $build['reminder_action'] = [
        '#type'       => 'container',
        '#attributes' => ['class' => ['iqr-actions']],
        'btn' => [
          '#type'       => 'link',
          '#title'      => $this->t('📲 Send RSVP Reminder to @n non-responders', ['@n' => count($pending)]),
          '#url'        => Url::fromRoute('invitation_qr.send_rsvp_reminder', ['node' => $node->id()]),
          '#attributes' => ['class' => ['button', 'button--primary']],
        ],
      ];
    }

    $makeTable = function (array $subs, string $caption, int $element) use ($node, $request, $sortKey, $sortOrder, $pageSize) {
      $tableTotal   = count($subs);
      $pagerManager = \Drupal::service('pager.manager');
      $pager        = $pagerManager->createPager($tableTotal, $pageSize, $element);
      $page         = $pager->getCurrentPage();
      $pageSubs     = array_slice($subs, $page * $pageSize, $pageSize);

      $rows = [];
      foreach ($pageSubs as $i => $sub) {
        $d       = $sub->getData();
        $ts      = $d['rsvp_time'] ?? NULL;
        $msgTs   = $d['last_reply_time'] ?? NULL;
        $msgBody = $d['last_reply_body'] ?? '';
        $phone   = $d['phone_number'] ?? '';
        $serial  = ($page * $pageSize) + $i + 1;

        $replyCell = '—';
        if ($msgBody !== '' && $phone !== '') {
          $replyUrl = Url::fromRoute('invitation_qr.submissions_list', ['node' => $node->id()], [
            'query'     => ['reply_phone' => $phone, 'reply_sid' => $sub->id()],
            'fragment'  => 'iqr-adhoc-reply',
          ]);
          $replyCell = [
            'data' => [
              '#type'       => 'link',
              '#title'      => $this->t('↩ Reply'),
              '#url'        => $replyUrl,
              '#attributes' => ['class' => ['button', 'button--small']],
            ],
          ];
        }

        $rows[] = [
          $serial,
          $d['name']  ?? '—',
          $phone      ?: '—',
          $d['email'] ?? '—',
          $d['rsvp_reply_count'] ?? '0',
          $ts ? \Drupal::service('date.formatter')->format((int)$ts, 'short') : '—',
          $msgBody !== '' ? $msgBody : '—',
          $msgTs ? \Drupal::service('date.formatter')->format((int)$msgTs, 'short') : '—',
          $replyCell,
        ];
      }

      $header = [
        $this->t('#'),
        $this->rsvpSortLink($this->t('Name'), 'name', $sortKey, $sortOrder, $node, $request),
        $this->rsvpSortLink($this->t('Phone'), 'phone', $sortKey, $sortOrder, $node, $request),
        $this->rsvpSortLink($this->t('Email'), 'email', $sortKey, $sortOrder, $node, $request),
        $this->rsvpSortLink($this->t('Replies'), 'replies', $sortKey, $sortOrder, $node, $request),
        $this->rsvpSortLink($this->t('Last Reply'), 'rsvp_time', $sortKey, $sortOrder, $node, $request),
        $this->rsvpSortLink($this->t('Last Free-text Message'), 'message', $sortKey, $sortOrder, $node, $request),
        $this->rsvpSortLink($this->t('Message Time'), 'message_time', $sortKey, $sortOrder, $node, $request),
        $this->t('Action'),
      ];

      $pageCount     = (int) ceil($tableTotal / $pageSize);
      $captionSuffix = $pageCount > 1 ? ' — ' . $this->t('page @cur of @total', ['@cur' => $page + 1, '@total' => $pageCount]) : '';

      return [
        'table' => [
          '#type'       => 'table',
          '#caption'    => $caption . $captionSuffix,
          '#header'     => $header,
          '#rows'       => $rows,
          '#empty'      => $this->t('None.'),
          '#attributes' => ['class' => ['iqr-submissions-table']],
        ],
        'pager' => [
          '#type'     => 'pager',
          '#element'  => $element,
          '#quantity' => 5,
        ],
      ];
    };

    $build['confirmed_table'] = $makeTable($confirmed, $this->t('✅ Confirmed (@n)', ['@n' => count($confirmed)]), 0);
    $build['declined_table']  = $makeTable($declined,  $this->t('❌ Declined (@n)',  ['@n' => count($declined)]), 1);
    $build['pending_table']   = $makeTable($pending,   $this->t('⏳ No Response (@n)', ['@n' => count($pending)]), 2);

    $build['#attached']['library'][] = 'invitation_qr/invitation-qr.admin';
    return $build;
  }

  public function sendRsvpReminder(NodeInterface $node): RedirectResponse {
    $config      = $this->config('invitation_qr.settings');
    $submissions = $this->qrService->getSubmissionsForNode($node->id());
    $template    = $config->get('rsvp_reminder_message')
      ?: 'Hi @name, please reply YES or NO to confirm your attendance.';

    $sent = $skipped = 0;
    foreach ($submissions as $sub) {
      $data = $sub->getData();
      if (!empty($data['rsvp'])) { $skipped++; continue; }

      $fid     = $data['stamped_card_fid'] ?? NULL;
      $file    = $fid ? $this->entityTypeManager()->getStorage('file')->load($fid) : NULL;
      $cardUrl = $file ? $this->qrService->getAbsoluteFileUrl($file->getFileUri()) : '';
      $message = str_replace('@name', $data['name'] ?? '', $template);

      $tempConfig = new class($config, $message) {
        private $cfg; private $msg;
        public function __construct($cfg, $msg) { $this->cfg = $cfg; $this->msg = $msg; }
        public function get($k) {
          return $k === 'twilio_message' ? $this->msg : $this->cfg->get($k);
        }
      };

      $ok = $this->qrService->sendViaTwilio(
        $data['phone_number'] ?? '',
        $data['name'] ?? '',
        $cardUrl,
        $tempConfig
      );

      if ($ok) $sent++; else $skipped++;
    }

    $this->messenger()->addStatus(
      $this->t('RSVP reminders sent to @sent guests. @skipped skipped.', [
        '@sent'    => $sent,
        '@skipped' => $skipped,
      ])
    );
    return $this->redirect('invitation_qr.rsvp_dashboard', ['node' => $node->id()]);
  }

  // ══════════════════════════════════════════════════════════════════════════
  // Downloads
  // ══════════════════════════════════════════════════════════════════════════

  public function downloadZip(NodeInterface $node): mixed {
    $submissions = $this->qrService->getSubmissionsForNode($node->id());
    try {
      $zipPath = $this->zipService->buildZip($node->id(), $submissions);
    }
    catch (\RuntimeException $e) {
      $this->messenger()->addError($e->getMessage());
      return $this->redirect('invitation_qr.submissions_list', ['node' => $node->id()]);
    }
    if (!$zipPath) {
      $this->messenger()->addWarning($this->t('No stamped invitation cards are ready yet.'));
      return $this->redirect('invitation_qr.submissions_list', ['node' => $node->id()]);
    }
    $filename = 'invitations_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $node->label()) . '_' . date('Ymd') . '.zip';
    $response = new BinaryFileResponse($zipPath);
    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
    $response->headers->set('Content-Type', 'application/zip');
    $response->deleteFileAfterSend(TRUE);
    return $response;
  }

  public function downloadAccessZip(NodeInterface $node): mixed {
    $submissions = $this->qrService->getSubmissionsForNode($node->id());
    try {
      $zipPath = $this->zipService->buildAccessZip($node->id(), $submissions);
    }
    catch (\RuntimeException $e) {
      $this->messenger()->addError($e->getMessage());
      return $this->redirect('invitation_qr.submissions_list', ['node' => $node->id()]);
    }
    if (!$zipPath) {
      $this->messenger()->addWarning($this->t('No stamped access cards are ready yet.'));
      return $this->redirect('invitation_qr.submissions_list', ['node' => $node->id()]);
    }
    $filename = 'access_cards_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $node->label()) . '_' . date('Ymd') . '.zip';
    $response = new BinaryFileResponse($zipPath);
    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
    $response->headers->set('Content-Type', 'application/zip');
    $response->deleteFileAfterSend(TRUE);
    return $response;
  }

  public function downloadSingle(int $submission): mixed {
    $sub = $this->entityTypeManager()->getStorage('webform_submission')->load($submission);
    if (!$sub) throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();

    $data = $sub->getData();
    $fid  = $data['stamped_card_fid'] ?? NULL;
    if (!$fid) {
      $this->messenger()->addWarning($this->t('Invitation card not yet ready.'));
      return $this->redirect('invitation_qr.all_events');
    }

    $file = $this->entityTypeManager()->getStorage('file')->load($fid);
    if (!$file) throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();

    $realPath = \Drupal::service('file_system')->realpath($file->getFileUri());
    $name     = preg_replace('/[^A-Za-z0-9_\-]/', '_', $data['name'] ?? 'guest');
    $phone    = preg_replace('/[^0-9]/', '', $data['phone_number'] ?? $submission);

    $response = new BinaryFileResponse($realPath);
    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, "invitation_{$name}_{$phone}.png");
    $response->headers->set('Content-Type', 'image/png');
    return $response;
  }

  public function downloadAccessSingle(int $submission): mixed {
    $sub = $this->entityTypeManager()->getStorage('webform_submission')->load($submission);
    if (!$sub) throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();

    $data = $sub->getData();
    $fid  = $data['access_card_fid'] ?? NULL;
    if (!$fid) {
      $this->messenger()->addWarning($this->t('Access card not yet ready.'));
      return $this->redirect('invitation_qr.all_events');
    }

    $file = $this->entityTypeManager()->getStorage('file')->load($fid);
    if (!$file) throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();

    $realPath = \Drupal::service('file_system')->realpath($file->getFileUri());
    $name     = preg_replace('/[^A-Za-z0-9_\-]/', '_', $data['name'] ?? 'guest');
    $phone    = preg_replace('/[^0-9]/', '', $data['phone_number'] ?? $submission);

    $response = new BinaryFileResponse($realPath);
    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, "access_{$name}_{$phone}.png");
    $response->headers->set('Content-Type', 'image/png');
    return $response;
  }

  // ══════════════════════════════════════════════════════════════════════════
  // CSV Download — exports filtered results
  // ══════════════════════════════════════════════════════════════════════════

  public function downloadCsv(NodeInterface $node, Request $request): StreamedResponse {
    $submissions = $this->qrService->getSubmissionsForNode($node->id());

    // Read filters from query string.
    $search          = trim($request->query->get('search', ''));
    $filterInvSent   = $request->query->get('inv_sent', '');
    $filterInvStatus = $request->query->get('inv_status', '');
    $filterAccSent   = $request->query->get('acc_sent', '');
    $filterAccStatus = $request->query->get('acc_status', '');
    $filterRsvp      = $request->query->get('rsvp_filter', '');

    $filterCheckin   = $request->query->get('checkin_filter', '');
    $sortOrder       = $request->query->get('sort_order', 'desc');

    // Sort submissions by created date.
    $submissionsArray = iterator_to_array($submissions);
    usort($submissionsArray, function ($a, $b) use ($sortOrder) {
      return $sortOrder === 'asc'
        ? $a->getCreatedTime() <=> $b->getCreatedTime()
        : $b->getCreatedTime() <=> $a->getCreatedTime();
    });
    $submissions = $submissionsArray;

    // Build filtered rows.
    $csvRows = [];
    foreach ($submissions as $submission) {
      $data = $submission->getData();
      $sid  = (int) $submission->id();
      $rsvp = $data['rsvp'] ?? '';

      // Apply text search.
      if ($search) {
        $hay = strtolower(($data['name']??'').' '.($data['phone_number']??'').' '.($data['email']??''));
        if (strpos($hay, strtolower($search)) === FALSE) continue;
      }

      // Get states.
      $invState    = $this->qrService->getInvSendState($sid);
      $accessState = $this->qrService->getAccessSendState($sid);

      $db = \Drupal::database();
      $invDelivery = $db->select('webform_submission_data', 'w')
        ->fields('w', ['value'])
        ->condition('sid', $sid)->condition('name', 'inv_delivery_status')
        ->condition('property', '')->condition('delta', 0)
        ->execute()->fetchField() ?: '';

      $accessDelivery = $db->select('webform_submission_data', 'w')
        ->fields('w', ['value'])
        ->condition('sid', $sid)->condition('name', 'access_delivery_status')
        ->condition('property', '')->condition('delta', 0)
        ->execute()->fetchField() ?: '';

      // Apply filters.
      if ($filterInvSent !== '') {
        if ($filterInvSent === 'unsent' && $invState !== 'unsent') continue;
        if ($filterInvSent === 'yes'    && $invState !== 'sent')   continue;
        if ($filterInvSent === 'failed' && $invState !== 'failed') continue;
      }
      if ($filterInvStatus !== '') {
        if ($filterInvStatus === 'none' && $invDelivery !== '') continue;
        if ($filterInvStatus !== 'none' && $invDelivery !== $filterInvStatus) continue;
      }
      if ($filterAccSent !== '') {
        if ($filterAccSent === 'unsent' && $accessState !== 'unsent') continue;
        if ($filterAccSent === 'yes'    && $accessState !== 'sent')   continue;
        if ($filterAccSent === 'failed' && $accessState !== 'failed') continue;
      }
      if ($filterAccStatus !== '') {
        if ($filterAccStatus === 'none' && $accessDelivery !== '') continue;
        if ($filterAccStatus !== 'none' && $accessDelivery !== $filterAccStatus) continue;
      }
      if ($filterRsvp !== '') {
        if ($filterRsvp === 'pending' && !empty($rsvp)) continue;
        if ($filterRsvp !== 'pending' && $rsvp !== $filterRsvp) continue;
      }
      if ($filterCheckin !== '') {
        $isCheckedIn = $this->qrService->isCheckedIn($submission);
        if ($filterCheckin === 'yes' && !$isCheckedIn) continue;
        if ($filterCheckin === 'no'  &&  $isCheckedIn) continue;
      }

      $csvRows[] = [
        $sid,
        $data['name']         ?? '',
        $data['phone_number'] ?? '',
        $data['email']        ?? '',
        $data['guest_token']  ?? '',
        $invState,
        $invDelivery   ?: 'none',
        $accessState,
        $accessDelivery ?: 'none',
        $rsvp          ?: 'pending',
        $this->qrService->isCheckedIn($submission) ? 'yes' : 'no',
        \Drupal::service('date.formatter')->format($submission->getCreatedTime(), 'custom', 'Y-m-d H:i:s'),
      ];
    }

    // Build filename with active filters.
    $parts = ['guests', preg_replace('/[^A-Za-z0-9]/', '_', $node->label())];
    if ($filterInvSent)   $parts[] = 'inv_' . $filterInvSent;
    if ($filterInvStatus) $parts[] = 'invstatus_' . $filterInvStatus;
    if ($filterAccSent)   $parts[] = 'acc_' . $filterAccSent;
    if ($filterAccStatus) $parts[] = 'accstatus_' . $filterAccStatus;
    if ($filterRsvp)      $parts[] = 'rsvp_' . $filterRsvp;
    if ($filterCheckin)   $parts[] = 'checkin_' . $filterCheckin;
    if ($sortOrder === 'asc') $parts[] = 'oldest_first';
    if ($search)          $parts[] = 'search_' . preg_replace('/[^A-Za-z0-9]/', '_', $search);
    $parts[] = date('Ymd_His');
    $filename = implode('_', $parts) . '.csv';

    $headers = [
      'SID', 'Name', 'Phone', 'Email', 'Token',
      'Inv. Sent', 'Inv. Status',
      'Access Sent', 'Access Status',
      'RSVP', 'Checked In', 'Submitted',
    ];

    $response = new StreamedResponse(function () use ($headers, $csvRows) {
      $handle = fopen('php://output', 'w');
      // UTF-8 BOM so Excel opens correctly.
      fwrite($handle, "\xEF\xBB\xBF");
      fputcsv($handle, $headers);
      foreach ($csvRows as $row) {
        fputcsv($handle, $row);
      }
      fclose($handle);
    });

    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
    $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');

    return $response;
  }

  // ══════════════════════════════════════════════════════════════════════════
  // Helpers
  // ══════════════════════════════════════════════════════════════════════════

  /**
   * Returns an HTML badge for a Twilio delivery status string.
   */
  protected function deliveryStatusBadge(string $status): string {
    return match($status) {
      'delivered'   => '<span class="iqr-delivered">✅ Delivered</span>',
      'read'        => '<span class="iqr-delivered">👁 Read</span>',
      'sent'        => '<span class="iqr-sent">📤 Sent</span>',
      'queued'      => '<span class="iqr-sending">⏳ Queued</span>',
      'failed'      => '<span class="iqr-failed">❌ Failed</span>',
      'undelivered' => '<span class="iqr-failed">⚠️ Undelivered</span>',
      default       => '<span class="iqr-pending">—</span>',
    };
  }

  /**
   * Processes up to $batchSize items from the stamping queue per call.
   * Returns number of items actually processed.
   * Safe to call from HTTP requests — will not exhaust PHP timeout.
   */
  protected function processQueueInBatches(int $batchSize = 10): int {
    $queue     = \Drupal::queue(InvitationQrService::QUEUE_NAME);
    $worker    = \Drupal::service('plugin.manager.queue_worker')
      ->createInstance(InvitationQrService::QUEUE_NAME);
    $processed = 0;

    while ($processed < $batchSize && ($item = $queue->claimItem())) {
      try {
        $worker->processItem($item->data);
        $queue->deleteItem($item);
        $processed++;
      }
      catch (\Exception $e) {
        $queue->releaseItem($item);
        \Drupal::logger('invitation_qr')->error(
          'Stamping queue item failed: @msg', ['@msg' => $e->getMessage()]
        );
        // Count as processed to avoid infinite loop on permanent failures.
        $processed++;
      }
    }

    return $processed;
  }

  protected function processQueueNow(): void {
    $queue  = \Drupal::queue(InvitationQrService::QUEUE_NAME);
    $worker = \Drupal::service('plugin.manager.queue_worker')
      ->createInstance(InvitationQrService::QUEUE_NAME);

    while ($item = $queue->claimItem()) {
      try {
        $worker->processItem($item->data);
        $queue->deleteItem($item);
      }
      catch (\Exception $e) {
        $queue->releaseItem($item);
        \Drupal::logger('invitation_qr')->error('Queue item failed: @msg', ['@msg' => $e->getMessage()]);
      }
    }
  }

  /**
   * Immediately drain the send queue — called after every UI button press.
   *
   * This processes only the items that were just queued by the current request.
   * Because queueInvitationSend / queueAccessCardSend are idempotent, a double
   * click or page reload will not add a second item — so this loop runs exactly
   * once per legitimate send action.
   */
  /**
   * @param int $batchSize 0 drains the entire queue (single-send buttons).
   * @param bool $unattended TRUE when called from the Auto-Resume endpoint
   *   (cronSend()) rather than a logged-in admin clicking a button — skips
   *   the messenger() warning, since there's no one there to see it.
   */
  protected function processSendQueueNow(int $batchSize = 0, bool $unattended = FALSE): int {
    $queue  = \Drupal::queue(InvitationQrService::SEND_QUEUE_NAME);
    $worker = \Drupal::service('plugin.manager.queue_worker')
      ->createInstance(InvitationQrService::SEND_QUEUE_NAME);
    $processed = 0;

    // If batchSize is 0 drain entire queue (used by single send buttons).
    // If batchSize > 0 process only that many items (used by batch send buttons).
    while (($batchSize === 0 || $processed < $batchSize) && ($item = $queue->claimItem())) {
      try {
        $worker->processItem($item->data);
        $queue->deleteItem($item);
        $processed++;
      }
      catch (\Drupal\Core\Queue\SuspendQueueException $e) {
        // Daily WhatsApp sending-rate limit reached — stop this run entirely
        // rather than burning through the rest of the queue. Nothing is
        // marked as failed; remaining guests (including this one) stay
        // safely queued. They do NOT send themselves in the background
        // unless the Auto-Resume URL is configured (Settings → Auto-Resume)
        // — otherwise the next actual click of Send picks up where this
        // left off, any time after the 24h window has room again.
        $queue->releaseItem($item);
        \Drupal::logger('invitation_qr')->info('Send queue paused — daily WhatsApp sending limit reached for now.');
        if (!$unattended) {
          $this->messenger()->addWarning($this->t(
            'Daily WhatsApp sending limit reached for now — the remaining guests are still safely queued. Click Send again later to continue, or set up the Auto-Resume URL in Settings so this finishes without you needing to come back.'
          ));
        }
        break;
      }
      catch (\Exception $e) {
        $queue->releaseItem($item);
        \Drupal::logger('invitation_qr')->error(
          'Send queue item failed (sid=@sid type=@type): @msg',
          [
            '@sid'  => $item->data['sid'] ?? '?',
            '@type' => $item->data['type'] ?? '?',
            '@msg'  => $e->getMessage(),
          ]
        );
        $processed++;
      }
    }

    return $processed;
  }

  // ══════════════════════════════════════════════════════════════════════════
  // Auto-Resume (unattended sending)
  // ══════════════════════════════════════════════════════════════════════════

  /**
   * Public, key-protected endpoint a scheduled task can hit periodically
   * (every few hours) to advance pending sends without anyone clicking
   * "Send" in the UI. Safe to call as often as you like — if there's
   * nothing queued, or the daily limit is currently maxed, it's a no-op.
   *
   * Not a Drupal cron hook on purpose: this module's queue processing is
   * deliberately click-triggered only, to avoid silently re-sending to
   * failed/invalid numbers in the background. This endpoint only ever
   * advances a batch that a human already started via "Send All" — it
   * can't create new work on its own.
   */
  public function cronSend(Request $request): JsonResponse {
    $config      = $this->config('invitation_qr.settings');
    $expectedKey = trim((string) ($config->get('send_cron_key') ?: ''));
    $givenKey    = trim((string) $request->query->get('key', ''));

    if ($expectedKey === '' || $givenKey === '' || !hash_equals($expectedKey, $givenKey)) {
      return new JsonResponse(['error' => 'Invalid or missing key.'], 403);
    }

    $queue  = \Drupal::queue(InvitationQrService::SEND_QUEUE_NAME);
    $before = $queue->numberOfItems();

    if ($before === 0) {
      return new JsonResponse(['status' => 'idle', 'remaining' => 0]);
    }

    // Generous batch — this runs unattended, not from an impatient click.
    $processed = $this->processSendQueueNow(200, TRUE);
    $after     = $queue->numberOfItems();

    $completed = [];
    if ($after === 0) {
      // The shared send queue just fully drained. Close out and notify
      // every campaign currently registered as active. This assumes one
      // campaign runs at a time, which matches how "Send All" is actually
      // used here — if two events' sends happen to overlap, both would be
      // reported complete together rather than individually timed.
      foreach ($this->qrService->getActiveSendBatches() as $batch) {
        $nodeId = (int) ($batch['node_id'] ?? 0);
        $type   = (string) ($batch['type'] ?? '');
        if (!$nodeId || !$type) {
          continue;
        }

        $stateKey = $type === 'access'
          ? 'invitation_qr.send_access_total_' . $nodeId
          : 'invitation_qr.send_inv_total_' . $nodeId;
        \Drupal::state()->delete($stateKey);
        if ($type === 'access') {
          \Drupal::state()->delete('invitation_qr.send_access_done_' . $nodeId);
          \Drupal::state()->delete('invitation_qr.send_access_total_' . $nodeId . '_declined');
        }
        $this->qrService->clearActiveSendBatch($nodeId, $type);

        $node = $this->entityTypeManager()->getStorage('node')->load($nodeId);
        $this->qrService->notifySendCampaignComplete($node, $type, (int) ($batch['total'] ?? 0));
        $completed[] = ['node_id' => $nodeId, 'type' => $type];
      }
    }

    return new JsonResponse([
      'status'    => $after === 0 ? 'completed' : 'in_progress',
      'before'    => $before,
      'after'     => $after,
      'processed' => $processed,
      'completed' => $completed,
    ]);
  }

  protected function buildFilterSelect(string $name, $label, array $options, ?string $selected): array {
    $selected = $selected ?? '';
    $optionsHtml = '';
    foreach ($options as $value => $text) {
      $isSelected  = ((string) $value === $selected) ? ' selected="selected"' : '';
      $escapedVal  = htmlspecialchars((string) $value, ENT_QUOTES);
      $escapedText = htmlspecialchars((string) $text,  ENT_QUOTES);
      $optionsHtml .= '<option value="' . $escapedVal . '"' . $isSelected . '>' . $escapedText . '</option>';
    }
    $escapedName  = htmlspecialchars($name, ENT_QUOTES);
    $escapedLabel = htmlspecialchars((string) $label, ENT_QUOTES);
    return [
      '#markup' => Markup::create(
        '<label class="iqr-filter-label">'
        . $escapedLabel
        . '<select name="' . $escapedName . '" class="iqr-filter-select">'
        . $optionsHtml
        . '</select></label>'
      ),
    ];
  }

  protected function buildSearchForm(Url $action, string $current, $placeholder): array {
    return [
      '#type'  => 'container',
      '#attributes' => ['class' => ['iqr-search-wrap']],
      'form' => [
        '#type'  => 'html_tag',
        '#tag'   => 'form',
        '#attributes' => ['method' => 'get', 'action' => $action->toString()],
        'input' => [
          '#type'  => 'html_tag',
          '#tag'   => 'input',
          '#attributes' => ['type'=>'text','name'=>'search','value'=>$current,'placeholder'=>$placeholder,'class'=>['iqr-search-input']],
        ],
        'submit' => [
          '#type'  => 'html_tag',
          '#tag'   => 'input',
          '#attributes' => ['type'=>'submit','value'=>$this->t('Search'),'class'=>['button']],
        ],
        'clear' => $current ? [
          '#type'  => 'html_tag',
          '#tag'   => 'a',
          '#attributes' => ['href' => $action->toString(), 'class' => ['button']],
          '#value' => $this->t('Clear'),
        ] : [],
      ],
    ];
  }

  protected function buildAdhocForm(NodeInterface $node): array {
    $action = Url::fromRoute('invitation_qr.send_adhoc_twilio', ['node' => $node->id()]);

    // Pre-fill from a "↩ Reply" link (e.g. from the RSVP Dashboard's free-text
    // message column) so staff don't have to copy/paste the phone number.
    $request     = \Drupal::request();
    $replyPhone  = trim((string) $request->query->get('reply_phone', ''));
    $replySid    = trim((string) $request->query->get('reply_sid', ''));
    $hasPrefill  = $replyPhone !== '';

    return [
      '#type'  => 'details',
      '#title' => $this->t('📲 Send to a specific number'),
      '#open'  => $hasPrefill,
      '#attributes' => ['class' => ['iqr-adhoc-wrap']] + ($hasPrefill ? ['id' => 'iqr-adhoc-reply'] : []),
      'form' => [
        '#type'  => 'html_tag',
        '#tag'   => 'form',
        '#attributes' => ['method' => 'post', 'action' => $action->toString(), 'class' => ['iqr-adhoc-form']],
        'token_field' => [
          '#type'  => 'html_tag',
          '#tag'   => 'input',
          '#attributes' => ['type'=>'hidden','name'=>'form_token','value'=>\Drupal::csrfToken()->get('iqr-adhoc')],
        ],
        'phone_wrap' => [
          '#type'  => 'html_tag',
          '#tag'   => 'label',
          '#value' => $this->t('Phone number (with country code e.g. +2348012345678)'),
          'input'  => [
            '#type'  => 'html_tag',
            '#tag'   => 'input',
            '#attributes' => ['type'=>'tel','name'=>'adhoc_phone','value'=>$replyPhone,'placeholder'=>'+2348012345678','class'=>['iqr-search-input'],'required'=>TRUE],
          ],
        ],
        'sid_wrap' => [
          '#type'  => 'html_tag',
          '#tag'   => 'label',
          '#value' => $this->t('Attach card from guest (submission ID, optional)'),
          'input'  => [
            '#type'  => 'html_tag',
            '#tag'   => 'input',
            '#attributes' => ['type'=>'number','name'=>'adhoc_sid','value'=>$replySid,'placeholder'=>$this->t('e.g. 46922'),'class'=>['iqr-search-input']],
          ],
        ],
        'message_wrap' => [
          '#type'  => 'html_tag',
          '#tag'   => 'label',
          '#value' => $this->t('Custom message (optional — leave blank to send the default invitation/access-card template instead)'),
          'input'  => [
            '#type'  => 'html_tag',
            '#tag'   => 'textarea',
            '#attributes' => ['name'=>'adhoc_message','rows'=>3,'placeholder'=>$this->t('e.g. Hi, you are eligible — please go ahead and submit your poem.'),'class'=>['iqr-search-input']],
          ],
        ],
        'submit' => [
          '#type'  => 'html_tag',
          '#tag'   => 'button',
          '#attributes' => ['type'=>'submit','class'=>['button','button--primary']],
          '#value' => $this->t('Send Message'),
        ],
      ],
    ];
  }

  protected function statChip($label, int $value, string $color): array {
    return [
      '#type'  => 'html_tag',
      '#tag'   => 'div',
      '#attributes' => ['class' => ['iqr-stat-chip', 'iqr-stat-chip--' . $color]],
      'value' => ['#type'=>'html_tag','#tag'=>'strong','#value'=>$value],
      'label' => ['#type'=>'html_tag','#tag'=>'span','#value'=>$label],
    ];
  }

}