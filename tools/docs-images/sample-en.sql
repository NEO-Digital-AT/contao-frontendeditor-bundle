SET NAMES utf8mb4;
-- English sample content on the English offer page, for the English documentation screenshots.
SET @a = (SELECT a.id FROM tl_article a JOIN tl_page p ON p.id = a.pid WHERE p.alias = 'offer' ORDER BY a.sorting LIMIT 1);
SET @img = (SELECT singleSRC FROM tl_content WHERE type = 'image' AND singleSRC IS NOT NULL LIMIT 1);
DELETE c FROM tl_content c JOIN tl_content p ON c.ptable = 'tl_content' AND c.pid = p.id WHERE p.pid = @a AND p.ptable = 'tl_article';
DELETE FROM tl_content WHERE pid = @a AND ptable = 'tl_article' AND type <> 'headline';
INSERT INTO tl_content (pid, ptable, sorting, tstamp, type, text) VALUES (@a, 'tl_article', 128, UNIX_TIMESTAMP(), 'text', '<p>Prices on request. We answer within one working day.</p>');
INSERT INTO tl_content (pid, ptable, sorting, tstamp, type, singleSRC, alt, overwriteMeta, size) VALUES (@a, 'tl_article', 192, UNIX_TIMESTAMP(), 'image', @img, 'View of the lake from the terrace', 1, '');
DELETE FROM tl_form WHERE alias = 'enquiry';
INSERT INTO tl_form (tstamp, title, alias, method) VALUES (UNIX_TIMESTAMP(), 'Enquiry', 'enquiry', 'POST');
SET @f = LAST_INSERT_ID();
INSERT INTO tl_form_field (pid, sorting, tstamp, type, name, label, mandatory) VALUES (@f, 128, UNIX_TIMESTAMP(), 'text', 'name', 'Your name', 1), (@f, 256, UNIX_TIMESTAMP(), 'text', 'email', 'Email address', 1), (@f, 384, UNIX_TIMESTAMP(), 'textarea', 'message', 'Message', 0), (@f, 512, UNIX_TIMESTAMP(), 'submit', 'send', 'Send', 0);
UPDATE tl_form_field SET slabel = 'Send enquiry' WHERE pid = @f AND type = 'submit';
INSERT INTO tl_content (pid, ptable, sorting, tstamp, type, form, headline) VALUES (@a, 'tl_article', 256, UNIX_TIMESTAMP(), 'form', @f, 'a:2:{s:4:"unit";s:2:"h2";s:5:"value";s:7:"Enquiry";}');
INSERT INTO tl_content (pid, ptable, sorting, tstamp, type, headline, closeSections) VALUES (@a, 'tl_article', 320, UNIX_TIMESTAMP(), 'accordion', 'a:2:{s:4:"unit";s:2:"h2";s:5:"value";s:26:"Frequently asked questions";}', 0);
SET @acc = LAST_INSERT_ID();
INSERT INTO tl_content (pid, ptable, sorting, tstamp, type, sectionHeadline, text) VALUES
  (@acc, 'tl_content', 128, UNIX_TIMESTAMP(), 'text', 'a:2:{s:5:"value";s:17:"Arriving by train";s:4:"unit";s:2:"h3";}', '<p>The station is ten minutes away.</p>'),
  (@acc, 'tl_content', 256, UNIX_TIMESTAMP(), 'text', 'a:2:{s:5:"value";s:7:"Parking";s:4:"unit";s:2:"h3";}', '<p>There is parking right at the house.</p>');
INSERT INTO tl_content (pid, ptable, sorting, tstamp, type, headline) VALUES (@a, 'tl_article', 384, UNIX_TIMESTAMP(), 'element_group', 'a:2:{s:5:"value";s:0:"";s:4:"unit";s:2:"h2";}');
SET @grp = LAST_INSERT_ID();
INSERT INTO tl_content (pid, ptable, sorting, tstamp, type, headline, text) VALUES (@grp, 'tl_content', 128, UNIX_TIMESTAMP(), 'text', 'a:2:{s:5:"value";s:10:"In a group";s:4:"unit";s:2:"h3";}', '<p>A text inside an element group.</p>');
INSERT INTO tl_content (pid, ptable, sorting, tstamp, type, headline, com_order, com_template) VALUES (@a, 'tl_article', 448, UNIX_TIMESTAMP(), 'comments', 'a:2:{s:4:"unit";s:2:"h2";s:5:"value";s:8:"Comments";}', 'ascending', 'com_default');
SET @c = LAST_INSERT_ID();
INSERT INTO tl_comments (tstamp, source, parent, date, name, email, comment, published, ip) VALUES (UNIX_TIMESTAMP(), 'tl_content', @c, UNIX_TIMESTAMP(), 'Max Mustermann', 'office@example.com', '<p>Lovely apartment, we will come back.</p>', 1, '127.0.0.1');
-- An English news archive with three posts.
DELETE n FROM tl_news n JOIN tl_news_archive a ON a.id = n.pid WHERE a.title = 'News';
DELETE FROM tl_news_archive WHERE title = 'News';
INSERT INTO tl_news_archive (tstamp, title, jumpTo) VALUES (UNIX_TIMESTAMP(), 'News', (SELECT id FROM tl_page WHERE alias = 'offer'));
SET @n = LAST_INSERT_ID();
INSERT INTO tl_news (pid, tstamp, headline, alias, author, date, time, published, source) VALUES
  (@n, UNIX_TIMESTAMP(), 'Breakfast with a view of the lake', 'breakfast-with-a-view', 1, UNIX_TIMESTAMP() - 86400 * 3, UNIX_TIMESTAMP() - 86400 * 3, 1, 'default'),
  (@n, UNIX_TIMESTAMP(), 'A new hiking map of the region', 'new-hiking-map', 1, UNIX_TIMESTAMP() - 86400 * 10, UNIX_TIMESTAMP() - 86400 * 10, 1, 'default'),
  (@n, UNIX_TIMESTAMP(), 'Autumn offer: three nights, pay two', 'autumn-offer', 1, UNIX_TIMESTAMP() - 86400, UNIX_TIMESTAMP() - 86400, 0, 'default');
-- Language versions (image "languages"): the English article belongs to the German one; headline, text, image and
-- "Getting here" are current translations, the other German elements are missing in English. The hashes are those
-- of ContentHash::of() for the German texts. capture.cjs marks the text as outdated for the image "languages" only.
SET @de = (SELECT a.id FROM tl_article a JOIN tl_page p ON p.id = a.pid WHERE p.alias = 'angebot' ORDER BY a.sorting LIMIT 1);
UPDATE tl_article SET languageMain = @de WHERE id = @a;
DELETE FROM tl_neo_fe_link WHERE element NOT IN (SELECT id FROM tl_content) OR element IN (SELECT id FROM tl_content WHERE pid = @a AND ptable = 'tl_article');
SET @m1 = (SELECT id FROM tl_content WHERE pid = @de AND ptable = 'tl_article' AND type = 'headline' AND headline LIKE '%"Unser Angebot"%' LIMIT 1);
SET @e1 = (SELECT id FROM tl_content WHERE pid = @a AND ptable = 'tl_article' AND type = 'headline' AND headline LIKE '%"Our offer"%' LIMIT 1);
SET @m2 = (SELECT id FROM tl_content WHERE pid = @de AND ptable = 'tl_article' AND type = 'text' AND text LIKE '<p>Preise auf Anfrage%' LIMIT 1);
SET @e2 = (SELECT id FROM tl_content WHERE pid = @a AND ptable = 'tl_article' AND type = 'text' AND text LIKE '<p>Prices on request%' LIMIT 1);
SET @m3 = (SELECT id FROM tl_content WHERE pid = @de AND ptable = 'tl_article' AND type = 'image' AND alt = 'Blick von der Terrasse auf den See' LIMIT 1);
SET @e3 = (SELECT id FROM tl_content WHERE pid = @a AND ptable = 'tl_article' AND type = 'image' LIMIT 1);
SET @m4 = (SELECT id FROM tl_content WHERE pid = @de AND ptable = 'tl_article' AND type = 'headline' AND headline LIKE '%"Anfahrt"%' LIMIT 1);
SET @e4 = (SELECT id FROM tl_content WHERE pid = @a AND ptable = 'tl_article' AND type = 'headline' AND headline LIKE '%"Getting here"%' LIMIT 1);
INSERT INTO tl_neo_fe_link (tstamp, main, element, language, source_hash, synced_at, outdated) VALUES
  (UNIX_TIMESTAMP(), @m1, @e1, 'en', SHA2('{"headline":"Unser Angebot","text":"","alt":"","caption":""}', 256), UNIX_TIMESTAMP(), ''),
  (UNIX_TIMESTAMP(), @m2, @e2, 'en', SHA2('{"headline":"","text":"<p>Preise auf Anfrage. Wir antworten innerhalb eines Werktags.<\\/p>","alt":"","caption":""}', 256), UNIX_TIMESTAMP(), ''),
  (UNIX_TIMESTAMP(), @m3, @e3, 'en', SHA2('{"headline":"","text":"","alt":"Blick von der Terrasse auf den See","caption":""}', 256), UNIX_TIMESTAMP(), ''),
  (UNIX_TIMESTAMP(), @m4, @e4, 'en', SHA2('{"headline":"Anfahrt","text":"","alt":"","caption":""}', 256), UNIX_TIMESTAMP(), '');
