<?php

declare(strict_types=1);

namespace Drupal\space_booking_skedda\Plugin\migrate\process;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\Attribute\MigrateProcess;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Drupal\space_booking_skedda\ImportSettings;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Splits the exported "Spaces" cell into separate names.
 *
 * One booking may hold several rooms, and the export puts them in one cell.
 * Feed the result to migration_lookup to turn the names into space IDs.
 *
 * @code
 * process:
 *   spaces:
 *     - plugin: skedda_space_list
 *       source: Spaces
 *       delimiter: ';'
 *     - plugin: migration_lookup
 *       migration: space_booking_skedda_spaces
 * @endcode
 */
#[MigrateProcess('skedda_space_list')]
final class SkeddaSpaceList extends ProcessPluginBase implements ContainerFactoryPluginInterface {

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
   *
   * @return list<string>
   *   The space names.
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property): array {
    if (is_array($value)) {
      $parts = $value;
    }
    else {
      $delimiter = (string) ($this->configuration['delimiter'] ?? $this->settings->spaceDelimiter);
      $parts = $delimiter === '' ? [$value] : explode($delimiter, (string) $value);
    }

    $names = [];
    foreach ($parts as $part) {
      $name = trim((string) $part);
      // A room named twice in one cell is still one room.
      if ($name !== '' && !in_array($name, $names, TRUE)) {
        $names[] = $name;
      }
    }

    return $names;
  }

  /**
   * {@inheritdoc}
   */
  public function multiple(): bool {
    return TRUE;
  }

}
