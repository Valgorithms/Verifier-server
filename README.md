# Verifier-Server

Valithor's official webserver for BYOND user verification.

## Overview

Verifier-Server is a web server designed to handle BYOND user verification. It supports both filesystem and SQL (SQLite/MySQL) storage for verification data.

## Features

- Handles GET and POST requests for user verification.
- Supports both filesystem and SQL (SQLite/MySQL) storage.
- Configurable via `.env` file.

## Installation

1. Clone the repository:
    ```sh
    git clone https://github.com/Valgorithms/Verifier-server.git
    cd Verifier-server
    ```

2. Install dependencies:
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

    # SQLite configuration
    #DB_DSN=sqlite:verify.db
    #DB_USERNAME=
    #DB_PASSWORD=
    #DB_OPTIONS=

    # MySQL configuration
    DB_DSN=mysql:host=127.0.0.1;port=3307;dbname=verify_list
    DB_PORT=3306
    DB_USERNAME=your_username
    DB_PASSWORD=your_password
    #DB_OPTIONS={"option1":"value1","option2":"value2"}
    ```

## Usage

1. Start the server:
    ```sh
    php run.php
    ```

2. The server will listen on the address and port specified in the `.env` file.

## Endpoints

- `GET /verified`: Retrieve the list of verified users.
- `POST /verified`: Add or delete a verified user. Requires a valid token.

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
