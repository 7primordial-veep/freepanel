#!/bin/bash
php8.1 ./console doctrine:database:drop --force &> /dev/null
php8.1 ./console doctrine:database:create &> /dev/null
php8.1 ./console doctrine:schema:update --force &> /dev/null
php8.1 ./console doctrine:schema:update --force
APP_ENV=dev php8.1 ./console doctrine:fixtures:load -n
php8.1 ./clpctl vhost-templates:import
