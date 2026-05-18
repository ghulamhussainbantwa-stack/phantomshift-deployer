# PhantomShift — Laravel Blue-Green 
https://packagist.org/users/ghulamhussainbantwa-stack/packages/

https://laravel-news.com/account/links

Zero downtime Blue-Green deployment package for Laravel.
Deploy without your users ever noticing.

## Installation

```bash
composer require phantomshift/laravel-deployer
```

## Usage

### Deploy
```bash
php artisan deploy:blue-green v1.0.0
```

### Deploy without confirm
```bash
php artisan deploy:blue-green v1.0.0 --force
```

### Rollback
```bash
php artisan deploy:rollback
```

### See all releases
```bash
php artisan deploy:rollback --list
```

## How it works
