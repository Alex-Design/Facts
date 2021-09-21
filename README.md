# Facts

Utilise this Facts API to determine the factual result of your input!

## Installation
- Clone the project
- Run the following command inside the project:
```
composer install
```
- Place the following line into the .env file, with NAME and PASSWORD substituted.
 These values correspond to the user account for connecting to the database.
```
DATABASE_URL="mysql://NAME:PASSWORD@127.0.0.1:3306/facts?serverVersion=5.7"
```
- Next, run the following command to make the database accordingly:
```
php bin/console doctrine:database:create
```
- Import this project's .sql file into the database. This file can be
 found inside the 'imports' folder at the root of this project.

## Running the App
- Run the following command inside the project:
```
symfony server:start
```

## Calling the API
There is only one endpoint in the API:

- POST /facts/calculate

This endpoint can accept either of the following two JSON data structures:

### Normal:
```
{
  "expression": {"fn": "+", "a": "price", "b": "eps"},
  "security": "BCD"
}
```

### Nested:
```
{
  "expression": {
    "fn": "/", 
    "a": {"fn": "*", "a": "eps", "b": "debt"}, 
    "b": {"fn": "+", "a": "assets", "b": "liabilities"}
  },
  "security": "CDE"
}
```

## How the API Works
The idea is that the properties of "a" and "b" combine with the "security"
to identify the value in the facts SQL table to determine their individual
numerical values. The "fn" then performs a calculation on these integer
values. The final calculation is returned as the response.

_If a given value is not found, then it is used with the value of zero._

## Possible Improvements
###  JSON exceptions

At the time of writing this API, the PHP version installed was not sufficient to
allow installation of the required package. Once the PHP version has been updated,
it will be possible to implement JSON exceptions as currently Symfony returns
them in a formatted HTML file, which can only be seen in a user-friendly way in 
the 'preview' tab in postman.

The following is the command to install the exception package:
```
composer require symfony/serializer-pack
```

### Unit tests

Unit tests would be required should this API be used in production to ensure
that all calculations under all circumstances are correct, and that all
expected errors are thrown in cases of invalid input.