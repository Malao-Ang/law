FROM php:8.4-fpm

# 1. Set environment variables
ENV DEBIAN_FRONTEND=noninteractive

# 2. Install System Dependencies 
# เพิ่ม poppler-utils เพื่อให้ใช้คำสั่ง pdftotext ได้
RUN apt-get update && apt-get install -y --no-install-recommends \
    libzip-dev unzip libxml2-dev libpng-dev libjpeg-dev libfreetype6-dev libonig-dev \
    locales curl git pkg-config autoconf g++ make libssl-dev \
    poppler-utils \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# 3. Install PHP extensions
RUN docker-php-ext-install zip xml pdo pdo_mysql mbstring bcmath \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd

# 4. Install MongoDB extension (ถ้าไม่ได้ใช้ MongoDB สามารถลบบรรทัดนี้ออกเพื่อลดขนาด Image ได้ครับ)
RUN pecl install mongodb && docker-php-ext-enable mongodb

# 5. Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 6. Set working directory
WORKDIR /var/www/html

# 7. Set Permissions (จัดการเรื่องสิทธิ์การเขียนไฟล์เพื่อเลี่ยง Readonly Database/Folder)
RUN chown -R www-data:www-data /var/www/html

# 8. Expose port
EXPOSE 9000

# 9. Start PHP-FPM
CMD ["php-fpm"]
