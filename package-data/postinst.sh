#!/bin/sh
set -e

if [ -n "$DEBUG" ]; then
  set -x
fi

OS_NAME=
OS_VERSION=
ARCH=
export DEBIAN_FRONTEND=noninteractive

setOSInfo()
{
  [ -e '/bin/uname' ] && uname='/bin/uname' || uname='/usr/bin/uname'
  ARCH=`uname -m`
  OPERATING_SYSTEM=`uname -s`
  if [ "$OPERATING_SYSTEM" = 'Linux' ]; then
    if [ -e '/etc/debian_version' ]; then
      if [ -e '/etc/lsb-release' ]; then
        . /etc/lsb-release
        OS_NAME=$DISTRIB_ID
        OS_VERSION=$DISTRIB_RELEASE
      else
        OS_NAME='Debian'
        DEBIAN_VERSION=$(cat /etc/debian_version)
        OS_VERSION=`echo $DEBIAN_VERSION | cut -d "." -f -1`
      fi
    else
      die "Unable to detect Debian or Ubuntu."
    fi
  else
    die "Operating System needs to be Linux."
  fi
}

baseSetup()
{
  echo "LC_ALL="en_US.UTF-8"" > /etc/environment
  echo 'APT::Periodic::Update-Package-Lists "0";' > /etc/apt/apt.conf.d/20auto-upgrades
  echo 'APT::Periodic::Unattended-Upgrade "0";' >> /etc/apt/apt.conf.d/20auto-upgrades
  /usr/sbin/locale-gen

  if [ "$IS_LXC" = "0" ]; then
    if ! grep -q 'fs.file-max = 500000' /etc/sysctl.conf; then
        echo "fs.file-max = 500000" >> /etc/sysctl.conf
        sysctl -p
        echo "* soft nofile 60000"  >> /etc/security/limits.conf
        echo "* hard nofile 60000"  >> /etc/security/limits.conf
        echo "* soft core 0" >> /etc/security/limits.conf
      fi
  fi

  echo kernel.core_pattern= > /etc/sysctl.d/50-coredump.conf && /lib/systemd/systemd-sysctl
  chmod o-x /bin/su

  if [ -d "/etc/cloud/cloud.cfg.d" ]; then
    echo "manage_etc_hosts: false" > /etc/cloud/cloud.cfg.d/100-clp.cfg
    echo "ssh: false" >> /etc/cloud/cloud.cfg.d/100-clp.cfg
    #echo "preserve_hostname: true" >> /etc/cloud/cloud.cfg.d/100-clp.cfg
  fi

  update-alternatives --set php /usr/bin/php8.4
}

setupSshRestrictionRules()
{
  local SECURITY_ACCESS_FILE="/etc/security/access.conf"
  echo "session optional pam_umask.so umask=0002" >> /etc/pam.d/common-session
  if [ "$IS_LXC" = "0" ]; then
    echo "PasswordAuthentication yes" >> /etc/ssh/sshd_config
    sed -i "s/.*PermitRootLogin.*/PermitRootLogin yes/g" /etc/ssh/sshd_config
  fi
  [ -f /etc/ssh/sshd_config.d/60-cloudimg-settings.conf ] && > /etc/ssh/sshd_config.d/60-cloudimg-settings.conf
  echo "DenyUsers clp" >> /etc/ssh/sshd_config
  echo "account required pam_access.so" > /tmp/cloudpanel/pam_sshd
  cat /etc/pam.d/sshd >> /tmp/cloudpanel/pam_sshd
  cat /tmp/cloudpanel/pam_sshd > /etc/pam.d/sshd
  /etc/init.d/ssh restart
}

setupCloudPanel()
{
  mkdir -p /home/clp/htdocs/app/data/
  cp -R /tmp/cloudpanel/data/cloudpanel/files/ /home/clp/htdocs/app/
  cp /tmp/cloudpanel/data/cloudpanel/.env /home/clp/htdocs/app/files/.env
  chown -R clp:clp /home/clp/
  chmod -R 770 /home/clp/
  chmod 777 /usr/bin/clpctl
  su -s /bin/bash -c "/usr/bin/php8.1 -c /home/clp/services/php-fpm/cli/php.ini /home/clp/htdocs/app/files/bin/console doctrine:database:drop --force" clp
  su -s /bin/bash -c "/usr/bin/php8.1 -c /home/clp/services/php-fpm/cli/php.ini /home/clp/htdocs/app/files/bin/console doctrine:database:create" clp
  su -s /bin/bash -c "/usr/bin/php8.1 -c /home/clp/services/php-fpm/cli/php.ini /home/clp/htdocs/app/files/bin/console doctrine:schema:update --force &> /dev/null" clp
  su -s /bin/bash -c "/usr/bin/php8.1 -c /home/clp/services/php-fpm/cli/php.ini /home/clp/htdocs/app/files/bin/console doctrine:schema:update --force" clp
  su -s /bin/bash -c "APP_ENV=dev /usr/bin/php8.1 -c /home/clp/services/php-fpm/cli/php.ini /home/clp/htdocs/app/files/bin/console doctrine:fixtures:load -n" clp
  su -s /bin/bash -c "/usr/bin/php8.1 -c /home/clp/services/php-fpm/cli/php.ini /home/clp/htdocs/app/files/bin/console doctrine:migrations:sync-metadata-storage -n" clp
  su -s /bin/bash -c "/usr/bin/php8.1 -c /home/clp/services/php-fpm/cli/php.ini /home/clp/htdocs/app/files/bin/console doctrine:migrations:version --add --all -n" clp
  su -s /bin/bash -c "/usr/bin/clpctl vhost-templates:import &> /dev/null" clp
  su -s /bin/bash -c "/usr/bin/clpctl cloudflare:update:ips &> /dev/null" clp
  echo $CLOUD > /home/clp/.cloud
  if [ -n "$CLOUD" ]; then
    su -s /bin/bash -c "/usr/bin/php8.1 -c /home/clp/services/php-fpm/cli/php.ini /home/clp/htdocs/app/files/bin/console doctrine:query:sql \"INSERT INTO config (id, key, value) VALUES (NULL, 'cloud', '"$CLOUD"');\" &> /dev/null" clp
  fi
  if [ -n "$IP" ]; then
    su -s /bin/bash -c "/usr/bin/php8.1 -c /home/clp/services/php-fpm/cli/php.ini /home/clp/htdocs/app/files/bin/console doctrine:query:sql \"INSERT INTO config (id, key, value) VALUES (NULL, 'masquerade_address', '"$IP"');\" &> /dev/null" clp
  fi
  rm -rf /home/clp/htdocs/app/files/var/cache/dev/
  rm -rf /home/clp/htdocs/app/files/var/log/dev.log
  systemctl restart clp-php-fpm
  systemctl restart clp-nginx
}

setupMySQL()
{
  MYSQL_ROOT_PASSWORD="root"
  mysqladmin -u root password $MYSQL_ROOT_PASSWORD

  case $DB_ENGINE in
    "MYSQL_5.7")
      mysql -uroot -p$MYSQL_ROOT_PASSWORD mysql < /tmp/cloudpanel/data/database/mysql/5.7/config.sql
      systemctl stop mysql
      mv /var/lib/mysql /home/mysql/
      cp /tmp/cloudpanel/data/database/mysql/5.7/my.cnf /etc/mysql/my.cnf
    ;;
    "MYSQL_8.0")
      mysql -uroot -p$MYSQL_ROOT_PASSWORD mysql < /tmp/cloudpanel/data/database/mysql/8.0/config.sql
      systemctl stop mysql
      mv /var/lib/mysql /home/mysql/
      cp /tmp/cloudpanel/data/database/mysql/8.0/mysql.conf.d/mysqld.cnf /etc/mysql/mysql.conf.d/mysqld.cnf
    ;;
    "MYSQL_8.4")
      systemctl stop mysql
      mv /var/lib/mysql /home/mysql/
      cp /tmp/cloudpanel/data/database/mysql/8.4/mysql.conf.d/mysqld.cnf /etc/mysql/mysql.conf.d/mysqld.cnf
      systemctl restart mysql
      mysql -uroot -p$MYSQL_ROOT_PASSWORD mysql < /tmp/cloudpanel/data/database/mysql/8.4/config.sql
    ;;
    "MARIADB_10.6")
      mysql -uroot -p$MYSQL_ROOT_PASSWORD mysql < /tmp/cloudpanel/data/database/mariadb/10.6/config.sql
      systemctl stop mysql
      mv /var/lib/mysql /home/mysql/
      mkdir -p /etc/systemd/system/mariadb.service.d/
      cp /tmp/cloudpanel/data/database/mariadb/10.6/protect.conf /etc/systemd/system/mariadb.service.d/protect.conf
      rm -rf /etc/mysql/mariadb.conf.d/*
      cp /tmp/cloudpanel/data/database/mariadb/10.6/mariadb.conf.d/100-cloudpanel.cnf /etc/mysql/mariadb.conf.d/
      systemctl daemon-reload
    ;;
    "MARIADB_10.11")
      mysql -uroot -p$MYSQL_ROOT_PASSWORD mysql < /tmp/cloudpanel/data/database/mariadb/10.11/config.sql
      systemctl stop mysql
      mv /var/lib/mysql /home/mysql/
      mkdir -p /etc/systemd/system/mariadb.service.d/
      cp /tmp/cloudpanel/data/database/mariadb/10.11/protect.conf /etc/systemd/system/mariadb.service.d/protect.conf
      rm -rf /etc/mysql/mariadb.conf.d/*
      cp /tmp/cloudpanel/data/database/mariadb/10.11/mariadb.conf.d/100-cloudpanel.cnf /etc/mysql/mariadb.conf.d/
      systemctl daemon-reload
    ;;
    "MARIADB_11.4")
      mysql -uroot -p$MYSQL_ROOT_PASSWORD mysql < /tmp/cloudpanel/data/database/mariadb/11.4/config.sql
      systemctl stop mysql
      mv /var/lib/mysql /home/mysql/
      mkdir -p /etc/systemd/system/mariadb.service.d/
      cp /tmp/cloudpanel/data/database/mariadb/11.4/protect.conf /etc/systemd/system/mariadb.service.d/protect.conf
      rm -rf /etc/mysql/mariadb.conf.d/*
      cp /tmp/cloudpanel/data/database/mariadb/11.4/mariadb.conf.d/100-cloudpanel.cnf /etc/mysql/mariadb.conf.d/
      systemctl daemon-reload
    ;;
   "MARIADB_11.8")
      mysql -uroot -p$MYSQL_ROOT_PASSWORD mysql < /tmp/cloudpanel/data/database/mariadb/11.8/config.sql
      systemctl stop mysql
      mv /var/lib/mysql /home/mysql/
      mkdir -p /etc/systemd/system/mariadb.service.d/
      cp /tmp/cloudpanel/data/database/mariadb/11.8/protect.conf /etc/systemd/system/mariadb.service.d/protect.conf
      rm -rf /etc/mysql/mariadb.conf.d/*
      cp /tmp/cloudpanel/data/database/mariadb/11.8/mariadb.conf.d/100-cloudpanel.cnf /etc/mysql/mariadb.conf.d/
      systemctl daemon-reload
      ;;
    *)
      echo "Database Engine $DB_ENGINE not supported."
    ;;
  esac

  if [ "$OS_NAME" = "Ubuntu" ]; then
    echo "alias /var/lib/mysql/ -> /home/mysql/," >> /etc/apparmor.d/tunables/alias
    systemctl restart apparmor
  fi
  systemctl restart mysql
}

setupUfw()
{
  /usr/sbin/ufw --force reset
  /usr/sbin/ufw allow ssh
  /usr/sbin/ufw allow http
  /usr/sbin/ufw allow https
  /usr/sbin/ufw allow 8433:8443/tcp
  /usr/sbin/ufw allow 443/udp
  /usr/sbin/ufw --force enable
  systemctl enable ufw
}

setupComposer()
{
  cp /tmp/cloudpanel/data/composer/composer /usr/local/bin/composer
  chmod 777 /usr/local/bin/composer
  /usr/bin/php8.4 /usr/local/bin/composer self-update -q
}

setupLogrotate()
{
  rm -f /etc/cron.daily/logrotate
  sed -i "s/OnCalendar=daily/OnCalendar=*-*-* 23:58:00/g" /lib/systemd/system/logrotate.timer
  cp /tmp/cloudpanel/data/logrotate/systemd/logrotate.service /lib/systemd/system/
  systemctl daemon-reload
}

setupMotd()
{
  rm -f /etc/motd
  rm -rf /etc/update-motd.d/*
  cp /tmp/cloudpanel/data/motd/10-cloudpanel /etc/update-motd.d/10-cloudpanel
  case "$CLOUD" in
    aws)
      sed -i 's/^CLOUDPANEL_URL=.*/CLOUDPANEL_URL="https:\/\/$(curl -s -H "X-aws-ec2-metadata-token: $(curl -X PUT "http:\/\/169.254.169.254\/latest\/api\/token" -H "X-aws-ec2-metadata-token-ttl-seconds: 21600")" http:\/\/169.254.169.254\/latest\/meta-data\/public-ipv4):8443"/' /etc/update-motd.d/10-cloudpanel
    ;;
    do)
      sed -i 's/^CLOUDPANEL_URL=.*/CLOUDPANEL_URL="https:\/\/$(curl -s http:\/\/169.254.169.254\/metadata\/v1\/interfaces\/public\/0\/ipv4\/address):8443"/' /etc/update-motd.d/10-cloudpanel
    ;;
    hetzner)
      sed -i 's/^CLOUDPANEL_URL=.*/CLOUDPANEL_URL="https:\/\/$(curl -s http:\/\/169.254.169.254\/hetzner\/v1\/metadata\/public-ipv4):8443"/' /etc/update-motd.d/10-cloudpanel
    ;;
    gce)
      sed -i 's/^CLOUDPANEL_URL=.*/CLOUDPANEL_URL="https:\/\/$(curl -H "Metadata-Flavor: Google" -s http:\/\/metadata\/computeMetadata\/v1\/instance\/network-interfaces\/0\/access-configs\/0\/external-ip):8443"/' /etc/update-motd.d/10-cloudpanel
    ;;
    *)
    ;;
  esac
  chmod 775 /etc/update-motd.d/10-cloudpanel
}

setupProftpd()
{
  if [ -n "$IP" ]; then
    sed -i "s/MasqueradeAddress .*/MasqueradeAddress $IP/g" /etc/proftpd/proftpd.conf
    systemctl restart proftpd
  fi
}

setPermissions()
{
  chown -R clp:clp /home/clp/
  chmod -R 700 /home/clp/scripts/
  chmod -R 770 /home/clp/
  chmod 700 /home/clp/
  chmod 755 /usr/bin/clpctl
  chmod 700 /usr/bin/clp-update
  chmod 755 /usr/bin/wp
  chown root:root /usr/bin/clpctlWrapper
  chown root:root /usr/bin/clp-update
  chmod 700 /usr/bin/clpctlWrapper
  chmod 755 /usr/local/bin/composer
  chmod 700 /etc/nginx/
  chmod -R 777 /var/lib/nginx/
  chmod 775 /etc/update-motd.d/10-cloudpanel
  chmod 701 /home
}

installCleanUp()
{
  deleteTmpDirectory
  if [ $(dpkg-query -W -f='${Status}' dphys-swapfile 2>/dev/null | grep -c "ok installed") -eq 1 ];
  then
    systemctl stop dphys-swapfile
    swapoff -a
    systemctl restart dphys-swapfile
  fi
  if [ "$CLOUD" = "aws" ] && [ "$CREATE_AMI" = "1" ] && [ -f "/home/admin/.ssh/authorized_keys" ]; then
    echo "" > /home/admin/.ssh/authorized_keys
    echo "" > /root/.ssh/authorized_keys
  fi
  if [ "$CLOUD" = "aws" ] && [ "$CREATE_AMI" = "1" ] && [ -f "/home/ubuntu/.ssh/authorized_keys" ]; then
    echo "" > /home/ubuntu/.ssh/authorized_keys
    echo "" > /root/.ssh/authorized_keys
  fi
}

updateCloudPanel()
{
  chmod -R 777 /home/clp/scripts/
  /home/clp/scripts/create_backup.sh
  systemctl stop clp-nginx
  systemctl stop clp-php-fpm
  rm -rf /home/clp/htdocs/app_bak
  mv /home/clp/htdocs/app/ /home/clp/htdocs/app_bak
  mkdir -p /home/clp/htdocs/app/data/
  cp -R /tmp/cloudpanel/data/cloudpanel/files /home/clp/htdocs/app/files
  cp /tmp/cloudpanel/data/cloudpanel/.env /home/clp/htdocs/app/files/.env
  rm -rf /home/clp/htdocs/app/files/var/cache/*
  cp /home/clp/htdocs/app_bak/data/db.sq3 /home/clp/htdocs/app/data/db.sq3
  chown -R clp:clp /home/clp/
  chmod -R 750 /home/clp/htdocs/
  su -s /bin/bash -c "/usr/bin/php8.1 -c /home/clp/services/php-fpm/cli/php.ini /home/clp/htdocs/app/files/bin/console doctrine:migrations:migrate -n" clp
  su -s /bin/bash -c "/usr/bin/clpctl vhost-templates:import &> /dev/null" clp
  rm -rf /home/clp/htdocs/app_bak/
  systemctl restart clp-php-fpm
  systemctl restart clp-nginx
}

setupCloudPanelCrontabs()
{
  CLOUD=$(cat /home/clp/.cloud)
  if [ "$CLOUD" = "aws" ]; then
    cp /tmp/cloudpanel/data/clp/crontab/clp-aws /etc/cron.d/clp-aws
  fi
  if [ "$CLOUD" = "do" ]; then
    cp /tmp/cloudpanel/data/clp/crontab/clp-do /etc/cron.d/clp-do
  fi
  if [ "$CLOUD" = "hetzner" ]; then
    cp /tmp/cloudpanel/data/clp/crontab/clp-hetzner /etc/cron.d/clp-hetzner
  fi
  if [ "$CLOUD" = "gce" ]; then
    cp /tmp/cloudpanel/data/clp/crontab/clp-gce /etc/cron.d/clp-gce
  fi
  if [ "$CLOUD" = "vultr" ]; then
    cp /tmp/cloudpanel/data/clp/crontab/clp-vultr /etc/cron.d/clp-vultr
  fi
}

setupCloudpanelServices()
{
  cp /tmp/cloudpanel/data/clp/services/nginx/systemd/clp-nginx.service /lib/systemd/system/
  cp /tmp/cloudpanel/data/clp/services/php-fpm/systemd/clp-php-fpm.service /lib/systemd/system/
  systemctl daemon-reload
  if [ "$(systemctl is-enabled clp-nginx)" = "disabled" ]; then
    systemctl enable clp-nginx
  fi
  if [ "$(systemctl is-enabled clp-php-fpm)" = "disabled" ]; then
    systemctl enable clp-php-fpm
  fi
  systemctl restart clp-nginx
  systemctl restart clp-php-fpm
}

setupClpAgent()
{
  ARCHITECTURE=`uname -m`
  if pgrep -x "clp-agent" >/dev/null
  then
    systemctl stop clp-agent
  fi
  if [ "$ARCHITECTURE" = "aarch64" ]; then
    cp /tmp/cloudpanel/data/clp-agent/bin/aarch64/clp-agent /usr/sbin/clp-agent
  fi
  if [ "$ARCHITECTURE" = "x86_64" ]; then
    cp /tmp/cloudpanel/data/clp-agent/bin/x86_64/clp-agent /usr/sbin/clp-agent
  fi
  cp /tmp/cloudpanel/data/clp-agent/systemd/clp-agent.service /lib/systemd/system/
  systemctl daemon-reload
  if [ "$(systemctl is-enabled clp-agent)" = "disabled" ]; then
    systemctl enable clp-agent
  fi
  systemctl restart clp-agent
}

deleteTmpDirectory()
{
  rm -rf /tmp/cloudpanel/
}

setOSInfo

if [ "$1" = "configure" ]; then
  if [ ! -d "/home/clp/htdocs/app/files" ]; then
    baseSetup
    setupSshRestrictionRules
    setupMySQL
    setupCloudpanelServices
    setupCloudPanel
    setupCloudPanelCrontabs
    setupProftpd
    setupUfw
    setupComposer
    setupMotd
    setupLogrotate
    setupClpAgent
    setPermissions
    installCleanUp
  else
    setupCloudpanelServices
    updateCloudPanel
    setupCloudPanelCrontabs
    setupMotd
    setupClpAgent
    setPermissions
    deleteTmpDirectory
  fi
fi


