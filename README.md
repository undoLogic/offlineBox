### OfflineBox 

Allows to easily download database backups sucurely from your custom software. 

## Install

First install Docker
- Settings - Start Docker when you sign into your computer 

Download OfflineBox files from github
- Unzip
- Run 1-start-offlineBox.sh

Add your config file (auto-populate coming later)

It will auto run 2 times per day

## Manual run
From terminal
- 4-debug.bat
- php run.php
- This will run a single time

## Auto-Start when Windows Starts

To ensure this always runs each day and when the computer starts we are going to create a Windows Task Scheduler

1. Open 'Task Scheduler'
2. Action -> 'Create Task'
3. Name "OfflineBox" or any name you like
4. Tiggers - "Daily at 7AM" AND "At Startup"
5. Actions - "Start a Program" - Browse for '1-autoStart-offlineBox.bat' 
- Put the folder where these files are in 'start in (optional)'

Now your docker will always be running and your backups will run in the background

- - - More coming later - - - 