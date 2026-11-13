---
title: Customising
summary: Colours, sizes and texts of the editor.
audience: developers
language: en
updated: 2026-11-12
---

# Customising

The editor takes its look from Contao's back end: buttons, icons, colours and
fonts come from the back-end style sheets, including a back-end skin added with
`contao.backend.custom_css`, in light and dark.

Visitors never get anything of the editor; nothing here changes the website for
them.

## Colours and sizes

In edit mode the editor shows a view of its own: Contao's preview bar, the
sidebar, and the page in a frame next to it. The page in the frame is the page
as Contao renders it, with its layout and style sheets; the editor adds its
frames, types and buttons there in an element `nfe-editor`. The view itself has
no style sheets of the website.

Single values can be changed with CSS variables on the element `nfe-editor`, in
a style sheet of the website's layout. They apply to what the editor shows in
the page; the view (sidebar, menus, cards) takes over the values that differ
from its own when the page is loaded:

```css
nfe-editor {
    --nfe-accent: #0b5cad;
    --nfe-panel-width: 420px;
}
```

| Variable | What it sets | Default |
| --- | --- | --- |
| `--nfe-accent` | Frames, selected rows, focus | Contao's accent colour (`--contao`) |
| `--nfe-surface` | Background of sidebar, cards and menus | `--content-bg` |
| `--nfe-border` | Lines and borders | `--content-border` |
| `--nfe-text` | Text | `--text` |
| `--nfe-muted` | Secondary text | `--gray` |
| `--nfe-error` | Errors, "Editing not permitted" | `--red` |
| `--nfe-hover` | Rows under the mouse | `--hover-row` |
| `--nfe-selected`, `--nfe-selected-border` | Selected row | `--active-bg`, `--active-border` |
| `--nfe-radius` | Rounded corners | `--border-radius` |
| `--nfe-frame-width` | Line width of the frame and the type tab at an element | `2px` |
| `--nfe-panel-width` | Width of the sidebar (the user can still drag it) | 360px |
| `--nfe-form-width` | Width of the sidebar with Contao's forms | 480px |

The editor follows the back end's look: without a skin it looks like Contao's
back end, with a back-end skin it takes over its colours. The main action of a
row (**Save**, **Apply**, **Create** …) looks like the back end's own main
action, the first button of a form: Contao draws it like every other button; a
skin that colours it (for example with a white label on its accent colour) gets
the same colours in the editor. The open tab of an element (its type, with the
menu open) is filled with `--nfe-accent` and labelled as Contao labels its
orange: white in the light colour scheme, black in the dark one (`--white`).

| Variable | What it sets | Default |
| --- | --- | --- |
| `--nfe-on-accent` | Text on the accent colour (open tab) | `--white` |
| `--nfe-main-bg`, `--nfe-main-text`, `--nfe-main-border` | Main action of a row; set by the editor only when the skin colours the back end's main action | the back end's buttons |

Check the contrast of your colours (text at least 4.5:1). Contao's own white
label on its orange reaches about 2.7:1; the editor keeps it so that it looks
like the back end.

## Texts

The texts of the editor are Symfony translations in
`translations/neo_frontend_editor.<language>.yaml` (eleven languages). Override
single texts like any Symfony translation, for example in
`translations/neo_frontend_editor.en.yaml` of the project:

```yaml
editor:
    insert_element: 'Add content'
```

Texts of the back end (switches, settings) are Contao language files in
`contao/languages/<language>/`; override them in `contao/languages/` of the
project.

## The language of the editor

The editor speaks the language of the back-end user (**Back end language** in
the user settings), not the language of the page.
