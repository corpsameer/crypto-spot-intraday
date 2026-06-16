# Crypto Spot Intraday Gainer Scanner

Crypto Spot Intraday Gainer Scanner is a personal-use CoinDCX spot intraday / 2–3 day research and simulation tool.

This is a personal-use simulation/research tool. It does not place real trades. Real trading is disabled in the MVP.

## Tech Stack

- Laravel
- Blade
- MySQL
- Python for future data/scanner tasks
- CoinDCX spot market research only

## Route Prefix

All application routes are served under `/cryptospot`.

Important routes:

- `GET /cryptospot`
- `GET /cryptospot/login`
- `POST /cryptospot/login`
- `GET /cryptospot/dashboard`
- `POST /cryptospot/logout`

No public registration route is provided.

## Local Setup

Install PHP dependencies:

```bash
composer install
```

Create the local environment file and application key:

```bash
cp .env.example .env
php artisan key:generate
```

Create the MySQL database if it does not already exist:

```sql
CREATE DATABASE crypto_spot_intraday CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Run migrations and seed the default admin user:

```bash
php artisan migrate --seed
```

Review routes:

```bash
php artisan route:list
```

Serve locally:

```bash
php artisan serve
```

Then open:

```text
http://127.0.0.1:8000/cryptospot
```

## Default Login

- Email: `admin@example.com`
- Password: `password`

The password is hashed by the Laravel `Hash` facade in the database seeder.

## Environment

The default local environment targets MySQL database `crypto_spot_intraday` with credentials configured in `.env`. Do not hardcode database credentials in application code.

## MVP Scope

Task 1 only sets up the Laravel foundation, single-user login, dashboard shell, and module placeholders. CoinDCX API integration, scanners, scoring, simulated trades, analytics, and real market data collection are intentionally not implemented yet.
