SET NAMES utf8mb4;
-- Base of the documentation test installation (README.md in this folder): one website at a plain http address, in
-- German (language fallback, prefix "de") and English (prefix "en"), a layout with navigation and language switch
-- in the header, the pages angebot / offer (linked for changelanguage) with their first elements, a blog, events
-- and FAQ in German.
-- Run once on an empty installation (it removes all pages, articles and elements first), after "contao:filesync"
-- has registered files/beispiel/see.svg. Neutral sample content only.
DELETE FROM tl_content WHERE ptable IN ('tl_article', 'tl_content', 'tl_news', 'tl_calendar_events');
DELETE FROM tl_article;
DELETE FROM tl_page;
DELETE FROM tl_layout WHERE name = 'Beispiel';
DELETE FROM tl_module WHERE name IN ('Navigation', 'Sprachwahl');
SET @theme = (SELECT MIN(id) FROM tl_theme);
INSERT INTO tl_module (pid, tstamp, name, type, levelOffset, showLevel) VALUES (@theme, UNIX_TIMESTAMP(), 'Navigation', 'navigation', 0, 1);
SET @nav = LAST_INSERT_ID();
INSERT INTO tl_module (pid, tstamp, name, type) VALUES (@theme, UNIX_TIMESTAMP(), 'Sprachwahl', 'changelanguage');
SET @lang = LAST_INSERT_ID();
INSERT INTO tl_layout (pid, tstamp, name, type, `rows`, cols, template, viewport, modules, head) VALUES (@theme, UNIX_TIMESTAMP(), 'Beispiel', 'default', '2rwh', '1cl', 'fe_page', 'width=device-width,initial-scale=1',
  CONCAT('a:3:{i:0;a:3:{s:3:"mod";s:', LENGTH(@nav), ':"', @nav, '";s:3:"col";s:6:"header";s:6:"enable";s:1:"1";}i:1;a:3:{s:3:"mod";s:', LENGTH(@lang), ':"', @lang, '";s:3:"col";s:6:"header";s:6:"enable";s:1:"1";}i:2;a:3:{s:3:"mod";s:1:"0";s:3:"col";s:4:"main";s:6:"enable";s:1:"1";}}'),
  '<style>body{margin:0;font:17px/1.5 Georgia,serif;color:#1f2a33;background:#faf9f6}#header{border-bottom:1px solid #e2e0da;background:#fff}#header .inside{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:8px 24px;max-width:1100px;margin:0 auto;padding:12px 24px;font-family:Arial,sans-serif}#header ul{display:flex;flex-wrap:wrap;gap:8px 20px;margin:0;padding:0;list-style:none}#header a,#header strong{color:#1f2a33;text-decoration:none}#header strong.active,#header .active{border-bottom:2px solid #1f5d73}#main .inside{max-width:1100px;margin:0 auto;padding:24px}img{max-width:100%;height:auto}h1{font-size:2.6rem;margin:.4em 0}</style>');
SET @layout = LAST_INSERT_ID();
SET @chmod = 'a:12:{i:0;s:2:"u1";i:1;s:2:"u2";i:2;s:2:"u3";i:3;s:2:"u4";i:4;s:2:"u5";i:5;s:2:"u6";i:6;s:2:"g1";i:7;s:2:"g2";i:8;s:2:"g3";i:9;s:2:"g4";i:10;s:2:"g5";i:11;s:2:"g6";}';
SET @group = (SELECT MIN(id) FROM tl_user_group);
INSERT INTO tl_page (pid, sorting, tstamp, title, alias, type, language, fallback, urlPrefix, urlSuffix, dns, useSSL, published, includeLayout, layout, includeChmod, cuser, cgroup, chmod) VALUES (0, 128, UNIX_TIMESTAMP(), 'Beispiel', 'beispiel', 'root', 'de', 1, 'de', '', '', 0, 1, 1, @layout, 1, 1, IFNULL(@group, 0), @chmod);
SET @de = LAST_INSERT_ID();
INSERT INTO tl_page (pid, sorting, tstamp, title, alias, type, language, fallback, urlPrefix, urlSuffix, dns, useSSL, published, includeLayout, layout, includeChmod, cuser, cgroup, chmod) VALUES (0, 256, UNIX_TIMESTAMP(), 'Example', 'example', 'root', 'en', 0, 'en', '', '', 0, 1, 1, @layout, 1, 1, IFNULL(@group, 0), @chmod);
SET @en = LAST_INSERT_ID();
INSERT INTO tl_page (pid, sorting, tstamp, title, alias, type, published) VALUES (@de, 128, UNIX_TIMESTAMP(), 'Startseite', 'startseite', 'regular', 1);
SET @de_home = LAST_INSERT_ID();
INSERT INTO tl_page (pid, sorting, tstamp, title, alias, type, published, description) VALUES (@de, 256, UNIX_TIMESTAMP(), 'Angebot', 'angebot', 'regular', 1, 'Ferienwohnung am See mit Terrasse: Ausstattung, Anfahrt und Preise auf Anfrage.');
SET @de_offer = LAST_INSERT_ID();
INSERT INTO tl_page (pid, sorting, tstamp, title, alias, type, published) VALUES (@de, 384, UNIX_TIMESTAMP(), 'Umgebung', 'umgebung', 'regular', 1), (@de, 512, UNIX_TIMESTAMP(), 'Kontakt', 'kontakt', 'regular', 1);
INSERT INTO tl_page (pid, sorting, tstamp, title, alias, type, published, languageMain) VALUES (@en, 128, UNIX_TIMESTAMP(), 'Home', 'home', 'regular', 1, @de_home);
INSERT INTO tl_page (pid, sorting, tstamp, title, alias, type, published, languageMain, description) VALUES (@en, 256, UNIX_TIMESTAMP(), 'Offer', 'offer', 'regular', 1, @de_offer, 'Holiday apartment by the lake with a terrace: furnishings, getting here and prices on request.');
SET @en_offer = LAST_INSERT_ID();
INSERT INTO tl_article (pid, sorting, tstamp, title, alias, inColumn, published) SELECT id, 128, UNIX_TIMESTAMP(), title, CONCAT(alias, '-artikel'), 'main', 1 FROM tl_page WHERE type = 'regular';
SET @img = (SELECT uuid FROM tl_files WHERE path = 'files/beispiel/see.svg');
SET @a = (SELECT id FROM tl_article WHERE pid = @de_offer);
INSERT INTO tl_content (pid, ptable, sorting, tstamp, type, headline) VALUES (@a, 'tl_article', 64, UNIX_TIMESTAMP(), 'headline', 'a:2:{s:4:"unit";s:2:"h1";s:5:"value";s:13:"Unser Angebot";}');
INSERT INTO tl_content (pid, ptable, sorting, tstamp, type, text) VALUES (@a, 'tl_article', 128, UNIX_TIMESTAMP(), 'text', '<p>Preise auf Anfrage. Wir antworten innerhalb eines Werktags.</p>');
INSERT INTO tl_content (pid, ptable, sorting, tstamp, type, singleSRC, alt, overwriteMeta, size) VALUES (@a, 'tl_article', 192, UNIX_TIMESTAMP(), 'image', @img, 'Blick von der Terrasse auf den See', 1, '');
INSERT INTO tl_content (pid, ptable, sorting, tstamp, type, headline) VALUES (@a, 'tl_article', 224, UNIX_TIMESTAMP(), 'headline', 'a:2:{s:4:"unit";s:2:"h4";s:5:"value";s:7:"Anfahrt";}');
SET @a = (SELECT id FROM tl_article WHERE pid = @en_offer);
INSERT INTO tl_content (pid, ptable, sorting, tstamp, type, headline) VALUES (@a, 'tl_article', 64, UNIX_TIMESTAMP(), 'headline', 'a:2:{s:4:"unit";s:2:"h1";s:5:"value";s:9:"Our offer";}');
INSERT INTO tl_content (pid, ptable, sorting, tstamp, type, headline) VALUES (@a, 'tl_article', 224, UNIX_TIMESTAMP(), 'headline', 'a:2:{s:4:"unit";s:2:"h4";s:5:"value";s:12:"Getting here";}');
-- German posts: a blog, events and questions, read on the offer page.
DELETE n FROM tl_news n JOIN tl_news_archive a ON a.id = n.pid WHERE a.title = 'Blog';
DELETE FROM tl_news_archive WHERE title = 'Blog';
INSERT INTO tl_news_archive (tstamp, title, jumpTo) VALUES (UNIX_TIMESTAMP(), 'Blog', @de_offer);
SET @n = LAST_INSERT_ID();
INSERT INTO tl_news (pid, tstamp, headline, alias, author, date, time, published, source) VALUES
  (@n, UNIX_TIMESTAMP(), 'Frühstück mit Blick auf den See', 'fruehstueck-mit-seeblick', 1, UNIX_TIMESTAMP() - 86400 * 3, UNIX_TIMESTAMP() - 86400 * 3, 1, 'default'),
  (@n, UNIX_TIMESTAMP(), 'Eine neue Wanderkarte der Region', 'neue-wanderkarte', 1, UNIX_TIMESTAMP() - 86400 * 10, UNIX_TIMESTAMP() - 86400 * 10, 1, 'default'),
  (@n, UNIX_TIMESTAMP(), 'Herbstangebot: drei Nächte, zwei bezahlen', 'herbstangebot-news', 1, UNIX_TIMESTAMP() - 86400, UNIX_TIMESTAMP() - 86400, 0, 'default');
DELETE e FROM tl_calendar_events e JOIN tl_calendar c ON c.id = e.pid WHERE c.title = 'Veranstaltungen';
DELETE FROM tl_calendar WHERE title = 'Veranstaltungen';
INSERT INTO tl_calendar (tstamp, title, jumpTo) VALUES (UNIX_TIMESTAMP(), 'Veranstaltungen', @de_offer);
SET @c = LAST_INSERT_ID();
INSERT INTO tl_calendar_events (pid, tstamp, title, alias, author, startDate, endDate, startTime, endTime, published, source) VALUES
  (@c, UNIX_TIMESTAMP(), 'Seefest', 'seefest', 1, UNIX_TIMESTAMP() + 86400 * 20, NULL, UNIX_TIMESTAMP() + 86400 * 20, UNIX_TIMESTAMP() + 86400 * 20, 1, 'default'),
  (@c, UNIX_TIMESTAMP(), 'Geführte Wanderung', 'gefuehrte-wanderung', 1, UNIX_TIMESTAMP() + 86400 * 35, NULL, UNIX_TIMESTAMP() + 86400 * 35, UNIX_TIMESTAMP() + 86400 * 35, 1, 'default');
DELETE q FROM tl_faq q JOIN tl_faq_category c ON c.id = q.pid WHERE c.title = 'Fragen';
DELETE FROM tl_faq_category WHERE title = 'Fragen';
INSERT INTO tl_faq_category (tstamp, title, jumpTo) VALUES (UNIX_TIMESTAMP(), 'Fragen', @de_offer);
SET @q = LAST_INSERT_ID();
INSERT INTO tl_faq (pid, sorting, tstamp, question, alias, author, answer, published) VALUES
  (@q, 128, UNIX_TIMESTAMP(), 'Sind Haustiere erlaubt?', 'haustiere', 1, '<p>Nach Absprache, gerne.</p>', 1),
  (@q, 256, UNIX_TIMESTAMP(), 'Gibt es einen Parkplatz?', 'parkplatz', 1, '<p>Ja, direkt am Haus.</p>', 1);
