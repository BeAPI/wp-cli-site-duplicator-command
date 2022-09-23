<a href="https://beapi.fr">![Be API Github Banner](.github/banner-github.png)</a>

# WP-CLI Site Duplicator

For multisite only. Introduces a WP-CLI command for duplicating a site on a WordPress multisite network.

Install and activate as a normal plugin. Then in the command line, from your site's root directory, run `wp help`. You should see `duplicate` as a registered command, then you can continue.

## Requirements

* PHP >= 5.6

## Installation

```
wp plugin install https://github.com/BeAPI/wp-cli-site-duplicator-command/archive/refs/heads/master.zip --activate-network
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

## Who ?

Created by [Be API](https://beapi.fr), the French WordPress leader agency since 2009. Based in Paris, we are more than 30 people and always [hiring](https://beapi.workable.com) some fun and talented guys. So we will be pleased to work with you.

This plugin is only maintained, which means we do not guarantee some free support. Consider reporting an [issue](#issues--features-request--proposal) and be patient.

If you really like what we do or want to thank us for our quick work, feel free to [donate](https://www.paypal.me/BeAPI) as much as you want / can, even 1€ is a great gift for buying coffee :)
