# dewslandslide-chatterbox
PHP portion of the sockets and websockets backend code for real time message exchanges between GSM server


[HOW TO SETUP - Ubuntu]
1. Pull repository to /var/www/
2. Rename the folder to chatterbox
3. Install Composer
  3.1 sudo apt-get update
  3.2 sudo apt-get install curl
  3.3 sudo curl -s https://getcomposer.org/installer | php
  3.4 sudo mv composer.phar /usr/local/bin/composer
  3.5 run: composer
4. Install Memcached
  4.1 sudo apt-get update -y
  4.2 sudo apt-get install memcached
  4.3 sudo apt-get install php-memcached
5. cd /bin 
6. php chatterbox-server.php
