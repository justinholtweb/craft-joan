---
title: Installation
slug: installation
order: 10
summary: Requirements, install, and the first rebuild.
---

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later
- MySQL or PostgreSQL — both are supported

## Install

```sh
composer require justinholtweb/craft-joan
php craft plugin/install joan
```

Or find **Joan** in the Craft Plugin Store and install it from there.

## Nothing to set up

Joan installs no tables, writes nothing, and deletes nothing. There is no migration to worry
about on a large site and nothing to undo if you uninstall it — the plugin is a set of questions
asked of what is already in the database, cached for fifteen minutes and thrown away.

That is also why the whole plugin is free. It cannot damage anything, so there was no case for
charging you to find out whether it works on your site.

## The first rebuild

Open **Joan → Overview**. The first page load builds the inventory, which means:

- one aggregate pass over `elements_sites.content`,
- one over `relations`,
- one over `entries` for nested-element fields,
- and one read of every file under the scanned paths.

On a normal site that is a second or two. On a site with millions of content rows it is longer,
and it happens once — everything after it is served from the cache until a field or a field
layout changes.

If the first build is slower than you want to sit through, run it from the console instead:

```sh
php craft joan/fields --refresh
```

## Permissions

Joan adds two, under a **Joan** heading in **Settings → Users → User Groups**:

| Permission | Lets a user |
| --- | --- |
| **View the content model inventory** | Read every Joan screen |
| **Rebuild the inventory** | Press **Rebuild**, which rereads the content table and the codebase |

The second is nested under the first. Rebuilding is the only thing in the plugin that costs
anything, so it is worth keeping away from people who will press it out of curiosity on a site
where it takes a minute.

Joan's own settings screen is admin-only, and is not covered by either permission.

## Uninstalling

```sh
php craft plugin/uninstall joan
composer remove justinholtweb/craft-joan
```

Nothing is left behind. There are no tables to drop, and no content to migrate out, because
Joan never put any in.
