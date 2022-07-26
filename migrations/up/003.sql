-- Initial migration
CREATE TABLE table3 (
  id integer NOT NULL PRIMARY KEY,
  name text NOT NULL
);

INSERT INTO table3 (id, name)
VALUES
(1,	'tb3 Adam'),
(2,	'tb3 Bob'),
(3,	'tb3 Carl');
