# Tigger #

    AUTHOR: Ben Lake <me@benlake.org>
    LICENSE: GNU Public License v3 (http://opensource.org/licenses/gpl-3.0.html)
    COPYRIGHT (c) 2011, Ben Lake

Tigger is a PHP CLI application used to monitor tickets in vTiger (the OSS version of SugarCRM). It
uses the rudimentary (but thankfully available) web service provided by vTiger. The application
provides the ability to view tickets assigned to you, "watch" tickets that are not assigned to you,
and enter time for any ticket with a very simply shorthand command.

More to come as I package this application for publication...

## About ##

I developed this tool because an company I was working with decided to use vTiger. Not because I
decided to use vTiger. Then the company demanded time entry on all tickets. Well, vTiger's web
web interface for time entry is deplorable. It just was not reasonable in any way and caused a
lot of extra work for the development team. I realized there was a "web service" available and
went from there. Upon release there were no longer any complaints about time entry from the team.
There are plenty of features that could be added, but what is here got the job done. I publish this
for the sake of showing that I actually do right code sometimes, and to see if it helps any other
team get through the pain!

## Compatibility ##

Tigger is really only tested working against vTiger 5.1.0. If you have any additional compatibility
information please let me know.

## vTiger Patches ##

I had to make a few tweaks to vTiger to fix a bug or two, but I'll need to find those and validate
them.
