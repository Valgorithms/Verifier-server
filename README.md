# Verifier-Server

Valithor's official webserver for BYOND user verification.

## Overview

Verifier-Server is a web server designed to handle BYOND user verification.

## Features

- Handles GET and POST requests for user verification.
- Supports both filesystem and SQL (SQLite/MySQL) storage.
- Configurable via `.env` file.
- Logger mode for detailed request and response logging.
- Graceful shutdown support on all systems with ReactPHP.
- Graceful shutdown support on non-Windows systems with `pcntl` extension when not leveraging ReactPHP.

## Installation

1. Clone the repository:
    ```sh
    git clone https://github.com/Valgorithms/Verifier-server.git
    cd Verifier-server
    ```

2. Install dependencies (PHP 8.2 or newer):
    ```sh
    composer install
    ```

3. Create and configure the `.env` file:
    ```sh
    cp example.env .env
    ```

    Edit the `.env` file to match your configuration:
    ```properties
    HOST_ADDR=127.0.0.1
    HOST_PORT=8080
    TOKEN=changeme
    STORAGE_TYPE=filesystem
    JSON_PATH=json/verify.json

    # SQLite configuration
    #DB_DSN=sqlite:verify.db
    #DB_USERNAME=
    #DB_PASSWORD=
    #DB_OPTIONS=

    # MySQL configuration
    #DB_DSN=mysql:host=127.0.0.1;port=3306;dbname=verify_list
    #DB_PORT=3306
    #DB_USERNAME=your_username
    #DB_PASSWORD=your_password
    #DB_OPTIONS={"option1":"value1","option2":"value2"}
    ```

    The `.env` file lives in the repository root regardless of the working
    directory; variables set in the real environment take precedence over it.
    The OAuth2 web endpoints (`SS14_OAUTH2_CLIENT_ID`/`SECRET`, `dwa_client_id`/
    `dwa_client_secret`) are optional and only registered when both halves are set.

## Usage

1. Start the server:
    ```sh
    php run.php
    ```

2. The server will listen on the address and port specified in the `.env` file.

## Endpoints

- `GET /verified`: Retrieve the list of verified users.
- `POST /verified`: Add or delete a verified user. Requires a valid token.
- `GET /ss14verified` and `POST /ss14verified`: the same contract for Space Station 14
  accounts, with an `ss14` field instead of `ckey`.

A trailing slash is accepted (`/verified/`), and `/` is an alias of `/verified`.

## HTTP contract

This is the contract [Civilizationbot](https://github.com/Valgorithms/Civilizationbot)
relies on for `approveme`; keep it backward compatible.

### Records

```json
[{"discord": "123456789012345678", "ss13": "alice", "create_time": "2026-01-01 12:00:00"}]
```

Each record links exactly one Discord user id to one BYOND ckey. A `ckey` is
stored in its normalised form: lowercased, with `.`, `_`, `-`, `+` and spaces
removed, which is how BYOND and the bot compare keys.

### `POST /verified`

Body: `application/x-www-form-urlencoded` (the legacy `Field: value` header
form is still accepted for in-process callers). Fields:

| Field     | Required | Meaning |
| --------- | -------- | ------- |
| `token`   | yes      | Must equal the configured `TOKEN`. |
| `discord` | insert   | Discord user id, 17 to 20 digits. |
| `ckey`    | insert   | BYOND key, normalised before storage. |
| `method`  | no       | `delete` removes the record matching `ckey`, else `discord`. Anything else inserts. |

Status codes:

| Status | Meaning |
| ------ | ------- |
| 200    | Inserted (body: the full list) or deleted (body: the removed records). |
| 400    | `discord` or `ckey` is malformed. Nothing was stored. |
| 401    | Missing or wrong `token`, or the server still uses the default token. |
| 403    | The Discord id or the (normalised) ckey is already registered. |
| 404    | `delete` found no matching record. |
| 405    | Unsupported HTTP method. |

### `GET /verified`

Returns the full list as JSON. There is no read token; do not host the
server on a public interface unless that is intended.

## Running Tests

To run the tests, execute the following command:
```sh
php runtests.php
```

## Using Scripts

### Get Verified List

To get the list of verified users, run the following script:
```sh
./scripts/get_verified.sh
```

### Insert a New Record

To insert a new `ss13` and `discord` record, run the following script:
```sh
./scripts/post_verified.sh
```

## License

This project is licensed under the MIT License.

## Offline verification checks

Run `php scripts/lint.php` to parse all package source, entry points, scripts, and tests.
CI checks PHP 8.2, 8.3, 8.4, and 8.5.

Run `php vendor/bin/phpunit --bootstrap vendor/autoload.php tests/isolated`.
These contract tests inject storage that never writes files or opens databases;
they do not load `.env`, bind a listening socket, or contact verification services.
A server loading test constructs the server and routes a synthetic request without
initializing its network listener.
The older tests outside this directory use configured storage and are not run by CI.

Run `php -d xdebug.mode=off scripts/benchmark_lookups.php` for a synthetic
lookup comparison. It uses in-memory lists and performs no persistence or HTTP.
Lookup columns are cached until the list changes, preserving existing loose
comparison semantics and original row keys. A snapshot and columns consume
additional memory; database reads still follow the configured storage behavior.

Verification HTTP requests accept `application/x-www-form-urlencoded` bodies.
Body fields override corresponding legacy headers. Internal header-string calls
remain supported. Successful registrations explicitly return HTTP 200.
