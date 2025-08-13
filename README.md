## About Test Chat

Test Chat is a simple chat application built with Laravel and JS. It allows users to send and receive messages in real-time.

### Features
- Real-time messaging
- User authentication
- Message history
- Send images and files
- Typing indicators
- Online/offline status

### Requirements
- PHP 8.0 or higher
- Composer
- Node.js and npm
- MySQL or SQLite
- Laravel 12

### Installation
1. Clone the repository:
   ```bash
   git clone rwal127/laravel-chat.git
   cd laravel-chat
    ```
2. Install dependencies:
    ```bash
    composer install
    npm install
    npm run build
    ```
3. Copy the `.env.example` file to `.env` and update the database configuration:
    ```bash
    cp .env.example .env
    ```
4. Generate the application key and link the storage directory:
    ```bash
    php artisan key:generate
    php artisan storage:link
      ```
5. Add your [Pusher](https://pusher.com/) credentials to the `.env` file:
    ```env
    PUSHER_APP_ID=your_pusher_app_id
    PUSHER_APP_KEY=your_pusher_app_key
    PUSHER_APP_SECRET=your_pusher_app_secret
    PUSHER_APP_CLUSTER=your_pusher_app_cluster
    ```
   Replace `your_pusher_app_id`, `your_pusher_app_key`, `your_pusher_app_secret`, and `your_pusher_app_cluster` with your actual Pusher credentials. 
6. Add at least 2 users to private/users.json file to the `storage` directory (for testing purposes):
    ```json
    [{
            "name": "Admin",
            "login": "admin",
            "password": "administrator",
            "email": "admin@test-chat.dev",
            "is_admin": true
        },
        {
            "name": "Test User",
            "login": "testuser",
            "password": "12345678",
            "email": "testuser@test-chat.dev",
            "is_admin": false
        }]
    ```
   This file contains default user credentials for testing. You can modify it as needed.
   
7. Run the migrations & seed the database:
    ```bash
    php artisan migrate --seed
    ```
8. Start the development server:
    ```bash
    php artisan serve
    ```
9. Open your browser and go to `http://localhost:8000`.
10. Log in with one of the default users and test app in /chat.

