-- Initial migration
CREATE TABLE table2 (
  id integer NOT NULL PRIMARY KEY,
  name text NOT NULL
);

INSERT INTO table2 (id, name)
VALUES
(1,	'tb2 Adam'),
(2,	'tb2 Bob'),
(3,	'tb2 Carl');
