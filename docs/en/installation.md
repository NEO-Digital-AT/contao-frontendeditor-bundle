---
title: Installation
summary: Requirements, installing with the Contao Manager or Composer, updating and removing the NEO Frontend Editor.
audience: operations
language: en
updated: 2026-11-12
---

# Installation

## Requirements

| What | Needed for |
| --- | --- |
| Contao 5.3 to 6.0 (tested with 5.3, 5.4, 5.5, 5.6, 5.7 and 6.0) | everything |
| PHP 8.2 or later (Contao 5.7: PHP 8.3, Contao 6: PHP 8.4 or later) | everything |
| `neo-digital/contao-basic-bundle` | everything; installed automatically. It holds the page *Access keys and licences* shared by the extensions of the family. |
| `scssphp/scssphp` | page style (Pro); installed automatically |
| `terminal42/contao-changelanguage` | language versions (Plus); install it yourself if the website has several languages |
| One translation service: an Azure Translator resource (key, region), a DeepL API account (Free or Pro) or the AI connection | translation proposals (Plus) |
| An AI connection (route and key) on *Access keys and licences* | writing aid (Pro) |
| Contao's Comments bundle | editing comments on the page (only if the website uses comments) |

The editor runs in Contao's front end preview. Nothing else has to be set up on
the server.

## Installing with the Contao Manager

1. Open the Contao Manager.
2. Search for `neo-digital/contao-frontendeditor-bundle`.
3. Click **Add** and then **Apply changes**.
4. When the Contao Manager asks, run the database update (**Update database**)
   and apply all changes it proposes.

## Installing with Composer

```bash
composer require neo-digital/contao-frontendeditor-bundle
php vendor/bin/contao-console contao:migrate
```

`contao:migrate` creates the tables of the extension. Composer also copies the
public files (script, styles, the accessibility checker) to `public/bundles/`;
if that did not happen, run:

```bash
php vendor/bin/contao-console assets:install
```

## After the installation

The editor is not visible to anyone yet. Continue with [Setup](setup.md): a
user group needs the switch **Front end editing allowed** first.

## Updating

1. Update the package with the Contao Manager or with
   `composer update neo-digital/contao-frontendeditor-bundle`.
2. Run the database update (`contao:migrate`). Updates only add tables and
   fields; nothing that was set is removed.
3. Read `CHANGELOG.md` of the new version. When a version needs new
   permissions, the changelog says so.

## Removing

1. Remove the package with the Contao Manager or with
   `composer remove neo-digital/contao-frontendeditor-bundle`.
2. Run the database update. Contao then offers to delete the tables of the
   extension (links between language versions, focus keywords, page styles,
   settings). Delete them only if you do not plan to install the extension
   again.

Content that was changed with the editor stays, because it was saved in
Contao's own fields. Page styles (Pro) are no longer delivered once the
extension is removed.
