@echo off
echo Erstelle marces CMS-Projektstruktur...

REM Hauptverzeichnis erstellen
mkdir marces

REM Hauptordnerstruktur
cd marces
mkdir admin
mkdir config
mkdir content
mkdir templates
mkdir assets
mkdir system

REM Inhaltsverzeichnisse
cd content
mkdir pages
mkdir blog
mkdir versions
cd ..

REM Templates-Verzeichnisse
cd templates
mkdir partials
mkdir errors
cd ..

REM Assets-Verzeichnisse
cd assets
mkdir css
mkdir js
mkdir fonts
mkdir images
cd ..

REM System-Verzeichnisse
cd system
mkdir core
mkdir cache
cd ..

REM Weitere Unterverzeichnisse
cd admin
mkdir assets
cd assets
mkdir css
mkdir js
cd ../..

cd content\pages
echo. > home.md
echo. > about.md
echo. > contact.md
cd ..\..

cd templates
echo. > base.tpl.php
echo. > page.tpl.php
cd partials
echo. > header.tpl.php
echo. > footer.tpl.php
cd ..
cd errors
echo. > error-404.tpl.php
echo. > error-500.tpl.php
cd ../..

cd assets\css
echo. > main-style.css
echo. > admin-style.css
cd ..
cd js
echo. > main.js
cd ../..

cd system\core
echo. > bootstrap.inc.php
echo. > application.class.php
echo. > router.class.php
echo. > content.class.php
echo. > template.class.php
echo. > exceptions.inc.php
echo. > utilities.inc.php
cd ..\cache
echo Deny from all > .htaccess
cd ../..

cd config
echo. > system.config.php
echo. > routes.config.php
cd ..

REM Hauptdateien
echo. > index.php
echo. > .htaccess
echo. > README.md

cd admin
echo. > index.php
echo. > login.php
cd ..

echo Projektstruktur für marces CMS wurde erfolgreich erstellt!
echo Verzeichnis: %CD%\marces
cd ..