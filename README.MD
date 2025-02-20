# Wiki Donate

Wiki Donate is a platform designed to streamline and facilitate donations for a variety of causes. This project is built as a full-stack application, with the backend and frontend developed in separate folders for modularity and scalability.

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

- GoDaddy: hosting solution for deployment

## Features

### Donation Management

- Allows users to donate and view their contributions

### Authentication

- Secure user authentication to manage profiles and donations

### Admin Dashboard

- Admins can view, approve, or manage donation requests

### Responsive Design

- A mobile-friendly design to enhance user experience across devices

## Development

### Backend Setup

1. Navigate to the `backend/` folder
2. Install dependencies: `composer install`
3. Set up the `.env` file and configure the database connection
4. Run migrations: `php artisan migrate`
5. Start the development server: `php artisan serve`

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
