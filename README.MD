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

- Laravel 13: for building RESTful APIs and handling server-side logic
- MySQL: as the database solution for managing structured data
- Sanctum: token-based authentication for the SPA
- PHP 8.3+

### Frontend

- Vue 3 (Vue Router + Pinia): Single Page Application
- Vite: build tooling
- Tailwind CSS: for designing a clean and modern UI

### Deployment and Hosting

- Shared hosting / any PHP host: point the document root to `public/`
- Run `npm run build` before deploying so the compiled assets exist in `public/build`

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
3. Copy `.env.example` to `.env` and configure the database connection
4. Generate encryption keys: `php artisan key:generate`
5. Run migrations: `php artisan migrate`

### Environment Variables

All configuration lives in `.env`. Frontend (Vite) build-time variables are
consumed in the SPA via `import.meta.env` and must be prefixed with `VITE_`:

| Variable | Description |
| --- | --- |
| `VITE_API_URL` | Base URL for the API (default `/api/v1`) |
| `VITE_STRIPE_PUBLIC_KEY` | Stripe publishable key used for card payments |
| `VITE_PAYPAL_CLIENT_ID` | PayPal client ID used for PayPal checkout |
| `VITE_AI_CHATBOT_API_KEY` | API key for the AI chatbot widget |
| `VITE_APP_NAME` | App name exposed to the frontend |

The backend payment variables (`STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`,
`PAYPAL_CLIENT_ID`, `PAYPAL_SECRET`, `PAYPAL_WEBHOOK_ID`) must also be set for
Stripe and PayPal flows to work. Note that changing `VITE_*` values requires a
re-run of `npm run build` (or `npm run dev`) to take effect.

### Running locally

**Option A — Laravel serves the built SPA:**

1. Build the frontend: `npm run build`
2. Start the server: `php artisan serve`
3. Open `http://localhost:8000`

**Option B — Vite dev server with API proxy:**

1. Start the Laravel server: `php artisan serve`
2. Start Vite: `npm run dev`
3. Open `http://localhost:5173` (`/api` requests are proxied to Laravel)

The Laravel web route serves the SPA for all non-API, non-asset paths.

### Testing

- Backend (PHPUnit/Pest): `php artisan test`
- Frontend production build check: `npm run build`

## Contribution

If you wish to contribute to Wiki Donate, follow these steps:

1. Fork this repository
2. Create a feature branch: `git checkout -b feature-name`
3. Commit your changes: `git commit -m "Description of changes"`
4. Push to the branch: `git push origin feature-name`
5. Open a pull request

## License

This project is licensed under the MIT License.