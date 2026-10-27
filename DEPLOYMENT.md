# ChoiceKit release process

The WordPress.org plugin slug is `choicekit-for-woocommerce`. Git tags use plain
semantic versions such as `1.0.0`, matching the plugin header and `Stable tag` in
`readme.txt`. Do not prefix the Git tag with `v`.

1. Rotate any SVN password that has been shared outside a private password manager.
2. In the GitHub repository, set Actions secrets `SVN_USERNAME` and `SVN_PASSWORD`.
   Use the WordPress.org SVN account name and a newly generated SVN password. Never
   put the password in Git, a workflow file, command arguments, or logs.
3. Push reviewed source to `main` and wait for **Plugin checks** to pass.
   This runs PHP and JavaScript syntax checks plus the financial and cart integration
   suites against WordPress 7.1.3 and WooCommerce 10.9.1.
4. Create and push an annotated version tag, for example `1.0.0` for the initial
   release. The deploy workflow validates the version and packages only the plugin
   entry file, `readme.txt`, `includes/`, and runtime `assets/`.
5. Confirm that the deploy workflow passed, then inspect SVN `trunk/` and
   `tags/<version>/`. The top-level SVN `assets/` directory is reserved for
   directory banners, icons, and screenshots; it is separate from runtime CSS/JS.
6. If WordPress.org Release Confirmation is enabled, confirm the new version in
   its Release Management dashboard. Then verify the public listing and install ZIP.

Release deployment is triggered only by a Git version tag. A push to `main` runs
checks without publishing a WordPress.org version.
If a tag push is skipped or its workflow does not start, run **Deploy to
WordPress.org** manually from GitHub Actions with the existing semantic version
tag. The workflow checks out that tag and verifies its plugin and readme versions
before deployment. Do not move a published tag.

## Directory artwork

The `.wordpress-org/` folder contains WordPress.org banners and icons. Its files
use the exact names and sizes listed in the WordPress Plugin Handbook. Editable
SVG artwork is in `design/wordpress-org/`.

Pushing `.wordpress-org/` changes to `main` runs the asset-only deployment
workflow. It updates the top-level SVN `assets/` directory without creating a
plugin version or changing its install ZIP. The workflow can also be started
manually from GitHub Actions. It ignores code differences between `main` and
the published tag.
