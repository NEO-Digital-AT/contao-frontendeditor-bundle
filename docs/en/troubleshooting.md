---
title: Troubleshooting
summary: Common problems with the NEO Frontend Editor - what you see, why, and what to do.
audience: operations
language: en
updated: 2026-11-14
---

# Troubleshooting

Most problems are missing permissions. The editor never offers a button Contao
would refuse; so a missing button nearly always means a missing switch or a
missing Contao permission. [Permissions](permissions.md) lists them per
function.

For modules, the layout, form fields and comments, the card **Info** names the
missing permission itself. For other cases, the Contao system log (back end →
**System log**) records refused actions with the reason.

## The editor

| What you see | Why | What to do |
| --- | --- | --- |
| No button **Edit page** in the preview bar | The user lacks **Front end editing allowed** | Set the switch in the user group (or user). |
| The editor does not appear on the website | Intended: it only works in Contao's preview | Open the preview from the back end. |
| **Edit page** reloads the page, but the editor does not open (no error) | An older version of the extension: the website sets `COOKIE_ALLOW_LIST` and Contao's HTTP cache, which also handles `preview.php`, removed the editor's switch (`contao_frontend_editor`) | Update the extension; it keeps the switch past the list itself. If updating is not possible, add `contao_frontend_editor` to `COOKIE_ALLOW_LIST`. |
| The sidebar shows an error or stays empty | The page is outside the user's pagemounts, or the session expired | Check **Pagemounts**; log in again. |
| The area of the page next to the sidebar stays empty, or the browser says the page cannot be shown there | The website forbids showing its pages in a frame of the same address: header `X-Frame-Options: DENY` or `Content-Security-Policy: frame-ancestors 'none'`, set by the web server or an extension (Contao's Managed Edition sends `SAMEORIGIN`, which allows it) | Allow the same address: `X-Frame-Options: SAMEORIGIN` or `frame-ancestors 'self'`, at least for `preview.php`. |
| A link in the page opens another website instead of the editor | Intended: the editor shows only pages of this website | Go back in the browser. |
| The form in the sidebar stays empty (back end under its own address) | The address or the domains of the root pages are not set | **Frontend Editor** → **Address of the back end**; fill in the **Domain name** of every root page. |
| The form in the sidebar shows "Forbidden" or "Access denied" | Contao refuses the form in the back end, too | Check the permissions of that part ([Permissions](permissions.md)); since Contao 5.7 also the **Table permissions**. |

## Texts, images, structure

| What you see | Why | What to do |
| --- | --- | --- |
| No **Edit** at an element, but **All fields** | None of the element's texts can be edited on the page: its fields hold no text it shows, or you may not change them (a click on a text says why; see *Every other text of a part* in the user manual) | Intended; use **All fields**. |
| No **Edit**, no **All fields** | **Change texts and images**, the element type (**Content elements**), **Allowed fields** or `tl_content` → Update missing | See *Texts and images* in [Permissions](permissions.md). |
| No **Insert element**, **Duplicate**, **Delete** | **Create, move and delete elements**, or `tl_content` → Create / Delete missing | See *Structure*. |
| No **Hide** | Field **Invisible** (`invisible`) not in **Allowed fields** | Add it. |
| No **Create article** | **Create articles**, a field of `tl_article` under **Allowed fields**, or *Edit articles* / *Edit article hierarchy* on the page missing | See *Create articles*. |
| An element inside an accordion cannot be found | Its section is closed on the page | Use the sidebar: group "Elements in: …". |
| "Saving failed. The connection to the server was interrupted." | Network or session | **Save again**; log in again if it repeats. |

## Pages and posts (Plus)

| What you see | Why | What to do |
| --- | --- | --- |
| No views **Posts**, **Modules**, **Languages** | No Plus licence for the domain, or the switches are missing | Enter the licence; set the switches. |
| No **New page**, or no buttons in the page tree | *Edit page* and *Edit page hierarchy* missing on the parent page, or **Create and publish pages (Plus)** | See *Pages*. |
| The page tree for a new page shows only one website | Intended: a page is created in the website and language of the open page | Open a page of the other website first. |
| A post opens as "Not found" | It is not published and the preview bar says "Unpublished: hide" | Set it to "show". |
| A kind of post or an archive is missing | Back-end module, archive (**Allowed archives**), switch **Edit posts (Plus)** missing; or the archive's reader page belongs to another website | See *Posts* and [Several websites and languages](websites-and-languages.md). |

## Languages (Plus)

| What you see | Why | What to do |
| --- | --- | --- |
| No button **Languages** | No linked language versions (changelanguage), switch **Edit language versions (Plus)** missing, or no Plus licence | Link the pages; set the switch. |
| No step **Create language versions** after a new page | One language only, languages not linked with changelanguage, not a *Regular page*, or the page exists in every language | See [Pages](usage/pages.md#create-the-language-versions-right-away). |
| A row of **Create language versions** says "You may not create a page at this place in …" | *Edit page* / *Edit page hierarchy* of the parent page, the pagemount of that language or the field permission Page name missing | See *Language versions* in [Permissions](permissions.md). |
| A language version could not be created because of its alias | The alias exists already in that language | **Try again** with another alias. |
| "Language versions need the extension changelanguage." | changelanguage is not installed | Install `terminal42/contao-changelanguage`. |
| "Translations need a translation service: choose one in the settings …" | No translation service is set up, or the chosen one lost its access | Set up Azure Translator, DeepL or the AI connection under *Access keys and licences*, then choose it in the settings of the editor. |
| "… cannot be reached right now" (with the name of the service) | The server of the website does not reach the service | **Test connection** in its section; the server must be allowed to open outgoing HTTPS connections. |
| "Azure Translator refused the translation …" | Key or region wrong, or the resource is not a Translator resource | Check in the Azure portal; **Test connection**. |
| "DeepL refused the translation …" | Key wrong, the characters of the billing period used up, or DeepL does not offer one of the two languages | **Test connection** in the section **DeepL** shows the reason. |
| "AI model refused the translation …" | Key or model wrong, or the model gave no usable answer | **Test connection** in the section **AI connection**; choose another model. |
| A service cannot be chosen under **Translation service** | Its access is not set up | Follow the link below the field to its section. |
| **Model for translations** or the settings of the writing aid are disabled | The AI connection has no key | Follow the link at the field and enter the key. |

## Pro

| What you see | Why | What to do |
| --- | --- | --- |
| No **Writing aid** in the text tools | No Pro licence | Enter the licence. |
| "The writing aid needs the AI connection and a model." | Route, key or model missing | *Access keys and licences* → **AI**; **Frontend Editor** → **Model**. |
| "The writing aid has reached its limit for today." | **Requests per day** used up | Raise the limit, or wait until tomorrow. |
| "The check could not run." | The checker file `bundles/neofrontendeditor/axe/axe.min.js` cannot be loaded | Run `php vendor/bin/contao-console assets:install`; check whether a content security policy of the website blocks it. |
| No **Screen size** | Window 768 px wide or narrower, or no Pro licence | Use a wider window. |
| The focus keyword cannot be saved | *Edit page* missing for the page | See *Checks and writing aid*. |
| No **Page style (CSS)** | Switch **Edit page styles (Pro)**, back-end module **Page styles** or *Edit page* missing | See *Page style*. |
| The page style has no effect | A more specific selector of the layout wins | Use a more specific selector. |

## Templates and modules (web designers)

| What you see | Why | What to do |
| --- | --- | --- |
| No **Info**, no frames around modules | Switch **Templates and modules (for web designers)** missing | Set the switch. |
| **Edit module**, **Edit field**, **Edit comment** or **Edit layout** missing | Contao does not allow the change | **Info** at the part (for the layout: **Templates of the page**) names the missing permission. |
| **Open in Template Studio** missing | Back-end module **Templates** missing | Add it to **Back end modules**. |
| A form field has no frame | Hidden fields are not framed; fields of forms that are not built with Contao's form generator neither | Intended. |
| A text an element shows from another table (an entry of a catalogue) says it cannot be changed here | The element's field that chooses the record has no relation in its DCA, so the editor does not know the record | In the DCA of the field, set `'foreignKey' => 'tl_table.title'` and `'relation' => ['type' => 'hasOne', 'load' => 'lazy']` (as Contao's own fields do). The records and their entries (child table) are then edited and translated with the element. |

## Still stuck?

1. Log in with an administrator. If the function works there, it is a
   permission; compare the group with [Permissions](permissions.md).
2. Open the same record in the back end with the user. If the back end refuses
   it too, the cause is in Contao's permissions, not in the editor.
3. Look into the **System log** of the back end.
