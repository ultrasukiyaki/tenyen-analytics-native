# Contributing

Open an issue before a substantial change, keep pull requests focused, and explain compatibility impact and tests. Code must remain compatible with PHP 8.1 and must not add a required Composer dependency.

Run:

```sh
php tests/run.php
find . -type f -name '*.php' -not -path './vendor/*' -not -path './dist/*' -print0 | xargs -0 -n1 php -l
find . -type f -name '*.js' -not -path './node_modules/*' -not -path './dist/*' -print0 | xargs -0 -n1 node --check
```

English is the canonical source-string language. Japanese translations must use clear standard Japanese. Never translate, normalize, alias, or replace raw ASN organization names.

Do not commit MMDB files, secrets, `config.php`, logs, production IP/access data, runtime files, or dependency directories.
