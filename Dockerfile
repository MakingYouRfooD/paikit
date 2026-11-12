FROM php:8.2-apache

# Install system dependencies and PostgreSQL / MySQL extensions
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pdo_mysql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite for .htaccess clean URLs
RUN a2enmod rewrite

# Enable AllowOverride All so .htaccess works inside /var/www/html
RUN sed -ri -e 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html/

# Set proper permissions for web server
RUN chown -R www-data:www-data /var/www/html

# Configure Apache to listen on Render's dynamic PORT environment variable (default 80)
RUN printf '#!/bin/bash\n\
PORT="${PORT:-80}"\n\
sed -i "s/Listen [0-9]*/Listen ${PORT}/g" /etc/apache2/ports.conf\n\
sed -i "s/<VirtualHost \\*:[0-9]*>/<VirtualHost \\*:${PORT}>/g" /etc/apache2/sites-available/000-default.conf\n\
exec apache2-foreground\n' > /usr/local/bin/entrypoint.sh \
    && chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

CMD ["/usr/local/bin/entrypoint.sh"]
