# Wiki Donate Frontend

The frontend of this wiki application is built with modern web technologies to ensure a responsive and user-friendly experience. It leverages the following components and libraries:

-   **Vue.js**: A progressive JavaScript framework used for building interactive user interfaces.
-   **Tailwind CSS**: A utility-first CSS framework for styling the application with ease and consistency.
-   **Pinia**: A state management library for managing the application's state in a scalable way.
-   **Nuxt.js**: A framework for building server-rendered Vue.js applications, enabling features like server-side rendering and static site generation.

### Key Features

-   **Responsive Design**: The application is fully responsive, ensuring a seamless experience across devices, from desktops to mobile phones.
-   **Rich Text Editor**: Users can create and edit content using a feature-rich editor, allowing for text formatting, image embedding, and more.
-   **Dynamic Routing**: The application supports dynamic routing for easy navigation between different pages and sections.
-   **Component-Based Architecture**: The frontend is structured with reusable Vue components, promoting code reuse and easier maintenance.

### Styling

The application uses Tailwind CSS for styling, which allows for rapid design and customization. Custom styles are added to ensure a unique look and feel aligned with the application's branding.

### State Management

Pinia is used for managing global state, providing a simple and flexible way to handle data across the application. It helps in maintaining the state of user authentication, article edits, and more.

### Build Tools

-   **Vite**: A build tool that provides a fast development environment with hot module replacement and optimized builds.
-   **ESLint & Prettier**: Tools for maintaining code quality and consistency across the codebase.

This combination of tools and technologies ensures a robust, scalable, and maintainable frontend for the wiki application.

The backend of the application is hosted in a separate repository, which can be found at [https://github.com/WikiDonate/wiki-donate-backend](https://github.com/WikiDonate/wiki-donate-backend).

## Basic Wiki Features

-   **User Authentication**: Allow users to create accounts and log in to contribute to the wiki.
-   **Page Creation and Editing**: Users can create and edit wiki pages using a rich text editor.
-   **Version Control**: Track changes to wiki pages with version history to allow reverting to previous versions.
-   **Search Functionality**: Implement search capabilities to find content easily within the wiki.
-   **Categories and Tags**: Organize pages with categories and tags for better navigation.
-   **Discussion Pages**: Enable discussion pages for each article to foster community interaction and feedback.

## Setup

Make sure to install the dependencies:

```bash
yarn install
```

## Development Server

Start the development server on `http://localhost:3000`:

```bash
yarn dev
```

## Production

Build the application for production:

```bash
yarn build

```

Locally preview production build:

```bash
yarn preview
```
