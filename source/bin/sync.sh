#!/bin/bash
#rm -f /Users/$(whoami)/.ssh/known_hosts
#rm -rf /Users/$(whoami)/Library/Application\ Support/Unison/*
unison -prefer newer -ignore "Name .git" -ignore "Path var" -ignore "Path vendor/google" -ignore "Path public/phpmyadmin" -ignore "Path vendor" -ignorearchives -repeat watch /Users/admin/PhpstormProjects/cloudpanel-v2.clp/ ssh://root@127.0.0.1//home/cloudpanel/htdocs/cloudpanel-v2.clp/ -ui text
