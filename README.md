# TraMatch

TraMatch is a web- and mobile-friendly travel itinerary recommender for Filipino local travelers. It uses a user’s travel profile and swipe decisions to identify destinations that fit their interests, budget, group size, and trip duration.

This repository currently covers the work completed through **Chunk 6: Swipe Discovery**.

## Current product flow

```text
Register or log in
        ↓
Complete travel preferences
        ↓
Swipe destination cards
        ↓
Like or pass destinations
        ↓
View liked destination recommendations
```

## Implemented features

- Laravel Breeze authentication
- User profiles
- Destination catalog for Luzon
- Destination tags
- Budget filtering
- Weighted travel preferences
- Preference-based destination matching
- Explainable recommendation scores
- Interactive swipe discovery deck
- Like and Pass actions
- Keyboard controls for the swipe deck
- Saved swipe decisions
- Passed destinations excluded from the deck
- Liked destinations prioritized in recommendations
- Resettable discovery deck
- Responsive Tropical Festival visual design
- MySQL or MariaDB database support
- Vite and Tailwind CSS development workflow

Itinerary generation, maps, reviews, administration, PWA support, automated testing, and deployment improvements are planned for later chunks.

## Technology stack

- PHP
- Laravel 13
- Laravel Breeze
- Blade
- Tailwind CSS 4
- Vite
- JavaScript
- MySQL or MariaDB
- Leaflet and OpenStreetMap planned for the map module
- Git and GitHub

## Requirements

Install the following before setting up the project:

- PHP 8.4 or a Laravel-supported PHP version
- Composer
- Node.js 22.12.0 or newer
- npm
- MySQL or MariaDB
- Git

On Windows, XAMPP can provide MySQL. Start MySQL in the XAMPP Control Panel before running migrations.

## Clone the project

```powershell
git clone https://github.com/YOUR-USERNAME/tramatch.git
cd tramatch
```

Replace the repository URL with the actual GitHub repository URL.

## Install PHP dependencies

```powershell
composer install
```

## Install JavaScript dependencies

Use `npm ci` when `package-lock.json` is committed:

```powershell
npm ci --include=optional
```

If the project does not contain `package-lock.json`, use:

```powershell
npm install --include=optional
```

Node `22.12.0` or newer is required by the current Vite packages.

## Create the environment file

In PowerShell:

```powershell
Copy-Item .env.example .env
```

Generate the application key:

```powershell
php artisan key:generate
```

## Create the database

Create a MySQL database named `tramatch`.

Using the MySQL console:

```sql
CREATE DATABASE tramatch CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Using XAMPP, open phpMyAdmin at:

```text
http://localhost/phpmyadmin
```

Select **New**, then create a database named:

```text
tramatch
```

## Configure `.env`

Update the database values in `.env`:

```dotenv
APP_NAME=TraMatch
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tramatch
DB_USERNAME=root
DB_PASSWORD=
```

For a default XAMPP installation, the MySQL username is usually `root` and the password is usually blank. Change the values if your local MySQL installation uses a different username, password, or port.

Never commit `.env` to GitHub.

## Create the database tables

Run all migrations and seed the development database:

```powershell
php artisan migrate --seed
```

To view migration status:

```powershell
php artisan migrate:status
```

The repository includes migrations for the following tables:

| Table | Purpose |
|---|---|
| `users` | Accounts, authentication, and roles |
| `travel_profiles` | Budget, group size, trip duration, and region |
| `tags` | Travel interests such as beach, nature, and history |
| `user_preferences` | Weighted user interests |
| `destinations` | Destination information, coordinates, costs, and tags |
| `destination_tag` | Destination-to-tag relationships |
| `destination_swipes` | User Like and Pass decisions |
| `itineraries` | Saved itinerary records prepared for the next module |
| `itinerary_days` | Day records for saved itineraries |
| `itinerary_items` | Destination items assigned to itinerary days |
| `reviews` | Review records prepared for the next module |

The seeders add starter tags and sample Luzon destinations:

```powershell
php artisan db:seed
```

The seeders use `updateOrCreate`, so they can be run again during local development.

## Start the application

Use two PowerShell terminals.

Terminal 1 starts Vite:

```powershell
npm run dev
```

Terminal 2 starts Laravel:

```powershell
php artisan serve
```

Open the application at:

```text
http://localhost:8000
```

Use the Laravel URL as the main application URL. Vite usually runs on port `5173` and only serves frontend assets.

## Available routes

| URL | Purpose |
|---|---|
| `/` | Laravel welcome page |
| `/register` | New account registration |
| `/login` | User login |
| `/dashboard` | Dashboard or first-time preference redirect |
| `/preferences` | Travel profile and weighted interests |
| `/destinations` | Public destination catalog |
| `/destinations/{slug}` | Destination details |
| `/recommendations` | Liked destination recommendations |
| `/discover` | Swipe discovery deck |
| `/discover/swipes` | Saves Like or Pass actions |
| `/discover/reset` | Resets a user’s swipe deck |
| `/profile` | Breeze user profile page |

The preference, recommendation, and discovery routes require authentication.

## First-time user flow

A user without a travel profile is redirected from `/dashboard` to `/preferences`.

After saving preferences, the user is sent to `/discover`.

The user can then:

- Swipe right or click **Like** to save a destination.
- Swipe left or click **Pass** to exclude a destination.
- Use the left and right keyboard arrows.
- Reset the deck to start again.
- Open `/recommendations` to view liked destinations.

Changing the user’s preferences clears their previous swipe decisions and creates a fresh discovery deck.

## Recommendation logic

The current recommendation service only returns destinations liked during discovery.

The score combines the user profile and swipe behavior:

```text
70% weighted profile match + 30% liked-by-swipe bonus
```

The system also filters destinations by the user’s selected budget. Passed destinations are excluded from recommendations.

## Creating an administrator account

Register an account first, then run:

```powershell
php artisan tinker
```

Inside Tinker:

```php
$user = App\Models\User::where('email', 'admin@example.com')->firstOrFail();
$user->role = 'admin';
$user->save();
exit
```

Replace the email address with the account you want to make an administrator. The administrative interface is planned for a later chunk.

## Frontend commands

Start Vite with live updates:

```powershell
npm run dev
```

Create a production frontend build:

```powershell
npm run build
```

The compiled assets are written to:

```text
public/build
```

## Useful Laravel commands

Clear cached configuration, routes, and views:

```powershell
php artisan optimize:clear
```

List routes:

```powershell
php artisan route:list
```

Check migration status:

```powershell
php artisan migrate:status
```

Open Laravel Tinker:

```powershell
php artisan tinker
```

## Existing local databases

If a developer already has an older local database, do not edit an already-ran migration and expect Laravel to run it again. Use a new corrective migration for schema changes.

The project may contain corrective migrations for older incomplete local tables. Keep those migration files in GitHub because a fresh clone needs them to reproduce the current schema.

For a completely disposable local database, this command rebuilds all tables and runs the seeders:

```powershell
php artisan migrate:fresh --seed
```

This deletes all local users, preferences, destinations, swipes, and other database records. Never run it against production data.

## Collaboration workflow

Create a feature branch before changing code:

```powershell
git switch main
git pull origin main
git switch -c feature/your-feature-name
```

Check your changes:

```powershell
git status
git diff
```

Commit related changes together:

```powershell
git add .
git commit -m "Describe the completed change"
```

Push the branch:

```powershell
git push -u origin feature/your-feature-name
```

Open a pull request on GitHub. Ask another team member to review the changes before merging into `main`.

Pull the latest changes before starting new work:

```powershell
git switch main
git pull origin main
```

### Merge the `bon` branch into `main`

Save or commit any current local changes first:

```powershell
git status
git add .
git commit -m "Save current work"
```

Fetch the latest remote branches:

```powershell
git fetch origin
```

Inspect the changes in `bon`:

```powershell
git diff origin/main...origin/bon --stat
git log --oneline --decorate --graph origin/main..origin/bon
```

Update your local `main` branch:

```powershell
git switch main
git pull origin main
```

Merge the branch:

```powershell
git merge --no-ff origin/bon
```

If there are no conflicts, run the application tests:

```powershell
php artisan test
npm run build
```

Push the merged `main` branch:

```powershell
git push origin main
```

If Git reports conflicts, check the affected files:

```powershell
git status
```

Resolve the conflict markers, then run:

```powershell
git add .
git commit -m "Resolve bon merge conflicts"
git push origin main
```

To cancel the merge before committing:

```powershell
git merge --abort
```

Do not force-push to `main`. After confirming the merge is successful, the local branch can be removed with:

```powershell
git branch -d bon
```

The remote branch can be removed only after confirming that nobody still needs it:

```powershell
git push origin --delete bon
```

## Files that should not be committed

Do not commit:

```text
.env
/vendor
/node_modules
/public/build
/storage/*.key
```

Laravel’s default `.gitignore` already excludes most generated and sensitive files. Confirm that `.env` is ignored before pushing:

```powershell
git status --ignored
```

## Current project status

Completed or actively implemented:

- Project setup
- Tropical Festival design system
- Database models and migrations
- Destination catalog and seed data
- User preferences and weighted matching
- Swipe discovery
- Itinerary generation
- Leaflet destination and itinerary maps
- Reviews and ratings
- Administrator destination management
- Destination archive and permanent removal options
- PWA foundation
- Editorial front page
- Smooth scrolling and homepage motion
- Login and registration visual redesign
- Page transitions for supported internal navigation

Current polish work:

- Front-page intro overlay
- Mobile responsiveness
- Cross-browser transitions
- Accessibility review
- Test coverage updates
- Database and migration cleanup

Not yet ready for production:

- Full production deployment
- Final security audit
- Final accessibility audit
- Final performance audit
- Complete automated test coverage
