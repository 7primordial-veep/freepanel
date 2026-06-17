#!/bin/bash
#rm -f /Users/$(whoami)/.ssh/known_hosts
#rm -rf /Users/$(whoami)/Library/Application\ Support/Unison/*
unison -prefer newer -ignore "Name .git" -ignore "Path var" -ignorearchives -repeat watch /Users/admin/PhpstormProjects/cloudpanel-v2.clp/public/file-manager/ ssh://root@127.0.0.1//home/cloudpanel/htdocs/cloudpanel-v2.clp/public/file-manager/ -ui text
