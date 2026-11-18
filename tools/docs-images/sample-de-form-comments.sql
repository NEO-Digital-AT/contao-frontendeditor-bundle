SET NAMES utf8mb4;
DELETE FROM tl_form_field WHERE pid NOT IN (SELECT id FROM tl_form);
-- Stage 7 fixture on the German offer page: a form of the form generator with fields, comments with one comment.
SET @a = (SELECT a.id FROM tl_article a JOIN tl_page p ON p.id = a.pid WHERE p.alias = 'angebot' ORDER BY a.sorting LIMIT 1);
DELETE FROM tl_form WHERE alias = 'anfrage';
INSERT INTO tl_form (tstamp, title, alias, method) VALUES (UNIX_TIMESTAMP(), 'Anfrage', 'anfrage', 'POST');
SET @f = LAST_INSERT_ID();
INSERT INTO tl_form_field (pid, sorting, tstamp, type, name, label, mandatory) VALUES (@f, 128, UNIX_TIMESTAMP(), 'text', 'name', 'Ihr Name', 1), (@f, 256, UNIX_TIMESTAMP(), 'text', 'email', 'E-Mail-Adresse', 1), (@f, 384, UNIX_TIMESTAMP(), 'textarea', 'message', 'Nachricht', 0), (@f, 512, UNIX_TIMESTAMP(), 'submit', 'send', 'Senden', 0);
UPDATE tl_form_field SET slabel = 'Anfrage senden' WHERE pid = @f AND type = 'submit';
DELETE FROM tl_content WHERE pid = @a AND ptable = 'tl_article' AND sorting >= 1000;
INSERT INTO tl_content (pid, ptable, sorting, tstamp, type, form, headline) VALUES (@a, 'tl_article', 1024, UNIX_TIMESTAMP(), 'form', @f, 'a:2:{s:4:"unit";s:2:"h2";s:5:"value";s:7:"Anfrage";}');
INSERT INTO tl_content (pid, ptable, sorting, tstamp, type, headline, com_order, com_template) VALUES (@a, 'tl_article', 1152, UNIX_TIMESTAMP(), 'comments', 'a:2:{s:4:"unit";s:2:"h2";s:5:"value";s:10:"Kommentare";}', 'ascending', 'com_default');
SET @c = LAST_INSERT_ID();
DELETE FROM tl_comments WHERE source = 'tl_content';
INSERT INTO tl_comments (tstamp, source, parent, date, name, email, comment, published, ip) VALUES (UNIX_TIMESTAMP(), 'tl_content', @c, UNIX_TIMESTAMP(), 'Anna Beispiel', 'anna@example.com', '<p>Sehr schöne Wohnung, gerne wieder.</p>', 1, '127.0.0.1');
