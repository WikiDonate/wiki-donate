# Wiki Donate

Wiki Donate is a platform designed to streamline and facilitate donations for a variety of causes. This project includes robust features such as user authentication, article contributions, community discussions, donation management, and more. Built with a modular architecture, it ensures scalability and maintainability.

The API documentation can be accessed at `https://your-domain/api/documentation`.

## Project Structure

The entire application lives in a single Laravel project at the repository root:

- `app/`: server-side code, built using Laravel
- `resources/js/`: the client-side Vue.js SPA (Vue 3 + Vue Router + Pinia), built with Vite
- `routes/`: API and web routes
- `public/`: the web root (served directly; contains the built Vite assets in `public/build`)

## Tech Stack

### Backend

- Laravel: for building RESTful APIs and handling server-side logic
- MySQL: as the database solution for managing structured data
- Sanctum: token-based authentication for the SPA

### Frontend

- Vue 3 (Vue Router + Pinia): Single Page Application
- Vite: build tooling
- Tailwind CSS: for designing a clean and modern UI

### Deployment and Hosting

- Shared hosting / any PHP host: point the document root to `public/`

## Features

### Core Features

#### Donation Management

- Allows users to make donations, track their contributions, and view donation history.

#### Authentication and Authorization

- Secure login and registration system for users and administrators.

#### Admin Dashboard

- Admins can manage donations, approve or reject user submissions, and generate reports.

#### Responsive Design

- A mobile-first design to ensure accessibility across devices.

### Additional Features

#### Article Contributions

- Users can write, edit, and publish articles related to donation causes, guidelines, or success stories.

#### Talk (Community Discussions)

- A dedicated space where users can engage in discussions, ask questions, or share feedback.

#### Review and Feedback System

- Allows users to leave reviews and rate their experience with the platform or specific donation campaigns.

#### Search and Filtering

- Comprehensive search functionality to filter articles, discussions, and donation campaigns.

#### Multi-Language Support

- Support for multiple languages to cater to a diverse user base.

#### Real-time Notifications

- Notify users of updates, approved articles, new comments, or milestones achieved in campaigns.

#### User Profiles

- Each user has a customizable profile that displays their activities, contributions, and reviews.

#### Analytics and Reports

- Admins can generate insights about donation trends, active users, and campaign performance.

## Development

### Setup

1. Install PHP dependencies: `composer install`
2. Install frontend dependencies: `npm install`
3. Set up the `.env` file and configure the database connection
4. Generate encryption keys: `php artisan key:generate`
5. Run migrations: `php artisan migrate`

### Running locally

- Backend: `php artisan serve`
- Frontend (Vite dev server with API proxy): `npm run dev` — open `http://localhost:5173`
- Production build (emits to `public/build`): `npm run build`

The Laravel web route serves the SPA for all non-API paths, so you can also visit the app directly through `php artisan serve`.

## Contribution

If you wish to contribute to Wiki Donate, follow these steps:

1. Fork this repository
2. Create a feature branch: `git checkout -b feature-name`
3. Commit your changes: `git commit -m "Description of changes"`
4. Push to the branch: `git push origin feature-name`
5. Open a pull request

## License

This project is licensed under the MIT License.