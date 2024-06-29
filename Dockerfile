# Use the official PHP 8.1 image from the Docker Hub
FROM php:8.1-apache

# Install dependencies
RUN apt-get update && apt-get install -y \
    python3-distutils \
    python3-pip \
    libpq-dev \
    libjpeg-dev \
    libpng-dev \
    && docker-php-ext-install pdo pdo_mysql

# Copy source code to the container
COPY . /var/www/html/

# Set permissions (optional but recommended)
RUN chown -R www-data:www-data /var/www/html

# Expose port 80
EXPOSE 80
