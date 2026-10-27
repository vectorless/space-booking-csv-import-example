<?php

declare(strict_types=1);

namespace Drupal\Tests\space_booking_skedda\Kernel;

use Drupal\Tests\migrate\Kernel\MigrateTestBase;
use Drupal\space_booking\BookingInterface;
use Drupal\space_booking\Enum\BookingStatus;
use Drupal\space_booking\Enum\BookingType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Importing a Skedda booking export.
 *
 * The fixture is written by hand against Skedda's documented column list, so
 * these tests prove the pipeline rather than the file: when a real export
 * turns up, the fixture is what needs correcting, not the plugins.
 */
#[Group('space_booking')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
final class SkeddaImportTest extends MigrateTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'options', 'datetime', 'text', 'filter',
    'taxonomy', 'file', 'migrate', 'migrate_source_csv', 'space_booking',
    'space_booking_skedda',
  ];

  /**
   * The private file directory the fixture is copied into.
   */
  private string $dir;

  /**
   * {@inheritdoc}
   *
   * A real filesystem rather than the virtual one, because League\Csv reads
   * the export with fopen(), and a real private:// directory, because that is
   * where an export full of names, emails and telephone numbers belongs.
   */
  protected function setUpFilesystem(): void {
    $this->dir = $this->siteDirectory . '/private';
    mkdir($this->siteDirectory, 0775, TRUE);
    mkdir($this->siteDirectory . '/files', 0775, TRUE);
    mkdir($this->dir, 0775, TRUE);
    mkdir($this->siteDirectory . '/files/config/sync', 0775, TRUE);

    $this->setSetting('file_public_path', $this->siteDirectory . '/files');
    $this->setSetting('file_private_path', $this->dir);
    $this->setSetting('config_sync_directory', $this->siteDirectory . '/files/config/sync');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['space_booking', 'space_booking_skedda']);
    $this->installEntitySchema('space_booking_space');
    $this->installEntitySchema('space_booking_booking');

    $this->config('system.date')->set('timezone.default', 'Europe/London')->save();
    $this->config('space_booking.settings')
      // The venue's own history is not held to the rules written for members
      // booking next week, so open the window wide enough to accept it.
      ->set('max_advance_days', 0)
      ->set('min_duration_minutes', 0)
      ->save();
    $this->config('space_booking_skedda.settings')
      ->set('timezone', 'Europe/London')
      ->save();

    // The shipped migrations read private://skedda-export.csv and are run here
    // exactly as shipped: a path overridden per instance would not reach the
    // copy of the spaces migration that migration_lookup builds for itself.
    copy(__DIR__ . '/../../fixtures/skedda-export-sample.csv', $this->dir . '/skedda-export.csv');
  }

  /**
   * {@inheritdoc}
   */

  /**
   * Run one of the shipped migrations against the fixture.
   *
   * @param string $id
   *   The migration plugin ID.
   */
  private function runMigration(string $id): void {
    $this->executeMigration($id);
  }

  /**
   * Import the rooms, then the bookings.
   */
  private function importAll(): void {
    $this->runMigration('space_booking_skedda_spaces');
    $this->runMigration('space_booking_skedda_bookings');
  }

  /**
   * The imported spaces.
   *
   * @return array<int, \Drupal\space_booking\SpaceInterface>
   *   Spaces keyed by ID.
   */
  private function spaces(): array {
    return $this->container->get('entity_type.manager')->getStorage('space_booking_space')->loadMultiple();
  }

  /**
   * The imported bookings.
   *
   * @return array<int, \Drupal\space_booking\BookingInterface>
   *   Bookings keyed by ID.
   */
  private function bookings(): array {
    return $this->container->get('entity_type.manager')->getStorage('space_booking_booking')->loadMultiple();
  }

  /**
   * Every room named in the export becomes a space, once.
   */
  public function testRoomsAreCreatedOnce(): void {
    $this->runMigration('space_booking_skedda_spaces');

    $names = array_map(static fn ($space): string => (string) $space->label(), $this->spaces());
    sort($names);

    $this->assertSame(['Garden Room', 'Main Hall', 'Studio'], $names,
      'Three rooms, although the export names them nine times between them.');
  }

  /**
   * An imported room is bookable and needs no approval.
   */
  public function testImportedRoomsAreUsable(): void {
    $this->runMigration('space_booking_skedda_spaces');

    foreach ($this->spaces() as $space) {
      $this->assertTrue($space->isBookable());
      $this->assertFalse($space->requiresApproval(), 'Importing history must not route it into an approval queue.');
    }
  }

  /**
   * The bookings arrive, and the clashing row does not.
   */
  public function testBookingsImport(): void {
    $this->importAll();

    $titles = array_map(static fn (BookingInterface $b): string => (string) $b->label(), $this->bookings());
    sort($titles);

    $this->assertSame(
      ['Art Group', 'Choir', 'Floor resealing', 'Lunch Club', 'Parent and Baby', 'Yoga'],
      $titles,
    );
    $this->assertNotContains('Overlaps the choir', $titles,
      'A row that would double-book the hall is refused, not written.');
  }

  /**
   * The refusal is reported rather than swallowed.
   */
  public function testTheRefusalIsExplained(): void {
    $this->importAll();

    // A row refused by entity validation is recorded against the migration's
    // own map, which is what `drush migrate:messages` reads, rather than the
    // run's output.
    $map = $this->container->get('plugin.manager.migration')
      ->createInstance('space_booking_skedda_bookings')
      ->getIdMap();

    $messages = [];
    foreach ($map->getMessages() as $message) {
      $messages[] = (string) $message->message;
    }

    $this->assertStringContainsString('already booked', implode("\n", $messages),
      'The import says why it skipped the row, in the same words the form uses.');
  }

  /**
   * Local wall-clock time is read in the venue's timezone.
   */
  public function testTimesAreRead(): void {
    $this->importAll();

    $yoga = $this->bookingTitled('Yoga');
    $expected = new \DateTimeImmutable('2027-01-04 18:00:00', new \DateTimeZone('Europe/London'));

    $this->assertSame($expected->getTimestamp(), $yoga->getStart());
    $this->assertSame(90 * 60, $yoga->getDuration());
  }

  /**
   * One booking, two rooms.
   */
  public function testOneBookingCanHoldSeveralRooms(): void {
    $this->importAll();

    $names = array_map(
      static fn ($space): string => (string) $space->label(),
      $this->bookingTitled('Lunch Club')->getSpaces(),
    );
    sort($names);

    $this->assertSame(['Garden Room', 'Main Hall'], $names);
  }

  /**
   * An unrecognised type falls back rather than failing the row.
   */
  public function testUnknownTypesFallBack(): void {
    $this->importAll();

    $this->assertSame(BookingType::Internal, $this->bookingTitled('Floor resealing')->getType(),
      '"Maintenance" means nothing to this module, so the booking is kept as internal use.');
    $this->assertSame(BookingStatus::Confirmed, $this->bookingTitled('Choir')->getStatus());
  }

  /**
   * Holder details are kept as a note, not turned into accounts.
   */
  public function testHoldersBecomeNotes(): void {
    $this->importAll();

    $yoga = $this->bookingTitled('Yoga');
    $this->assertNull($yoga->getHolder(), 'Importing must not create user accounts behind the venue\'s back.');
    $this->assertStringContainsString('rachel.doyle@example.com', (string) $yoga->get('notes')->value);
  }

  /**
   * Running it twice does not double the bookings.
   */
  public function testReimportingIsSafe(): void {
    $this->importAll();
    $first = count($this->bookings());

    $this->importAll();

    $this->assertSame($first, count($this->bookings()),
      'Room, start and end identify a booking, so a second run updates rather than duplicates.');
  }

  /**
   * Load an imported booking by its title.
   */
  private function bookingTitled(string $title): BookingInterface {
    foreach ($this->bookings() as $booking) {
      if ((string) $booking->label() === $title) {
        return $booking;
      }
    }
    $this->fail(sprintf('No booking titled "%s" was imported.', $title));
  }

}
