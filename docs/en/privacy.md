---
title: Privacy
summary: What the editor stores, what goes to which external service, and what belongs in the privacy policy.
audience: operations
language: en
updated: 2026-11-12
---

# Privacy

The editor works only for logged-in back-end users in Contao's preview. It never
adds anything to the pages visitors see, except a page style (Pro) that a web
designer saved for a page. It processes no visitor data.

## What is stored

| What | Where | How long |
| --- | --- | --- |
| Changes to content, pages, posts | Contao's own tables and Contao's versions (with the user name), as with changes in the back end | as Contao keeps versions |
| Links between the elements of language versions (Plus) | a table of the extension | until the link is removed |
| Focus keyword per page (Pro) | a table of the extension | until changed |
| Page styles (Pro) | a table of the extension and Contao's versions | until deleted |
| Width and state of the sidebar | the browser of the editor (local storage) | until the browser data is cleared |

## What goes to external services

| Function | Service | What is sent | When |
| --- | --- | --- | --- |
| Basic | none | – | – |
| Licence (Plus, Pro) | the licence service | licence key, extension, domains of the website, versions | when the licence is activated and once a day |
| Translation proposals (Plus), service Azure Translator | Microsoft Azure Translator, processed at the chosen location (default Europe) | the texts of the element (every text field, the texts of its entries, the title, alternative text and caption of its images, HTML code) and the two languages; for a new page its name | only when a user clicks **Translate all fields**, **Translate this field** or **Translate whole page**, or requests the names of new language versions |
| Translation proposals (Plus), service DeepL | DeepL SE (Germany), DeepL API Free or Pro according to the key | the same texts and the two languages | as above |
| Translation proposals (Plus), service AI model | the AI route set on *Access keys and licences*, with the model chosen in the settings | the same texts, the two languages and fixed instructions for the translation | as above |
| Writing aid (Pro) | the AI route set on *Access keys and licences* (e.g. a route with EU-hosted models) | the texts of the page (page title, description, texts of the elements), the field to rewrite, the search term | only when a user clicks **Create proposals** |
| Checks (Pro) | none; they run in the editor's browser. The accessibility checker (axe-core) comes with the extension and is loaded from the website itself | – | – |
| Page style (Pro) | none; translated on the website's own server. A style may not load anything from other servers | – | – |

No user names and no visitor data are sent.

## What the website operator does

- Conclude a data processing agreement with the services you use: Microsoft
  (Azure), DeepL, the provider of the AI route. With DeepL API Free, DeepL may
  use the texts for a limited time to improve its service; DeepL API Pro
  deletes them after the translation (DeepL privacy policy). For texts that
  must stay under a data processing agreement, use DeepL API Pro, Azure
  Translator or an AI route with such an agreement.
- Name these services in the privacy policy where it applies (the editor is
  used by staff, not by visitors).
- AI proposals and translation proposals are marked as such in the editor
  ("Machine translation (DeepL) – please check") and are only published after
  a person has checked and saved them.
