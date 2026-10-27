# Space Booking: CSV import, worked example

Import a venue's existing diary from a CSV export into
[Space Booking](https://github.com/vectorless/space-booking).

The working example here reads a **Skedda** booking export, but almost nothing
in it is Skedda-specific. If your old system can produce a CSV with a start, an
end and a room name per row, this repository is the shortest path to getting it
into Drupal — copy it, change the column names, and you are most of the way
there. [Adapting it to another system](#adapting-it-to-another-system) is the
part worth reading.

> This module is also shipped inside the main project as
> `modules/space_booking_skedda`. This repository is the standalone copy, kept
> for people who want a template rather than a dependency.

## Why migrations instead of an import script

It would be quicker to write a loop that reads a CSV and calls `->save()`.
Using core's [Migrate API](https://www.drupal.org/docs/drupal-apis/migrate-api)
instead buys four things that matter when the data is a venue's diary:

| | |
|---|---|
| **Rollback** | `drush migrate:rollback` removes exactly what the import created, and nothing else |
| **Re-runs** | room + start + end identifies a booking, so running it twice updates rather than duplicates |
| **A per-row log** | every refused row is recorded with the reason, readable with `drush migrate:messages` |
| **Customisation without forking** | the mapping is YAML; change it by editing config, not by patching PHP |

It also means the import obeys the booking rules. The destination is declared
`validate: true`, so each row is checked by the same entity constraints as the
booking form:

```
Processed 1 item  (1 created, 0 failed) - space_booking_skedda_spaces
Processed 7 items (6 created, 1 failed) - space_booking_skedda_bookings
```

```
drush migrate:messages space_booking_skedda_bookings

Main Hall is already booked between 5 Jan 2027, 19:00 and 5 Jan 2027, 20:30.
```

An import cannot create a double booking that the user interface would have
refused. The bad row is reported and skipped; the rest still import.

## Installing

```bash
composer require drupal/migrate_source_csv drupal/migrate_tools
drush en space_booking_skedda migrate_tools
```

`migrate` is in core. `migrate_source_csv` reads the file. `migrate_tools`
provides the drush commands — the migrations themselves do not depend on it.

Space Booking must be installed first; this module is useless without it.

## Running it

Put the export where the migrations expect it, then:

```bash
drush migrate:import space_booking_skedda_spaces,space_booking_skedda_bookings
drush migrate:messages space_booking_skedda_bookings
drush migrate:status
drush migrate:rollback space_booking_skedda_bookings,space_booking_skedda_spaces
```

Default path: **`private://skedda-export.csv`**. Use a private directory — a
booking export contains names, email addresses and telephone numbers, and
`sites/default/files` is served to the public.

**Configuration → Content → Space booking → Skedda import** sets the path,
the delimiters, the timezone, the date formats and the fallback booking type,
so a site builder can adjust an import without editing YAML.

## What it does with the data

### Rooms

A booking export has no list of rooms — only bookings that name them. The
first migration reads the booking file and yields each distinct room once.

**A room whose name already exists is left alone.** It keeps the capacity,
tags and overlap mapping somebody set up by hand, and the imported bookings
join the room that is already on the schedule rather than creating a second
column with the same name.

Imported rooms arrive with a name and nothing else — capacity, type, approval
and physical overlaps are things only the venue knows.

### Bookings

| Source column | Becomes |
|---|---|
| `Scheduled start`, `End` | start and end |
| `Spaces`, `Assigned space` | the rooms, matched by name |
| `Title` | title |
| `Created` | created |
| `Type` | booking type, through a mapping you control |
| `Holder *` | the notes field |
| `Duration (minutes)`, `Spaces count` | ignored — both derivable |
| `Price`, `Payment Status`, `Gateway charge reference` | dropped; Space Booking has no payments |
| `Seen on-site` | dropped; no check-in |

Two decisions are deliberate, and both are easy to reverse in your own copy:

- **Holders become notes, not user accounts.** Creating Drupal users for a
  venue's whole membership is a decision for the venue, not a side effect of
  an import. To do it anyway, add `migrate_plus` and an
  `entity_lookup`/`entity_generate` step on the `holder` property.
- **Everything imports as confirmed.** A diary that already exists has already
  been agreed; routing years of history into an approval queue helps nobody.

## Adapting it to another system

Copy `migrations/*.yml` into your own module's `migrations/` directory, give
them new IDs, and edit the column names. That is the whole workflow — these are
ordinary migration plugins with nothing clever in them.

The four process plugins exist because CSV exports are loosely specified, and
each is useful well beyond Skedda:

| Plugin | Does |
|---|---|
| `skedda_datetime` | reads a date in a configurable list of formats, in a configurable timezone, and fails loudly with the raw value when none match |
| `skedda_space_list` | splits one cell into several room names on a configurable delimiter |
| `skedda_space_lookup` | matches a room name to an existing space, ignoring case |
| `skedda_booking_type` | maps your system's own type names onto `user`, `internal` and `unavailable` |

A minimal migration for a different system's export:

```yaml
id: my_venue_bookings
label: 'Bookings from the old system'

source:
  plugin: csv
  path: 'private://old-system.csv'
  ids: ['Room', 'Start']

process:
  start:
    plugin: skedda_datetime
    source: 'Start'
    timezone: 'Europe/London'
    formats: ['d/m/Y H:i']
  end:
    plugin: skedda_datetime
    source: 'Finish'
  title: 'Description'
  spaces:
    - plugin: skedda_space_list
      source: 'Room'
      delimiter: '|'
    - plugin: skedda_space_lookup
  booking_type:
    plugin: skedda_booking_type
    source: 'Category'
    map:
      'Private hire': user
      'Closed': unavailable
    default: internal
  status:
    plugin: default_value
    default_value: confirmed

destination:
  plugin: 'entity:space_booking_booking'
  validate: true
```

Keep `validate: true` unless you have a reason not to. Without it an import
can write clashes straight past the rules.

### If your rooms come from a separate file

Drop `skedda_space_names` and use the plain `csv` source with
`destination: 'entity:space_booking_space'`. Then either keep
`skedda_space_lookup` (matching by name) or switch to `migration_lookup`
against your rooms migration.

## Testing

```bash
SIMPLETEST_DB=mysql://user:pass@db/db \
  phpunit web/modules/contrib/space_booking_skedda/tests
```

Nine kernel tests run both migrations against
`tests/fixtures/skedda-export-sample.csv`, which is **written by hand from
Skedda's documented column list**, not captured from a real account. They
prove the pipeline: rooms deduplicated, times read in the venue's timezone, a
booking holding two rooms, unknown types falling back, holders becoming notes,
re-running without duplicating, and a deliberate clash being refused.

When you have a real export, correct the fixture first — it is the thing most
likely to be wrong.

## Three things to check against a real export

Skedda documents its columns but not these, and each can distort an import
quietly. All three are configurable, and each fails loudly rather than
guessing:

1. **The date format.** It follows the account's locale. The plugin tries nine
   common formats and, when none round-trips exactly, fails the row with the
   raw value in the message. That message is the fastest way to learn the real
   format; then set it explicitly.
2. **The separator between rooms** inside one `Spaces` cell. The default
   assumes a comma inside a quoted field.
3. **Recurring bookings** — one row per occurrence, or one row per series? If
   it is per series, the individual occurrences are not in the file at all and
   no importer can invent them. Export a date-filtered view instead.

One more, worth saying plainly: **the export has no status column**, so a
cancelled booking may be sitting in the file looking exactly like a live one.
Check a sample against the source system before trusting the whole import.

## Licence

GPL-2.0-or-later, same as Drupal.
