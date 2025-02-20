# Wiki Donate Backend

This Laravel backend provides the API services for the Wiki Donate application. It includes endpoints for handling user authentication, page creation and editing, version control, and other core functionalities required for a wiki site.

The API documentation can be accessed at [https://api.wikidonate.org/api/documentation](https://api.wikidonate.org/api/documentation).

The frontend repository can be found at [https://github.com/WikiDonate/wiki-donate-frontend](https://github.com/WikiDonate/wiki-donate-frontend).

## Features

-   **User Authentication**: Manage user registrations, logins, and secure access for two user roles: admin and editor. Admins have full access to manage content and users, while editors can create and edit wiki pages.
-   **API for Article and Talk Page Creation and Editing**: Expose endpoints to create and edit article and talk pages, utilizing version control to store and retrieve page history. The API should support creating and editing article and talk pages, including creating new versions of existing pages.
-   **Search Functionality**: API endpoints to support search queries for finding content efficiently.
-   **Category and Tag Management**: Organize wiki pages by categories and tags, facilitating navigation and filtering.
-   **Discussion Pages**: Implement discussion functionality for articles to promote community engagement.

## Prerequisites

-   **PHP 8.1+**
-   **Laravel 11**
-   **Composer**
-   **Database** (MySQL)

## Install Dependencies

Install PHP dependencies using Composer:

```bash
composer install
```

## Environment Configuration

Copy the .env.example file to create a new .env file:

```bash
cp .env.example .env
```

## Generate Application Key

```bash
php artisan key:generate
```

## Database Migration and Seeding

```bash
php artisan migrate --seed
```

## Start the Server

The API will be accessible at `http://localhost:8000`

```bash
php artisan serve
```
