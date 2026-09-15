@echo off
"C:\Users\ai\MariaDB12\bin\mariadb_admin.exe" -u admin -pMisstree@0909 shutdown
if errorlevel 1 taskkill /IM mariadbd.exe /F
