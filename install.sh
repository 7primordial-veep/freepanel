#!/bin/bash -x
#
# CloudPanel Custom Installer
# Deploys CloudPanel from a git repository instead of the official apt source.
#
# Usage:
#   DB_ENGINE=MYSQL_8.4 bash install.sh
#
# Supported DB_ENGINE values depend on OS. See checkDatabaseEngine() below.
# Default: MYSQL_8.4
#
# Environment variables:
#   GIT_REPO_URL  - Git repo URL to clone from (optional if install.sh is run from a checkout)
#   GIT_BRANCH    - Branch to clone (default: master)
#   DB_ENGINE     - Database engine (default: MYSQL_8.4)
#   SWAP          - Enable swap (default: true)
#   CLOUD         - Cloud provider: aws, do, hetzner, gce, vultr (optional)
#

set -e

VERBOSE=0
OS_NAME=
OS_VERSION=
OS_CODE_NAME=
ARCH=
export IP=
export DEBIAN_FRONTEND=noninteractive
[[ -z "$CREATE_AMI" ]] && export CREATE_AMI
[[ -z "$DB_ENGINE" ]] && export DB_ENGINE="MYSQL_8.4"
[[ -z "$GIT_BRANCH" ]] && export GIT_BRANCH="master"
[[ -z "$GIT_REPO_URL" ]] && export GIT_REPO_URL="https://github.com/7primordial-veep/freepanel.git"

export IS_LXC=0
if grep -q container=lxc "/proc/1/environ" 2>/dev/null; then
  export IS_LXC=1
fi

RED_TEXT_COLOR=$(tput setaf 1 2>/dev/null || echo '')
GREEN_TEXT_COLOR=$(tput setaf 2 2>/dev/null || echo '')
YELLOW_TEXT_COLOR=$(tput setaf 3 2>/dev/null || echo '')
RESET_TEXT_COLOR=$(tput sgr0 2>/dev/null || echo '')

if [ -z "${SWAP}" ]; then
  SWAP=true
fi

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

die()
{
  /bin/echo -e "ERROR: $*" >&2
  exit 1
}

verbose()
{
  if [ "$VERBOSE" -eq "1" ]; then
    echo "$@" >&2
  fi
}

setOSInfo()
{
  [ -e '/bin/uname' ] && uname='/bin/uname' || uname='/usr/bin/uname'
  ARCH=$(uname -m)
  OPERATING_SYSTEM=$(uname -s)
  if [ "$OPERATING_SYSTEM" = 'Linux' ]; then
    if [ -e '/etc/debian_version' ]; then
      if [ -e '/etc/lsb-release' ]; then
        . /etc/lsb-release
        OS_NAME=$DISTRIB_ID
        OS_CODE_NAME=$(awk -F'=' '/VERSION_CODENAME/ {print $2}' /etc/os-release)
        OS_VERSION=$DISTRIB_RELEASE
      else
        OS_NAME='Debian'
        OS_CODE_NAME=$(awk -F= '/VERSION_CODENAME/{print $2}' /etc/os-release)
        DEBIAN_VERSION=$(cat /etc/debian_version)
        OS_VERSION=$(echo "$DEBIAN_VERSION" | cut -d "." -f -1)
      fi
    else
      die "Unable to detect Debian or Ubuntu."
    fi
  else
    die "Operating System needs to be Linux."
  fi

  verbose "Architecture: $ARCH"
  verbose "OS Name: $OS_NAME"
  verbose "OS Version: $OS_VERSION"
}

checkRequirements()
{
  apt update
  apt -y install lsof
  checkOperatingSystem
  checkPortConflicts
  checkDatabaseEngine
  checkIfHostnameResolves
  checkRootPartitionSize
}

checkOperatingSystem()
{
  if [ "$OS_NAME" = "Debian" ] || [ "$OS_NAME" = "Ubuntu" ]; then
    if [ "$OS_NAME" = "Debian" ]; then
      if [ "$OS_VERSION" != "11" ] && [ "$OS_VERSION" != "12" ] && [ "$OS_VERSION" != "13" ]; then
        die "Debian 11, 12 and 13 are supported."
      fi
    else
      if [ "$OS_VERSION" != "22.04" ] && [ "$OS_VERSION" != "24.04" ]; then
        die "Ubuntu 22.04 LTS, 24.04 LTS are supported."
      fi
    fi
  else
    die "Operating System needs to be Debian or Ubuntu."
  fi
}

checkPortConflicts()
{
  local OPEN_PORTS
  OPEN_PORTS=$(lsof -i:80 -i:443 -i:3306 -P -n -sTCP:LISTEN 2>/dev/null || true)
  if [ -n "${OPEN_PORTS}" ]; then
    die "Your system already has services running on port 80, 443 or 3306."
  fi
}

checkDatabaseEngine()
{
  if [ "$OS_NAME" = "Debian" ]; then
    case $OS_VERSION in
      "11")
        case $DB_ENGINE in
          "MYSQL_5.7"|"MYSQL_8.0"|"MARIADB_10.6"|"MARIADB_10.11"|"MARIADB_11.4"|"MARIADB_11.8") ;;
          *) die "Database Engine $DB_ENGINE not supported." ;;
        esac ;;
      "12")
        case $DB_ENGINE in
          "MYSQL_8.4"|"MYSQL_8.0"|"MARIADB_10.11"|"MARIADB_11.4"|"MARIADB_11.8") ;;
          *) die "Database Engine $DB_ENGINE not supported." ;;
        esac ;;
      "13")
        case $DB_ENGINE in
          "MYSQL_8.0"|"MARIADB_11.8") ;;
          *) die "Database Engine $DB_ENGINE not supported." ;;
        esac ;;
      *) die "Unsupported Debian version: $OS_VERSION" ;;
    esac
  elif [ "$OS_NAME" = "Ubuntu" ]; then
    case $OS_VERSION in
      "22.04")
        case $DB_ENGINE in
          "MYSQL_8.0"|"MARIADB_10.6"|"MARIADB_10.11"|"MARIADB_11.4"|"MARIADB_11.8") ;;
          *) die "Database Engine $DB_ENGINE not supported." ;;
        esac ;;
      "24.04")
        case $DB_ENGINE in
          "MYSQL_8.4"|"MYSQL_8.0"|"MARIADB_10.11"|"MARIADB_11.4"|"MARIADB_11.8") ;;
          *) die "Database Engine $DB_ENGINE not supported." ;;
        esac ;;
      *) die "Unsupported Ubuntu version: $OS_VERSION" ;;
    esac
  else
    die "Unsupported OS: $OS_NAME"
  fi
  echo "Database Engine: $DB_ENGINE"
}

checkIfHostnameResolves()
{
  local LOCAL_IP
  LOCAL_IP=$(getent hosts "$HOSTNAME" | awk '{print $1}')
  if [ -z "${LOCAL_IP}" ]; then
    die "Hostname $HOSTNAME does not resolve. Set a hosts entry in: /etc/hosts"
  fi
}

checkRootPartitionSize()
{
  local ROOT_PARTITION
  ROOT_PARTITION=$(df --output=avail / | sed '1d')
  if [ "$ROOT_PARTITION" -lt 6000000 ]; then
    die "At least 6GB of free hard disk space is required"
  fi
}

removeUnnecessaryPackages()
{
  apt -y --purge remove mysql* &>/dev/null || true
}

setIp()
{
  IP=$(curl -sk --connect-timeout 10 --retry 3 --retry-delay 0 https://d3qnd54q8gb3je.cloudfront.net/ 2>/dev/null || true)
  IP=$(echo "$IP" | cut -d"," -f1)
}

setupRequiredPackages()
{
  apt -y upgrade
  apt -y install gnupg apt-transport-https debsums chrony redis-server
  DEBIAN_FRONTEND=noninteractive apt-get install -y postfix
  # ClamAV on-demand scanner (clamdscan via clamav-daemon). Signatures fetched by clamav-freshclam.
  DEBIAN_FRONTEND=noninteractive apt-get install -y clamav clamav-daemon clamav-freshclam || true
  systemctl enable clamav-daemon 2>/dev/null || true
  systemctl enable clamav-freshclam 2>/dev/null || true
  # clamav-daemon refuses to start until freshclam has fetched at least one signature.
  # Run freshclam synchronously (takes ~30s) so the daemon comes up on first install.
  systemctl start clamav-freshclam 2>/dev/null || true
  for i in 1 2 3 4 5 6 7 8 9 10; do
    [ -f /var/lib/clamav/daily.cvd ] || [ -f /var/lib/clamav/daily.cld ] && break
    sleep 5
  done
  systemctl start clamav-daemon 2>/dev/null || true
  mkdir -p /var/log/clp-malware-scan && chown clp:clp /var/log/clp-malware-scan && chmod 0750 /var/log/clp-malware-scan
  if [ ! -f /etc/sudoers.d/clp-clamav ]; then
    echo 'clp ALL=(ALL) NOPASSWD: /usr/bin/clamdscan' > /etc/sudoers.d/clp-clamav
    chmod 0440 /etc/sudoers.d/clp-clamav
  fi
  if [ "$SWAP" != false ]; then
    echo "CONF_SWAPFILE=/home/.swap" > /etc/dphys-swapfile
    echo "CONF_SWAPSIZE=2048" >> /etc/dphys-swapfile
    echo "CONF_MAXSWAP=2048" >> /etc/dphys-swapfile
    DEBIAN_FRONTEND=noninteractive apt-get -y -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confnew install dphys-swapfile
  fi
}

generateLocales()
{
  apt -y install locales locales-all
  /usr/sbin/locale-gen en_US && /usr/sbin/locale-gen en_US.UTF-8
}

addAptSourceList()
{
  # Migrated off packages.cloudpanel.io (CDN-fronted upstream mirror) to
  # community-maintained upstream sources to remove the single-vendor liability.
  # See README "Added features" for context.

  apt -y install apt-transport-https ca-certificates curl gnupg lsb-release >/dev/null 2>&1 || true

  # PHP (Surý). Single keyring deb installs the archive key at
  # /usr/share/keyrings/deb.sury.org-php.gpg.
  curl -fsSL -o /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
  DEBIAN_FRONTEND=noninteractive dpkg -i /tmp/debsuryorg-archive-keyring.deb
  rm -f /tmp/debsuryorg-archive-keyring.deb
  echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $OS_CODE_NAME main" > /etc/apt/sources.list.d/sury-php.list

  # nginx — distro (Ubuntu's nginx + libnginx-mod-brotli covers our needs;
  # Surý's nginx repo isn't published for current distros).

  # Varnish 7.7 — varnish70 has no noble release; varnish77 is the current LTS-ish line covering noble+bookworm+trixie.
  curl -fsSL https://packagecloud.io/install/repositories/varnishcache/varnish77/script.deb.sh | bash

  apt -y update
}

# Helper: install percona-release once, then enable the requested repo series.
# Usage: enablePerconaRepo ps-57 | ps-80 | ps-84-lts
enablePerconaRepo()
{
  local series="$1"
  if ! command -v percona-release >/dev/null 2>&1; then
    curl -fsSL -o /tmp/percona-release_latest.generic_all.deb https://repo.percona.com/apt/percona-release_latest.generic_all.deb
    DEBIAN_FRONTEND=noninteractive dpkg -i /tmp/percona-release_latest.generic_all.deb
    rm -f /tmp/percona-release_latest.generic_all.deb
  fi
  percona-release enable-only "$series" release 2>/dev/null || percona-release enable "$series" release
  percona-release enable tools release 2>/dev/null || true
  apt -y update
}

createMariaDBSymlinks()
{
  ln -sf /usr/bin/mariadb /usr/bin/mysql
  ln -sf /usr/bin/mariadb-access /usr/bin/mysqlaccess
  ln -sf /usr/bin/mariadb-admin /usr/bin/mysqladmin
  ln -sf /usr/bin/mariadb-check /usr/bin/mysqlanalyze
  ln -sf /usr/bin/mariadb-binlog /usr/bin/mysqlbinlog
  ln -sf /usr/bin/mariadb-check /usr/bin/mysqlcheck
  ln -sf /usr/bin/mariadb-convert-table-format /usr/bin/mysql_convert_table_format
  ln -sf /usr/bin/mariadbd-multi /usr/bin/mysqld_multi
  ln -sf /usr/bin/mariadbd-safe /usr/bin/mysqld_safe
  ln -sf /usr/bin/mariadbd-safe-helper /usr/bin/mysqld_safe_helper
  ln -sf /usr/bin/mariadb-dump /usr/bin/mysqldump
  ln -sf /usr/bin/mariadb-dumpslow /usr/bin/mysqldumpslow
  ln -sf /usr/bin/mariadb-find-rows /usr/bin/mysql_find_rows
  ln -sf /usr/bin/mariadb-fix-extensions /usr/bin/mysql_fix_extensions
  ln -sf /usr/bin/mariadb-hotcopy /usr/bin/mysqlhotcopy
  ln -sf /usr/bin/mariadb-import /usr/bin/mysqlimport
  ln -sf /usr/bin/mariadb-install-db /usr/bin/mysql_install_db
  ln -sf /usr/bin/mariadb-check /usr/bin/mysqloptimize
  ln -sf /usr/bin/mariadb-plugin /usr/bin/mysql_plugin
  ln -sf /usr/bin/mariadb-check /usr/bin/mysqlrepair
  ln -sf /usr/bin/mariadb-report /usr/bin/mysqlreport
  ln -sf /usr/bin/mariadb-secure-installation /usr/bin/mysql_secure_installation
  ln -sf /usr/bin/mariadb-setpermission /usr/bin/mysql_setpermission
  ln -sf /usr/bin/mariadb-show /usr/bin/mysqlshow
  ln -sf /usr/bin/mariadb-slap /usr/bin/mysqlslap
  ln -sf /usr/bin/mariadb-tzinfo-to-sql /usr/bin/mysql_tzinfo_to_sql
  ln -sf /usr/bin/mariadb-upgrade /usr/bin/mysql_upgrade
  ln -sf /usr/bin/mariadb-waitpid /usr/bin/mysql_waitpid
}

installMySQL()
{
  addAptSourceList

  if [ "$OS_NAME" = "Debian" ]; then
    case $OS_VERSION in
      "11")
        case $DB_ENGINE in
          "MYSQL_5.7")
            enablePerconaRepo ps-57
            DEBIAN_FRONTEND=noninteractive apt -y install percona-server-client-5.7 percona-server-server-5.7
            ;;
          "MYSQL_8.0")
            enablePerconaRepo ps-80
            DEBIAN_FRONTEND=noninteractive apt -y install percona-server-client percona-server-server
            ;;
          "MARIADB_10.6")
            wget -qO- https://mariadb.org/mariadb_release_signing_key.asc | gpg --dearmor > /etc/apt/trusted.gpg.d/mariadb.gpg
            echo "deb [arch=amd64,arm64] https://mirror.mariadb.org/repo/10.6/debian bullseye main" > /etc/apt/sources.list.d/mariadb.list
            apt -y update && apt -y install mariadb-server
            ;;
          "MARIADB_10.11")
            wget -qO- https://mariadb.org/mariadb_release_signing_key.asc | gpg --dearmor > /etc/apt/trusted.gpg.d/mariadb.gpg
            echo "deb [arch=amd64,arm64] https://mirror.mariadb.org/repo/10.11/debian bullseye main" > /etc/apt/sources.list.d/mariadb.list
            apt -y update && apt -y install mariadb-server
            ;;
          "MARIADB_11.4")
            wget -qO- https://mariadb.org/mariadb_release_signing_key.asc | gpg --dearmor > /etc/apt/trusted.gpg.d/mariadb.gpg
            echo "deb [arch=amd64,arm64] https://mirror.mariadb.org/repo/11.4/debian bullseye main" > /etc/apt/sources.list.d/mariadb.list
            apt -y update && apt -y install mariadb-server
            createMariaDBSymlinks
            ;;
          "MARIADB_11.8")
            wget -qO- https://mariadb.org/mariadb_release_signing_key.asc | gpg --dearmor > /etc/apt/trusted.gpg.d/mariadb.gpg
            echo "deb [arch=amd64,arm64] https://mirror.mariadb.org/repo/11.8/debian bullseye main" > /etc/apt/sources.list.d/mariadb.list
            apt -y update && apt -y install mariadb-server
            createMariaDBSymlinks
            ;;
        esac ;;
      "12")
        case $DB_ENGINE in
          "MYSQL_8.4")
            curl -o /tmp/percona-release_latest.generic_all.deb https://repo.percona.com/apt/percona-release_latest.generic_all.deb
            dpkg -i /tmp/percona-release_latest.generic_all.deb
            percona-release enable-only ps-84-lts release
            percona-release enable tools release
            apt -y update
            DEBIAN_FRONTEND=noninteractive apt -y install percona-server-server
            rm -f /tmp/percona-release_latest.generic_all.deb
            ;;
          "MYSQL_8.0")
            enablePerconaRepo ps-80
            DEBIAN_FRONTEND=noninteractive apt -y install percona-server-client percona-server-server
            ;;
          "MARIADB_10.11")
            apt -y update && apt -y install mariadb-server
            ;;
          "MARIADB_11.4")
            wget -qO- https://mariadb.org/mariadb_release_signing_key.asc | gpg --dearmor > /etc/apt/trusted.gpg.d/mariadb.gpg
            echo "deb [arch=amd64,arm64] https://mirror.mariadb.org/repo/11.4/debian bookworm main" > /etc/apt/sources.list.d/mariadb.list
            apt -y update && apt -y install mariadb-server
            createMariaDBSymlinks
            ;;
          "MARIADB_11.8")
            wget -qO- https://mariadb.org/mariadb_release_signing_key.asc | gpg --dearmor > /etc/apt/trusted.gpg.d/mariadb.gpg
            echo "deb [arch=amd64,arm64] https://mirror.mariadb.org/repo/11.8/debian bookworm main" > /etc/apt/sources.list.d/mariadb.list
            apt -y update && apt -y install mariadb-server
            createMariaDBSymlinks
            ;;
        esac ;;
      "13")
        case $DB_ENGINE in
          "MYSQL_8.0")
            enablePerconaRepo ps-80
            DEBIAN_FRONTEND=noninteractive apt -y install percona-server-client percona-server-server
            ;;
          "MARIADB_11.8")
            wget -qO- https://mariadb.org/mariadb_release_signing_key.asc | gpg --dearmor > /etc/apt/trusted.gpg.d/mariadb.gpg
            echo "deb [arch=amd64,arm64] https://mirror.mariadb.org/repo/11.8/debian trixie main" > /etc/apt/sources.list.d/mariadb.list
            apt -y update && apt -y install mariadb-server
            createMariaDBSymlinks
            ;;
        esac ;;
    esac
  elif [ "$OS_NAME" = "Ubuntu" ]; then
    case $OS_VERSION in
      "22.04")
        case $DB_ENGINE in
          "MYSQL_8.0")
            apt -y update
            DEBIAN_FRONTEND=noninteractive apt -y install mysql-client-8.0 mysql-server-8.0
            ;;
          "MARIADB_10.6")
            apt -y update && apt -y install mariadb-server
            ;;
          "MARIADB_10.11")
            wget -qO- https://mariadb.org/mariadb_release_signing_key.asc | gpg --dearmor > /etc/apt/trusted.gpg.d/mariadb.gpg
            echo "deb [arch=amd64,arm64] https://mirror.mariadb.org/repo/10.11/ubuntu/ jammy main" > /etc/apt/sources.list.d/mariadb.list
            apt -y update && apt -y install mariadb-server
            ;;
          "MARIADB_11.4")
            wget -qO- https://mariadb.org/mariadb_release_signing_key.asc | gpg --dearmor > /etc/apt/trusted.gpg.d/mariadb.gpg
            echo "deb [arch=amd64,arm64] https://mirror.mariadb.org/repo/11.4/ubuntu jammy main" > /etc/apt/sources.list.d/mariadb.list
            apt -y update && apt -y install mariadb-server
            ;;
          "MARIADB_11.8")
            wget -qO- https://mariadb.org/mariadb_release_signing_key.asc | gpg --dearmor > /etc/apt/trusted.gpg.d/mariadb.gpg
            echo "deb [arch=amd64,arm64] https://mirror.mariadb.org/repo/11.8/ubuntu jammy main" > /etc/apt/sources.list.d/mariadb.list
            apt -y update && apt -y install mariadb-server
            createMariaDBSymlinks
            ;;
        esac ;;
      "24.04")
        case $DB_ENGINE in
          "MYSQL_8.4")
            curl -o /tmp/percona-release_latest.generic_all.deb https://repo.percona.com/apt/percona-release_latest.generic_all.deb
            dpkg -i /tmp/percona-release_latest.generic_all.deb
            percona-release enable-only ps-84-lts release
            percona-release enable tools release
            apt -y update
            DEBIAN_FRONTEND=noninteractive apt -y install percona-server-server
            rm -f /tmp/percona-release_latest.generic_all.deb
            ;;
          "MYSQL_8.0")
            enablePerconaRepo ps-80
            DEBIAN_FRONTEND=noninteractive apt -y install percona-server-client percona-server-server
            ;;
          "MARIADB_10.11")
            apt -y update && apt -y install mariadb-server
            ;;
          "MARIADB_11.4")
            wget -qO- https://mariadb.org/mariadb_release_signing_key.asc | gpg --dearmor > /etc/apt/trusted.gpg.d/mariadb.gpg
            echo "deb [arch=amd64,arm64] https://mirror.mariadb.org/repo/11.4/ubuntu noble main" > /etc/apt/sources.list.d/mariadb.list
            apt -y update && apt -y install mariadb-server
            ;;
          "MARIADB_11.8")
            wget -qO- https://mariadb.org/mariadb_release_signing_key.asc | gpg --dearmor > /etc/apt/trusted.gpg.d/mariadb.gpg
            echo "deb [arch=amd64,arm64] https://mirror.mariadb.org/repo/11.8/ubuntu noble main" > /etc/apt/sources.list.d/mariadb.list
            apt -y update && apt -y install mariadb-server
            createMariaDBSymlinks
            ;;
        esac ;;
    esac
  fi
}

installCloudPanelDependencies()
{
  # Install nginx, PHP, and other packages from CloudPanel's apt repo
  # that would normally come as dependencies of the cloudpanel deb package.
  apt -y install \
    nginx \
    php8.1-fpm php8.1-cli php8.1-common php8.1-curl php8.1-gd \
    php8.1-intl php8.1-mbstring php8.1-mysql php8.1-opcache \
    php8.1-readline php8.1-xml php8.1-zip php8.1-sqlite3 \
    php8.1-bcmath php8.1-soap php8.1-imap php8.1-ftp \
    php8.2-fpm php8.2-cli php8.2-common php8.2-curl php8.2-gd \
    php8.2-intl php8.2-mbstring php8.2-mysql php8.2-opcache \
    php8.2-readline php8.2-xml php8.2-zip php8.2-sqlite3 \
    php8.3-fpm php8.3-cli php8.3-common php8.3-curl php8.3-gd \
    php8.3-intl php8.3-mbstring php8.3-mysql php8.3-opcache \
    php8.3-readline php8.3-xml php8.3-zip php8.3-sqlite3 \
    php8.4-fpm php8.4-cli php8.4-common php8.4-curl php8.4-gd \
    php8.4-intl php8.4-mbstring php8.4-mysql php8.4-opcache \
    php8.4-readline php8.4-xml php8.4-zip php8.4-sqlite3 \
    proftpd ufw \
    libarchive-tools \
    2>/dev/null || true

  # Stop default nginx/php-fpm so CloudPanel's custom services take over
  systemctl stop nginx 2>/dev/null || true
  systemctl disable nginx 2>/dev/null || true
  for v in 8.1 8.2 8.3 8.4; do
    systemctl stop "php${v}-fpm" 2>/dev/null || true
    systemctl disable "php${v}-fpm" 2>/dev/null || true
  done
}

cloneRepo()
{
  # Prefer local checkout if install.sh is run from one; otherwise clone.
  if [ -d "$SCRIPT_DIR/source" ] && [ -d "$SCRIPT_DIR/services" ]; then
    echo "Using local repository at: $SCRIPT_DIR"
    REPO_DIR="$SCRIPT_DIR"
  else
    echo "Cloning repository from: $GIT_REPO_URL (branch: $GIT_BRANCH)"
    apt -y install git
    REPO_DIR="/tmp/cloudpanel-repo"
    rm -rf "$REPO_DIR"
    git clone --branch "$GIT_BRANCH" --depth 1 "$GIT_REPO_URL" "$REPO_DIR"
  fi
}

baseSetup()
{
  echo "LC_ALL=\"en_US.UTF-8\"" > /etc/environment
  echo 'APT::Periodic::Update-Package-Lists "0";' > /etc/apt/apt.conf.d/20auto-upgrades
  echo 'APT::Periodic::Unattended-Upgrade "0";' >> /etc/apt/apt.conf.d/20auto-upgrades
  /usr/sbin/locale-gen

  if [ "$IS_LXC" = "0" ]; then
    if ! grep -q 'fs.file-max = 500000' /etc/sysctl.conf; then
      echo "fs.file-max = 500000" >> /etc/sysctl.conf
      sysctl -p
      echo "* soft nofile 60000" >> /etc/security/limits.conf
      echo "* hard nofile 60000" >> /etc/security/limits.conf
      echo "* soft core 0" >> /etc/security/limits.conf
    fi
  fi

  echo kernel.core_pattern= > /etc/sysctl.d/50-coredump.conf && /lib/systemd/systemd-sysctl
  chmod o-x /bin/su

  if [ -d "/etc/cloud/cloud.cfg.d" ]; then
    echo "manage_etc_hosts: false" > /etc/cloud/cloud.cfg.d/100-clp.cfg
    echo "ssh: false" >> /etc/cloud/cloud.cfg.d/100-clp.cfg
  fi

  update-alternatives --set php /usr/bin/php8.4
}

setupSshRestrictionRules()
{
  echo "session optional pam_umask.so umask=0002" >> /etc/pam.d/common-session
  if [ "$IS_LXC" = "0" ]; then
    echo "PasswordAuthentication yes" >> /etc/ssh/sshd_config
    sed -i "s/.*PermitRootLogin.*/PermitRootLogin yes/g" /etc/ssh/sshd_config
  fi
  [ -f /etc/ssh/sshd_config.d/60-cloudimg-settings.conf ] && > /etc/ssh/sshd_config.d/60-cloudimg-settings.conf
  echo "DenyUsers clp" >> /etc/ssh/sshd_config
  echo "account required pam_access.so" > /tmp/cloudpanel_pam_sshd
  cat /etc/pam.d/sshd >> /tmp/cloudpanel_pam_sshd
  cat /tmp/cloudpanel_pam_sshd > /etc/pam.d/sshd
  rm -f /tmp/cloudpanel_pam_sshd
  /etc/init.d/ssh restart
}

setupClpUser()
{
  if ! id -u clp &>/dev/null; then
    useradd -m -d /home/clp -s /bin/bash clp
  fi
}

deployCloudPanelFiles()
{
  echo "Deploying CloudPanel files from repository..."

  # Create directory structure
  mkdir -p /home/clp/htdocs/app/data/
  mkdir -p /home/clp/htdocs/app/files/
  mkdir -p /home/clp/backups
  mkdir -p /home/clp/logs/nginx
  mkdir -p /home/clp/logs/php
  touch /home/clp/logs/php/error.log

  # Deploy the Symfony application
  cp -R "$REPO_DIR"/source/* /home/clp/htdocs/app/files/
  cp "$REPO_DIR"/source/.env /home/clp/htdocs/app/files/.env
  [ -f "$REPO_DIR/source/.gitignore.app" ] && cp "$REPO_DIR/source/.gitignore.app" /home/clp/htdocs/app/files/.gitignore

  # Generate a unique APP_SECRET
  APP_SECRET=$(openssl rand -hex 16)
  sed -i "s/APP_SECRET=.*/APP_SECRET=$APP_SECRET/" /home/clp/htdocs/app/files/.env

  # Deploy services (nginx, php-fpm configs)
  mkdir -p /home/clp/services/nginx/ssl-certificates
  mkdir -p /home/clp/services/nginx/ssl
  mkdir -p /home/clp/services/nginx/basic-auth
  cp -R "$REPO_DIR"/services/nginx/* /home/clp/services/nginx/
  cp -R "$REPO_DIR"/services/php-fpm /home/clp/services/php-fpm

  # The panel writes vhosts/SSL/basic-auth into /etc/nginx/* but clp-nginx loads
  # from /home/clp/services/nginx/*. Symlink the canonical path so both agree.
  if [ ! -L /etc/nginx ]; then
    [ -d /etc/nginx ] && mv /etc/nginx /etc/nginx.distro.bak
    ln -sfn /home/clp/services/nginx /etc/nginx
  fi

  # Graceful degrade for brotli/zstd (Ubuntu noble doesn't ship the module packages
  # and we don't want set -e to bail on missing directives). Try to install them
  # opportunistically; if either is missing, blank compression.conf and drop the
  # corresponding modules-enabled stubs. gzip in nginx.conf still applies.
  apt -y install libnginx-mod-brotli libnginx-mod-zstd >/dev/null 2>&1 || true
  HAVE_BROTLI=0; HAVE_ZSTD=0
  [ -f /usr/lib/nginx/modules/ngx_http_brotli_filter_module.so ] && HAVE_BROTLI=1
  [ -f /usr/lib/nginx/modules/ngx_http_zstd_filter_module.so ]   && HAVE_ZSTD=1
  [ "$HAVE_BROTLI" = "0" ] && rm -f /home/clp/services/nginx/modules-enabled/50-mod-http-brotli-*.conf
  [ "$HAVE_ZSTD" = "0" ]   && rm -f /home/clp/services/nginx/modules-enabled/50-mod-http-zstd-*.conf
  if [ "$HAVE_BROTLI" = "0" ] && [ "$HAVE_ZSTD" = "0" ]; then
    echo '# brotli/zstd modules unavailable on this distro; gzip in nginx.conf still applies.' > /home/clp/services/nginx/compression.conf
  elif [ "$HAVE_BROTLI" = "0" ]; then
    sed -i '/^# --- Brotli/,/^# ---/{ /^# ---/!d }' /home/clp/services/nginx/compression.conf
  elif [ "$HAVE_ZSTD" = "0" ]; then
    sed -i '/^# --- Zstd/,/^$/{ /^# ---/!d }' /home/clp/services/nginx/compression.conf
  fi

  # Generate self-signed SSL certificate for the panel
  openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
    -keyout /home/clp/services/nginx/ssl-certificates/private.key \
    -out /home/clp/services/nginx/ssl-certificates/cert.crt \
    -subj "/C=US/ST=State/L=City/O=CloudPanel/CN=localhost" 2>/dev/null

  # Generate DH params (use pre-computed for speed)
  openssl dhparam -out /home/clp/services/nginx/ssl/dhparams.pem 2048 2>/dev/null &
  DH_PID=$!

  # Deploy scripts
  mkdir -p /home/clp/scripts
  cp "$REPO_DIR"/scripts/create_backup.sh /home/clp/scripts/

  # Deploy system binaries
  cp "$REPO_DIR"/system/bin/clpctl /usr/bin/clpctl
  cp "$REPO_DIR"/system/bin/clp-update /usr/bin/clp-update
  cp "$REPO_DIR"/system/bin/clpctlWrapper /usr/bin/clpctlWrapper
  cp "$REPO_DIR"/system/bin/wp /usr/bin/wp

  # Deploy clp-agent
  if [ "$ARCH" = "aarch64" ]; then
    if [ -f "$REPO_DIR/package-data/clp-agent/bin/aarch64/clp-agent" ]; then
      cp "$REPO_DIR/package-data/clp-agent/bin/aarch64/clp-agent" /usr/sbin/clp-agent
    fi
  else
    if [ -f "$REPO_DIR/package-data/clp-agent/bin/x86_64/clp-agent" ]; then
      cp "$REPO_DIR/package-data/clp-agent/bin/x86_64/clp-agent" /usr/sbin/clp-agent
    elif [ -f "$REPO_DIR/system/sbin/clp-agent" ]; then
      cp "$REPO_DIR/system/sbin/clp-agent" /usr/sbin/clp-agent
    fi
  fi

  # Deploy systemd services
  cp "$REPO_DIR"/system/systemd/clp-agent.service /usr/lib/systemd/system/
  cp "$REPO_DIR"/system/systemd/clp-nginx.service /usr/lib/systemd/system/
  cp "$REPO_DIR"/system/systemd/clp-php-fpm.service /usr/lib/systemd/system/

  # Deploy system configs
  cp "$REPO_DIR"/system/etc/cron-clp /etc/cron.d/clp
  cp "$REPO_DIR"/system/etc/sudoers-cloudpanel /etc/sudoers.d/cloudpanel
  chmod 440 /etc/sudoers.d/cloudpanel
  cp "$REPO_DIR"/system/etc/bashrc /etc/bashrc/bashrc 2>/dev/null || true
  mkdir -p /etc/bashrc && cp "$REPO_DIR"/system/etc/bashrc /etc/bashrc/bashrc

  # Deploy composer
  if [ -f "$REPO_DIR/package-data/composer/composer" ]; then
    cp "$REPO_DIR/package-data/composer/composer" /usr/local/bin/composer
    chmod 777 /usr/local/bin/composer
    /usr/bin/php8.4 /usr/local/bin/composer self-update -q 2>/dev/null || true
  fi

  # Wait for DH params
  wait $DH_PID 2>/dev/null || true
}

setupMySQL()
{
  MYSQL_ROOT_PASSWORD="root"
  mysqladmin -u root password $MYSQL_ROOT_PASSWORD 2>/dev/null || true

  local DB_DATA_DIR="$REPO_DIR/package-data/database"

  case $DB_ENGINE in
    "MYSQL_5.7")
      mysql -uroot -p$MYSQL_ROOT_PASSWORD mysql < "$DB_DATA_DIR/mysql/5.7/config.sql"
      systemctl stop mysql
      [ -d /var/lib/mysql ] && mv /var/lib/mysql /home/mysql/ || true
      cp "$DB_DATA_DIR/mysql/5.7/my.cnf" /etc/mysql/my.cnf
      ;;
    "MYSQL_8.0")
      mysql -uroot -p$MYSQL_ROOT_PASSWORD mysql < "$DB_DATA_DIR/mysql/8.0/config.sql"
      systemctl stop mysql
      [ -d /var/lib/mysql ] && mv /var/lib/mysql /home/mysql/ || true
      cp "$DB_DATA_DIR/mysql/8.0/mysql.conf.d/mysqld.cnf" /etc/mysql/mysql.conf.d/mysqld.cnf
      ;;
    "MYSQL_8.4")
      systemctl stop mysql
      [ -d /var/lib/mysql ] && mv /var/lib/mysql /home/mysql/ || true
      cp "$DB_DATA_DIR/mysql/8.4/mysql.conf.d/mysqld.cnf" /etc/mysql/mysql.conf.d/mysqld.cnf
      systemctl restart mysql
      mysql -uroot -p$MYSQL_ROOT_PASSWORD mysql < "$DB_DATA_DIR/mysql/8.4/config.sql"
      ;;
    "MARIADB_10.6")
      mysql -uroot -p$MYSQL_ROOT_PASSWORD mysql < "$DB_DATA_DIR/mariadb/10.6/config.sql"
      systemctl stop mysql
      [ -d /var/lib/mysql ] && mv /var/lib/mysql /home/mysql/ || true
      mkdir -p /etc/systemd/system/mariadb.service.d/
      cp "$DB_DATA_DIR/mariadb/10.6/protect.conf" /etc/systemd/system/mariadb.service.d/protect.conf
      rm -rf /etc/mysql/mariadb.conf.d/*
      cp "$DB_DATA_DIR/mariadb/10.6/mariadb.conf.d/100-cloudpanel.cnf" /etc/mysql/mariadb.conf.d/
      systemctl daemon-reload
      ;;
    "MARIADB_10.11")
      mysql -uroot -p$MYSQL_ROOT_PASSWORD mysql < "$DB_DATA_DIR/mariadb/10.11/config.sql"
      systemctl stop mysql
      [ -d /var/lib/mysql ] && mv /var/lib/mysql /home/mysql/ || true
      mkdir -p /etc/systemd/system/mariadb.service.d/
      cp "$DB_DATA_DIR/mariadb/10.11/protect.conf" /etc/systemd/system/mariadb.service.d/protect.conf
      rm -rf /etc/mysql/mariadb.conf.d/*
      cp "$DB_DATA_DIR/mariadb/10.11/mariadb.conf.d/100-cloudpanel.cnf" /etc/mysql/mariadb.conf.d/
      systemctl daemon-reload
      ;;
    "MARIADB_11.4")
      mysql -uroot -p$MYSQL_ROOT_PASSWORD mysql < "$DB_DATA_DIR/mariadb/11.4/config.sql"
      systemctl stop mysql
      [ -d /var/lib/mysql ] && mv /var/lib/mysql /home/mysql/ || true
      mkdir -p /etc/systemd/system/mariadb.service.d/
      cp "$DB_DATA_DIR/mariadb/11.4/protect.conf" /etc/systemd/system/mariadb.service.d/protect.conf
      rm -rf /etc/mysql/mariadb.conf.d/*
      cp "$DB_DATA_DIR/mariadb/11.4/mariadb.conf.d/100-cloudpanel.cnf" /etc/mysql/mariadb.conf.d/
      systemctl daemon-reload
      ;;
    "MARIADB_11.8")
      mysql -uroot -p$MYSQL_ROOT_PASSWORD mysql < "$DB_DATA_DIR/mariadb/11.8/config.sql"
      systemctl stop mysql
      [ -d /var/lib/mysql ] && mv /var/lib/mysql /home/mysql/ || true
      mkdir -p /etc/systemd/system/mariadb.service.d/
      cp "$DB_DATA_DIR/mariadb/11.8/protect.conf" /etc/systemd/system/mariadb.service.d/protect.conf
      rm -rf /etc/mysql/mariadb.conf.d/*
      cp "$DB_DATA_DIR/mariadb/11.8/mariadb.conf.d/100-cloudpanel.cnf" /etc/mysql/mariadb.conf.d/
      systemctl daemon-reload
      ;;
  esac

  if [ "$OS_NAME" = "Ubuntu" ]; then
    echo "alias /var/lib/mysql/ -> /home/mysql/," >> /etc/apparmor.d/tunables/alias
    systemctl restart apparmor
  fi
  systemctl restart mysql
}

setupCloudPanelServices()
{
  systemctl daemon-reload
  systemctl enable clp-nginx
  systemctl enable clp-php-fpm
  systemctl enable clp-agent
  systemctl restart clp-nginx
  systemctl restart clp-php-fpm
  systemctl restart clp-agent
}

setupCloudPanelApp()
{
  # Ensure clp owns the deployed app + var/cache + var/log BEFORE running any
  # console command as clp. setPermissions() runs much later, but Symfony needs
  # a writable var/ on the very first console invocation (it creates
  # var/cache/prod on demand).
  mkdir -p /home/clp/htdocs/app/files/var/cache /home/clp/htdocs/app/files/var/log
  chown -R clp:clp /home/clp/htdocs/app/
  chown -R clp:clp /home/clp/services/ /home/clp/scripts/ /home/clp/backups/ /home/clp/logs/ 2>/dev/null || true

  # Drop to a cwd clp can read. If install.sh was launched from /root/something
  # (mode 0700), Symfony Process bails with: "The provided cwd does not exist"
  # because is_dir() returns false for clp on root's tree.
  cd /tmp

  # Initialize the SQLite database and run migrations
  su -s /bin/bash -c "/usr/bin/php8.1 -c /home/clp/services/php-fpm/cli/php.ini /home/clp/htdocs/app/files/bin/console doctrine:database:drop --force" clp 2>/dev/null || true
  su -s /bin/bash -c "/usr/bin/php8.1 -c /home/clp/services/php-fpm/cli/php.ini /home/clp/htdocs/app/files/bin/console doctrine:database:create" clp
  su -s /bin/bash -c "/usr/bin/php8.1 -c /home/clp/services/php-fpm/cli/php.ini /home/clp/htdocs/app/files/bin/console doctrine:schema:update --force" clp 2>/dev/null || true
  su -s /bin/bash -c "/usr/bin/php8.1 -c /home/clp/services/php-fpm/cli/php.ini /home/clp/htdocs/app/files/bin/console doctrine:schema:update --force" clp
  su -s /bin/bash -c "APP_ENV=dev /usr/bin/php8.1 -c /home/clp/services/php-fpm/cli/php.ini /home/clp/htdocs/app/files/bin/console doctrine:fixtures:load -n" clp
  su -s /bin/bash -c "/usr/bin/php8.1 -c /home/clp/services/php-fpm/cli/php.ini /home/clp/htdocs/app/files/bin/console doctrine:migrations:sync-metadata-storage -n" clp
  su -s /bin/bash -c "/usr/bin/php8.1 -c /home/clp/services/php-fpm/cli/php.ini /home/clp/htdocs/app/files/bin/console doctrine:migrations:version --add --all -n" clp
  su -s /bin/bash -c "/usr/bin/clpctl vhost-templates:import" clp 2>/dev/null || true
  su -s /bin/bash -c "/usr/bin/clpctl cloudflare:update:ips" clp 2>/dev/null || true

  echo "${CLOUD:-}" > /home/clp/.cloud
  if [ -n "$CLOUD" ]; then
    su -s /bin/bash -c "/usr/bin/php8.1 -c /home/clp/services/php-fpm/cli/php.ini /home/clp/htdocs/app/files/bin/console doctrine:query:sql \"INSERT INTO config (id, key, value) VALUES (NULL, 'cloud', '$CLOUD');\"" clp 2>/dev/null || true
  fi
  if [ -n "$IP" ]; then
    su -s /bin/bash -c "/usr/bin/php8.1 -c /home/clp/services/php-fpm/cli/php.ini /home/clp/htdocs/app/files/bin/console doctrine:query:sql \"INSERT INTO config (id, key, value) VALUES (NULL, 'masquerade_address', '$IP');\"" clp 2>/dev/null || true
  fi

  rm -rf /home/clp/htdocs/app/files/var/cache/dev/
  rm -rf /home/clp/htdocs/app/files/var/log/dev.log
}

setupCloudPanelCrontabs()
{
  local CLOUD_CRON_DIR="$REPO_DIR/package-data/clp/crontab"
  if [ -n "$CLOUD" ] && [ -f "$CLOUD_CRON_DIR/clp-$CLOUD" ]; then
    cp "$CLOUD_CRON_DIR/clp-$CLOUD" "/etc/cron.d/clp-$CLOUD"
  fi

  # ---- Per-site disk quota daily enforcement (clpctl quota:enforce) ----
  # Runs as clp at 04:17 daily so it doesn't collide with letsencrypt renewals
  # and DB backups. clp's $PATH lacks /usr/bin in non-login shells under cron,
  # so we invoke clpctl by absolute path.
  cat > /etc/cron.d/clp-quota-enforce <<'CRON_EOF'
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
17 4 * * * clp /usr/bin/clpctl quota:enforce >/dev/null 2>&1
CRON_EOF
  chmod 0644 /etc/cron.d/clp-quota-enforce
}

setupResourceQuotaTooling()
{
  # XFS project quota userland (xfs_quota). Safe no-op on non-XFS /home —
  # the panel only invokes xfs_quota when DiskQuotaProbe::isHardQuotaSupported()
  # returns true.
  DEBIAN_FRONTEND=noninteractive apt-get install -y xfsprogs quota 2>/dev/null || true

  # /etc/projects + /etc/projid must exist for xfs_quota to resolve project ids.
  [ -f /etc/projects ] || touch /etc/projects
  [ -f /etc/projid ] || touch /etc/projid
  chmod 0644 /etc/projects /etc/projid
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

setupMotd()
{
  rm -f /etc/motd
  rm -rf /etc/update-motd.d/*
  if [ -f "$REPO_DIR/package-data/motd/10-cloudpanel" ]; then
    cp "$REPO_DIR/package-data/motd/10-cloudpanel" /etc/update-motd.d/10-cloudpanel
    chmod 775 /etc/update-motd.d/10-cloudpanel
  fi
}

setupLogrotate()
{
  rm -f /etc/cron.daily/logrotate
  sed -i "s/OnCalendar=daily/OnCalendar=*-*-* 23:58:00/g" /lib/systemd/system/logrotate.timer 2>/dev/null || true
  if [ -f "$REPO_DIR/package-data/logrotate/systemd/logrotate.service" ]; then
    cp "$REPO_DIR/package-data/logrotate/systemd/logrotate.service" /lib/systemd/system/
    systemctl daemon-reload
  fi
}

setupProftpd()
{
  if [ -n "$IP" ]; then
    sed -i "s/MasqueradeAddress .*/MasqueradeAddress $IP/g" /etc/proftpd/proftpd.conf 2>/dev/null || true
    systemctl restart proftpd 2>/dev/null || true
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
  chmod 755 /usr/local/bin/composer 2>/dev/null || true
  chmod 700 /etc/nginx/ 2>/dev/null || true
  chmod -R 777 /var/lib/nginx/ 2>/dev/null || true
  chmod 701 /home
}

showSuccessMessage()
{
  CLOUDPANEL_URL="https://$IP:8443"
  printf "\n\n"
  printf "~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n"
  printf "${GREEN_TEXT_COLOR}The installation of CloudPanel is complete!${RESET_TEXT_COLOR}\n\n"
  printf "CloudPanel can be accessed now:${YELLOW_TEXT_COLOR} $CLOUDPANEL_URL ${RESET_TEXT_COLOR}\n"
  printf "~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~\n"
}

cleanUp()
{
  if [ -n "$GIT_REPO_URL" ] && [ -d "/tmp/cloudpanel-repo" ]; then
    rm -rf /tmp/cloudpanel-repo
  fi
  apt clean
}

# ========================
# Main installation flow
# ========================

setOSInfo
checkRequirements
setIp
setupRequiredPackages
generateLocales
removeUnnecessaryPackages
installMySQL
installCloudPanelDependencies
cloneRepo
baseSetup
setupClpUser
setupSshRestrictionRules
deployCloudPanelFiles
setupMySQL
setupCloudPanelServices
setupCloudPanelApp
setupCloudPanelCrontabs
setupResourceQuotaTooling
setupProftpd
setupUfw
setupMotd
setupLogrotate
setPermissions
showSuccessMessage
cleanUp
