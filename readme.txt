=== qTranslate Cleanup and WPML Import ===
Contributors: OnTheGoSystems
Donate link: http://wpml.org
Tags: qTranslate, wpml, multilingual, i18n, convertion, import, uninstall, cleanup
Requires at least: 3.0
Tested up to: 3.3.1
Stable tag: 0.1
License: GPLv2 or later

Allows a complete uninstall and cleanup of qTranslate meta-tags or importing translations into WPML

== Description ==

This plugin can either cleanup the qTranslate meta-HTML tags from your site and leave just one 'clean' language, or migrate all languages to WPML's format.

**Very important: This plugin will modify the entire content of your database. You must backup your database before attempting to use it.**

For complete documentation, please refer to the [qTranslate uninstall and WPML importer documentation](http://wpml.org/documentation/related-projects/qtranslate-importer/).

= qTranslate uninstall and cleanup mode =

This mode is intended if you just want to keep one language in your site and you want to clean up the language meta-tags that qTranslate added. For this mode, you don't need WPML.

Instructions:

1. Save all qTranslate settings
2. Go to the Plugins admin page and de-activate qTranslate
3. Install & activate QT Importer
4. Go to Options -> QT Importer, select language to keep and click Start. 

= Migrate all languages from qTranslate to WPML =

In this mode, the QT import plugin will convert the language information from qTranslate's language tags format to WPML's post-per-language format. For this to work, you must have WPML active in the site (but not necessarily configured).

Instructions:

1. Save all qTranslate settings
2. Go to the Plugins admin page and de-activate qTranslate
3. Have WPML activated, but not yet configured (just activated)
4. Install & activate QT Importer
5. Go to Options -> QT Importer and click Start
6. Add redirects from old URLs to new URLs

The import runs in small batches so it doesn't have timeout issues with large databases. You can run it on sites of any size.

During the import process, the plugin generates a set of URL redirect rules. These rules tell visitors and search engines that the URLs in your site have changed (from qTranslate's format to WPML's format). When the import completes, you'll be able to export these rules either as rewrite directives for your .htaccess file or as a PHP file to add to the theme.

You can skip the redirect rules, but then, incoming links to internal pages may lead to 404 pages.

The import tool converts posts, meta data and taxonomy. We tried to take every possible scenario in mind, but there's no alternative to manual testing. Please consider spending time reviewing the final result and possible doing some last touch-ups before relaunching the site with WPML.

== Frequently Asked Questions ==

= Which version of WPML can I use this import with? =

It's been tested on WPML 2.4.3 and above. Previous versions might work, but might have unpreditable behavior.

= Do I have to get WPML to use this? =

This plugin has two modes of operation. Without WPML, it will let you clean the qTranslate language codes from your content and keep just one language. With WPML, you'll be able to keep all languages.

== Installation ==

Upload the plugin to your blog, activate it.

== Changelog ==

= 0.1 =

* Initial release

== Upgrade Notice ==

= 0.9 =
* Initial release

