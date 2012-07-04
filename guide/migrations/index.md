# Migrations

## Migrations? But I'm not leaving the country!

The term "Migrations" is used to describe the movement from one location to another.
In the context of databases it refers to managing differences between schemas.  In essence, it's version control for databases.

When you make a change to your schema you store a copy of the SQL you ran in a migration file, along with SQL that will revert the changes you made.

## Status

To find out which state you are currently in, execute:

    ./minion migrations:status

or simply

    ./minion migrations