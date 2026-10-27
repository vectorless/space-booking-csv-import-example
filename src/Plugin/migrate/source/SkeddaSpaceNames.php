<?php

declare(strict_types=1);

namespace Drupal\space_booking_skedda\Plugin\migrate\source;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\Attribute\MigrateSource;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate_source_csv\Plugin\migrate\source\CSV;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Every distinct room named in a booking export.
 *
 * The export has no list of rooms: it has a list of bookings, each naming the
 * rooms it occupies. Rather than ask for a second file, this reads the booking
 * export and yields each room once, so the rooms can be migrated first and the
 * bookings can then look them up by name.
 *
 * @code
 * source:
 *   plugin: skedda_space_names
 *   path: 'private://skedda-export.csv'
 *   ids: [name]
 *   space_column: 'Spaces'
 *   space_delimiter: ','
 *   skip_existing: true
 * @endcode
 */
#[MigrateSource('skedda_space_names')]
final class SkeddaSpaceNames extends CSV implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager, for rooms the site already has.
   */
  private ?EntityTypeManagerInterface $entityTypeManager = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, ?MigrationInterface $migration = NULL): static {
    $source = new static($configuration, $plugin_id, $plugin_definition, $migration);
    $source->entityTypeManager = $container->get('entity_type.manager');
    return $source;
  }

  /**
   * Names of the rooms the site already has, lower-cased.
   *
   * @return array<string, true>
   *   A set, for cheap lookups.
   */
  private function existingNames(): array {
    if ($this->entityTypeManager === NULL || ($this->configuration['skip_existing'] ?? TRUE) === FALSE) {
      return [];
    }

    $existing = [];
    foreach ($this->entityTypeManager->getStorage('space_booking_space')->loadMultiple() as $space) {
      $existing[mb_strtolower((string) $space->label())] = TRUE;
    }
    return $existing;
  }

  /**
   * {@inheritdoc}
   */
  public function initializeIterator(): \Generator {
    $column = (string) ($this->configuration['space_column'] ?? 'Spaces');
    $fallback = (string) ($this->configuration['fallback_column'] ?? 'Assigned space');
    $delimiter = (string) ($this->configuration['space_delimiter'] ?? ',');

    // A room the venue already set up keeps its capacity, its tags and the
    // rooms it overlaps. Creating a second one with the same name would split
    // the bookings across two columns of the schedule.
    $seen = $this->existingNames();
    foreach (parent::initializeIterator() as $record) {
      $cell = trim((string) ($record[$column] ?? ''));
      if ($cell === '') {
        // Some exports only fill the assigned space, for instance where the
        // venue lets Skedda choose which of a pool of rooms to use.
        $cell = trim((string) ($record[$fallback] ?? ''));
      }
      if ($cell === '') {
        continue;
      }

      $names = $delimiter === '' ? [$cell] : explode($delimiter, $cell);
      foreach ($names as $name) {
        $name = trim($name);
        $key = mb_strtolower($name);
        if ($name === '' || isset($seen[$key])) {
          continue;
        }
        $seen[$key] = TRUE;
        yield ['name' => $name];
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function fields(): array {
    return ['name' => 'Space name'];
  }

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    return sprintf('rooms named in %s', (string) $this->configuration['path']);
  }

}
