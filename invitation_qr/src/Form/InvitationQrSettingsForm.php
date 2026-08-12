<?php

namespace Drupal\invitation_qr\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Admin settings form — QR, name overlay, Twilio, check-in role,
 * message templates (wedding + generic), access card field.
 */
class InvitationQrSettingsForm extends ConfigFormBase {

  protected function getEditableConfigNames(): array {
    return ['invitation_qr.settings'];
  }

  public function getFormId(): string {
    return 'invitation_qr_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $c = $this->config('invitation_qr.settings');

    // ── Mapping ───────────────────────────────────────────────────────────────
    $form['mapping'] = ['#type' => 'details', '#title' => $this->t('Content & Webform Mapping'), '#open' => TRUE];
    $form['mapping']['webform_id'] = [
      '#type' => 'textfield', '#title' => $this->t('Webform ID'),
      '#default_value' => $c->get('webform_id'), '#required' => TRUE,
    ];
    $form['mapping']['node_type'] = [
      '#type' => 'textfield', '#title' => $this->t('Content Type'),
      '#default_value' => $c->get('node_type'), '#required' => TRUE,
    ];
    $form['mapping']['invitation_card_field'] = [
      '#type' => 'textfield', '#title' => $this->t('Invitation Card Field (on node)'),
      '#default_value' => $c->get('invitation_card_field') ?: 'field_invitation_card', '#required' => TRUE,
      '#description' => $this->t('Field machine name for the invitation card image. Name overlay is stamped here.'),
    ];
    $form['mapping']['access_card_field'] = [
      '#type' => 'textfield', '#title' => $this->t('Access Card Field (on node)'),
      '#default_value' => $c->get('access_card_field') ?: 'field_access_card', '#required' => TRUE,
      '#description' => $this->t('Field machine name for the access card image. QR code is stamped here. Sent manually.'),
    ];
    $form['mapping']['zoom_link_field'] = [
      '#type' => 'textfield', '#title' => $this->t('Zoom Link Field (on node)'),
      '#default_value' => $c->get('zoom_link_field') ?: 'field_zoom_link',
      '#description' => $this->t('Field machine name for the Zoom/online meeting link. Use {{Zoom Link}} token in message templates. Leave blank if not used.'),
    ];

    // ── QR Stamp (on access card) ─────────────────────────────────────────────
    $form['qr'] = ['#type' => 'details', '#title' => $this->t('QR Code Stamp (Access Card)'), '#open' => TRUE];
    $form['qr']['qr_position'] = [
      '#type' => 'select', '#title' => $this->t('QR Position on Access Card'),
      '#options' => [
        'top-left' => 'Top Left', 'top-right' => 'Top Right',
        'bottom-left' => 'Bottom Left', 'bottom-right' => 'Bottom Right', 'center' => 'Center',
      ],
      '#default_value' => $c->get('qr_position') ?: 'bottom-right',
    ];
    $form['qr']['qr_size']   = ['#type'=>'number','#title'=>$this->t('QR Size (px)'),'#default_value'=>$c->get('qr_size')?: 150,'#min'=>50,'#max'=>500];
    $form['qr']['qr_margin'] = ['#type'=>'number','#title'=>$this->t('QR Margin (px)'),'#default_value'=>$c->get('qr_margin')?: 20,'#min'=>0,'#max'=>200];

    // ── Name overlay (on invitation card) ─────────────────────────────────────
    $form['name'] = ['#type' => 'details', '#title' => $this->t('Guest Name Overlay (Invitation Card)'), '#open' => TRUE];
    $form['name']['name_enabled'] = [
      '#type' => 'checkbox', '#title' => $this->t('Overlay guest name on the invitation card'),
      '#default_value' => $c->get('name_enabled') ?? TRUE,
    ];
    $form['name']['name_font_path'] = [
      '#type' => 'textfield', '#title' => $this->t('TTF Font File (absolute path)'),
      '#default_value' => $c->get('name_font_path'),
      '#description' => $this->t('e.g. /home/user/fonts/OpenSans-Bold.ttf — leave blank for GD built-in font.'),
    ];
    $form['name']['name_font_size'] = [
      '#type' => 'number', '#title' => $this->t('Font Size (pt)'),
      '#default_value' => $c->get('name_font_size') ?: 36, '#min' => 8, '#max' => 200,
    ];
    $form['name']['name_position'] = [
      '#type' => 'select', '#title' => $this->t('Name Position'),
      '#options' => [
        'top-left' => 'Top Left', 'top-center' => 'Top Center', 'top-right' => 'Top Right',
        'middle-left' => 'Middle Left', 'center' => 'Center', 'middle-right' => 'Middle Right',
        'bottom-left' => 'Bottom Left', 'bottom-center' => 'Bottom Center', 'bottom-right' => 'Bottom Right',
      ],
      '#default_value' => $c->get('name_position') ?: 'center',
    ];
    $form['name']['offsets'] = ['#type' => 'container', '#attributes' => ['style' => 'display:flex;gap:1rem']];
    $form['name']['offsets']['name_offset_x'] = ['#type'=>'number','#title'=>$this->t('X Offset (px)'),'#default_value'=>$c->get('name_offset_x')??0];
    $form['name']['offsets']['name_offset_y'] = ['#type'=>'number','#title'=>$this->t('Y Offset (px)'),'#default_value'=>$c->get('name_offset_y')??0];
    $form['name']['color_note'] = ['#markup' => '<p>' . $this->t('Text color (RGB 0–255):') . '</p>'];
    $form['name']['colors'] = ['#type' => 'container', '#attributes' => ['style' => 'display:flex;gap:1rem']];
    $form['name']['colors']['name_color_r'] = ['#type'=>'number','#title'=>$this->t('R'),'#default_value'=>$c->get('name_color_r')??255,'#min'=>0,'#max'=>255];
    $form['name']['colors']['name_color_g'] = ['#type'=>'number','#title'=>$this->t('G'),'#default_value'=>$c->get('name_color_g')??255,'#min'=>0,'#max'=>255];
    $form['name']['colors']['name_color_b'] = ['#type'=>'number','#title'=>$this->t('B'),'#default_value'=>$c->get('name_color_b')??255,'#min'=>0,'#max'=>255];

    // ── Message Templates ─────────────────────────────────────────────────────
    // Access Card Fallback Message field removed — content is fully managed
    // via Twilio Content Templates now (per-node template SID overrides), so
    // this free-text fallback setting no longer serves a purpose.
    $form['messages'] = ['#type' => 'details', '#title' => $this->t('Message Templates'), '#open' => TRUE];

    $form['messages']['note'] = [
      '#markup' => '<p>' . $this->t('Message content is managed in <a href="/admin/invitation-qr/templates">Twilio Content Templates</a>. The text you define in Twilio Content Template Builder is what gets sent to guests — the module fills in the variable values (guest name, event details, card image URL) automatically.') . '</p>',
    ];

    // ── Twilio ────────────────────────────────────────────────────────────────
    $form['twilio'] = ['#type' => 'details', '#title' => $this->t('Twilio (WhatsApp / SMS)'), '#open' => FALSE];
    $form['twilio']['twilio_messaging_service_sid'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Messaging Service SID'),
      '#default_value' => $c->get('twilio_messaging_service_sid') ?: 'MG398bc30d6886496c58ce1d2bfc6547be',
      '#description'   => $this->t('Your Twilio Messaging Service SID (MG...). Required for sending WhatsApp Card templates.'),
    ];
    $form['twilio']['twilio_enabled'] = [
      '#type' => 'checkbox', '#title' => $this->t('Auto-send invitation card via Twilio after stamping'),
      '#default_value' => $c->get('twilio_enabled') ?? FALSE,
      '#description' => $this->t('Access cards are always sent manually regardless of this setting.'),
    ];

    // Build template options from saved templates.
    $templateOptions = ['' => $this->t('— None (free-form fallback) —')];
    $rawTemplates = $c->get('twilio_templates');
    if ($rawTemplates) {
      $tpls = json_decode($rawTemplates, TRUE) ?: [];
      foreach ($tpls as $tpl) {
        $templateOptions[$tpl['id']] = $tpl['label'] . ' (' . $tpl['sid'] . ')';
      }
    }

    $form['twilio']['default_invitation_template'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Default Template for Invitation Card'),
      '#options'       => $templateOptions,
      '#default_value' => $c->get('default_invitation_template') ?: '',
      '#description'   => $this->t('WhatsApp approved template to use when sending invitation cards. Manage templates at <a href="/admin/invitation-qr/templates">Message Templates</a>.'),
    ];
    $form['twilio']['default_access_template'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Default Template for Access Card'),
      '#options'       => $templateOptions,
      '#default_value' => $c->get('default_access_template') ?: '',
      '#description'   => $this->t('WhatsApp approved template to use when sending access cards.'),
    ];
    $form['twilio']['twilio_channel'] = [
      '#type' => 'select', '#title' => $this->t('Channel'),
      '#options' => ['whatsapp' => 'WhatsApp', 'sms' => 'SMS'],
      '#default_value' => $c->get('twilio_channel') ?: 'whatsapp',
    ];
    $form['twilio']['twilio_account_sid'] = [
      '#type' => 'textfield', '#title' => $this->t('Account SID'),
      '#default_value' => $c->get('twilio_account_sid'),
    ];
    $form['twilio']['twilio_auth_token'] = [
      '#type' => 'password', '#title' => $this->t('Auth Token'),
      '#description' => $this->t('Leave blank to keep existing value.'),
    ];
    $form['twilio']['twilio_from'] = [
      '#type' => 'textfield', '#title' => $this->t('From Number'),
      '#default_value' => $c->get('twilio_from'),
      '#description' => $this->t('E.g. +14155238886 (without whatsapp: prefix).'),
    ];
    $form['twilio']['daily_conversation_limit'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Daily conversation limit (throttle)'),
      '#default_value' => $c->get('daily_conversation_limit') ?? 250,
      '#min'           => 0,
      '#description'   => $this->t('Max NEW unique WhatsApp conversations to open per rolling 24h window — match your actual approved tier (Tier 1 = 250, Tier 2 = 1,000, Tier 3 = 10,000; check Meta Business Manager → WhatsApp Manager for your current tier — tiers can go up automatically as your quality/volume grows, so revisit this number occasionally). Bulk sends pause automatically once this is hit; they resume the next time you click Send, or on their own if you\'ve set up the Auto-Resume URL below. Set to 0 to disable throttling.'),
    ];

    // ── Auto-Resume (unattended sending) ───────────────────────────────────────
    $form['autoresume'] = ['#type' => 'details', '#title' => $this->t('Auto-Resume (unattended sending)'), '#open' => FALSE];
    $cronKey = $c->get('send_cron_key') ?: bin2hex(random_bytes(16));
    $form['autoresume']['send_cron_key'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Auto-resume secret key'),
      '#default_value' => $cronKey,
      '#description'   => $this->t('Click Save once to activate this key, then point a scheduled task (e.g. a cron job, or an uptime/monitoring service that can hit a URL every few hours) at the URL below. Each hit processes a batch of pending sends and — once a whole campaign finishes — emails the address in Notifications below. Without this, sends only advance when someone clicks Send in the UI.'),
    ];
    try {
      $resumeUrl = Url::fromRoute('invitation_qr.cron_send', [], ['absolute' => TRUE, 'query' => ['key' => $cronKey]])->toString();
      $form['autoresume']['resume_url_display'] = [
        '#markup' => '<p><code>' . $resumeUrl . '</code></p>',
      ];
    }
    catch (\Throwable $e) {
      // Route not registered yet (module just deployed, cache not rebuilt).
      // Don't let this break the whole settings page.
      $form['autoresume']['resume_url_display'] = [
        '#markup' => '<p>' . $this->t('URL not available yet — run a cache rebuild (drush cr) after deploying, then reload this page.') . '</p>',
      ];
    }

    // ── Notifications ─────────────────────────────────────────────────────────
    $form['notifications'] = ['#type' => 'details', '#title' => $this->t('Notifications'), '#open' => FALSE];
    $form['notifications']['reply_notification_email'] = [
      '#type'          => 'email',
      '#title'         => $this->t('Email to notify on new free-text replies and finished send campaigns'),
      '#default_value' => $c->get('reply_notification_email') ?: '',
      '#description'   => $this->t('Used for two things: when a guest sends a message that isn\'t a plain YES/NO (e.g. "I was told I\'m not eligible"), and when a bulk send campaign (invitations or access cards) finishes completely. Leave blank to disable both.'),
    ];

    // ── Check-in ──────────────────────────────────────────────────────────────
    $form['checkin'] = ['#type' => 'details', '#title' => $this->t('Check-In Settings'), '#open' => TRUE];
    $form['checkin']['checkin_role'] = [
      '#type' => 'textfield', '#title' => $this->t('Check-In Role (machine name)'),
      '#default_value' => $c->get('checkin_role') ?: 'checkin',
    ];

    // ── RSVP ──────────────────────────────────────────────────────────────────
    $form['rsvp'] = ['#type' => 'details', '#title' => $this->t('RSVP (WhatsApp/SMS Replies)'), '#open' => FALSE];
    $form['rsvp']['rsvp_enabled'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Enable RSVP via inbound WhatsApp/SMS replies'),
      '#default_value' => $c->get('rsvp_enabled') ?? FALSE,
      '#description'   => $this->t('Only controls automatic YES/NO keyword matching and auto-replies below. Turning this off does NOT stop incoming messages from being captured — every reply is still logged to the guest\'s conversation history, and free-text replies still save to the submission and notify staff, so nothing a guest sends is ever missed even with auto-handling off. Webhook URL: <code>https://yoursite.com/invitation-qr/twilio-webhook</code>'),
    ];
    $form['rsvp']['rsvp_yes_keywords'] = [
      '#type' => 'textfield', '#title' => $this->t('YES keywords (comma-separated)'),
      '#default_value' => $c->get('rsvp_yes_keywords') ?: 'yes,yeah,yep,coming,confirm,attending',
    ];
    $form['rsvp']['rsvp_no_keywords'] = [
      '#type' => 'textfield', '#title' => $this->t('NO keywords (comma-separated)'),
      '#default_value' => $c->get('rsvp_no_keywords') ?: 'no,nope,cannot,cancel,decline',
    ];
    $form['rsvp']['rsvp_reply_yes'] = [
      '#type' => 'textarea', '#title' => $this->t('Auto-reply for YES'),
      '#default_value' => $c->get('rsvp_reply_yes') ?: 'Thank you @name! We are excited to see you at the event!',
      '#rows' => 2,
    ];
    $form['rsvp']['rsvp_reply_no'] = [
      '#type' => 'textarea', '#title' => $this->t('Auto-reply for NO'),
      '#default_value' => $c->get('rsvp_reply_no') ?: 'Thank you for letting us know @name. We will miss you!',
      '#rows' => 2,
    ];
    $form['rsvp']['rsvp_reply_unknown'] = [
      '#type' => 'textarea', '#title' => $this->t('Auto-reply for unrecognised messages'),
      '#default_value' => $c->get('rsvp_reply_unknown') ?: 'Hi @name! Please reply YES to confirm or NO to decline.',
      '#rows' => 2,
    ];
    $form['rsvp']['rsvp_reminder_message'] = [
      '#type' => 'textarea', '#title' => $this->t('RSVP reminder message'),
      '#default_value' => $c->get('rsvp_reminder_message') ?: 'Hi @name, please reply YES or NO to confirm your attendance.',
      '#rows' => 2,
    ];

    // ── Processing ────────────────────────────────────────────────────────────
    $form['processing'] = ['#type' => 'details', '#title' => $this->t('Processing Mode'), '#open' => FALSE];
    $form['processing']['process_realtime'] = [
      '#type' => 'checkbox', '#title' => $this->t('Real-time mode'),
      '#default_value' => $c->get('process_realtime') ?? FALSE,
      '#description' => $this->t('Only for small lists or dev. Use queue (cron/drush) for production.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $c = $this->config('invitation_qr.settings');

    $authToken = $form_state->getValue('twilio_auth_token');
    if (empty($authToken)) {
      $authToken = $c->get('twilio_auth_token');
    }

    $c->set('webform_id',              $form_state->getValue('webform_id'))
      ->set('node_type',               $form_state->getValue('node_type'))
      ->set('invitation_card_field',   $form_state->getValue('invitation_card_field'))
      ->set('access_card_field',       $form_state->getValue('access_card_field'))
      ->set('zoom_link_field',         $form_state->getValue('zoom_link_field'))
      ->set('qr_position',             $form_state->getValue('qr_position'))
      ->set('qr_size',                 (int) $form_state->getValue('qr_size'))
      ->set('qr_margin',               (int) $form_state->getValue('qr_margin'))
      ->set('name_enabled',            (bool) $form_state->getValue('name_enabled'))
      ->set('name_font_path',          $form_state->getValue('name_font_path'))
      ->set('name_font_size',          (int) $form_state->getValue('name_font_size'))
      ->set('name_position',           $form_state->getValue('name_position'))
      ->set('name_offset_x',           (int) $form_state->getValue('name_offset_x'))
      ->set('name_offset_y',           (int) $form_state->getValue('name_offset_y'))
      ->set('name_color_r',            (int) $form_state->getValue('name_color_r'))
      ->set('name_color_g',            (int) $form_state->getValue('name_color_g'))
      ->set('name_color_b',            (int) $form_state->getValue('name_color_b'))
      ->set('twilio_messaging_service_sid', $form_state->getValue('twilio_messaging_service_sid'))
      ->set('twilio_enabled',          (bool) $form_state->getValue('twilio_enabled'))
      ->set('default_invitation_template', $form_state->getValue('default_invitation_template'))
      ->set('default_access_template',     $form_state->getValue('default_access_template'))
      ->set('twilio_channel',          $form_state->getValue('twilio_channel'))
      ->set('twilio_account_sid',      $form_state->getValue('twilio_account_sid'))
      ->set('twilio_auth_token',       $authToken)
      ->set('twilio_from',             $form_state->getValue('twilio_from'))
      ->set('daily_conversation_limit', (int) $form_state->getValue('daily_conversation_limit'))
      ->set('send_cron_key',           trim((string) $form_state->getValue('send_cron_key')))
      ->set('reply_notification_email', trim((string) $form_state->getValue('reply_notification_email')))
      ->set('checkin_role',            $form_state->getValue('checkin_role'))
      ->set('rsvp_enabled',            (bool) $form_state->getValue('rsvp_enabled'))
      ->set('rsvp_yes_keywords',       $form_state->getValue('rsvp_yes_keywords'))
      ->set('rsvp_no_keywords',        $form_state->getValue('rsvp_no_keywords'))
      ->set('rsvp_reply_yes',          $form_state->getValue('rsvp_reply_yes'))
      ->set('rsvp_reply_no',           $form_state->getValue('rsvp_reply_no'))
      ->set('rsvp_reply_unknown',      $form_state->getValue('rsvp_reply_unknown'))
      ->set('rsvp_reminder_message',   $form_state->getValue('rsvp_reminder_message'))
      ->set('process_realtime',        (bool) $form_state->getValue('process_realtime'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
