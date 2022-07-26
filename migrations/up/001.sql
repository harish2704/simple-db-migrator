-- Initial migration
CREATE TABLE table1 (
  id integer NOT NULL PRIMARY KEY,
  name text NOT NULL
);

INSERT INTO table1 (id, name)
VALUES
(1,	'Adam'),
(2,	'Bob'),
(3,	'Carl');
