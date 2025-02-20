# Wiki Donate

Wiki Donate is a platform designed to streamline and facilitate donations for a variety of causes. This project includes robust features such as user authentication, article contributions, community discussions, donation management, and more. Built with a modular architecture, it ensures scalability and maintainability.

The API documentation can be accessed at [https://api.wikidonate.org/api/documentation](https://api.wikidonate.org/api/documentation).

## Project Structure

The project is structured as follows:

- `backend/`: contains the server-side code, built using Laravel 11
- `frontend/`: contains the client-side code, built using Nuxt.js and styled with Tailwind CSS

## Tech Stack

### Backend

- Laravel 11: for building RESTful APIs and handling server-side logic
- MySQL: as the database solution for managing structured data

### Frontend

- Nuxt.js: for building the frontend as a Single Page Application (SPA)
- Tailwind CSS: for designing a clean and modern UI

### Deployment and Hosting

- BlueHost: hosting solution for deployment

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

### Backend Setup

1. Navigate to the `backend/` folder
2. Install dependencies: `composer install`
3. Set up the `.env` file and configure the database connection
4. Run migrations: `php artisan migrate`
5. Start the development server: `php artisan serve`
6. Generate encryption keys: `php artisan key:generate`

### Frontend Setup

1. Navigate to the `frontend/` folder
2. Install dependencies: `npm install`
3. Start the development server: `npm run dev`

## Contribution

If you wish to contribute to Wiki Donate, follow these steps:

1. Fork this repository
2. Create a feature branch: `git checkout -b feature-name`
3. Commit your changes: `git commit -m "Description of changes"`
4. Push to the branch: `git push origin feature-name`
5. Open a pull request

## License

This project is licensed under the MIT License.
