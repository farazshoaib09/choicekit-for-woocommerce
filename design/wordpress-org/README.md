# ChoiceKit WordPress.org artwork

The editable artwork is in `icon-source.svg` and `banner-source.svg`.

The ready-to-upload files are in the repository's `.wordpress-org` directory:

- `icon.svg`
- `icon-128x128.png`
- `icon-256x256.png`
- `banner-772x250.png`
- `banner-1544x500.png`

WordPress.org expects these in the SVN repository's top-level `assets` directory, beside `trunk` and `tags`. The existing 10up release action uses `.wordpress-org` as its default asset source. Artwork changes can be deployed without changing the plugin's version by using an asset-only SVN update.
