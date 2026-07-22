<?php

namespace Drupal\invitation_qr\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin UI for managing Twilio Content Templates.
 */
class TemplateManagerController extends ControllerBase {

  public static function availableTokens(): array {
    return [
      ''                            => '— Select field —',
      'guest_name'                  => 'Guest Name',
      'bride_father_name'           => 'Bride Father Name',
      'groom_father_name'           => 'Groom Father Name',
      'bride_name'                  => 'Bride Name',
      'groom_name'                  => 'Groom Name',
      'event_date'                  => 'Event Date (DD-MM-YYYY)',
      'event_venue'                 => 'Event Venue',
      'event_time'                  => 'Event Time',
      'event_title'                 => 'Event Title (node title)',
      'zoom_link'                   => 'Zoom Link',
      'stamped_invitation_card_url' => '🖼 Stamped Invitation Card URL (for Media header)',
      'stamped_access_card_url'     => '🎟 Stamped Access Card URL (for Media header)',
    ];
  }

  // ── Template list ──────────────────────────────────────────────────────────

  public function listTemplates(): array {
    $templates = $this->getTemplates();
    $build     = [];

    $build['add_btn'] = [
      '#type'       => 'link',
      '#title'      => $this->t('+ Add New Template'),
      '#url'        => Url::fromRoute('invitation_qr.template_add'),
      '#attributes' => ['class' => ['button', 'button--primary']],
      '#prefix'     => '<div class="iqr-actions">',
      '#suffix'     => '</div><br>',
    ];

    $rows = [];
    foreach ($templates as $tpl) {
      $varList = [];
      foreach ($tpl['variables'] ?? [] as $num => $token) {
        if ($token) {
          $label     = self::availableTokens()[$token] ?? $token;
          $varList[] = '<code>{{' . $num . '}}</code> = ' . htmlspecialchars($label);
        }
      }

      $rows[] = [
        htmlspecialchars($tpl['label'] ?? '—'),
        ['data' => ['#markup' => '<code>' . htmlspecialchars($tpl['sid'] ?? '') . '</code>']],
        ['data' => ['#markup' => implode('<br>', $varList) ?: '—']],
        ['data' => [
          '#type'  => 'container',
          'edit'   => [
            '#type'       => 'link',
            '#title'      => $this->t('Edit'),
            '#url'        => Url::fromRoute('invitation_qr.template_edit', ['template_id' => $tpl['id']]),
            '#attributes' => ['class' => ['button', 'button--small']],
          ],
          'sep'    => ['#markup' => ' '],
          'delete' => [
            '#type'       => 'link',
            '#title'      => $this->t('Delete'),
            '#url'        => Url::fromRoute('invitation_qr.template_delete', ['template_id' => $tpl['id']]),
            '#attributes' => [
              'class'   => ['button', 'button--small', 'button--danger'],
              'onclick' => "return confirm('Delete this template?');",
            ],
          ],
        ]],
      ];
    }

    $build['table'] = [
      '#type'       => 'table',
      '#header'     => [
        $this->t('Name'),
        $this->t('Content SID'),
        $this->t('Variables'),
        $this->t('Actions'),
      ],
      '#rows'       => $rows,
      '#empty'      => $this->t('No templates yet. Click "+ Add New Template" above.'),
      '#attributes' => ['class' => ['iqr-templates-table']],
    ];

    $build['note'] = [
      '#markup' => '<p class="iqr-help">'
        . $this->t('Templates must be approved in your <a href="https://console.twilio.com" target="_blank" rel="noopener">Twilio Console → Content Template Builder</a> before use.')
        . '</p>',
    ];

    $build['#attached']['library'][] = 'invitation_qr/invitation-qr.admin';
    return $build;
  }

  // ── Add / Edit form (proper Drupal render array) ───────────────────────────

  public function addEditForm(Request $request, string $template_id = ''): array {
    $templates   = $this->getTemplates();
    $existing    = ($template_id && isset($templates[$template_id])) ? $templates[$template_id] : [];
    $isEdit      = !empty($existing);
    $currentVars = $existing['variables'] ?? [];
    $tokenOptions = self::availableTokens();

    $saveRoute = $isEdit
      ? Url::fromRoute('invitation_qr.template_edit_save', ['template_id' => $template_id])->toString()
      : Url::fromRoute('invitation_qr.template_add_save')->toString();

    $cancelUrl = Url::fromRoute('invitation_qr.templates')->toString();

    // Build variable rows as a proper render array table.
    $varRows = [];
    for ($i = 1; $i <= 11; $i++) {
      $selectedToken = $currentVars[$i] ?? '';

      // Build select element options.
      $optionsMarkup = '';
      foreach ($tokenOptions as $val => $label) {
        $sel = ($val === $selectedToken) ? ' selected="selected"' : '';
        $optionsMarkup .= '<option value="' . htmlspecialchars($val) . '"' . $sel . '>'
          . htmlspecialchars($label) . '</option>';
      }

      $varRows[] = [
        ['data' => ['#markup' => '<strong>{{' . $i . '}}</strong>']],
        ['data' => ['#markup' => '<select name="variables[' . $i . ']" class="iqr-var-select form-select">'
          . $optionsMarkup . '</select>']],
      ];
    }

    $csrfToken = \Drupal::csrfToken()->get('iqr-template-form');

    $build = [];

    $build['back'] = [
      '#type'       => 'link',
      '#title'      => $this->t('← Back to Templates'),
      '#url'        => Url::fromRoute('invitation_qr.templates'),
      '#attributes' => ['class' => ['iqr-back-link']],
    ];

    // Open the form tag.
    $build['form_open'] = [
      '#markup' => '<form method="post" action="' . $saveRoute . '" class="iqr-template-form">
        <input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">',
    ];

    // Label field.
    $build['field_label'] = [
      '#type'   => 'container',
      '#attributes' => ['class' => ['iqr-form-row']],
      'label_el' => ['#markup' => '<label for="tpl-label"><strong>' . $this->t('Template Name') . '</strong> <span class="form-required">*</span></label>'],
      'input'    => [
        '#markup' => '<input type="text" id="tpl-label" name="label" required
          class="form-text"
          placeholder="e.g. Wedding Invitation"
          value="' . htmlspecialchars($existing['label'] ?? '') . '">',
      ],
    ];

    // SID field.
    $build['field_sid'] = [
      '#type'   => 'container',
      '#attributes' => ['class' => ['iqr-form-row']],
      'label_el' => ['#markup' => '<label for="tpl-sid"><strong>' . $this->t('Content SID') . '</strong> <span class="form-required">*</span></label>'],
      'input'    => [
        '#markup' => '<input type="text" id="tpl-sid" name="sid" required
          class="form-text"
          placeholder="HXxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
          value="' . htmlspecialchars($existing['sid'] ?? '') . '">
          <small>' . $this->t('Copy from Twilio Console → Content Template Builder') . '</small>',
      ],
    ];

    // Machine name field.
    $build['field_id'] = [
      '#type'   => 'container',
      '#attributes' => ['class' => ['iqr-form-row']],
      'label_el' => ['#markup' => '<label for="tpl-id"><strong>' . $this->t('Machine Name (ID)') . '</strong> <span class="form-required">*</span></label>'],
      'input'    => [
        '#markup' => '<input type="text" id="tpl-id" name="id" required
          class="form-text"
          placeholder="e.g. wedding_invitation"
          ' . ($isEdit ? 'readonly' : '') . '
          value="' . htmlspecialchars($existing['id'] ?? '') . '">
          <small>' . $this->t('Lowercase letters, numbers, underscores only. Cannot be changed after saving.') . '</small>',
      ],
    ];

    // Variable mapping heading.
    $build['var_heading'] = [
      '#markup' => '<h3>' . $this->t('Variable Mapping') . '</h3>
        <p>' . $this->t('Map each <code>{{n}}</code> placeholder in your Twilio template to the correct field.') . '</p>',
    ];

    // Variable mapping table.
    $build['var_table'] = [
      '#type'       => 'table',
      '#header'     => [$this->t('Variable'), $this->t('Maps to')],
      '#rows'       => $varRows,
      '#attributes' => ['class' => ['iqr-var-table']],
    ];

    // Submit buttons.
    $build['actions'] = [
      '#markup' => '<div class="iqr-form-actions">
        <button type="submit" class="button button--primary">'
        . ($isEdit ? $this->t('Save Changes') : $this->t('Add Template'))
        . '</button>
        <a href="' . $cancelUrl . '" class="button">' . $this->t('Cancel') . '</a>
      </div>',
    ];

    // Close form tag.
    $build['form_close'] = ['#markup' => '</form>'];

    $build['#attached']['library'][] = 'invitation_qr/invitation-qr.admin';
    return $build;
  }

  // ── Save handler ──────────────────────────────────────────────────────────

  public function saveTemplate(Request $request, string $template_id = ''): RedirectResponse {
    $label = trim($request->request->get('label', ''));
    $sid   = trim($request->request->get('sid', ''));
    $id    = preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($request->request->get('id', ''))));
    $vars  = $request->request->all('variables') ?: [];

    if (!$label || !$sid || !$id) {
      $this->messenger()->addError($this->t('Name, Content SID and Machine Name are all required.'));
      return $this->redirect('invitation_qr.templates');
    }

    $variables = [];
    foreach ($vars as $num => $token) {
      if (!empty($token)) {
        $variables[(int) $num] = $token;
      }
    }

    $templates      = $this->getTemplates();
    $templates[$id] = [
      'id'        => $id,
      'label'     => $label,
      'sid'       => $sid,
      'variables' => $variables,
    ];

    $this->saveTemplates($templates);
    $this->messenger()->addStatus($this->t('Template "@label" saved.', ['@label' => $label]));
    return $this->redirect('invitation_qr.templates');
  }

  // ── Delete handler ────────────────────────────────────────────────────────

  public function deleteTemplate(string $template_id): RedirectResponse {
    $templates = $this->getTemplates();
    if (isset($templates[$template_id])) {
      $label = $templates[$template_id]['label'] ?? $template_id;
      unset($templates[$template_id]);
      $this->saveTemplates($templates);
      $this->messenger()->addStatus($this->t('Template "@label" deleted.', ['@label' => $label]));
    }
    return $this->redirect('invitation_qr.templates');
  }

  // ── Storage ───────────────────────────────────────────────────────────────

  public static function getTemplates(): array {
    $raw     = \Drupal::config('invitation_qr.settings')->get('twilio_templates');
    if (empty($raw)) return [];
    $decoded = json_decode($raw, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  protected function saveTemplates(array $templates): void {
    \Drupal::configFactory()
      ->getEditable('invitation_qr.settings')
      ->set('twilio_templates', json_encode($templates))
      ->save();
  }

}
