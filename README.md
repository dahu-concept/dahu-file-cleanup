# Dahu File Cleanup

A retention policy for the generated files that pile up on a WordPress server:
named rules with their own folders, extensions and maximum age, a dry run that
shows every file before anything is deleted, a daily scheduled run, a ceiling
per run, and a log.

MIT licensed. Requires WordPress 6.0 and PHP 7.4. No WooCommerce, no external
service.

## What it is for

Invoice PDFs, shipping labels, quote PDFs, backup archives, export files, debug
logs. Plugins generate them, nobody deletes them, and a year later the disk is
full of documents that were useful for a week. Deleting them by hand means
opening an SFTP client and judging a thousand filenames; deleting them with a
shell one-liner means being certain, at midnight, about a `find -delete` you
wrote once.

This plugin is the middle ground: you write the policy once, in words, and it
runs every night.

## A rule

Five answers:

| | |
|---|---|
| **Name** | What it is called in the log |
| **Folders** | One per line, relative to the WordPress root |
| **Extensions** | `pdf, zip, log` — the whole of the selection |
| **Older than** | In days, measured on the file modification time |
| **Subfolders** | Whether to descend |

Nothing in a rule is a pattern. There is no way to write "everything in this
folder", because the extension list is the only selector and it cannot be
empty: a rule that lists no extension matches nothing rather than matching all.

A rule with no retention, no extension, no folder or no name **cannot be
switched on**. The screen says which answer is missing, and an enabled flag
submitted on an incomplete rule is refused rather than honoured — a rule
cannot promise the runner something it has no way to do.

## What it refuses to touch

The folders:

- Anything the resolved path puts outside your site. Paths are judged on
  `realpath()`, never on what was typed, so a `..` or a symbolic link cannot
  widen a rule.
- The WordPress root, `wp-content`, and the uploads root. Target a subfolder.
- Anything inside `wp-admin`, `wp-includes`, the plugin, must-use plugin or
  theme folders, `wp-content/languages`, or `wp-content/upgrade`. The ban
  covers the whole subtree, not just its top: a plugin that caches inside its
  own folder is a real thing, and cleaning it is still not this tool's job.
- Wildcards, in any position.

The files:

- **Symbolic links are never followed and never deleted**, in either scan mode.
  A link is left exactly where it is, and what it points at is not touched.
- Code, stylesheet, markup, server-configuration and translation extensions —
  `php`, `phtml`, `js`, `css`, `html`, `htaccess`, `ini`, `env`, `mo`, `po` and
  the rest — cannot be put on a rule. A PHP file in an uploads folder is either
  a mistake worth seeing or a shell worth keeping as evidence, and in neither
  case something to delete on a schedule.
- Archives and dumps are deliberately *not* on that list: `zip`, `sql`, `gz`
  and `wpstg` are what a backup plugin leaves behind, and rotating those is
  most of the point.

Every deletion goes through one function, which re-resolves the file, checks it
is a regular file, checks it is under the validated folder and checks its
extension against the rule — immediately before removing it, not when it was
selected. The filesystem can change between a scan and a deletion, and a check
performed on the old one proves nothing about the new one.

## The dry run

The dry run lists what every rule would delete right now, including rules that
are switched off, and it reports, per folder:

| | |
|---|---|
| Folder as typed | and the **absolute path it resolved to** |
| Files | regular files found, and how many symbolic links were skipped |
| Right extension | how many of them the rule's extension list selects |
| Oldest / Newest | the modification times actually encountered |
| Selected | what would be deleted |

Those columns exist because an empty dry run has at least four causes — the
folder is not where you think it is, it holds nothing with that extension,
everything in it is newer than the retention, or it cannot be read — and from
the outside they look identical. Guessing between them over somebody's
invoices is not acceptable.

There is one derived warning. When plenty of files carry a selected extension
and **not one** is old enough, the screen says so: that is the signature of
modification times rewritten wholesale by a clone or a restore, after which no
retention can find anything old however old the files really are. It needs a
volume of files before it fires, because a small folder that is legitimately
all recent has the same shape and nothing wrong with it.

## The ceiling

A run stops a rule once it has deleted a set number of files — 2000 by default
— notes it in the log, and leaves the rest for the next run.

It is there for the day a folder is widened by one line and suddenly matches
forty thousand files. A run that stops and reports is recoverable; a run that
does not, is not. It also keeps a nightly walk of a large tree inside the time
and memory a shared host allows.

## Running it

The scheduled run is daily, at 03:00 site time. It is also rescheduled on any
admin page load if it has gone missing, which an aggressive optimisation plugin
does more often than you would hope: a retention policy that silently stopped
running is worse than one that never ran.

Many hosts set `DISABLE_WP_CRON` and call `wp-cron.php` from a system cron, and
many set neither, in which case "daily" means "whenever a visitor next turns
up". The screen says so when it detects it, and there is a command for it:

```bash
wp file-cleanup rules              # what is configured, and what would run
wp file-cleanup preview            # per-folder diagnostics, deletes nothing
wp file-cleanup preview --files    # with the file list
wp file-cleanup run                # a dry run, because that is the safe default
wp file-cleanup run --execute      # the real thing
```

Two runs cannot overlap: a lock is taken for the duration.

## Ready-made rules

The default folders of plugins known to leave files behind — PDF Invoices &
Packing Slips, YITH Request a Quote, WP Staging — can be added as a rule in one
click. They arrive **switched off**, with the folder editable, and the two that
hold business records arrive with **no retention at all**, so they cannot run
until somebody has decided how long an invoice is kept.

Add your own:

```php
add_filter( 'dahu_file_cleanup_presets', function ( $presets ) {
	$presets['my_exports'] = array(
		'label'      => 'Nightly exports',
		'paths'      => 'wp-content/uploads/my-exports',
		'extensions' => array( 'csv', 'zip' ),
		'days'       => 14,
		'note'       => 'Written by the nightly export task.',
	);

	return $presets;
} );
```

## Hooks

```php
// Keep one file. Called for every selected file during a real run — never
// during the dry run, so a filter that keeps files makes the preview
// optimistic.
add_filter( 'dahu_file_cleanup_delete_file', function ( $delete, $file, $rule_key, $rule ) {
	return $delete;
}, 10, 4 );

// After a run, scheduled or manual.
add_action( 'dahu_file_cleanup_after_run', function ( $summary, $mode ) {}, 10, 2 );
```

## The log

One row per rule per run, newest first, two hundred rows kept, in an option
that is **not** autoloaded. Counts, not filenames: a list of every deleted file
would be the most useful log imaginable and also an option that grows without
bound on a site deleting thousands of files a night, read on every request.
Per-file accounting is what `dahu_file_cleanup_delete_file` is for.

## Uninstalling

**No file on disk is ever deleted by the uninstall routine.** This is a plugin
whose job is deleting files, and a last pass over the rules on the way out —
unattended, at the moment somebody decided they no longer wanted it — would be
the most destructive thing in it.

The rules and the log go only if you asked for that on the screen, and the
setting is off by default.

## External services

This plugin does not contact any external service.

## License

MIT — see [LICENSE](LICENSE).
