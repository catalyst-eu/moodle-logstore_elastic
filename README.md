# Moodle Log Store - Elasticsearch Backend

This plugin allows Moodle to store log events in an Elasticsearch database.

It is functionally similar to the core External database log store, providing data for Moodle's Log and Live Log reports.

There is minimal configuration other than entering the connection details for your Elasticsearch server and providing the name of an index (this does not need to exist as the plugin will create it with the first request).
