SET NAMES utf8mb4;
-- Nested content elements on the German offer page: an accordion with two sections (text elements) and an element group with one text.
SET @a = (SELECT a.id FROM tl_article a JOIN tl_page p ON p.id = a.pid WHERE p.alias = 'angebot' ORDER BY a.sorting LIMIT 1);
DELETE c FROM tl_content c JOIN tl_content p ON c.ptable = 'tl_content' AND c.pid = p.id WHERE p.pid = @a AND p.ptable = 'tl_article' AND p.type IN ('accordion', 'element_group');
DELETE FROM tl_content WHERE pid = @a AND ptable = 'tl_article' AND type IN ('accordion', 'element_group');
SET @s = (SELECT MAX(sorting) FROM tl_content WHERE pid = @a AND ptable = 'tl_article');
INSERT INTO tl_content (pid, ptable, sorting, tstamp, type, headline, closeSections) VALUES (@a, 'tl_article', @s + 64, UNIX_TIMESTAMP(), 'accordion', 'a:2:{s:5:"value";s:15:"Häufige Fragen";s:4:"unit";s:2:"h2";}', 0);
SET @acc = LAST_INSERT_ID();
INSERT INTO tl_content (pid, ptable, sorting, tstamp, type, sectionHeadline, text) VALUES
  (@acc, 'tl_content', 128, UNIX_TIMESTAMP(), 'text', 'a:2:{s:5:"value";s:19:"Anreise mit dem Zug";s:4:"unit";s:2:"h3";}', '<p>Der Bahnhof liegt zehn Minuten entfernt.</p>'),
  (@acc, 'tl_content', 256, UNIX_TIMESTAMP(), 'text', 'a:2:{s:5:"value";s:9:"Parkplatz";s:4:"unit";s:2:"h3";}', '<p>Parkplätze gibt es direkt am Haus.</p>');
INSERT INTO tl_content (pid, ptable, sorting, tstamp, type, headline) VALUES (@a, 'tl_article', @s + 128, UNIX_TIMESTAMP(), 'element_group', 'a:2:{s:5:"value";s:0:"";s:4:"unit";s:2:"h2";}');
SET @grp = LAST_INSERT_ID();
INSERT INTO tl_content (pid, ptable, sorting, tstamp, type, headline, text) VALUES
  (@grp, 'tl_content', 128, UNIX_TIMESTAMP(), 'text', 'a:2:{s:5:"value";s:13:"In der Gruppe";s:4:"unit";s:2:"h3";}', '<p>Ein Text in einer Elementgruppe.</p>');
