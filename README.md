[![Stable? Not Quite Yet](https://img.shields.io/badge/stable%3F-not%20quite%20yet-blue?style=for-the-badge)](https://packagist.org/packages/pubvana/themes)
[![License](https://img.shields.io/packagist/l/pubvana/themes?style=for-the-badge)](https://packagist.org/packages/pubvana/themes)
[![PHP Version](https://img.shields.io/packagist/php-v/pubvana/themes?style=for-the-badge)](https://packagist.org/packages/pubvana/themes)
[![Monthly Downloads](https://img.shields.io/packagist/dm/pubvana/themes?style=for-the-badge)](https://packagist.org/packages/pubvana/themes)
[![Total Downloads](https://img.shields.io/packagist/dt/pubvana/themes?style=for-the-badge)](https://packagist.org/packages/pubvana/themes)
[![GitHub Issues](https://img.shields.io/github/issues/Pubvana-CMS/themes?style=for-the-badge)](https://github.com/Pubvana-CMS/themes/issues)
[![Contributors](https://img.shields.io/github/contributors/Pubvana-CMS/themes?style=for-the-badge)](https://github.com/Pubvana-CMS/themes/graphs/contributors)
[![Latest Release](https://img.shields.io/github/v/release/Pubvana-CMS/themes?style=for-the-badge)](https://github.com/Pubvana-CMS/themes/releases)
[![Contributions Welcome](https://img.shields.io/badge/contributions-welcome-blue?style=for-the-badge)](https://github.com/Pubvana-CMS/themes/pulls)

# Pubvana Themes

**I noticed folks downloading some of these packages. I'm super grateful, Thank You!  I would like to let folks know until this notice disappears I'm doing a lot of breaking changes without worrying about them.  Once versions are up around 0.5.x things should settle down.**

Theme discovery, activation, options, and region/block management for Pubvana CMS.

## Related Docs

- Project docs index: [docs/README.md](../../../docs/README.md)
- Pubvana architecture: [docs/PUBVANA-ARCHITECTURE.md](../../../docs/PUBVANA-ARCHITECTURE.md)
- Package conventions: [docs/PLUGIN-ARCHITECTURE.md](../../../docs/PLUGIN-ARCHITECTURE.md)

## Features

- Discover themes from the filesystem
- Sync theme metadata from `pubvana.json`
- Activate and validate themes
- Persist theme options
- Publish theme assets
- Manage block placements across regions

## Requirements

- PHP ^8.1
- enlivenapp/flight-school ^0.2
- enlivenapp/flight-shield
- enlivenapp/migrations
- flightphp/active-record ^0.7
- pubvana/admin

## Installation

```bash
composer require pubvana/themes
```

Enable in `app/config/config.php`:

```php
'plugins' => [
    'pubvana/themes' => [
        'enabled'  => true,
        'priority' => 50,
    ],
],
```

## Flight School config

This package uses Flight School's return-array config format. `src/Config/Config.php` returns the package defaults as an array, and Flight School stores that array under `pubvana.themes` on `$app`.

That returned array currently includes `'routePrepend' => 'themes'`. This package currently ships admin routes only, so its screens remain under `/admin/themes...`.

## Service

Mapped as `$app->themes()`. Region/block rendering is handled through `$app->regions()`.

Common responsibilities include:

- discovering installed themes
- synchronizing theme records with the filesystem
- activating a selected theme
- reading and saving theme options
- rendering placed blocks into theme regions

## Admin

Core theme screens include:

- `/admin/themes`
- `/admin/themes/{id}/options`
- `/admin/themes/regions`

This package is also the home of region/block placement management.

## License

MIT
