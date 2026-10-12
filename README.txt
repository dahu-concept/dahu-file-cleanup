=== Dahu File Cleanup ===
Contributors: hugovialjaime
Tags: cleanup, retention, housekeeping, disk space, cron
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

A retention policy for generated files: named rules, a dry run that shows every file first, a daily run, a ceiling, and a log.

== Description ==

Invoice PDFs, shipping labels, quote PDFs, backup archives, export files, debug
logs. Plugins generate them, nobody deletes them, and a year later the disk is
full of documents that were useful for a week.

Dahu File Cleanup lets you write the policy once, in words, and runs it every
night.

= A rule =

Five answers: a name, the folders, the file extensions, how old a file has to
be, and whether to look in subfolders.

Nothing in a rule is a pattern. The extension list is the only selector and it
cannot be empty, so there is no way to say "everything in this folder" — a rule
that lists no extension matches nothing rather than matching all. A rule
missing any of its answers cannot be switched on, and the screen says which
one is missing.

= What it refuses to touch =

* Any path whose **resolved** location is outside your site. Paths are judged
  on realpath(), never on what was typed, so a `..` or a symbolic link cannot
  widen a rule.
* The WordPress root, wp-content, and the uploads root — target a subfolder.
* Anything inside wp-admin, wp-includes, the plugin, must-use plugin or theme
  folders, wp-content/languages or wp-content/upgrade. The whole subtree, not
  just its top.
* Wildcards, in any position.
* **Symbolic links are never followed and never deleted**, in either scan mode.
* Code, stylesheet, markup, server-configuration and translation extensions —
  php, phtml, js, css, html, htaccess, ini, env, mo, po and the rest — cannot
  be put on a rule at all. Archives and dumps deliberately can: zip, sql, gz
  and wpstg are what a backup plugin leaves behind.

Every deletion goes through one function, which re-resolves the file and
re-checks its type, its folder and its extension immediately before removing
it. A check performed when the file was selected proves nothing about the
filesystem a minute later.

= The dry run =

Lists what every rule would delete right now, switched on or not, and reports
per folder: the absolute path the folder resolved to, how many files are in it,
how many symbolic links were skipped, how many carry a selected extension, the
oldest and newest modification times found, and how many would be deleted.

Those columns exist because an empty dry run has at least four causes and from
the outside they look identical. There is also one derived warning: plenty of
files at the right extension and not one old enough is the signature of
modification times rewritten by a clone or a restore, after which no retention
can find anything old however old the files really are.

= The ceiling =

A run stops a rule after a set number of files — 2000 by default — notes it in
the log and leaves the rest for the next run. For the day a folder is widened
by one line and suddenly matches forty thousand files.

= WP-CLI =

    wp file-cleanup rules
    wp file-cleanup preview [--rule=<key>] [--files]
    wp file-cleanup run [--rule=<key>] [--execute]

`run` is a dry run without `--execute`. Useful on the many hosts where WP-Cron
either does not fire on its own or only fires when a visitor turns up.

= Ready-made rules =

The default folders of plugins known to leave files behind can be added in one
click. They arrive switched off, with the folder editable, and the two that
hold business records arrive with no retention at all.

= External services =

This plugin does not contact any external service.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it from the
   Plugins screen.
2. Activate it. **Nothing is deleted**: a fresh install has no rules, and the
   nightly event runs over an empty list.
3. Open Tools > File cleanup, write a rule or add a ready-made one, run the dry
   run, and only then switch the rule on.

== Frequently Asked Questions ==

= Will activating it delete anything? =

No. There are no rules on a fresh install, and a rule has to be completed and
switched on by hand. A plugin that deletes files has no business guessing which
ones.

= Can I tell it to delete everything older than a year in a folder? =

Not as such, and that is deliberate. You list the extensions, and only files
carrying one of them are selected. The nearest thing is to list every extension
actually in that folder, which the dry run shows you.

= Can it delete my theme's or a plugin's files? =

No. The plugin, must-use plugin and theme folders are refused outright, whole
subtree, and so are the extensions those files carry.

= It finds nothing, and I can see old files in the folder =

Run the dry run and read the diagnostic row for that folder. It prints the
absolute path the folder actually resolved to, the number of files in it, how
many carry an extension your rule lists, and the oldest modification time it
found. One of those four will be the answer, and usually it is the last: a
clone or a restore resets every modification time, after which nothing in the
folder is "old" as far as the filesystem is concerned.

= What happens to my files if I delete the plugin? =

Nothing at all. The uninstall routine never deletes a file on disk. The rules
and the log go only if you asked for that on the settings screen, and that
setting is off by default.

= Does it need WooCommerce? =

No. It knows nothing about orders or products — it deletes files from folders.

== Screenshots ==

1. The rules screen.
2. A dry run, with the per-folder diagnostics.

== Changelog ==

= 1.0.0 =
* First public release.

== Upgrade Notice ==

= 1.0.0 =
First public release.
