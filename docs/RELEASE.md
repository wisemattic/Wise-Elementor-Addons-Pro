# Releasing Wise Addons Pro

- **Repository:** `git@github.com:wisemattic/Wise-Elementor-Addons-Pro.git`. On this Mac, use the SSH host alias `github-wisemattic`.
- **Slug:** `wise-addons-for-elementor-pro`. This is also the plugin folder, the main file name and the update server product key.
- **Sales and licenses:** Lemon Squeezy, Wisemattic store `79937`.
- **Updates:** the `wisemattic-updates` mu-plugin on wisemattic.com, the same server wiseCampaign Pro uses.

## How updates work

The update server is `wp-content/mu-plugins/wisemattic-updates.php` on wisemattic.com.
1. About every 15 minutes it looks for the newest published `.zip` in the Lemon Squeezy product's files.
2. It downloads that zip once and reads the version, requirements and changelog from it. The changelog comes from `readme.txt` → `== Changelog ==`.
3. It serves the zip at `https://wisemattic.com/wp-json/wisemattic-updates/v1/wise-addons-for-elementor-pro/download`, but only to licenses of the product.

The plugin side is `includes/class-updater.php`. It reads `/info` and adds the update to WordPress' list. Only sites with an active license get a download link.

## One-time setup

1. In Lemon Squeezy, create the product **Wise Addons for Elementor Pro**:
   - Add the variants you plan to sell, for example 1 site, 5 sites and 100 sites, yearly or lifetime.
   - Turn on **license keys** for each variant and set its activation limit.
2. Note the **product ID(s)**. Then make two changes.
3. Plugin side: add the IDs to `License::product_ids()`. Replace the filter default `[]` with the IDs. Until you do this, the plugin accepts any product in the store whose name contains "Wise Addons".
4. Server side: add an entry to `products()` in `mu-plugins/wisemattic-updates.php` on wisemattic.com:

   ```php
   'wise-addons-for-elementor-pro' => [
       'name'        => 'Wise Addons for Elementor Pro',
       'store_id'    => 79937,
       'product_ids' => [ /* Lemon Squeezy product ID(s) */ ],
       'homepage'    => 'https://wiseaddons.com/pro/',
       'description' => '<p>Pro widgets and features for <a href="https://wordpress.org/plugins/wise-addons-for-elementor/">Wise Addons for Elementor</a>.</p><p>Updates need an active license.</p>',
       'icons'       => [ '1x' => '…', '2x' => '…' ],
       'banners'     => [ 'low' => '…', 'high' => '…' ],
   ],
   ```

   Back up the mu-plugin before you edit it. It also serves wiseCampaign Pro updates.

## Each release

1. Work on `develop`. Create `release/<version>` when the release is ready.
2. Bump the version in three places:
   - the plugin header `Version`
   - `WISEADDONS_PRO_VERSION`
   - the readme `Stable tag`
3. Add a `= <version> =` changelog entry to `readme.txt`. The update server shows the last 6 entries in "View details".
4. If the release needs a newer free plugin, raise `WISEADDONS_PRO_NEEDS_FREE` and `WISEADDONS_PRO_NEEDS_ADDON_API`.
5. Run `bash bin/build-zip.sh`. It writes `dist/wise-addons-for-elementor-pro-<version>.zip` at the root of the Local site.
6. Upload the zip to the Lemon Squeezy product's **Files**, on every variant. Within about 15 minutes, sites with an active license see the update.
7. Tag `v<version>`, then merge into `main` and back into `develop`.

## Testing the updater

- `wp transient delete wiseaddons_pro_release --network`, then load Dashboard → Updates.
- `curl https://wisemattic.com/wp-json/wisemattic-updates/v1/wise-addons-for-elementor-pro/info` shows what the server will offer.
- To point a test site at a staging server, use the filter `wiseaddons_pro/updates/api`.
