# Use the official PHP 8.1 image with Apache
FROM php:8.1-apache

# Enable Apache mod_rewrite and mod_ssl
RUN a2enmod rewrite
RUN a2enmod ssl

# Install cron and any necessary packages
RUN apt-get update && apt-get install -y \
    nano \
    cron \
    && rm -rf /var/lib/apt/lists/*

# Add the cron job to execute the PHP script every minute
#RUN echo "* * * * * root cd /var/www/html && /usr/local/bin/php /var/www/html/run.php >> /var/log/cron.log 2>&1" > /etc/cron.d/my-cron-job
RUN echo "0 12 * * * root cd /var/www/html && /usr/local/bin/php /var/www/html/run.php >> /var/log/cron.log 2>&1" > /etc/cron.d/my-cron-job
RUN echo "0 20 * * * root cd /var/www/html && /usr/local/bin/php /var/www/html/run.php >> /var/log/cron.log 2>&1" >> /etc/cron.d/my-cron-job

# Give execution rights on the cron job
RUN chmod 0644 /etc/cron.d/my-cron-job

# Apply cron job
RUN crontab /etc/cron.d/my-cron-job

# Create the log file to be able to run tail
RUN touch /var/log/cron.log

## Copy the PHP script to the appropriate directory
COPY index.php /var/www/html/

# Ensure Apache can access the index.php file
RUN chown -R www-data:www-data /var/www/html/

# Expose ports
EXPOSE 80
EXPOSE 443

# Run cron and Apache in the foreground
CMD ["sh", "-c", "cron && tail -f /var/log/cron.log & apache2-foreground"]
