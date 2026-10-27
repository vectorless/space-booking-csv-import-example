<?php

declare(strict_types=1);

namespace Drupal\space_booking_skedda\Plugin\migrate\process;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\Attribute\MigrateProcess;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Drupal\space_booking\Enum\BookingType;
use Drupal\space_booking_skedda\ImportSettings;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Maps the exported booking type onto this module's three types.
 *
 * Skedda's types are whatever the venue named them, so there is no mapping
 * that is right everywhere. Unmatched values fall back to the default rather
 * than failing the row: a booking in the wrong category still holds the room,
 * which is the thing you cannot afford to lose.
 *
 * @code
 * process:
 *   booking_type:
 *     plugin: skedda_booking_type
 *     source: Type
 *     map:
 *       'Member booking': user
 *       'Maintenance': unavailable
 *     default: internal
 * @endcode
 */
#[MigrateProcess('skedda_booking_type')]
final class SkeddaBookingType extends ProcessPluginBase implements ContainerFactoryPluginInterface {

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
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property): string {
    $default = (string) ($this->configuration['default'] ?? $this->settings->defaultBookingType);
    $raw = trim((string) $value);
    if ($raw === '') {
      return $this->valid($default);
    }

    // An explicit mapping wins, case-insensitively: venues capitalise as they
    // please and nobody should have to match that exactly.
    foreach ((array) ($this->configuration['map'] ?? []) as $from => $to) {
      if (mb_strtolower((string) $from) === mb_strtolower($raw)) {
        return $this->valid((string) $to);
      }
    }

    // Then the module's own values, so 'internal' and 'Internal use' both work.
    foreach (BookingType::cases() as $case) {
      if (mb_strtolower($case->value) === mb_strtolower($raw) || mb_strtolower((string) $case->label()) === mb_strtolower($raw)) {
        return $case->value;
      }
    }

    return $this->valid($default);
  }

  /**
   * Keep an unknown configured value from reaching the entity.
   */
  private function valid(string $type): string {
    return BookingType::tryFrom($type)?->value ?? BookingType::Internal->value;
  }

}
