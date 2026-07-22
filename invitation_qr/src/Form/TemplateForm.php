<?php

namespace Drupal\invitation_qr\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\invitation_qr\Controller\TemplateManagerController;

/**
 * Add / Edit template form — pure Drupal Form API.
 */
class TemplateForm extends FormBase {

  public function getFormId(): string {
    return 'invitation_qr_template_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, string $template_id = ''): array {
    $templates   = TemplateManagerController::getTemplates();
    $existing    = ($template_id && isset($templates[$template_id])) ? $templates[$template_id] : [];
    $isEdit      = !empty($existing);
    $currentVars = $existing['variables'] ?? [];

    $form['#template_id'] = $template_id;

    $form['label'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Template Name'),
      '#required'      => TRUE,
      '#default_value' => $existing['label'] ?? '',
      '#placeholder'   => 'e.g. Wedding Invitation',
    ];

    $form['sid'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Content SID'),
      '#required'      => TRUE,
      '#default_value' => $existing['sid'] ?? '',
      '#placeholder'   => 'HXxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
      '#description'   => $this->t('Copy from Twilio Console → Content Template Builder.'),
    ];

    $form['id'] = [
      '#type'          => 'machine_name',
      '#title'         => $this->t('Machine Name (ID)'),
      '#required'      => TRUE,
      '#default_value' => $existing['id'] ?? '',
      '#disabled'      => $isEdit,
      '#description'   => $this->t('Lowercase letters, numbers, underscores only. Cannot be changed after saving.'),
      '#machine_name'  => [
        'exists'    => [$this, 'machineNameExists'],
        'source'    => ['label'],
      ],
    ];

    $form['variables'] = [
      '#type'  => 'details',
      '#title' => $this->t('Variable Mapping'),
      '#open'  => TRUE,
      '#description' => $this->t('Map each template variable {{n}} to the corresponding field.'),
    ];

    $tokenOptions = TemplateManagerController::availableTokens();

    for ($i = 1; $i <= 11; $i++) {
      $form['variables']['var_' . $i] = [
        '#type'          => 'select',
        '#title'         => $this->t('{{@n}}', ['@n' => $i]),
        '#options'       => $tokenOptions,
        '#default_value' => $currentVars[$i] ?? '',
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type'  => 'submit',
      '#value' => $isEdit ? $this->t('Save Changes') : $this->t('Add Template'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type'       => 'link',
      '#title'      => $this->t('Cancel'),
      '#url'        => Url::fromRoute('invitation_qr.templates'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  public function machineNameExists(string $value): bool {
    $templates = TemplateManagerController::getTemplates();
    return isset($templates[$value]);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $label = $form_state->getValue('label');
    $sid   = $form_state->getValue('sid');
    $id    = $form_state->getValue('id');

    $variables = [];
    for ($i = 1; $i <= 11; $i++) {
      $val = $form_state->getValue('var_' . $i);
      if (!empty($val)) {
        $variables[$i] = $val;
      }
    }

    $templates      = TemplateManagerController::getTemplates();
    $templates[$id] = [
      'id'        => $id,
      'label'     => $label,
      'sid'       => $sid,
      'variables' => $variables,
    ];

    \Drupal::configFactory()
      ->getEditable('invitation_qr.settings')
      ->set('twilio_templates', json_encode($templates))
      ->save();

    $this->messenger()->addStatus($this->t('Template "@label" saved.', ['@label' => $label]));
    $form_state->setRedirectUrl(Url::fromRoute('invitation_qr.templates'));
  }

}
