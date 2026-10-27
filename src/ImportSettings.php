<?php

declare(strict_types=1);

namespace Drupal\space_booking_skedda;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * The handful of things that differ between one export and the next.
 *
 * A migration's process plugins take their options from the migration YAML.
 * These are the fallbacks used when the YAML says nothing, so that the shipped
 * migrations work on a site where the only thing anybody configured was the
 * path to the file.
 */
final readonly class ImportSettings {

  public const string CONFIG_NAME = 'space_booking_skedda.settings';

  /**
   * Build the settings.
   *
   * @param string $path
   *   Stream wrapper path to the exported file.
   * @param string $delimiter
   *   Column delimiter in the file.
   * @param string $spaceDelimiter
   *   What separates space names inside a single cell.
   * @param list<string> $dateFormats
   *   Candidate date formats, tried in order.
   * @param string $timezone
   *   The timezone the exported times are written in.
   * @param string $defaultBookingType
   *   Booking type for rows whose type is empty or unrecognised.
   */
  public function __construct(
    public string $path,
    public string $delimiter,
    public string $spaceDelimiter,
    public array $dateFormats,
    public string $timezone,
    public string $defaultBookingType,
  ) {}

  /**
   * Read the settings.
   */
  public static function fromConfig(ConfigFactoryInterface $configFactory): self {
    $config = $configFactory->get(self::CONFIG_NAME);
    $formats = $config->get('date_formats');

    return new self(
      (string) ($config->get('path') ?? ''),
      (string) ($config->get('delimiter') ?: ','),
      (string) ($config->get('space_delimiter') ?: ','),
      is_array($formats) && $formats !== [] ? array_values(array_map(strval(...), $formats)) : self::defaultDateFormats(),
      (string) ($config->get('timezone') ?: date_default_timezone_get()),
      (string) ($config->get('default_booking_type') ?: 'internal'),
    );
  }

  /**
   * Formats seen in exports, most specific first.
   *
   * Skedda's own documentation does not state the format, and it follows the
   * account's locale, so the importer tries several rather than insisting on
   * one. Anything unparseable stops the row with the raw value in the message,
   * which is the quickest route to discovering the real format.
   *
   * @return list<string>
   *   Date format strings for DateTime::createFromFormat().
   */
  public static function defaultDateFormats(): array {
    return [
      'Y-m-d H:i:s',
      'Y-m-d H:i',
      'Y-m-d\TH:i:sP',
      'd/m/Y H:i',
      'd/m/Y g:i A',
      'm/d/Y H:i',
      'm/d/Y g:i A',
      'j M Y H:i',
      'j M Y g:i A',
    ];
  }

}
