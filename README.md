### OfflineBox 

Allows to easily download database backups sucurely from your CakePHP 4.x custom software. 

## Install

First install Docker
- Settings - Start Docker when you sign into your computer 

Download OfflineBox files from github
- Unzip
- Run 1start.sh

First time your offlineBox_config.json will auto populate
- After you first run open that file and add your backup location URLS

Client
- Copy and paste this to your cakePHP 4.x project

It will auto run 2 times per day while docker is running


## Manual run
From terminal
- ./3manualRun.bat
- This will auto login and display in terminal as it downloads your backup sets

## Auto-Start when Windows Starts

To ensure this always runs each day and when the computer starts we are going to create a Windows Task Scheduler

1. Open 'Task Scheduler'
2. Action -> 'Create Task'
3. Name "OfflineBox" or any name you like
4. Tiggers - "Daily at 7AM" AND "At Startup"
5. Actions - "Start a Program" - Browse for '1-autoStart-offlineBox.bat' 
- Put the folder where these files are in 'start in (optional)'
- Delay task for 1 minute (give time for docker to startup)

Now your docker will always be running and your backups will run in the background

- - - More coming later - - - 