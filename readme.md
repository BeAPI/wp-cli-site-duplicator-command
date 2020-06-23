# WP-CLI Site Duplicator

For multisite only. Introduces a WP-CLI command for duplicating a site on a WordPress multisite network.

Install and activate as a normal plugin. Then in the command line, from your site's root directory, run `wp help`. You should see `duplicate` as a registered command, then you can continue.

## Installation

```
wp plugin install https://github.com/beapi/site-duplicator/archive/master.zip --activate-network
```

## Usage

Duplicate your current site:

```
wp site duplicate <new-site-slug>
```

Duplicate a specific site on the network:

```
wp site duplicate <new-site-slug> --url=domain.tld/somesite
``` 

### Additional options

For a little extra output add `--verbose`