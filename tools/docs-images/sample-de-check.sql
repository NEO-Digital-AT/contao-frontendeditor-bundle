SET NAMES utf8mb4;
-- Accessibility test fixture on the German offer page: skipped heading level, low contrast, image without alternative text.
SET @a = (SELECT a.id FROM tl_article a JOIN tl_page p ON p.id = a.pid WHERE p.alias = 'angebot' ORDER BY a.sorting LIMIT 1);
SET @img = (SELECT singleSRC FROM tl_content WHERE type = 'image' AND singleSRC IS NOT NULL LIMIT 1);
DELETE FROM tl_content WHERE pid = @a AND ptable = 'tl_article' AND sorting >= 384;
INSERT INTO tl_content (pid, ptable, sorting, tstamp, type, headline) VALUES (@a, 'tl_article', 384, UNIX_TIMESTAMP(), 'headline', 'a:2:{s:4:"unit";s:2:"h4";s:5:"value";s:11:"Ausstattung";}');
INSERT INTO tl_content (pid, ptable, sorting, tstamp, type, headline, text) VALUES (@a, 'tl_article', 512, UNIX_TIMESTAMP(), 'text', 'a:2:{s:4:"unit";s:2:"h2";s:5:"value";s:0:"";}', '<p><span style="color:#b8b8b8">Haustiere sind nach Absprache willkommen.</span></p>');
INSERT INTO tl_content (pid, ptable, sorting, tstamp, type, headline, singleSRC, overwriteMeta, alt) VALUES (@a, 'tl_article', 640, UNIX_TIMESTAMP(), 'image', 'a:2:{s:4:"unit";s:2:"h2";s:5:"value";s:0:"";}', @img, 1, '');
