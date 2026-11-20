<?php

namespace Drupal\invitation_qr\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\invitation_qr\Service\InvitationQrService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Per-event card settings: download-only mode and name on the access card.
 *
 * Saved per Invitation node in State (see InvitationQrService::
 * getEventSettings()). An event that has never been saved here uses the
 * defaults — the original WhatsApp workflow with a QR-only access card.
 */
class EventCardSettingsForm extends FormBase {

  protected InvitationQrService $qrService;

  public function __construct(InvitationQrService $qrService) {
    $this->qrService = $qrService;
  }

  public static function create(ContainerInterface $container): static {
    return new static($container->get('invitation_qr.qr_service'));
  }

  public function getFormId(): string {
    return 'invitation_qr_event_card_settings_form';
  }

  public function title(NodeInterface $node): string {
    return (string) $this->t('Event Card Settings — @title', ['@title' => $node->label()]);
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    $form_state->set('nid', (int) $node->id());
    $s      = $this->qrService->getEventSettings((int) $node->id());
    $config = $this->config('invitation_qr.settings');

    $form['back'] = [
      '#type'  => 'link',
      '#title' => $this->t('← Back to Guest List'),
      '#url'   => Url::fromRoute('invitation_qr.submissions_list', ['node' => $node->id()]),
      '#attributes' => ['class' => ['iqr-back-link']],
    ];

    // ── Delivery ─────────────────────────────────────────────────────────────
    $form['delivery'] = ['#type' => 'details', '#title' => $this->t('Delivery'), '#open' => TRUE];
    $form['delivery']['download_only'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Download only — never send anything to WhatsApp/Twilio for this event'),
      '#default_value' => $s['download_only'],
      '#description'   => $this->t('Cards are still stamped as usual and can be downloaded one by one or as a ZIP. All send buttons are hidden, auto-send is skipped, and any queued sends for this event are dropped. Leave unticked for the normal WhatsApp workflow.'),
    ];

    // ── Name on access card ─────────────────────────────────────────────────
    $form['name'] = ['#type' => 'details', '#title' => $this->t('Name on Access Card'), '#open' => TRUE];
    $form['name']['access_name_enabled'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Print the guest name on the access card (with the QR code)'),
      '#default_value' => $s['access_name_enabled'],
    ];

    $visible = ['visible' => [':input[name="access_name_enabled"]' => ['checked' => TRUE]]];
    $fieldOptions = $this->webformFieldOptions((string) $config->get('webform_id'));

    $form['name']['wrap'] = ['#type' => 'container', '#states' => $visible];
    $w = &$form['name']['wrap'];

    $w['name_source'] = [
      '#type'    => 'radios',
      '#title'   => $this->t('Where do the two lines come from?'),
      '#options' => [
        'split'  => $this->t('One field, split over two lines (at a "|" if typed, otherwise at the space nearest the middle)'),
        'fields' => $this->t('Two separate fields — line 1 and line 2'),
      ],
      '#default_value' => $s['name_source'],
    ];
    $w['line1_field'] = $this->fieldElement($this->t('Line 1 field (or the field to split)'), $s['line1_field'] ?: 'name', $fieldOptions, TRUE);
    $w['line2_field'] = $this->fieldElement($this->t('Line 2 field'), $s['line2_field'], $fieldOptions, FALSE)
      + ['#states' => ['visible' => [':input[name="name_source"]' => ['value' => 'fields']]]];
    $w['uppercase'] = [
      '#type' => 'checkbox', '#title' => $this->t('Print in CAPITALS'),
      '#default_value' => $s['uppercase'],
    ];

    $w['look'] = ['#type' => 'fieldset', '#title' => $this->t('Position & look')];
    $w['look']['position'] = [
      '#type'    => 'select',
      '#title'   => $this->t('Anchor'),
      '#options' => [
        'top-left' => 'Top Left', 'top-center' => 'Top Center', 'top-right' => 'Top Right',
        'middle-left' => 'Middle Left', 'center' => 'Center', 'middle-right' => 'Middle Right',
        'bottom-left' => 'Bottom Left', 'bottom-center' => 'Bottom Center', 'bottom-right' => 'Bottom Right',
      ],
      '#default_value' => $s['position'],
      '#description'   => $this->t('Both lines are aligned to this anchor, then nudged by the offsets.'),
    ];
    $w['look']['offsets'] = ['#type' => 'container', '#attributes' => ['style' => 'display:flex;gap:1rem;flex-wrap:wrap']];
    $w['look']['offsets']['offset_x'] = ['#type' => 'number', '#title' => $this->t('X offset (px)'), '#default_value' => $s['offset_x'], '#description' => $this->t('+ moves right')];
    $w['look']['offsets']['offset_y'] = ['#type' => 'number', '#title' => $this->t('Y offset (px)'), '#default_value' => $s['offset_y'], '#description' => $this->t('+ moves down')];

    $w['look']['sizes'] = ['#type' => 'container', '#attributes' => ['style' => 'display:flex;gap:1rem;flex-wrap:wrap']];
    $w['look']['sizes']['font_size']     = ['#type' => 'number', '#title' => $this->t('Font size (pt)'), '#default_value' => $s['font_size'], '#min' => 6, '#max' => 400];
    $w['look']['sizes']['min_font_size'] = ['#type' => 'number', '#title' => $this->t('Smallest size for long names (pt)'), '#default_value' => $s['min_font_size'], '#min' => 6, '#max' => 400];
    $w['look']['sizes']['max_width_pct'] = ['#type' => 'number', '#title' => $this->t('Max line width (% of card)'), '#default_value' => $s['max_width_pct'], '#min' => 10, '#max' => 100];
    $w['look']['sizes']['line_spacing']  = ['#type' => 'number', '#title' => $this->t('Line spacing'), '#default_value' => $s['line_spacing'], '#min' => 0.8, '#max' => 3, '#step' => 0.05];

    $w['look']['colour'] = ['#type' => 'container', '#attributes' => ['style' => 'display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end']];
    $w['look']['colour']['color']  = ['#type' => 'color', '#title' => $this->t('Text colour'), '#default_value' => $s['color']];
    $w['look']['colour']['shadow'] = ['#type' => 'checkbox', '#title' => $this->t('Soft shadow'), '#default_value' => $s['shadow']];

    $resolved = $this->qrService->resolveAccessFontPath($s, $config);
    $w['look']['font_path'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('TTF font file (absolute path, optional)'),
      '#default_value' => $s['font_path'],
      '#description'   => $this->t('Leave blank to use the global name font, or the DejaVu Sans Bold font bundled with this module. Currently using: @f', ['@f' => $resolved ?: $this->t('GD built-in font (no TTF found)')]),
    ];

    unset($w);

    // ── Preview ─────────────────────────────────────────────────────────────
    $previewUrl = Url::fromRoute('invitation_qr.access_card_preview', ['node' => $node->id()], ['query' => ['t' => time()]])->toString();
    $form['preview'] = ['#type' => 'details', '#title' => $this->t('Preview (saved settings)'), '#open' => TRUE];
    $form['preview']['img'] = [
      '#markup' => '<p>' . $this->t('Uses the first guest on this event (or a sample name) and a sample QR. Nothing is saved.') . '</p>'
        . '<img src="' . $previewUrl . '" alt="' . $this->t('Access card preview') . '" style="max-width:100%;max-height:600px;border:1px solid #ccc">',
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Save & refresh preview'), '#button_type' => 'primary'];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $font = trim((string) $form_state->getValue('font_path'));
    if ($font !== '' && !is_file($font)) {
      $form_state->setErrorByName('font_path', $this->t('Font file not found: @f', ['@f' => $font]));
    }
    if ((int) $form_state->getValue('min_font_size') > (int) $form_state->getValue('font_size')) {
      $form_state->setErrorByName('min_font_size', $this->t('The smallest size cannot be bigger than the font size.'));
    }
    if ($form_state->getValue('access_name_enabled') && $form_state->getValue('name_source') === 'fields'
      && trim((string) $form_state->getValue('line2_field')) === '') {
      $form_state->setErrorByName('line2_field', $this->t('Choose a field for line 2, or switch to "One field, split over two lines".'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $nid    = (int) $form_state->get('nid');
    $before = $this->qrService->getEventSettings($nid);
    $v      = $form_state->getValues();

    $settings = [
      'download_only'       => (bool) $v['download_only'],
      'access_name_enabled' => (bool) $v['access_name_enabled'],
      'name_source'         => $v['name_source'] === 'fields' ? 'fields' : 'split',
      'line1_field'         => trim((string) $v['line1_field']),
      'line2_field'         => trim((string) $v['line2_field']),
      'uppercase'           => (bool) $v['uppercase'],
      'font_path'           => trim((string) $v['font_path']),
      'font_size'           => (int) $v['font_size'],
      'min_font_size'       => (int) $v['min_font_size'],
      'line_spacing'        => (float) $v['line_spacing'],
      'max_width_pct'       => (int) $v['max_width_pct'],
      'color'               => (string) $v['color'],
      'shadow'              => (bool) $v['shadow'],
      'position'            => (string) $v['position'],
      'offset_x'            => (int) $v['offset_x'],
      'offset_y'            => (int) $v['offset_y'],
    ];
    $this->qrService->saveEventSettings($nid, $settings);
    $this->messenger()->addStatus($this->t('Event card settings saved.'));

    // Existing stamped access cards don't change by themselves — point the
    // admin at Re-stamp when anything that affects the card image changed.
    $cardKeys = array_diff(array_keys($settings), ['download_only']);
    $changed  = FALSE;
    foreach ($cardKeys as $k) {
      if ($before[$k] != $settings[$k]) { $changed = TRUE; break; }
    }
    if ($changed) {
      $this->messenger()->addWarning($this->t('Access cards already stamped still use the old layout. When the preview looks right, click "🔁 Re-stamp Access Cards" on the <a href=":url">Guest List</a>.', [
        ':url' => Url::fromRoute('invitation_qr.submissions_list', ['node' => $nid])->toString(),
      ]));
    }
  }

  /**
   * Webform element keys => titles, for the field pickers.
   */
  protected function webformFieldOptions(string $webformId): array {
    $options = [];
    if ($webformId && class_exists('\Drupal\webform\Entity\Webform')) {
      $webform = \Drupal\webform\Entity\Webform::load($webformId);
      if ($webform) {
        foreach ($webform->getElementsInitializedFlattenedAndHasValue() as $key => $el) {
          $title = (string) ($el['#title'] ?? $key);
          $options[$key] = $title . ' (' . $key . ')';
        }
      }
    }
    return $options;
  }

  protected function fieldElement($title, string $default, array $options, bool $required): array {
    if (!empty($options)) {
      if ($default !== '' && !isset($options[$default])) {
        $options[$default] = $default . ' ' . $this->t('(not on webform)');
      }
      return [
        '#type'          => 'select',
        '#title'         => $title,
        '#options'       => $options,
        '#empty_option'  => $this->t('— None —'),
        '#default_value' => $default,
      ];
    }
    return [
      '#type'          => 'textfield',
      '#title'         => $title,
      '#default_value' => $default,
      '#description'   => $this->t('Webform element key, e.g. name'),
    ];
  }

}
