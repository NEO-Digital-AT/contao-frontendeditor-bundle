---
title: Several websites and languages
summary: How the editor keeps several websites and the language versions of a website apart, and how to set up user groups for them.
audience: operations
language: en
updated: 2026-11-14
---

# Several websites and languages

One Contao installation can hold several websites (each with its own root page
and domain) and websites in several languages (one root page per language). The
editor keeps them apart; Contao's permissions decide who may work where.

## What the editor keeps apart by itself

- **What is edited is always the page open in the preview.** Elements, page
  settings, page style, layout and templates belong to that page.
- **New page** (Plus): the page tree shows only the website and language of the
  open page. A new page cannot end up in another website or language.
- **Posts** (Plus): the view lists only archives whose reader page belongs to
  the same website (domain), and archives shown on the open page.
- **Languages** (Plus): only the language versions linked with the extension
  changelanguage are shown side by side. Websites with different domains are
  never linked to each other.
- **Focus keyword** (Pro): **Keyword also on "{page}"** only counts pages of
  the same website and language.
- **Licence** (Plus, Pro): the licence covers domains. Each website with its own
  domain must be included in the licence.

## How to set up the user groups

Give each website its own user group, so nothing can land in the wrong place:

1. Create a user group per website, for example "Editors – Website A".
2. **Pagemounts**: only the root page(s) of that website. For a website with
   several languages, all its language root pages if the group edits all
   languages; only one if it does not.
3. **Allowed archives**, **Allowed calendars**, **Allowed FAQ categories**:
   only those of that website.
4. **Allowed forms**: only those of that website (only needed for web designers).
5. Set the switches and permissions as described on
   [Permissions](permissions.md).
6. Add each user only to the groups of the websites they work on.

A user then sees, in the editor as in the back end, only pages and archives of
their websites. In the preview of a page outside their pagemounts the editor
offers nothing to edit.

## Several languages of one website

- Link the pages of the languages with changelanguage: in the page settings of
  a language version, set the page of the main language (fallback language).
  Articles can be linked the same way.
- Posts (news, events, FAQ and posts of other extensions with the same field)
  are linked with changelanguage, too: in the archive of a language version,
  set the archive of the main language (**Master archive**); then each
  post of that archive can name its post of the main language (**Master
  article**). The reader
  pages of the archives are linked like any page. Only then does the editor
  compare and translate the elements of a post.
- The main language is the language of the root page marked as **Language
  fallback**.
- Elements of the language versions are linked by the editor itself (view
  **Languages** → **Link**, **Take over element** or, for an element written in
  a version first, **Take over into (main language)**). Those links appear in
  the back-end list **Translations**.
- A new page can get its language versions in the same step: after saving it,
  **Create language versions** creates the page in the other languages, at the
  same place in each page tree, with its own name and alias, and links them
  with the page of the main language (see
  [Pages](usage/pages.md#create-the-language-versions-right-away)). The version
  in the main language is always needed and is created automatically when the
  new page is saved in another language.

## Common problems

| What you see | Why | What to do |
| --- | --- | --- |
| The button **Languages** is missing | The page is not linked to pages of other languages, or the user lacks **Edit language versions (Plus)** | Link the pages with changelanguage; set the switch |
| A language version is missing in **Languages** | The user has no pagemount for that language's root page | Add the root page to **Pagemounts** |
| An archive is missing in **Posts** | Its reader page belongs to another website, or the user lacks **Allowed archives** | Check the archive's reader page (**Redirect page**) and the group |
| **New page** shows only one root page | Intended: a new page is created in the website and language of the open page | Open the preview of a page in the other website or language first |
