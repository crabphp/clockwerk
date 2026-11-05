# Contributing

Setup, formatting, linting and conventions follow [crabphp/framework's CONTRIBUTING.md](https://github.com/crabphp/framework/blob/main/CONTRIBUTING.md). Clone the framework beside this repository as `../framework`; Composer uses that path. Install PHP 8.4 or later with `mbstring`, `pcntl`, `posix`, `sockets` and `pdo_sqlite`, and Node.js 24 for the dashboard script tests. Run here:

```sh
composer install
composer validate --strict
composer format:check
composer lint
composer test
```

The suite uses SQLite and executes the shipped JavaScript in Node.js. It needs no Redis, MySQL or S3 service. Without Node.js the JavaScript cases are skipped; CI installs Node.js and fails on skipped tests. CI tests PHP 8.4 and 8.5 against framework v0.6.1 and main. The package requires framework `^0.6`.
