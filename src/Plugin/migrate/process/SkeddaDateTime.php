<?php

declare(strict_types=1);

namespace Drupal\space_booking_skedda\Plugin\migrate\process;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\migrate\Attribute\MigrateProcess;
use Drupal\migrate\MigrateException;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Drupal\space_booking_skedda\ImportSettings;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;

/**
 * Turns an exported date into a timestamp.
 *
 * The export writes local wall-clock time with no offset, so the timezone has
 * to be supplied: the same string means two different instants in London and
 * in Sydney, and an hour either side of a clock change.
 *
 * @code
 * process:
 *   start:
 *     plugin: skedda_datetime
 *     source: 'Scheduled start'
 *     timezone: 'Europe/London'
 *     formats:
 *       - 'd/m/Y H:i'
 * @endcode
 */
#[MigrateProcess('skedda_datetime')]
final class SkeddaDateTime extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly ImportSettings $settings,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    $configFactory = $container->get('config.factory');
    \assert($configFactory instanceof ConfigFactoryInterface);
    return new self($configuration, $plugin_id, $plugin_definition, ImportSettings::fromConfig($configFactory));
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property): int {
    $raw = trim((string) $value);
    if ($raw === '') {
      throw new MigrateException(sprintf('No date in "%s".', $destination_property));
    }

    $zone = new \DateTimeZone((string) ($this->configuration['timezone'] ?? $this->settings->timezone));
    $formats = $this->configuration['formats'] ?? $this->settings->dateFormats;

    foreach ((array) $formats as $format) {
      $date = \DateTimeImmutable::createFromFormat((string) $format, $raw, $zone);
      // createFromFormat() is forgiving: it fills missing parts from "now" and
      // reports surplus characters as warnings rather than failing. Only an
      // exact round trip proves the format was really the right one.
      if ($date !== FALSE && $date->format((string) $format) === $raw) {
        return $date->getTimestamp();
      }
    }

    // Last resort, for ISO 8601 and anything else strtotime() understands.
    $parsed = (new \DateTimeImmutable('now', $zone))->modify('@0');
    try {
      $parsed = new \DateTimeImmutable($raw, $zone);
    }
    catch (\Exception) {
      throw new MigrateException(sprintf(
        'Could not read "%s" as a date. Tried: %s. Set the real format in the migration, or in the Skedda import settings.',
        $raw,
        implode(', ', array_map(strval(...), (array) $formats)),
      ));
    }

    return $parsed->getTimestamp();
  }

}
