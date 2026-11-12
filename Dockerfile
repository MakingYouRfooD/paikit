FROM php:8.2-apache

# Install system dependencies and PostgreSQL / MySQL extensions
RUN apt-get update && apt-get install -y \
    libpq-dev \
    ca-certificates \
    curl \
    && docker-php-ext-install pdo pdo_pgsql pdo_mysql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite for .htaccess clean URLs
RUN a2enmod rewrite

# Enforce single MPM prefork for mod_php (prevents AH00534: More than one MPM loaded)
RUN a2dismod mpm_event mpm_worker 2>/dev/null || true && a2enmod mpm_prefork

# Enable AllowOverride All so .htaccess works inside /var/www/html
RUN sed -ri -e 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html/

# Set proper permissions for web server
RUN chown -R www-data:www-data /var/www/html

# Configure Apache to ensure only prefork MPM is active and listen on dynamic PORT
RUN printf '#!/bin/bash\n\
rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf\n\
ln -sf /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load\n\
ln -sf /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf\n\
PORT="${PORT:-80}"\n\
sed -i "s/Listen [0-9]*/Listen ${PORT}/g" /etc/apache2/ports.conf\n\
sed -i "s/<VirtualHost \\*:[0-9]*>/<VirtualHost \\*:${PORT}>/g" /etc/apache2/sites-available/000-default.conf\n\
exec apache2-foreground\n' > /usr/local/bin/entrypoint.sh \
    && chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

CMD ["/usr/local/bin/entrypoint.sh"]
