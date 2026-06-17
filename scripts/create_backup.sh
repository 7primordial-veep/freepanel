#!/bin/bash
BACKUPS_DIRECTORY="/home/clp/backups/"
NOW=$(date '+%Y-%m-%d_%H-%M-%S')
BACKUP_DIRECTORY="$(realpath -s $BACKUPS_DIRECTORY)/$NOW/"
if [ ! -d $BACKUP_DIRECTORY ]; then
  APP_DIRECTORY="$(realpath -s $BACKUP_DIRECTORY)/app/"
  mkdir -p $APP_DIRECTORY
  APP_DATA_DIRECTORY="$(realpath -s $APP_DIRECTORY)/data/"
  mkdir $APP_DATA_DIRECTORY
  echo "" > /home/clp/htdocs/app/files/var/log/prod.dev
  cp -R /home/clp/htdocs/app/files/ $APP_DIRECTORY
  sqlite3 /home/clp/htdocs/app/data/db.sq3 ".backup $(realpath -s $APP_DATA_DIRECTORY)/db.sq3"
fi
# Keep 3 backups
cd $BACKUPS_DIRECTORY && ls -t | tail -n +4 | xargs rm -rf