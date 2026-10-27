<?php

declare(strict_types=1);

namespace Drupal\space_booking_skedda\Plugin\migrate\process;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\Attribute\MigrateProcess;
use Drupal\migrate\MigrateException;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Turns room names into space IDs by matching on the name.
 *
 * Deliberately not migration_lookup: a venue may already have some of these
 * rooms, created by hand or by an earlier import, and matching on the name
 * joins the booking to the room that is already there instead of creating a
 * second room with the same name.
 *
 * @code
 * process:
 *   spaces:
 *     - plugin: skedda_space_list
 *       source: Spaces
 *     - plugin: skedda_space_lookup
 * @endcode
 */
#[MigrateProcess('skedda_space_lookup')]
final class SkeddaSpaceLookup extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self($configuration, $plugin_id, $plugin_definition, $container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   *
   * This runs after skedda_space_list, which returns multiple values, so
   * migrate calls it once per name. It must not declare multiple() itself or
   * the IDs end up nested one array too deep and the field saves as empty.
   *
   * @return int|list<int>
   *   The matching space ID, or IDs when handed several names at once.
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property): int|array {
    $names = is_array($value) ? $value : [$value];
    $ids = [];

    foreach ($names as $name) {
      $name = trim((string) $name);
      if ($name === '') {
        continue;
      }

      $id = $this->findByLabel($name);
      if ($id === NULL) {
        throw new MigrateException(sprintf(
          'No space is named "%s". Run the spaces migration first, or add the room by hand.',
          $name,
        ));
      }
      $ids[] = $id;
    }

    if ($ids === []) {
      throw new MigrateException('This booking names no room.');
    }

    return is_array($value) ? $ids : $ids[0];
  }

  /**
   * Find a space by its name, exactly and then ignoring case.
   */
  private function findByLabel(string $name): ?int {
    $storage = $this->entityTypeManager->getStorage('space_booking_space');

    $exact = $storage->getQuery()->accessCheck(FALSE)->condition('label', $name)->range(0, 1)->execute();
    if ($exact !== []) {
      return (int) reset($exact);
    }

    // "main hall" and "Main Hall" are the same room to everyone except a
    // database comparison on a case-sensitive collation.
    foreach ($storage->loadMultiple() as $space) {
      if (mb_strtolower((string) $space->label()) === mb_strtolower($name)) {
        return (int) $space->id();
      }
    }

    return NULL;
  }

}
