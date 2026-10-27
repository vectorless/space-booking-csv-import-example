<?php

declare(strict_types=1);

namespace Drupal\space_booking_skedda\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Datetime\TimeZoneFormHelper;
use Drupal\space_booking\Enum\BookingType;
use Drupal\space_booking_skedda\ImportSettings;

/**
 * The few things that differ between one export and the next.
 */
final class ImportSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'space_booking_skedda_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [ImportSettings::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(ImportSettings::CONFIG_NAME);

    $form['help'] = [
      '#markup' => $this->t('In Skedda, open <em>List view</em>, widen the date filter to cover everything you want, then <em>Export</em> to CSV. Put the file where the path below points, and run the two migrations: <code>drush migrate:import --group=space_booking_skedda</code>.'),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    $form['path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Path to the exported file'),
      '#description' => $this->t('A stream wrapper path, for example private://skedda-export.csv. The shipped migrations read this same path.'),
      '#default_value' => $config->get('path'),
      '#required' => TRUE,
    ];

    $form['delimiter'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Column delimiter'),
      '#size' => 4,
      '#maxlength' => 1,
      '#default_value' => $config->get('delimiter') ?: ',',
      '#required' => TRUE,
    ];

    $form['space_delimiter'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Separator between room names'),
      '#description' => $this->t('What divides the rooms when one booking holds several, inside the Spaces column.'),
      '#size' => 4,
      '#maxlength' => 1,
      '#default_value' => $config->get('space_delimiter') ?: ',',
      '#required' => TRUE,
    ];

    $form['timezone'] = [
      '#type' => 'select',
      '#title' => $this->t('Timezone the export is written in'),
      '#description' => $this->t('Exported times carry no offset, so the wrong timezone shifts every booking by whole hours.'),
      '#options' => ['' => $this->t("The site's own timezone")] + TimeZoneFormHelper::getOptionsList(),
      '#default_value' => $config->get('timezone') ?? '',
    ];

    $form['date_formats'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Date formats to try, one per line'),
      '#description' => $this->t('PHP date formats, tried in order. Leave empty to try the usual ones: @formats', [
        '@formats' => implode(', ', ImportSettings::defaultDateFormats()),
      ]),
      '#default_value' => implode("\n", (array) ($config->get('date_formats') ?: [])),
      '#rows' => 4,
    ];

    $form['default_booking_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Type for rows with no recognised type'),
      '#options' => BookingType::options(),
      '#default_value' => $config->get('default_booking_type') ?: 'internal',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $formats = array_values(array_filter(array_map(
      'trim',
      explode("\n", (string) $form_state->getValue('date_formats')),
    )));

    $this->config(ImportSettings::CONFIG_NAME)
      ->set('path', trim((string) $form_state->getValue('path')))
      ->set('delimiter', (string) $form_state->getValue('delimiter'))
      ->set('space_delimiter', (string) $form_state->getValue('space_delimiter'))
      ->set('timezone', (string) $form_state->getValue('timezone'))
      ->set('date_formats', $formats)
      ->set('default_booking_type', (string) $form_state->getValue('default_booking_type'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
