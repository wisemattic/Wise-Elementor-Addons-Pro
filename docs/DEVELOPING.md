# Developing Wise Addons Pro

Wise Addons Pro is an add-on for the free plugin, Wise Addons for Elementor. It has no widget engine of its own. It adds entries to the free plugin's widget and feature maps, and the free plugin does the rest: dashboard switches, Elementor registration, and loading each item's CSS and JS only where the item is used.

Read the free plugin's `docs/DEVELOPING.md` first. Everything there applies here. This file lists only what is different.

## Requirements

- **Free plugin:** Wise Addons for Elementor with `WISEADDONS_ADDON_API >= 1`. Version 1.1.0 is the first with it; until it ships, use its `develop` branch.
- **License:** an active or expired Pro license (see `includes/class-license.php`).

## How Pro loads

1. `plugins_loaded` at priority 5 (`Plugin::boot`), before the free plugin boots at 10. It checks three things:
   - the free plugin is active
   - the add-on API version
   - the license
2. `Pro_Map::init()` filters `wiseaddons/widgets_map` and `wiseaddons/features_map`. Each built item gets these keys:
   - `path` and `url`: its folder in this plugin
   - `pro => true`: shows the "Pro" badge in the dashboard
   - `class`: a fully qualified class name
3. An item is added only when its main file exists (`widgets/<slug>/widget.php` or `features/<slug>/feature.php`) and, for WooCommerce items, WooCommerce is active.

## Adding a Pro widget

1. Add an entry to `Pro_Map::widgets()`.
2. Create `widgets/<slug>/widget.php`:
   - namespace `WiseAddonsPro\Widgets`
   - the class extends `\WiseAddons\Widget_Base`
   - same structure as a free widget
3. Optionally add `widgets/<slug>/style.css` and `script.js`. The free plugin registers them under its usual handles from the entry's `url`.
4. Use the text domain `wise-addons-for-elementor-pro`.

## Adding a Pro feature

1. Add an entry to `Pro_Map::features()`.
2. Create `features/<slug>/feature.php`:
   - namespace `WiseAddonsPro\Features`
   - the class extends `\WiseAddons\Feature_Base`
   - same structure as a free feature
3. Feature scripts stay standalone vanilla JS with no dependencies, as in the free plugin.

## Rules

- **Prefixes:**
  - namespace `WiseAddonsPro`
  - constants `WISEADDONS_PRO_`
  - options `wiseaddons_pro_`
  - hooks `wiseaddons_pro/`
  - CSS classes for Pro widgets `wea-` plus the widget slug, the same convention as free
- **Original work only:** do not copy code, CSS, markup or assets from other addon plugins.
- **No third-party CDNs:** bundle what you need. Prefer small vanilla JS over libraries.
- **Free plugin changes:** if Pro needs something new from the free plugin, add a hook there on its `develop` branch, bump `WISEADDONS_ADDON_API`, and raise `WISEADDONS_PRO_NEEDS_ADDON_API` here.

## Local development without a license

Pro features need a license. On the local site only, add this to an mu-plugin (never ship it):

```php
add_filter( 'wiseaddons_pro/license/features_enabled', '__return_true' );
```

## Checks before you commit

The same checks as the free plugin:
- `php -l` on every changed file
- render each widget with default and main settings
- check the editor and frontend at desktop, tablet and mobile widths
- confirm no Pro asset loads on pages that don't use the item
