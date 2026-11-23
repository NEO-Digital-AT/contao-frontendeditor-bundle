# Documentation images

The images in `docs/<lang>/usage/bilder/` and `docs/<lang>/bilder/` are
screenshots of the real editor in a test installation, marked with the NEO
annotation layer (frames and numbers). `capture.cjs` takes them again, for
example after a change to the editor's interface. This folder is not part of
the Composer package (`.gitattributes`: `export-ignore`).

## What the test installation needs

- Contao 5.7 with this extension, `neo-digital/contao-basic-bundle` and
  `terminal42/contao-changelanguage`, served at `NFE_BASE`
  (default `http://127.0.0.1:8000`) on a development host, so Plus and Pro
  work without a licence key.
- The sample image: `files/beispiel/see.svg` of this folder in the
  installation's `files/beispiel/`, the folder marked public (an empty file
  `.public` in it), then `contao:filesync` and `contao:symlinks`.
- A user group for the editor (the first group of the installation; the
  sample pages belong to it), with the switches of the extension.
- A theme (any; the sample layout and modules go into the first one).
- The sample content of this folder, imported in this order:

  ```bash
  mysql contao < sample-base.sql
  mysql contao < sample-de-check.sql
  mysql contao < sample-de-form-comments.sql
  mysql contao < sample-de-nested.sql
  mysql contao < sample-en.sql
  ```

  Then `contao:migrate` once more: it brings the stored state of the sample
  translation links (`tl_neo_fe_link.source_hash`) to the current way of
  computing it. Without it, every pair shows "Translation outdated".

  `sample-base.sql` builds the website on an empty installation and removes
  all pages, articles and elements first: two root pages of one website,
  German (language fallback, prefix `de`) and English (prefix `en`), a layout
  with navigation and language switch, the pages `angebot` / `offer` (linked
  for changelanguage), further German pages for the navigation, a blog, events
  and FAQ. The other files find the pages by alias and replace only their own
  content.
- An administrator and an editor (`NFE_ADMIN_USER`, `NFE_EDITOR_USER`); the
  editor in the user group above, which gets the sample pages as page mounts.
  The group's rights are changed for the image `missing-permission` and
  restored afterwards.
- The AI connection and Azure Translator set up under *Access keys and
  licences* (route and location "custom"), the translation service and the
  model chosen in the settings of the editor. A local stand-in that answers
  like the services is enough (`GET /models`, `POST /chat/completions` with
  `{"proposals": [...]}` as the message, `POST /translate`); the images show
  what it returns.
- Node.js with Playwright and its Chromium, and the annotation layer
  `annotate.js` of the documentation plugin `neo-doku`, its path in
  `NFE_ANNOTATE`.

## Taking the images

```bash
NFE_ANNOTATE=…/annotate.js NFE_ADMIN_PASSWORD=… NFE_EDITOR_PASSWORD=… node tools/docs-images/capture.cjs en
```

```bash
NFE_ANNOTATE=…/annotate.js NFE_ADMIN_PASSWORD=… NFE_EDITOR_PASSWORD=… node tools/docs-images/capture.cjs de
```

A second argument takes only some images, e.g. `page-bar,language-versions`.
Do not run two captures at the same time (also not one of `contao-basic-bundle`):
each changes the back-end language of the users.
The script sets the back-end language of both users to the language of the
images and restores it at the end. The image `language-versions` creates the
page "Herbstangebot" and removes it again; for the image `languages` the
English text is an outdated translation for the time of the shot. For the image
`translation-window` the images of `angebot` and `offer` get a headline, a title and a caption
for the time of the shot; the image `translate-page-progress` is taken while
**Translate whole page** runs on it (the proposals are not saved). For
`writing-aid` and `translation-proposals` the script prints what the AI
returned, so that the documentation can quote it as the example of the image.
A cookie hint of the website counts as answered in the
browser of the shots (only there; no consent is logged), and its button that
opens it again is hidden, as is the editor's note on the quick guide.

The editor shows the page in a frame next to the sidebar. The script selects
parts of the page by click in that frame, as an editor does, and marks them
in the view; it waits until the page in the frame has the editor's layer.

| Variable | Default | Meaning |
| --- | --- | --- |
| `NFE_BASE` | `http://127.0.0.1:8000` | Address of the test installation |
| `NFE_ADMIN_USER`, `NFE_ADMIN_PASSWORD` | `admin`, – | Administrator |
| `NFE_EDITOR_USER`, `NFE_EDITOR_PASSWORD` | `redakteur`, – | Editor (image `missing-permission`) |
| `NFE_MYSQL` | `mysql contao` | Command for the database of the test installation (MySQL 8 or MariaDB), e.g. `docker exec db mysql -u… -p… contao` |
| `NFE_ANNOTATE` | – | Path to `annotate.js`, the annotation layer |
| `NFE_FORM` | `anfrage` | Alias of the sample form |

Check every image after taking it: frames on the right element, nothing cut
off, no real names, addresses or keys.
