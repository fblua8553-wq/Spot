# ১. অফিশিয়াল PHP-Apache ইমেজ ব্যবহার করা হচ্ছে
FROM php:8.2-apache

# ২. MySQL (mysqli) এক্সটেনশন ইনস্টল করা হচ্ছে যা আপনার config.php-এর জন্য দরকার
RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli

# ৩. Apache-এর Rewrite Module এনাবল করা (ভবিষ্যতের রাউটিংয়ের জন্য ভালো)
RUN a2enmod rewrite

# ૪. আপনার প্রজেক্টের সব ফাইল কন্টেইনারের ভেতরের htdocs-এ কপি করা হচ্ছে
COPY . /var/www/html/

# 📂 ফাইলের পারমিশন ঠিক করা
RUN chown -R www-data:www-data /var/www/html

# 🌐 পোর্ট ৮০ এক্সপোজ করা
EXPOSE 80
