# Multi-domain login

If your site is hosted behind several domains (for example each lanuage has a
translated domain), this module allows
you to login on all of these domains with one login form. Suppose you have a
site which is hosted with the following
domains
- www.english.com
- www.nederlands.nl
- www.francais.fr

If you login on www.english.com/user, you are only logged in on that domain
(you cookies are set for this domain). When you swicth to translate content
you will need to login on the other domain again.

This module does a redirect to each domain and logs you in behind the scenes.
The mechanism is the same as for one
time password reset, using a one time login url.

For a full description of the module, visit the
[project page](https://www.drupal.org/project/multi_domain_login).

Submit bug reports and feature suggestions, or track changes in the
[issue queue](https://www.drupal.org/project/issues/multi_domain_login).


## Requirements

No special requirements, depends on core user and system module.


## Installation

Install as you would normally install a contributed Drupal module. For further
information, see
[Installing Drupal Modules](https://www.drupal.org/docs/extending-drupal/installing-drupal-modules).


## Configuration

**General usage**

Configuration is found under the standard "`User Interface`" configuration item.
`/admin/config/multi_domain_login`


## Hooks

Your custom module can alter the list of domains using
hook_multi_domain_login_domains_alter(&$domains).


## Maintainers

- Mschudders - [Mschudders](https://www.drupal.org/u/mschudders)
- Kris Booghmans - [kriboogh](https://www.drupal.org/u/kriboogh)

**This project has been sponsored by:**
- [Calibrate](https://www.calibrate.be)
