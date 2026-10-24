<?php
declare(strict_types=1);

/**
 * MiniCloud – Mehrsprachigkeits-Modul (i18n) für 5 Sprachen:
 * - de: Deutsch (Standard)
 * - en: English
 * - fr: Français
 * - es: Español
 * - nl: Nederlands
 */

/**
 * Liefert alle unterstützten Sprachen mit Flagge und Eigennamen.
 *
 * @return array<string, string>
 */
function getAllowedLanguages(): array
{
    return [
        'de' => '🇩🇪 Deutsch',
        'en' => '🇬🇧 English',
        'fr' => '🇫🇷 Français',
        'es' => '🇪🇸 Español',
        'nl' => '🇳🇱 Nederlands',
    ];
}

/**
 * Ermittelt den aktuell aktiven Sprachcode ('de', 'en', 'fr', 'es', 'nl').
 */
function getCurrentLang(): string
{
    $allowed = array_keys(getAllowedLanguages());

    if (isset($_GET['lang']) && is_string($_GET['lang'])) {
        $g = strtolower(trim($_GET['lang']));
        if (in_array($g, $allowed, true)) {
            $_SESSION['lang'] = $g;
            if (!headers_sent()) {
                setcookie('mc_lang', $g, [
                    'expires'  => time() + 86400 * 365,
                    'path'     => '/',
                    'samesite' => 'Lax',
                ]);
            }
            return $g;
        }
    }

    if (isset($_SESSION['lang']) && is_string($_SESSION['lang']) && in_array($_SESSION['lang'], $allowed, true)) {
        return $_SESSION['lang'];
    }

    if (isset($_COOKIE['mc_lang']) && is_string($_COOKIE['mc_lang']) && in_array($_COOKIE['mc_lang'], $allowed, true)) {
        $_SESSION['lang'] = $_COOKIE['mc_lang'];
        return $_COOKIE['mc_lang'];
    }

    if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        $accept = strtolower((string)$_SERVER['HTTP_ACCEPT_LANGUAGE']);
        if (preg_match_all('/([a-z]{2})(?:-[a-zA-Z]{2})?/', $accept, $matches)) {
            foreach ($matches[1] as $code) {
                if (in_array($code, $allowed, true)) {
                    return $code;
                }
            }
        }
    }

    return 'de';
}

/**
 * Liefert das zentrale Übersetzungswörterbuch (Deutsch -> [en, fr, es, nl]).
 *
 * @return array<string, array{en: string, fr: string, es: string, nl: string}>
 */
function getTranslationDictionary(): array
{
    static $dict = null;
    if (is_array($dict)) {
        return $dict;
    }

    $dict = [
        // --- Kopfzeile, Themes & Navigation ---
        'Theme wählen' => [
            'en' => 'Choose theme',
            'fr' => 'Choisir le thème',
            'es' => 'Elegir tema',
            'nl' => 'Thema kiezen',
        ],
        'Design-Theme wählen' => [
            'en' => 'Choose design theme',
            'fr' => 'Choisir le thème visuel',
            'es' => 'Elegir tema de diseño',
            'nl' => 'Designthema kiezen',
        ],
        'Sprache wählen' => [
            'en' => 'Choose language',
            'fr' => 'Choisir la langue',
            'es' => 'Elegir idioma',
            'nl' => 'Taal kiezen',
        ],
        'Sanft & Gedämpft Hell' => [
            'en' => 'Soft & Muted Light',
            'fr' => 'Clair doux & tamisé',
            'es' => 'Claro suave y tenue',
            'nl' => 'Zacht & gedempt licht',
        ],
        'Halbdunkle Designs' => [
            'en' => 'Semi-Dark Themes',
            'fr' => 'Thèmes semi-sombres',
            'es' => 'Temas semioscuros',
            'nl' => 'Halfdonkere thema’s',
        ],
        'Dunkle Designs' => [
            'en' => 'Dark Themes',
            'fr' => 'Thèmes sombres',
            'es' => 'Temas oscuros',
            'nl' => 'Donkere thema’s',
        ],
        '☕ Sand (Warmbeige)' => [
            'en' => '☕ Sand (Warm Beige)',
            'fr' => '☕ Sable (Beige chaud)',
            'es' => '☕ Arena (Beige cálido)',
            'nl' => '☕ Zand (Warmbeige)',
        ],
        '🌿 Salbei (Naturgrün)' => [
            'en' => '🌿 Sage (Nature Green)',
            'fr' => '🌿 Sauge (Vert nature)',
            'es' => '🌿 Salvia (Verde natural)',
            'nl' => '🌿 Salie (Natuurgroen)',
        ],
        '☁️ Nordisch (Blaugrau)' => [
            'en' => '☁️ Nordic (Blue-Gray)',
            'fr' => '☁️ Nordique (Gris-bleu)',
            'es' => '☁️ Nórdico (Gris azulado)',
            'nl' => '☁️ Noords (Blauwgrijs)',
        ],
        '🌸 Lavendel (Flieder)' => [
            'en' => '🌸 Lavender (Lilac)',
            'fr' => '🌸 Lavande (Lilas)',
            'es' => '🌸 Lavanda (Lila)',
            'nl' => '🌸 Lavendel (Sering)',
        ],
        '☀️ Hell (Klassisch)' => [
            'en' => '☀️ Light (Classic)',
            'fr' => '☀️ Clair (Classique)',
            'es' => '☀️ Claro (Clásico)',
            'nl' => '☀️ Licht (Klassiek)',
        ],
        '🌆 Dämmerung (Schiefer)' => [
            'en' => '🌆 Dusk (Slate)',
            'fr' => '🌆 Crépuscule (Ardoise)',
            'es' => '🌆 Crepúsculo (Pizarra)',
            'nl' => '🌆 Schemering (Leisteen)',
        ],
        '🍫 Mokka (Warmbraun)' => [
            'en' => '🍫 Mocha (Warm Brown)',
            'fr' => '🍫 Moka (Brun chaud)',
            'es' => '🍫 Moca (Marrón cálido)',
            'nl' => '🍫 Mokka (Warmbruin)',
        ],
        '💎 Petrol (Blaugrün)' => [
            'en' => '💎 Petrol (Teal)',
            'fr' => '💎 Pétrole (Bleu-vert)',
            'es' => '💎 Petróleo (Verde azulado)',
            'nl' => '💎 Petrol (Blauwgroen)',
        ],
        '🍇 Pflaume (Violettgrau)' => [
            'en' => '🍇 Plum (Violet-Gray)',
            'fr' => '🍇 Prune (Gris violet)',
            'es' => '🍇 Ciruela (Gris violeta)',
            'nl' => '🍇 Pruim (Violetgrijs)',
        ],
        '🌙 Dunkel' => [
            'en' => '🌙 Dark',
            'fr' => '🌙 Sombre',
            'es' => '🌙 Oscuro',
            'nl' => '🌙 Donker',
        ],
        '🌊 Ozean' => [
            'en' => '🌊 Ocean',
            'fr' => '🌊 Océan',
            'es' => '🌊 Océano',
            'nl' => '🌊 Oceaan',
        ],
        '🌲 Wald' => [
            'en' => '🌲 Forest',
            'fr' => '🌲 Forêt',
            'es' => '🌲 Bosque',
            'nl' => '🌲 Bos',
        ],
        'Dateien' => [
            'en' => 'Files',
            'fr' => 'Fichiers',
            'es' => 'Archivos',
            'nl' => 'Bestanden',
        ],
        'Ordner' => [
            'en' => 'Folder',
            'fr' => 'Dossier',
            'es' => 'Carpeta',
            'nl' => 'Map',
        ],
        'ORDNER' => [
            'en' => 'FOLDER',
            'fr' => 'DOSSIER',
            'es' => 'CARPETA',
            'nl' => 'MAP',
        ],
        'Meine Dateien' => [
            'en' => 'My Files',
            'fr' => 'Mes fichiers',
            'es' => 'Mis archivos',
            'nl' => 'Mijn bestanden',
        ],
        'Papierkorb' => [
            'en' => 'Trash',
            'fr' => 'Corbeille',
            'es' => 'Papelera',
            'nl' => 'Prullenbak',
        ],
        'Papierkorb (30 Tage Aufbewahrung)' => [
            'en' => 'Trash (30 days retention)',
            'fr' => 'Corbeille (conservation 30 jours)',
            'es' => 'Papelera (30 días de retención)',
            'nl' => 'Prullenbak (30 dagen bewaard)',
        ],
        'Benutzer- & Gruppenordner-Administration' => [
            'en' => 'User & Group Folder Administration',
            'fr' => 'Administration des utilisateurs et dossiers de groupe',
            'es' => 'Administración de usuarios y carpetas de grupo',
            'nl' => 'Beheer van gebruikers en groepsmappen',
        ],
        'Abmelden' => [
            'en' => 'Sign out',
            'fr' => 'Déconnexion',
            'es' => 'Cerrar sesión',
            'nl' => 'Uitloggen',
        ],
        'Anmelden' => [
            'en' => 'Sign in',
            'fr' => 'Connexion',
            'es' => 'Iniciar sesión',
            'nl' => 'Inloggen',
        ],

        // --- Fußzeile & PWA-Modal ---
        'App installieren' => [
            'en' => 'Install App',
            'fr' => 'Installer l’application',
            'es' => 'Instalar aplicación',
            'nl' => 'App installeren',
        ],
        'Als App auf Smartphone, Tablet oder Desktop installieren' => [
            'en' => 'Install as an app on smartphone, tablet, or desktop',
            'fr' => 'Installer comme application sur smartphone, tablette ou ordinateur',
            'es' => 'Instalar como aplicación en smartphone, tablet o escritorio',
            'nl' => 'Als app installeren op smartphone, tablet of desktop',
        ],
        'Vollbild-App mit eigenem Symbol auf Ihrem Startbildschirm' => [
            'en' => 'Full-screen app with custom icon on your home screen',
            'fr' => 'Application plein écran avec icône dédiée sur votre écran d’accueil',
            'es' => 'Aplicación a pantalla completa con icono propio en su pantalla de inicio',
            'nl' => 'Volledig-scherm-app met eigen pictogram op uw startscherm',
        ],
        'Verstanden' => [
            'en' => 'Got it',
            'fr' => 'Compris',
            'es' => 'Entendido',
            'nl' => 'Begrepen',
        ],
        'Schließen' => [
            'en' => 'Close',
            'fr' => 'Fermer',
            'es' => 'Cerrar',
            'nl' => 'Sluiten',
        ],
        'Hilfe' => [
            'en' => 'Help',
            'fr' => 'Aide',
            'es' => 'Ayuda',
            'nl' => 'Help',
        ],
        'Impressum' => [
            'en' => 'Legal Notice',
            'fr' => 'Mentions légales',
            'es' => 'Aviso legal',
            'nl' => 'Colofon',
        ],
        'BCRYPT-geschützt • Freigabelinks • Gruppenordner' => [
            'en' => 'BCRYPT protected • Share links • Group folders',
            'fr' => 'Protégé par BCRYPT • Liens de partage • Dossiers de groupe',
            'es' => 'Protegido con BCRYPT • Enlaces compartidos • Carpetas de grupo',
            'nl' => 'BCRYPT-beveiligd • Deellinks • Groepsmappen',
        ],

        // --- Login & 2FA ---
        '🔒 Zwei-Faktor-Authentifizierung' => [
            'en' => '🔒 Two-Factor Authentication',
            'fr' => '🔒 Authentification à deux facteurs',
            'es' => '🔒 Autenticación de dos factores',
            'nl' => '🔒 Tweefactorauthenticatie',
        ],
        'E-Mail-Code eingeben' => [
            'en' => 'Enter Email Code',
            'fr' => 'Saisir le code e-mail',
            'es' => 'Introducir código de correo',
            'nl' => 'E-mailcode invoeren',
        ],
        '6-stelliger Sicherheitscode' => [
            'en' => '6-digit security code',
            'fr' => 'Code de sécurité à 6 chiffres',
            'es' => 'Código de seguridad de 6 dígitos',
            'nl' => '6-cijferige beveiligingscode',
        ],
        'Code bestätigen & anmelden' => [
            'en' => 'Verify code & sign in',
            'fr' => 'Vérifier le code et se connecter',
            'es' => 'Verificar código e iniciar sesión',
            'nl' => 'Code bevestigen & inloggen',
        ],
        'Neuen Code per E-Mail senden' => [
            'en' => 'Resend code via email',
            'fr' => 'Renvoyer un nouveau code par e-mail',
            'es' => 'Enviar nuevo código por correo',
            'nl' => 'Nieuwe code per e-mail verzenden',
        ],
        '← Anmeldung abbrechen' => [
            'en' => '← Cancel sign in',
            'fr' => '← Annuler la connexion',
            'es' => '← Cancelar inicio de sesión',
            'nl' => '← Inloggen annuleren',
        ],
        'Geben Sie Ihre Zugangsdaten ein, um auf Ihren Cloud-Speicher zuzugreifen.' => [
            'en' => 'Enter your credentials to access your cloud storage.',
            'fr' => 'Saisissez vos identifiants pour accéder à votre espace cloud.',
            'es' => 'Introduzca sus credenciales para acceder a su almacenamiento en la nube.',
            'nl' => 'Voer uw inloggegevens in om toegang te krijgen tot uw cloudopslag.',
        ],
        'Benutzername' => [
            'en' => 'Username',
            'fr' => 'Nom d’utilisateur',
            'es' => 'Nombre de usuario',
            'nl' => 'Gebruikersnaam',
        ],
        'Ihr Benutzername' => [
            'en' => 'Your username',
            'fr' => 'Votre nom d’utilisateur',
            'es' => 'Su nombre de usuario',
            'nl' => 'Uw gebruikersnaam',
        ],
        'Passwort' => [
            'en' => 'Password',
            'fr' => 'Mot de passe',
            'es' => 'Contraseña',
            'nl' => 'Wachtwoord',
        ],
        'Jetzt anmelden' => [
            'en' => 'Sign in now',
            'fr' => 'Se connecter',
            'es' => 'Iniciar sesión ahora',
            'nl' => 'Nu inloggen',
        ],
        'Selbstregistrierung deaktiviert: Neue Benutzerkonten werden ausschließlich durch den Administrator eingerichtet.' => [
            'en' => 'Self-registration disabled: New user accounts are created exclusively by the administrator.',
            'fr' => 'Auto-inscription désactivée : les nouveaux comptes sont créés exclusivement par l’administrateur.',
            'es' => 'Autorregistro desactivado: las nuevas cuentas de usuario son creadas exclusivamente por el administrador.',
            'nl' => 'Zelfregistratie uitgeschakeld: nieuwe gebruikersaccounts worden uitsluitend door de beheerder aangemaakt.',
        ],
        'Ungültiger Benutzername oder falsches Passwort.' => [
            'en' => 'Invalid username or incorrect password.',
            'fr' => 'Nom d’utilisateur ou mot de passe incorrect.',
            'es' => 'Nombre de usuario o contraseña incorrectos.',
            'nl' => 'Ongeldige gebruikersnaam of onjuist wachtwoord.',
        ],

        // --- Dashboard (index.php) ---
        'Dateien oder Ordner hierher ziehen' => [
            'en' => 'Drag & drop files or folders here',
            'fr' => 'Glissez-déposez des fichiers ou dossiers ici',
            'es' => 'Arrastre archivos o carpetas aquí',
            'nl' => 'Sleep bestanden of mappen hierheen',
        ],
        '(lädt sofort automatisch hoch)' => [
            'en' => '(uploads automatically)',
            'fr' => '(téléversement automatique immédiat)',
            'es' => '(se sube automáticamente)',
            'nl' => '(wordt direct automatisch geüpload)',
        ],
        'Zielordner (optional)' => [
            'en' => 'Target folder (optional)',
            'fr' => 'Dossier cible (optionnel)',
            'es' => 'Carpeta de destino (opcional)',
            'nl' => 'Doelmap (optioneel)',
        ],
        'Optionaler Zielordner für den Upload' => [
            'en' => 'Optional target folder for upload',
            'fr' => 'Dossier cible optionnel pour l’envoi',
            'es' => 'Carpeta de destino opcional para la subida',
            'nl' => 'Optionele doelmap voor upload',
        ],
        'Dateien werden hochgeladen...' => [
            'en' => 'Uploading files...',
            'fr' => 'Téléversement des fichiers...',
            'es' => 'Subiendo archivos...',
            'nl' => 'Bestanden worden geüpload...',
        ],
        '📂 Alle Dateien anzeigen (Ordnerübergreifend)' => [
            'en' => '📂 Show all files (across all folders)',
            'fr' => '📂 Afficher tous les fichiers (tous les dossiers)',
            'es' => '📂 Mostrar todos los archivos (todas las carpetas)',
            'nl' => '📂 Alle bestanden tonen (alle mappen)',
        ],
        'Ordner anlegen' => [
            'en' => 'Create folder',
            'fr' => 'Créer un dossier',
            'es' => 'Crear carpeta',
            'nl' => 'Map aanmaken',
        ],
        'Neuer Ordnername (z.B. Projekte)...' => [
            'en' => 'New folder name (e.g. Projects)...',
            'fr' => 'Nom du nouveau dossier (ex. Projets)...',
            'es' => 'Nombre de nueva carpeta (p. ej. Proyectos)...',
            'nl' => 'Nieuwe mapnaam (bijv. Projecten)...',
        ],
        'Meine hochgeladenen Dateien' => [
            'en' => 'My Uploaded Files',
            'fr' => 'Mes fichiers téléversés',
            'es' => 'Mis archivos subidos',
            'nl' => 'Mijn geüploade bestanden',
        ],
        'Gefilterte Ansicht aktiv' => [
            'en' => 'Filtered view active',
            'fr' => 'Vue filtrée active',
            'es' => 'Vista filtrada activa',
            'nl' => 'Gefilterde weergave actief',
        ],
        '← Zurück zum Hauptverzeichnis' => [
            'en' => '← Back to home directory',
            'fr' => '← Retour au répertoire principal',
            'es' => '← Volver al directorio principal',
            'nl' => '← Terug naar hoofdmap',
        ],
        'Dateien durchsuchen...' => [
            'en' => 'Search files...',
            'fr' => 'Rechercher des fichiers...',
            'es' => 'Buscar archivos...',
            'nl' => 'Bestanden doorzoeken...',
        ],
        'Suchen' => [
            'en' => 'Search',
            'fr' => 'Rechercher',
            'es' => 'Buscar',
            'nl' => 'Zoeken',
        ],
        'Keine Dateien oder Ordner vorhanden' => [
            'en' => 'No files or folders found',
            'fr' => 'Aucun fichier ou dossier disponible',
            'es' => 'No hay archivos ni carpetas disponibles',
            'nl' => 'Geen bestanden of mappen aanwezig',
        ],
        'Laden Sie oben Ihre erste Datei oder einen Ordner hoch.' => [
            'en' => 'Upload your first file or folder above.',
            'fr' => 'Téléversez votre premier fichier ou dossier ci-dessus.',
            'es' => 'Suba su primer archivo o carpeta arriba.',
            'nl' => 'Upload hierboven uw eerste bestand of map.',
        ],
        'In diesem Ordner befinden sich aktuell keine Dateien.' => [
            'en' => 'There are currently no files in this folder.',
            'fr' => 'Ce dossier ne contient actuellement aucun fichier.',
            'es' => 'Actualmente no hay archivos en esta carpeta.',
            'nl' => 'Er bevinden zich momenteel geen bestanden in deze map.',
        ],
        'Name' => [
            'en' => 'Name',
            'fr' => 'Nom',
            'es' => 'Nombre',
            'nl' => 'Naam',
        ],
        'Ordner / Verschieben' => [
            'en' => 'Folder / Move',
            'fr' => 'Dossier / Déplacer',
            'es' => 'Carpeta / Mover',
            'nl' => 'Map / Verplaatsen',
        ],
        'Nach Ordner sortieren' => [
            'en' => 'Sort by folder',
            'fr' => 'Trier par dossier',
            'es' => 'Ordenar por carpeta',
            'nl' => 'Sorteren op map',
        ],
        'Nach Größe sortieren' => [
            'en' => 'Sort by size',
            'fr' => 'Trier par taille',
            'es' => 'Ordenar por tamaño',
            'nl' => 'Sorteren op grootte',
        ],
        'Nach Datum sortieren' => [
            'en' => 'Sort by date',
            'fr' => 'Trier par date',
            'es' => 'Ordenar por fecha',
            'nl' => 'Sorteren op datum',
        ],
        'Typ / Datum' => [
            'en' => 'Type / Date',
            'fr' => 'Type / Date',
            'es' => 'Tipo / Fecha',
            'nl' => 'Type / Datum',
        ],
        '📁 Unterordner' => [
            'en' => '📁 Subfolder',
            'fr' => '📁 Sous-dossier',
            'es' => '📁 Subcarpeta',
            'nl' => '📁 Submap',
        ],
        'Ordner öffnen' => [
            'en' => 'Open folder',
            'fr' => 'Ouvrir le dossier',
            'es' => 'Abrir carpeta',
            'nl' => 'Map openen',
        ],
        'Ordner teilen' => [
            'en' => 'Share folder',
            'fr' => 'Partager le dossier',
            'es' => 'Compartir carpeta',
            'nl' => 'Map delen',
        ],
        'Ordner löschen' => [
            'en' => 'Delete folder',
            'fr' => 'Supprimer le dossier',
            'es' => 'Eliminar carpeta',
            'nl' => 'Map verwijderen',
        ],
        'Vorschau öffnen' => [
            'en' => 'Open preview',
            'fr' => 'Ouvrir l’aperçu',
            'es' => 'Abrir vista previa',
            'nl' => 'Voorbeeld openen',
        ],
        'Speicherplatz-Auslastung' => [
            'en' => 'Storage Usage',
            'fr' => 'Utilisation de l’espace de stockage',
            'es' => 'Uso de almacenamiento',
            'nl' => 'Opslaggebruik',
        ],
        'Verfügbar:' => [
            'en' => 'Available:',
            'fr' => 'Disponible :',
            'es' => 'Disponible:',
            'nl' => 'Beschikbaar:',
        ],
        'Aktiver Ordner' => [
            'en' => 'Current Folder',
            'fr' => 'Dossier actuel',
            'es' => 'Carpeta actual',
            'nl' => 'Huidige map',
        ],
        '🏠 Hauptverzeichnis' => [
            'en' => '🏠 Home Directory',
            'fr' => '🏠 Répertoire principal',
            'es' => '🏠 Directorio principal',
            'nl' => '🏠 Hoofdmap',
        ],
        'Hauptverzeichnis' => [
            'en' => 'Home Directory',
            'fr' => 'Répertoire principal',
            'es' => 'Directorio principal',
            'nl' => 'Hoofdmap',
        ],
        'Neuen Unterordner anlegen' => [
            'en' => 'Create new subfolder',
            'fr' => 'Créer un nouveau sous-dossier',
            'es' => 'Crear nueva subcarpeta',
            'nl' => 'Nieuwe submap aanmaken',
        ],
        '+ Erstellen' => [
            'en' => '+ Create',
            'fr' => '+ Créer',
            'es' => '+ Crear',
            'nl' => '+ Aanmaken',
        ],
        'Erstellen' => [
            'en' => 'Create',
            'fr' => 'Créer',
            'es' => 'Crear',
            'nl' => 'Aanmaken',
        ],
        'Gruppenordner' => [
            'en' => 'Group Folders',
            'fr' => 'Dossiers de groupe',
            'es' => 'Carpetas de grupo',
            'nl' => 'Groepsmappen',
        ],
        'Gruppenordner:' => [
            'en' => 'Group folder:',
            'fr' => 'Dossier de groupe :',
            'es' => 'Carpeta de grupo:',
            'nl' => 'Groepsmap:',
        ],
        'Freigegebener Ordner:' => [
            'en' => 'Shared folder:',
            'fr' => 'Dossier partagé :',
            'es' => 'Carpeta compartida:',
            'nl' => 'Gedeelde map:',
        ],
        'Ordner:' => [
            'en' => 'Folder:',
            'fr' => 'Dossier :',
            'es' => 'Carpeta:',
            'nl' => 'Map:',
        ],
        'Aktuell sind Ihnen noch keine Gruppenordner zugewiesen.' => [
            'en' => 'No group folders are currently assigned to you.',
            'fr' => 'Aucun dossier de groupe ne vous est actuellement attribué.',
            'es' => 'Actualmente no tiene carpetas de grupo asignadas.',
            'nl' => 'Er zijn momenteel nog geen groepsmappen aan u toegewezen.',
        ],
        'Ordnername' => [
            'en' => 'Folder Name',
            'fr' => 'Nom du dossier',
            'es' => 'Nombre de carpeta',
            'nl' => 'Mapnaam',
        ],
        'Berechtigung' => [
            'en' => 'Permission',
            'fr' => 'Permission',
            'es' => 'Permiso',
            'nl' => 'Machtiging',
        ],
        'Inhalt / Größe' => [
            'en' => 'Contents / Size',
            'fr' => 'Contenu / Taille',
            'es' => 'Contenido / Tamaño',
            'nl' => 'Inhoud / Grootte',
        ],
        'Größe / Inhalt' => [
            'en' => 'Size / Contents',
            'fr' => 'Taille / Contenu',
            'es' => 'Tamaño / Contenido',
            'nl' => 'Grootte / Inhoud',
        ],
        'Aktion' => [
            'en' => 'Action',
            'fr' => 'Action',
            'es' => 'Acción',
            'nl' => 'Actie',
        ],
        'Lesen & Schreiben' => [
            'en' => 'Read & Write',
            'fr' => 'Lecture & Écriture',
            'es' => 'Lectura y Escritura',
            'nl' => 'Lezen & Schrijven',
        ],
        '✏️ Lesen & Schreiben' => [
            'en' => '✏️ Read & Write',
            'fr' => '✏️ Lecture & Écriture',
            'es' => '✏️ Lectura y Escritura',
            'nl' => '✏️ Lezen & Schrijven',
        ],
        '(Lesen & Schreiben)' => [
            'en' => '(Read & Write)',
            'fr' => '(Lecture & Écriture)',
            'es' => '(Lectura y Escritura)',
            'nl' => '(Lezen & Schrijven)',
        ],
        'Nur Lesen' => [
            'en' => 'Read Only',
            'fr' => 'Lecture seule',
            'es' => 'Solo lectura',
            'nl' => 'Alleen lezen',
        ],
        '👁️ Nur Lesen' => [
            'en' => '👁️ Read Only',
            'fr' => '👁️ Lecture seule',
            'es' => '👁️ Solo lectura',
            'nl' => '👁️ Alleen lezen',
        ],
        '(Nur Lesen)' => [
            'en' => '(Read Only)',
            'fr' => '(Lecture seule)',
            'es' => '(Solo lectura)',
            'nl' => '(Alleen lezen)',
        ],
        'Öffnen' => [
            'en' => 'Open',
            'fr' => 'Ouvrir',
            'es' => 'Abrir',
            'nl' => 'Openen',
        ],
        'Für mich freigegeben' => [
            'en' => 'Shared with me',
            'fr' => 'Partagés avec moi',
            'es' => 'Compartido conmigo',
            'nl' => 'Gedeeld met mij',
        ],
        'Für mich freigegeben (Von Cloud-Benutzern)' => [
            'en' => 'Shared with me (From Cloud Users)',
            'fr' => 'Partagés avec moi (Par des utilisateurs du cloud)',
            'es' => 'Compartido conmigo (Por usuarios de la nube)',
            'nl' => 'Gedeeld met mij (Door cloudgebruikers)',
        ],
        'Aktuell wurden noch keine Ordner oder Dateien von anderen Cloud-Benutzern direkt mit Ihnen geteilt.' => [
            'en' => 'No folders or files have currently been shared directly with you by other cloud users.',
            'fr' => 'Aucun dossier ou fichier n’a encore été partagé directement avec vous par d’autres utilisateurs.',
            'es' => 'Actualmente ningún otro usuario de la nube ha compartido carpetas o archivos directamente con usted.',
            'nl' => 'Er zijn momenteel nog geen mappen of bestanden rechtstreeks door andere cloudgebruikers met u gedeeld.',
        ],
        'Geteilt von' => [
            'en' => 'Shared by',
            'fr' => 'Partagé par',
            'es' => 'Compartido por',
            'nl' => 'Gedeeld door',
        ],
        'Freigegeben am' => [
            'en' => 'Shared on',
            'fr' => 'Partagé le',
            'es' => 'Compartido el',
            'nl' => 'Gedeeld op',
        ],
        'Dateiname' => [
            'en' => 'Filename',
            'fr' => 'Nom du fichier',
            'es' => 'Nombre de archivo',
            'nl' => 'Bestandsnaam',
        ],
        'Verschieben / Kopieren' => [
            'en' => 'Move / Copy',
            'fr' => 'Déplacer / Copier',
            'es' => 'Mover / Copiar',
            'nl' => 'Verplaatsen / Kopiëren',
        ],
        'Größe' => [
            'en' => 'Size',
            'fr' => 'Taille',
            'es' => 'Tamaño',
            'nl' => 'Grootte',
        ],
        'Hochgeladen am' => [
            'en' => 'Uploaded on',
            'fr' => 'Téléversé le',
            'es' => 'Subido el',
            'nl' => 'Geüpload op',
        ],
        'Aktionen' => [
            'en' => 'Actions',
            'fr' => 'Actions',
            'es' => 'Acciones',
            'nl' => 'Acties',
        ],
        'Unterordner' => [
            'en' => 'Subfolder',
            'fr' => 'Sous-dossier',
            'es' => 'Subcarpeta',
            'nl' => 'Submap',
        ],
        '⇄ Verschieben – Meine Dateien' => [
            'en' => '⇄ Move – My Files',
            'fr' => '⇄ Déplacer – Mes fichiers',
            'es' => '⇄ Mover – Mis archivos',
            'nl' => '⇄ Verplaatsen – Mijn bestanden',
        ],
        '⇄ Verschieben – Gruppenordner' => [
            'en' => '⇄ Move – Group Folders',
            'fr' => '⇄ Déplacer – Dossiers de groupe',
            'es' => '⇄ Mover – Carpetas de grupo',
            'nl' => '⇄ Verplaatsen – Groepsmappen',
        ],
        '📋 Kopieren – Meine Dateien' => [
            'en' => '📋 Copy – My Files',
            'fr' => '📋 Copier – Mes fichiers',
            'es' => '📋 Copiar – Mis archivos',
            'nl' => '📋 Kopiëren – Mijn bestanden',
        ],
        '📋 Kopieren – Gruppenordner' => [
            'en' => '📋 Copy – Group Folders',
            'fr' => '📋 Copier – Dossiers de groupe',
            'es' => '📋 Copiar – Carpetas de grupo',
            'nl' => '📋 Kopiëren – Groepsmappen',
        ],
        '⇄ 🏠 Mein Hauptverzeichnis' => [
            'en' => '⇄ 🏠 My Home Directory',
            'fr' => '⇄ 🏠 Mon répertoire principal',
            'es' => '⇄ 🏠 Mi directorio principal',
            'nl' => '⇄ 🏠 Mijn hoofdmap',
        ],
        '📋 🏠 Mein Hauptverzeichnis' => [
            'en' => '📋 🏠 My Home Directory',
            'fr' => '📋 🏠 Mon répertoire principal',
            'es' => '📋 🏠 Mi directorio principal',
            'nl' => '📋 🏠 Mijn hoofdmap',
        ],
        'Vorschau' => [
            'en' => 'Preview',
            'fr' => 'Aperçu',
            'es' => 'Vista previa',
            'nl' => 'Voorbeeld',
        ],
        'Herunterladen' => [
            'en' => 'Download',
            'fr' => 'Télécharger',
            'es' => 'Descargar',
            'nl' => 'Downloaden',
        ],
        'Als ZIP herunterladen' => [
            'en' => 'Download as ZIP',
            'fr' => 'Télécharger en ZIP',
            'es' => 'Descargar como ZIP',
            'nl' => 'Downloaden als ZIP',
        ],
        'Ordner als ZIP herunterladen' => [
            'en' => 'Download folder as ZIP',
            'fr' => 'Télécharger le dossier en ZIP',
            'es' => 'Descargar carpeta como ZIP',
            'nl' => 'Map downloaden als ZIP',
        ],
        'Teilen' => [
            'en' => 'Share',
            'fr' => 'Partager',
            'es' => 'Compartir',
            'nl' => 'Delen',
        ],
        'Löschen' => [
            'en' => 'Delete',
            'fr' => 'Supprimer',
            'es' => 'Eliminar',
            'nl' => 'Verwijderen',
        ],
        '← Vorherige Datei' => [
            'en' => '← Previous file',
            'fr' => '← Fichier précédent',
            'es' => '← Archivo anterior',
            'nl' => '← Vorig bestand',
        ],
        'Nächste Datei →' => [
            'en' => 'Next file →',
            'fr' => 'Fichier suivant →',
            'es' => 'Siguiente archivo →',
            'nl' => 'Volgend bestand →',
        ],

        // --- Papierkorb (trash.php) ---
        '← Zurück zu meinen Dateien' => [
            'en' => '← Back to my files',
            'fr' => '← Retour à mes fichiers',
            'es' => '← Volver a mis archivos',
            'nl' => '← Terug naar mijn bestanden',
        ],
        'Papierkorb komplett leeren' => [
            'en' => 'Empty trash completely',
            'fr' => 'Vider entièrement la corbeille',
            'es' => 'Vaciar papelera por completo',
            'nl' => 'Prullenbak volledig legen',
        ],
        'Ihr Papierkorb ist leer' => [
            'en' => 'Your trash is empty',
            'fr' => 'Votre corbeille est vide',
            'es' => 'Su papelera está vacía',
            'nl' => 'Uw prullenbak is leeg',
        ],
        'Ursprünglicher Ordner' => [
            'en' => 'Original Folder',
            'fr' => 'Dossier d’origine',
            'es' => 'Carpeta original',
            'nl' => 'Oorspronkelijke map',
        ],
        'Aufbewahrung' => [
            'en' => 'Retention',
            'fr' => 'Conservation',
            'es' => 'Retención',
            'nl' => 'Bewaartijd',
        ],
        'Wiederherstellen' => [
            'en' => 'Restore',
            'fr' => 'Restaurer',
            'es' => 'Restaurar',
            'nl' => 'Herstellen',
        ],
        'Datei an ihren ursprünglichen Ort wiederherstellen' => [
            'en' => 'Restore file to its original location',
            'fr' => 'Restaurer le fichier à son emplacement d’origine',
            'es' => 'Restaurar archivo a su ubicación original',
            'nl' => 'Bestand herstellen naar oorspronkelijke locatie',
        ],
        'Endgültig löschen' => [
            'en' => 'Delete permanently',
            'fr' => 'Supprimer définitivement',
            'es' => 'Eliminar definitivamente',
            'nl' => 'Definitief verwijderen',
        ],

        // --- Admin-Bereich (admin.php) ---
        'System-Administration' => [
            'en' => 'System Administration',
            'fr' => 'Administration du système',
            'es' => 'Administración del sistema',
            'nl' => 'Systeembeheer',
        ],
        'Anwendungstitel, Logo & Impressum anpassen, Benutzerkonten (inkl. E-Mail-2FA) anlegen und Gruppenordner verwalten.' => [
            'en' => 'Customize application title, logo & legal notice, manage user accounts (incl. email 2FA) and group folders.',
            'fr' => 'Personnalisez le titre, le logo et les mentions légales, gérez les comptes utilisateurs (avec 2FA e-mail) et les dossiers de groupe.',
            'es' => 'Personalice el título, logotipo y aviso legal, gestione cuentas de usuario (incl. 2FA por correo) y carpetas de grupo.',
            'nl' => 'Pas applicatietitel, logo & colofon aan, beheer gebruikersaccounts (incl. e-mail-2FA) en groepsmappen.',
        ],
        '← Zurück zum Dashboard' => [
            'en' => '← Back to Dashboard',
            'fr' => '← Retour au tableau de bord',
            'es' => '← Volver al panel',
            'nl' => '← Terug naar dashboard',
        ],
        'Anwendungstitel, Logo & Impressum' => [
            'en' => 'Application Title, Logo & Legal Notice',
            'fr' => 'Titre de l’application, logo & mentions légales',
            'es' => 'Título de la aplicación, logotipo y aviso legal',
            'nl' => 'Applicatietitel, logo & colofon',
        ],
        'Passen Sie den Namen der Cloud, das Kopfzeilen-Logo, die 2FA-Absenderadresse und das öffentlich abrufbare Impressum an.' => [
            'en' => 'Customize the cloud name, header logo, 2FA sender address, and the publicly accessible legal notice.',
            'fr' => 'Personnalisez le nom du cloud, le logo d’en-tête, l’adresse d’expédition 2FA et les mentions légales.',
            'es' => 'Personalice el nombre de la nube, el logotipo, la dirección de envío 2FA y el aviso legal público.',
            'nl' => 'Pas de naam van de cloud, het koptekstlogo, het 2FA-afzenderadres en het openbare colofon aan.',
        ],
        'Impressum-Seite öffnen ↗' => [
            'en' => 'Open Legal Notice page ↗',
            'fr' => 'Ouvrir les mentions légales ↗',
            'es' => 'Abrir página de aviso legal ↗',
            'nl' => 'Colofon-pagina openen ↗',
        ],
        'Titel / Name der Anwendung' => [
            'en' => 'Application Title / Name',
            'fr' => 'Titre / Nom de l’application',
            'es' => 'Título / Nombre de la aplicación',
            'nl' => 'Titel / Naam van de applicatie',
        ],
        'Zusatztitel / Badge in der Kopfzeile (optional)' => [
            'en' => 'Subtitle / Header Badge (optional)',
            'fr' => 'Sous-titre / Badge d’en-tête (optionnel)',
            'es' => 'Subtítulo / Insignia en el encabezado (opcional)',
            'nl' => 'Subtitel / Badge in de koptekst (optioneel)',
        ],
        '✉️ E-Mail-Versand für 2FA-Codes' => [
            'en' => '✉️ Email Delivery for 2FA Codes',
            'fr' => '✉️ Envoi d’e-mails pour les codes 2FA',
            'es' => '✉️ Envío de correo para códigos 2FA',
            'nl' => '✉️ E-mailverzending voor 2FA-codes',
        ],
        'Versandart' => [
            'en' => 'Delivery Method',
            'fr' => 'Mode d’envoi',
            'es' => 'Método de envío',
            'nl' => 'Verzendmethode',
        ],
        'Standard PHP mail() / Lokaler Server (z.B. Postfix / STRATO-Webspace)' => [
            'en' => 'Standard PHP mail() / Local Server (e.g. Postfix / Shared Hosting)',
            'fr' => 'PHP mail() standard / Serveur local (ex. Postfix / Hébergement web)',
            'es' => 'PHP mail() estándar / Servidor local (p. ej. Postfix / Hosting web)',
            'nl' => 'Standaard PHP mail() / Lokale server (bijv. Postfix / Webhosting)',
        ],
        'Externer SMTP-Server (z.B. smtp.strato.de, smtp.ionos.de)' => [
            'en' => 'External SMTP Server (e.g. smtp.strato.de, smtp.ionos.de)',
            'fr' => 'Serveur SMTP externe (ex. smtp.strato.de, smtp.ionos.de)',
            'es' => 'Servidor SMTP externo (p. ej. smtp.strato.de, smtp.ionos.de)',
            'nl' => 'Externe SMTP-server (bijv. smtp.strato.de, smtp.ionos.de)',
        ],
        'Absender-E-Mail-Adresse (From)' => [
            'en' => 'Sender Email Address (From)',
            'fr' => 'Adresse e-mail de l’expéditeur (From)',
            'es' => 'Dirección de correo del remitente (From)',
            'nl' => 'Afzender e-mailadres (From)',
        ],
        'Hinweis für STRATO/IONOS: Muss als echtes Postfach Ihrer Domain angelegt sein.' => [
            'en' => 'Note for shared hosts: Must be a valid mailbox on your domain.',
            'fr' => 'Note pour l’hébergement mutualisé : doit être une boîte mail valide de votre domaine.',
            'es' => 'Nota para hosting compartido: debe ser un buzón real de su dominio.',
            'nl' => 'Opmerking voor webhosting: moet een echte mailbox van uw domein zijn.',
        ],
        'SMTP-Server (Host)' => [
            'en' => 'SMTP Server (Host)',
            'fr' => 'Serveur SMTP (Hôte)',
            'es' => 'Servidor SMTP (Host)',
            'nl' => 'SMTP-server (Host)',
        ],
        'Verschlüsselung' => [
            'en' => 'Encryption',
            'fr' => 'Chiffrement',
            'es' => 'Cifrado',
            'nl' => 'Versleuteling',
        ],
        'SMTP-Benutzername' => [
            'en' => 'SMTP Username',
            'fr' => 'Nom d’utilisateur SMTP',
            'es' => 'Usuario SMTP',
            'nl' => 'SMTP-gebruikersnaam',
        ],
        'Eigenes Logo hochladen' => [
            'en' => 'Upload custom logo',
            'fr' => 'Téléverser un logo personnalisé',
            'es' => 'Subir logotipo personalizado',
            'nl' => 'Eigen logo uploaden',
        ],
        'Eigenes Logo entfernen (Standard-Wolken-Icon verwenden)' => [
            'en' => 'Remove custom logo (use default cloud icon)',
            'fr' => 'Supprimer le logo personnalisé (utiliser l’icône nuage par défaut)',
            'es' => 'Eliminar logotipo personalizado (usar icono de nube predeterminado)',
            'nl' => 'Eigen logo verwijderen (standaard wolk-icoon gebruiken)',
        ],
        'Einstellungen speichern' => [
            'en' => 'Save Settings',
            'fr' => 'Enregistrer les paramètres',
            'es' => 'Guardar configuración',
            'nl' => 'Instellingen opslaan',
        ],
        'E-Mail-Versand testen' => [
            'en' => 'Test Email Delivery',
            'fr' => 'Tester l’envoi d’e-mails',
            'es' => 'Probar envío de correo',
            'nl' => 'E-mailverzending testen',
        ],
        '✉️ Test-E-Mail senden' => [
            'en' => '✉️ Send Test Email',
            'fr' => '✉️ Envoyer un e-mail de test',
            'es' => '✉️ Enviar correo de prueba',
            'nl' => '✉️ Test-e-mail verzenden',
        ],
        'Empfänger-E-Mail für Test...' => [
            'en' => 'Recipient email for test...',
            'fr' => 'E-mail du destinataire pour le test...',
            'es' => 'Correo del destinatario para prueba...',
            'nl' => 'Ontvanger e-mail voor test...',
        ],
        'Neuen Benutzer anlegen' => [
            'en' => 'Create New User',
            'fr' => 'Créer un nouvel utilisateur',
            'es' => 'Crear nuevo usuario',
            'nl' => 'Nieuwe gebruiker aanmaken',
        ],
        'Selbstregistrierung ist deaktiviert. Legen Sie hier neue Zugänge inkl. optionaler E-Mail-2FA an.' => [
            'en' => 'Self-registration is disabled. Create new user accounts (incl. optional email 2FA) here.',
            'fr' => 'L’auto-inscription est désactivée. Créez ici de nouveaux comptes (avec 2FA e-mail optionnel).',
            'es' => 'El autorregistro está desactivado. Cree aquí nuevas cuentas de usuario (incl. 2FA opcional por correo).',
            'nl' => 'Zelfregistratie is uitgeschakeld. Maak hier nieuwe accounts aan (incl. optionele e-mail-2FA).',
        ],
        'E-Mail-Adresse (optional, für 2FA)' => [
            'en' => 'Email address (optional, for 2FA)',
            'fr' => 'Adresse e-mail (optionnelle, pour 2FA)',
            'es' => 'Correo electrónico (opcional, para 2FA)',
            'nl' => 'E-mailadres (optioneel, voor 2FA)',
        ],
        'Initial-Passwort (BCRYPT)' => [
            'en' => 'Initial Password (BCRYPT)',
            'fr' => 'Mot de passe initial (BCRYPT)',
            'es' => 'Contraseña inicial (BCRYPT)',
            'nl' => 'Initieel wachtwoord (BCRYPT)',
        ],
        'Mindestens 8 Zeichen' => [
            'en' => 'At least 8 characters',
            'fr' => 'Au moins 8 caractères',
            'es' => 'Mínimo 8 caracteres',
            'nl' => 'Minimaal 8 tekens',
        ],
        'Speicherplatz (MB)' => [
            'en' => 'Storage Quota (MB)',
            'fr' => 'Quota de stockage (Mo)',
            'es' => 'Cuota de almacenamiento (MB)',
            'nl' => 'Opslagruimte (MB)',
        ],
        'Rolle' => [
            'en' => 'Role',
            'fr' => 'Rôle',
            'es' => 'Rol',
            'nl' => 'Rol',
        ],
        'Standardbenutzer' => [
            'en' => 'Standard User',
            'fr' => 'Utilisateur standard',
            'es' => 'Usuario estándar',
            'nl' => 'Standaardgebruiker',
        ],
        'Administrator' => [
            'en' => 'Administrator',
            'fr' => 'Administrateur',
            'es' => 'Administrador',
            'nl' => 'Beheerder',
        ],
        '2FA per E-Mail aktivieren' => [
            'en' => 'Enable Email 2FA',
            'fr' => 'Activer la 2FA par e-mail',
            'es' => 'Activar 2FA por correo',
            'nl' => '2FA per e-mail inschakelen',
        ],
        '(Sendet 6-stelligen Code beim Login)' => [
            'en' => '(Sends 6-digit code on login)',
            'fr' => '(Envoie un code à 6 chiffres à la connexion)',
            'es' => '(Envía un código de 6 dígitos al iniciar sesión)',
            'nl' => '(Stuurt 6-cijferige code bij inloggen)',
        ],
        'Benutzer erstellen' => [
            'en' => 'Create user',
            'fr' => 'Créer l’utilisateur',
            'es' => 'Crear usuario',
            'nl' => 'Gebruiker aanmaken',
        ],
        'Benutzerkonto erstellen' => [
            'en' => 'Create user account',
            'fr' => 'Créer le compte utilisateur',
            'es' => 'Crear cuenta de usuario',
            'nl' => 'Gebruikersaccount aanmaken',
        ],
        'Bestehende Benutzer verwalten' => [
            'en' => 'Manage Existing Users',
            'fr' => 'Gérer les utilisateurs existants',
            'es' => 'Gestionar usuarios existentes',
            'nl' => 'Bestaande gebruikers beheren',
        ],
        'Benutzer & E-Mail' => [
            'en' => 'User & Email',
            'fr' => 'Utilisateur & E-mail',
            'es' => 'Usuario y Correo',
            'nl' => 'Gebruiker & E-mail',
        ],
        'Belegt / Quota' => [
            'en' => 'Used / Quota',
            'fr' => 'Utilisé / Quota',
            'es' => 'Usado / Cuota',
            'nl' => 'Gebruikt / Quota',
        ],
        'Anpassen (E-Mail, 2FA, Quota, Passwort)' => [
            'en' => 'Edit (Email, 2FA, Quota, Password)',
            'fr' => 'Modifier (E-mail, 2FA, Quota, Mot de passe)',
            'es' => 'Editar (Correo, 2FA, Cuota, Contraseña)',
            'nl' => 'Aanpassen (E-mail, 2FA, Quota, Wachtwoord)',
        ],
        'Keine E-Mail' => [
            'en' => 'No email',
            'fr' => 'Aucun e-mail',
            'es' => 'Sin correo',
            'nl' => 'Geen e-mail',
        ],
        'E-Mail für 2FA (optional)' => [
            'en' => 'Email for 2FA (optional)',
            'fr' => 'E-mail pour 2FA (optionnel)',
            'es' => 'Correo para 2FA (opcional)',
            'nl' => 'E-mail voor 2FA (optioneel)',
        ],
        'Neues Passwort (optional)' => [
            'en' => 'New password (optional)',
            'fr' => 'Nouveau mot de passe (optionnel)',
            'es' => 'Nueva contraseña (opcional)',
            'nl' => 'Nieuw wachtwoord (optioneel)',
        ],
        'Speichern' => [
            'en' => 'Save',
            'fr' => 'Enregistrer',
            'es' => 'Guardar',
            'nl' => 'Opslaan',
        ],
        'Aktuell' => [
            'en' => 'Current',
            'fr' => 'Actuel',
            'es' => 'Actual',
            'nl' => 'Huidig',
        ],
        'Benutzergruppe erstellen' => [
            'en' => 'Create User Group',
            'fr' => 'Créer un groupe d’utilisateurs',
            'es' => 'Crear grupo de usuarios',
            'nl' => 'Gebruikersgroep aanmaken',
        ],
        'Fassen Sie mehrere Benutzer in Gruppen zusammen, um Gruppenordner mit einem Klick freizugeben.' => [
            'en' => 'Combine multiple users into groups to grant access to group folders with a single click.',
            'fr' => 'Regroupez plusieurs utilisateurs pour partager des dossiers de groupe en un clic.',
            'es' => 'Agrupe varios usuarios para compartir carpetas de grupo con un solo clic.',
            'nl' => 'Voeg meerdere gebruikers samen in groepen om groepsmappen met één klik vrij te geven.',
        ],
        'Gruppenname' => [
            'en' => 'Group Name',
            'fr' => 'Nom du groupe',
            'es' => 'Nombre del grupo',
            'nl' => 'Groepsnaam',
        ],
        'Beschreibung' => [
            'en' => 'Description',
            'fr' => 'Description',
            'es' => 'Descripción',
            'nl' => 'Beschrijving',
        ],
        'Beschreibung (optional)' => [
            'en' => 'Description (optional)',
            'fr' => 'Description (optionnel)',
            'es' => 'Descripción (opcional)',
            'nl' => 'Beschrijving (optioneel)',
        ],
        'Optional' => [
            'en' => 'Optional',
            'fr' => 'Optionnel',
            'es' => 'Opcional',
            'nl' => 'Optioneel',
        ],
        'Gruppe anlegen' => [
            'en' => 'Create group',
            'fr' => 'Créer le groupe',
            'es' => 'Crear grupo',
            'nl' => 'Groep aanmaken',
        ],
        'Noch keine Benutzergruppen angelegt.' => [
            'en' => 'No user groups created yet.',
            'fr' => 'Aucun groupe d’utilisateurs créé pour le moment.',
            'es' => 'Aún no se han creado grupos de usuarios.',
            'nl' => 'Nog geen gebruikersgroepen aangemaakt.',
        ],
        'Gruppe löschen' => [
            'en' => 'Delete group',
            'fr' => 'Supprimer le groupe',
            'es' => 'Eliminar grupo',
            'nl' => 'Groep verwijderen',
        ],
        'Keine Mitglieder' => [
            'en' => 'No members',
            'fr' => 'Aucun membre',
            'es' => 'Sin miembros',
            'nl' => 'Geen leden',
        ],
        'Mitglied hinzufügen...' => [
            'en' => 'Add member...',
            'fr' => 'Ajouter un membre...',
            'es' => 'Añadir miembro...',
            'nl' => 'Lid toevoegen...',
        ],
        'Gruppen & Mitglieder' => [
            'en' => 'Groups & Members',
            'fr' => 'Groupes & Membres',
            'es' => 'Grupos y Miembros',
            'nl' => 'Groepen & Leden',
        ],
        'Hinzufügen' => [
            'en' => 'Add',
            'fr' => 'Ajouter',
            'es' => 'Añadir',
            'nl' => 'Toevoegen',
        ],
        'Gruppenordner erstellen' => [
            'en' => 'Create Group Folder',
            'fr' => 'Créer un dossier de groupe',
            'es' => 'Crear carpeta de grupo',
            'nl' => 'Groepsmap aanmaken',
        ],
        'Gruppenordner anlegen' => [
            'en' => 'Create Group Folder',
            'fr' => 'Créer un dossier de groupe',
            'es' => 'Crear carpeta de grupo',
            'nl' => 'Groepsmap aanmaken',
        ],
        'Erstellen Sie gemeinsame Ordner und berechtigen Sie gezielt einzelne Benutzer oder ganze Benutzergruppen.' => [
            'en' => 'Create shared folders and grant permissions to specific users or entire user groups.',
            'fr' => 'Créez des dossiers partagés et accordez des droits à des utilisateurs ou groupes.',
            'es' => 'Cree carpetas compartidas y otorgue permisos a usuarios individuales o grupos.',
            'nl' => 'Maak gedeelde mappen aan en geef specifieke gebruikers of groepen toegang.',
        ],
        'Name des Gruppenordners' => [
            'en' => 'Group Folder Name',
            'fr' => 'Nom du dossier de groupe',
            'es' => 'Nombre de la carpeta de grupo',
            'nl' => 'Naam van de groepsmap',
        ],
        'Noch keine Gruppenordner angelegt.' => [
            'en' => 'No group folders created yet.',
            'fr' => 'Aucun dossier de groupe créé pour le moment.',
            'es' => 'Aún no se han creado carpetas de grupo.',
            'nl' => 'Nog geen groepsmappen aangemaakt.',
        ],
        'Berechtigte Benutzer & Gruppen:' => [
            'en' => 'Authorized Users & Groups:',
            'fr' => 'Utilisateurs & groupes autorisés :',
            'es' => 'Usuarios y grupos autorizados:',
            'nl' => 'Gemachtigde gebruikers & groepen:',
        ],
        'Derzeit nur für Administratoren sichtbar. Fügen Sie unten Benutzer oder Gruppen hinzu.' => [
            'en' => 'Currently visible to administrators only. Add users or groups below.',
            'fr' => 'Actuellement visible uniquement par les administrateurs. Ajoutez des utilisateurs ou groupes ci-dessous.',
            'es' => 'Actualmente solo visible para administradores. Añada usuarios o grupos abajo.',
            'nl' => 'Momenteel alleen zichtbaar voor beheerders. Voeg hieronder gebruikers of groepen toe.',
        ],
        '+ Benutzer wählen...' => [
            'en' => '+ Select user...',
            'fr' => '+ Choisir un utilisateur...',
            'es' => '+ Seleccionar usuario...',
            'nl' => '+ Gebruiker kiezen...',
        ],
        '+ Gruppe wählen...' => [
            'en' => '+ Select group...',
            'fr' => '+ Choisir un groupe...',
            'es' => '+ Seleccionar grupo...',
            'nl' => '+ Groep kiezen...',
        ],
        'Schreiben' => [
            'en' => 'Read & Write',
            'fr' => 'Écriture',
            'es' => 'Escritura',
            'nl' => 'Schrijven',
        ],
        'Berechtigen' => [
            'en' => 'Grant',
            'fr' => 'Autoriser',
            'es' => 'Autorizar',
            'nl' => 'Machtigen',
        ],
        'Gruppe +' => [
            'en' => 'Group +',
            'fr' => 'Groupe +',
            'es' => 'Grupo +',
            'nl' => 'Groep +',
        ],
        'Gruppenordner & Zugriffsrechte' => [
            'en' => 'Group Folders & Access Permissions',
            'fr' => 'Dossiers de groupe & Droits d’accès',
            'es' => 'Carpetas de grupo y Permisos de acceso',
            'nl' => 'Groepsmappen & Toegangsrechten',
        ],
        'Berechtigung speichern' => [
            'en' => 'Save permission',
            'fr' => 'Enregistrer la permission',
            'es' => 'Guardar permiso',
            'nl' => 'Machtiging opslaan',
        ],
        'Server-Status & Systemressourcen' => [
            'en' => 'Server Status & System Resources',
            'fr' => 'État du serveur & Ressources système',
            'es' => 'Estado del servidor y Recursos del sistema',
            'nl' => 'Serverstatus & Systeemstatus',
        ],
        '💾 Speicherplatz (Server)' => [
            'en' => '💾 Disk Space (Server)',
            'fr' => '💾 Espace disque (Serveur)',
            'es' => '💾 Espacio en disco (Servidor)',
            'nl' => '💾 Schijfruimte (Server)',
        ],
        '🧠 Arbeitsspeicher (RAM)' => [
            'en' => '🧠 Memory (RAM)',
            'fr' => '🧠 Mémoire vive (RAM)',
            'es' => '🧠 Memoria (RAM)',
            'nl' => '🧠 Werkgeheugen (RAM)',
        ],
        'Aktiv' => [
            'en' => 'Active',
            'fr' => 'Actif',
            'es' => 'Activo',
            'nl' => 'Actief',
        ],
        'Empfangen (↓)' => [
            'en' => 'Received (↓)',
            'fr' => 'Reçu (↓)',
            'es' => 'Recibido (↓)',
            'nl' => 'Ontvangen (↓)',
        ],
        'Gesendet (↑)' => [
            'en' => 'Sent (↑)',
            'fr' => 'Envoyé (↑)',
            'es' => 'Enviado (↑)',
            'nl' => 'Verzonden (↑)',
        ],

        // --- Impressum & Rechtlicher Hinweis ---
        'Impressum & Rechtlicher Hinweis' => [
            'en' => 'Legal Notice & Privacy Information',
            'fr' => 'Mentions légales & Confidentialité',
            'es' => 'Aviso legal y Privacidad',
            'nl' => 'Colofon & Juridische kennisgeving',
        ],
        '✏️ Text anpassen' => [
            'en' => '✏️ Edit text',
            'fr' => '✏️ Modifier le texte',
            'es' => '✏️ Editar texto',
            'nl' => '✏️ Tekst aanpassen',
        ],
        '← Zurück' => [
            'en' => '← Back',
            'fr' => '← Retour',
            'es' => '← Volver',
            'nl' => '← Terug',
        ],

        // --- Teilen-Modal & Öffentliche Freigabe ---
        'Element teilen' => [
            'en' => 'Share Item',
            'fr' => 'Partager l’élément',
            'es' => 'Compartir elemento',
            'nl' => 'Item delen',
        ],
        'Datei / Ordner teilen' => [
            'en' => 'Share File / Folder',
            'fr' => 'Partager un fichier / dossier',
            'es' => 'Compartir archivo / carpeta',
            'nl' => 'Bestand / map delen',
        ],
        '1. Öffentlicher Freigabelink (für Freunde)' => [
            'en' => '1. Public Share Link (for external users)',
            'fr' => '1. Lien de partage public (pour vos proches)',
            'es' => '1. Enlace público compartido (para amigos)',
            'nl' => '1. Openbare deellink (voor vrienden)',
        ],
        'Öffentlicher Freigabelink' => [
            'en' => 'Public Share Link',
            'fr' => 'Lien de partage public',
            'es' => 'Enlace público compartido',
            'nl' => 'Openbare deellink',
        ],
        'Neuen Freigabelink erstellen' => [
            'en' => 'Create new share link',
            'fr' => 'Créer un nouveau lien de partage',
            'es' => 'Crear nuevo enlace compartido',
            'nl' => 'Nieuwe deellink maken',
        ],
        'Passwortschutz (optional)' => [
            'en' => 'Password protection (optional)',
            'fr' => 'Protection par mot de passe (optionnel)',
            'es' => 'Protección con contraseña (opcional)',
            'nl' => 'Wachtwoordbeveiliging (optioneel)',
        ],
        'Kein Passwort' => [
            'en' => 'No password',
            'fr' => 'Aucun mot de passe',
            'es' => 'Sin contraseña',
            'nl' => 'Geen wachtwoord',
        ],
        'Ablaufdatum (optional)' => [
            'en' => 'Expiration date (optional)',
            'fr' => 'Date d’expiration (optionnel)',
            'es' => 'Fecha de caducidad (opcional)',
            'nl' => 'Vervaldatum (optioneel)',
        ],
        'Unbegrenzt gültig' => [
            'en' => 'Valid indefinitely',
            'fr' => 'Valable sans limite',
            'es' => 'Válido indefinidamente',
            'nl' => 'Onbeperkt geldig',
        ],
        '1 Tag gültig' => [
            'en' => 'Valid for 1 day',
            'fr' => 'Valable 1 jour',
            'es' => 'Válido 1 día',
            'nl' => '1 dag geldig',
        ],
        '7 Tage gültig' => [
            'en' => 'Valid for 7 days',
            'fr' => 'Valable 7 jours',
            'es' => 'Válido 7 días',
            'nl' => '7 dagen geldig',
        ],
        '30 Tage gültig' => [
            'en' => 'Valid for 30 days',
            'fr' => 'Valable 30 jours',
            'es' => 'Válido 30 días',
            'nl' => '30 dagen geldig',
        ],
        'Link generieren' => [
            'en' => 'Generate link',
            'fr' => 'Générer le lien',
            'es' => 'Generar enlace',
            'nl' => 'Link genereren',
        ],
        'Link erzeugen' => [
            'en' => 'Generate link',
            'fr' => 'Générer le lien',
            'es' => 'Generar enlace',
            'nl' => 'Link genereren',
        ],
        'Fertiger Freigabelink:' => [
            'en' => 'Generated share link:',
            'fr' => 'Lien de partage prêt :',
            'es' => 'Enlace compartido listo:',
            'nl' => 'Gegenereerde deellink:',
        ],
        '✓ Kopiert!' => [
            'en' => '✓ Copied!',
            'fr' => '✓ Copié !',
            'es' => '✓ ¡Copiado!',
            'nl' => '✓ Gekopieerd!',
        ],
        '2. Direkt mit Cloud-Benutzer teilen' => [
            'en' => '2. Share directly with Cloud User',
            'fr' => '2. Partager directement avec un utilisateur du cloud',
            'es' => '2. Compartir directamente con usuario de la nube',
            'nl' => '2. Direct delen met cloudgebruiker',
        ],
        'Direkt mit Cloud-Benutzer teilen' => [
            'en' => 'Share directly with cloud user',
            'fr' => 'Partager directement avec un utilisateur du cloud',
            'es' => 'Compartir directamente con un usuario de la nube',
            'nl' => 'Direct delen met cloudgebruiker',
        ],
        'Es sind noch keine weiteren Cloud-Benutzer im System angelegt.' => [
            'en' => 'No other cloud users have been created in the system yet.',
            'fr' => 'Aucun autre utilisateur n’est encore enregistré dans le système.',
            'es' => 'Aún no hay otros usuarios creados en el sistema.',
            'nl' => 'Er zijn nog geen andere cloudgebruikers in het systeem aangemaakt.',
        ],
        'Benutzer auswählen...' => [
            'en' => 'Select user...',
            'fr' => 'Choisir un utilisateur...',
            'es' => 'Seleccionar usuario...',
            'nl' => 'Gebruiker kiezen...',
        ],
        'Für Nutzer freigeben' => [
            'en' => 'Share with user',
            'fr' => 'Partager avec l’utilisateur',
            'es' => 'Compartir con usuario',
            'nl' => 'Delen met gebruiker',
        ],
        'Freigeben' => [
            'en' => 'Share',
            'fr' => 'Partager',
            'es' => 'Compartir',
            'nl' => 'Delen',
        ],
        'Kopieren' => [
            'en' => 'Copy',
            'fr' => 'Copier',
            'es' => 'Copiar',
            'nl' => 'Kopiëren',
        ],
        'Kopiert!' => [
            'en' => 'Copied!',
            'fr' => 'Copié !',
            'es' => '¡Copiado!',
            'nl' => 'Gekopieerd!',
        ],
        'Jetzt herunterladen' => [
            'en' => 'Download now',
            'fr' => 'Télécharger maintenant',
            'es' => 'Descargar ahora',
            'nl' => 'Nu downloaden',
        ],
        'Freigabe entsperren' => [
            'en' => 'Unlock share',
            'fr' => 'Déverrouiller le partage',
            'es' => 'Desbloquear recurso compartido',
            'nl' => 'Gedeeld item ontgrendelen',
        ],

        // --- Einstellungsseite (settings.php) ---
        'Einstellungen' => [
            'en' => 'Settings',
            'fr' => 'Paramètres',
            'es' => 'Ajustes',
            'nl' => 'Instellingen',
        ],
        'Sprache, Farbdesign und persönliche Kontoeinstellungen anpassen.' => [
            'en' => 'Customize language, color theme, and personal account settings.',
            'fr' => 'Personnaliser la langue, le thème visuel et les paramètres du compte.',
            'es' => 'Personalizar idioma, tema de color y ajustes de la cuenta.',
            'nl' => 'Taal, kleurthema en persoonlijke accountinstellingen aanpassen.',
        ],
        '← Zurück' => [
            'en' => '← Back',
            'fr' => '← Retour',
            'es' => '← Volver',
            'nl' => '← Terug',
        ],
        'Benutzerverwaltung, Gruppenordner, Branding, Impressum und SMTP-Mailserver konfigurieren.' => [
            'en' => 'Configure user management, group folders, branding, legal notice, and SMTP mail server.',
            'fr' => 'Configurer les utilisateurs, dossiers de groupe, personnalisation, mentions légales et serveur SMTP.',
            'es' => 'Configurar gestión de usuarios, carpetas de grupo, marca, aviso legal y servidor SMTP.',
            'nl' => 'Gebruikersbeheer, groepsmappen, huisstijl, colofon en SMTP-mailserver configureren.',
        ],
        'Administration öffnen →' => [
            'en' => 'Open Administration →',
            'fr' => 'Ouvrir l’administration →',
            'es' => 'Abrir administración →',
            'nl' => 'Beheer openen →',
        ],
        'Anwendungssprache' => [
            'en' => 'Application Language',
            'fr' => 'Langue de l’application',
            'es' => 'Idioma de la aplicación',
            'nl' => 'Toepassingstaal',
        ],
        'Wählen Sie Ihre bevorzugte Sprache für die Benutzeroberfläche.' => [
            'en' => 'Choose your preferred language for the user interface.',
            'fr' => 'Choisissez votre langue préférée pour l’interface utilisateur.',
            'es' => 'Elija su idioma preferido para la interfaz de usuario.',
            'nl' => 'Kies uw voorkeurstaal voor de gebruikersinterface.',
        ],
        'Farbdesign & Theme' => [
            'en' => 'Color Design & Theme',
            'fr' => 'Design & Thème de couleur',
            'es' => 'Diseño de color y Tema',
            'nl' => 'Kleurontwerp & Thema',
        ],
        'Alle hellen Themes sind angenehm abgedunkelt, um ein grelles Blenden zu vermeiden.' => [
            'en' => 'All light themes are softly toned down to prevent harsh glare.',
            'fr' => 'Tous les thèmes clairs sont agréablement tamisés pour éviter l’éblouissement.',
            'es' => 'Todos los temas claros están suavemente atenuados para evitar el deslumbramiento.',
            'nl' => 'Alle lichte thema’s zijn aangenaam gedempt om fel verblinden te voorkomen.',
        ],
        'Eigenes Passwort ändern' => [
            'en' => 'Change Your Password',
            'fr' => 'Modifier votre mot de passe',
            'es' => 'Cambiar su contraseña',
            'nl' => 'Eigen wachtwoord wijzigen',
        ],
        'Aktualisieren Sie Ihr persönliches Anmeldepasswort (mindestens 8 Zeichen, BCRYPT-verschlüsselt).' => [
            'en' => 'Update your personal sign-in password (minimum 8 characters, BCRYPT encrypted).',
            'fr' => 'Mettez à jour votre mot de passe personnel (au moins 8 caractères, chiffré BCRYPT).',
            'es' => 'Actualice su contraseña personal de inicio de sesión (mínimo 8 caracteres, cifrado BCRYPT).',
            'nl' => 'Werk uw persoonlijke inlogwachtwoord bij (minimaal 8 tekens, BCRYPT-versleuteld).',
        ],
        'Aktuelles Passwort' => [
            'en' => 'Current password',
            'fr' => 'Mot de passe actuel',
            'es' => 'Contraseña actual',
            'nl' => 'Huidig wachtwoord',
        ],
        'Neues Passwort (mind. 8 Zeichen)' => [
            'en' => 'New password (min. 8 chars)',
            'fr' => 'Nouveau mot de passe (min. 8 caract.)',
            'es' => 'Nueva contraseña (mín. 8 caract.)',
            'nl' => 'Nieuw wachtwoord (min. 8 tekens)',
        ],
        'Mindestens 8 Zeichen' => [
            'en' => 'Minimum 8 characters',
            'fr' => 'Au moins 8 caractères',
            'es' => 'Mínimo 8 caracteres',
            'nl' => 'Minimaal 8 tekens',
        ],
        'Neues Passwort bestätigen' => [
            'en' => 'Confirm new password',
            'fr' => 'Confirmer le nouveau mot de passe',
            'es' => 'Confirmar nueva contraseña',
            'nl' => 'Nieuw wachtwoord bevestigen',
        ],
        'Passwort wiederholen' => [
            'en' => 'Repeat password',
            'fr' => 'Répéter le mot de passe',
            'es' => 'Repetir contraseña',
            'nl' => 'Wachtwoord herhalen',
        ],
        'Passwort speichern' => [
            'en' => 'Save password',
            'fr' => 'Enregistrer le mot de passe',
            'es' => 'Guardar contraseña',
            'nl' => 'Wachtwoord opslaan',
        ],
        'Das aktuelle Passwort ist nicht korrekt.' => [
            'en' => 'The current password is incorrect.',
            'fr' => 'Le mot de passe actuel est incorrect.',
            'es' => 'La contraseña actual no es correcta.',
            'nl' => 'Het huidige wachtwoord is niet juist.',
        ],
        'Das neue Passwort muss mindestens 8 Zeichen lang sein.' => [
            'en' => 'The new password must be at least 8 characters long.',
            'fr' => 'Le nouveau mot de passe doit comporter au moins 8 caractères.',
            'es' => 'La nueva contraseña debe tener al menos 8 caracteres.',
            'nl' => 'Het nieuwe wachtwoord moet minimaal 8 tekens lang zijn.',
        ],
        'Die neuen Passwörter stimmen nicht überein.' => [
            'en' => 'The new passwords do not match.',
            'fr' => 'Les nouveaux mots de passe ne correspondent pas.',
            'es' => 'Las nuevas contraseñas no coinciden.',
            'nl' => 'De nieuwe wachtwoorden komen niet overeen.',
        ],
        'Ihr Passwort wurde erfolgreich geändert.' => [
            'en' => 'Your password has been changed successfully.',
            'fr' => 'Votre mot de passe a été modifié avec succès.',
            'es' => 'Su contraseña ha sido cambiada con éxito.',
            'nl' => 'Uw wachtwoord is succesvol gewijzigd.',
        ],

        // --- Tresor-Modus (AES-256 Einzeldatei-Verschlüsselung) ---
        '🔒 Tresor' => [
            'en' => '🔒 Vault',
            'fr' => '🔒 Coffre',
            'es' => '🔒 Bóveda',
            'nl' => '🔒 Kluis',
        ],
        '🔒 Tresor-Modus' => [
            'en' => '🔒 Vault Mode',
            'fr' => '🔒 Mode Coffre',
            'es' => '🔒 Modo Bóveda',
            'nl' => '🔒 Kluismodus',
        ],
        '🔒 Tresor-Passwort (optional)' => [
            'en' => '🔒 Vault password (optional)',
            'fr' => '🔒 Mot de passe Coffre (optionnel)',
            'es' => '🔒 Contraseña de bóveda (opcional)',
            'nl' => '🔒 Kluiswachtwoord (optioneel)',
        ],
        'Optional: Dateien direkt beim Hochladen mit AES-256 verschlüsseln (mind. 4 Zeichen)' => [
            'en' => 'Optional: Encrypt files directly during upload with AES-256 (min. 4 chars)',
            'fr' => 'Optionnel : Chiffrer les fichiers dès l’envoi avec AES-256 (min. 4 caract.)',
            'es' => 'Opcional: Cifrar archivos directamente al subirlos con AES-256 (mín. 4 caract.)',
            'nl' => 'Optioneel: Bestanden direct bij het uploaden versleutelen met AES-256 (min. 4 tekens)',
        ],
        'Tresor-Verschlüsselung' => [
            'en' => 'Vault Encryption',
            'fr' => 'Chiffrement Coffre-fort',
            'es' => 'Cifrado de Bóveda',
            'nl' => 'Kluisversleuteling',
        ],
        'Tresor-geschützte Datei' => [
            'en' => 'Vault-Protected File',
            'fr' => 'Fichier protégé par coffre-fort',
            'es' => 'Archivo protegido en bóveda',
            'nl' => 'Kluisbeveiligd bestand',
        ],
        'Tresor-geschützte Datei (AES-256 Zero-Knowledge)' => [
            'en' => 'Vault-protected file (AES-256 Zero-Knowledge)',
            'fr' => 'Fichier protégé par coffre-fort (AES-256 Zero-Knowledge)',
            'es' => 'Archivo protegido en bóveda (AES-256 Zero-Knowledge)',
            'nl' => 'Kluisbeveiligd bestand (AES-256 Zero-Knowledge)',
        ],
        'Im Tresor verschlüsseln (AES-256)' => [
            'en' => 'Encrypt in Vault (AES-256)',
            'fr' => 'Chiffrer dans le coffre (AES-256)',
            'es' => 'Cifrar en bóveda (AES-256)',
            'nl' => 'In kluis versleutelen (AES-256)',
        ],
        'Tresor-Schutz verwalten / entschlüsseln' => [
            'en' => 'Manage / decrypt Vault protection',
            'fr' => 'Gérer / déchiffrer la protection du coffre',
            'es' => 'Gestionar / descifrar protección de bóveda',
            'nl' => 'Kluisbeveiliging beheren / ontsleutelen',
        ],
        'Entschlüsseln & Herunterladen' => [
            'en' => 'Decrypt & Download',
            'fr' => 'Déchiffrer et télécharger',
            'es' => 'Descifrar y descargar',
            'nl' => 'Ontsleutelen & downloaden',
        ],
        '🔓 Entschlüsseln & Herunterladen' => [
            'en' => '🔓 Decrypt & Download',
            'fr' => '🔓 Déchiffrer et télécharger',
            'es' => '🔓 Descifrar y descargar',
            'nl' => '🔓 Ontsleutelen & downloaden',
        ],
        '📥 Entschlüsselt herunterladen' => [
            'en' => '📥 Decrypt & Download',
            'fr' => '📥 Déchiffrer et télécharger',
            'es' => '📥 Descifrar y descargar',
            'nl' => '📥 Ontsleutelen & downloaden',
        ],
        'Tresor-Passwort festlegen (mind. 4 Zeichen)' => [
            'en' => 'Set Vault password (min. 4 chars)',
            'fr' => 'Définir le mot de passe du coffre (min. 4 caract.)',
            'es' => 'Establecer contraseña de bóveda (mín. 4 caract.)',
            'nl' => 'Kluiswachtwoord instellen (min. 4 tekens)',
        ],
        'Geheimes Tresor-Passwort...' => [
            'en' => 'Secret Vault password...',
            'fr' => 'Mot de passe secret du coffre...',
            'es' => 'Contraseña secreta de bóveda...',
            'nl' => 'Geheim kluiswachtwoord...',
        ],
        'Tresor-Passwort wiederholen' => [
            'en' => 'Repeat Vault password',
            'fr' => 'Répéter le mot de passe du coffre',
            'es' => 'Repetir contraseña de bóveda',
            'nl' => 'Kluiswachtwoord herhalen',
        ],
        'Passwort zur Sicherheit bestätigen...' => [
            'en' => 'Confirm password for safety...',
            'fr' => 'Confirmer le mot de passe par sécurité...',
            'es' => 'Confirmar contraseña por seguridad...',
            'nl' => 'Wachtwoord voor de zekerheid bevestigen...',
        ],
        '🔒 Jetzt mit AES-256 verschlüsseln' => [
            'en' => '🔒 Encrypt now with AES-256',
            'fr' => '🔒 Chiffrer maintenant avec AES-256',
            'es' => '🔒 Cifrar ahora con AES-256',
            'nl' => '🔒 Nu met AES-256 versleutelen',
        ],
        '1. Entschlüsselt herunterladen' => [
            'en' => '1. Download Decrypted Copy',
            'fr' => '1. Télécharger une copie déchiffrée',
            'es' => '1. Descargar copia descifrada',
            'nl' => '1. Ontsleuteld downloaden',
        ],
        'Lädt die Datei entschlüsselt auf Ihr Gerät herunter. In der Cloud bleibt der Tresor-Schutz weiter bestehen.' => [
            'en' => 'Downloads the decrypted file to your device while keeping Vault protection active in the cloud.',
            'fr' => 'Télécharge le fichier déchiffré sur votre appareil tout en conservant la protection dans le cloud.',
            'es' => 'Descarga el archivo descifrado en su dispositivo manteniendo la protección en la nube.',
            'nl' => 'Downloadt het bestand ontsleuteld naar uw apparaat terwijl de kluisbeveiliging in de cloud actief blijft.',
        ],
        'Tresor-Passwort eingeben...' => [
            'en' => 'Enter Vault password...',
            'fr' => 'Saisir le mot de passe du coffre...',
            'es' => 'Introducir contraseña de bóveda...',
            'nl' => 'Kluiswachtwoord invoeren...',
        ],
        'Tresor-Passwort eingeben' => [
            'en' => 'Enter Vault password',
            'fr' => 'Saisir le mot de passe du coffre',
            'es' => 'Introducir contraseña de bóveda',
            'nl' => 'Kluiswachtwoord invoeren',
        ],
        'Persönliches Tresor-Passwort...' => [
            'en' => 'Personal Vault password...',
            'fr' => 'Mot de passe personnel du coffre...',
            'es' => 'Contraseña personal de bóveda...',
            'nl' => 'Persoonlijk kluiswachtwoord...',
        ],
        '2. Tresor-Schutz dauerhaft aufheben' => [
            'en' => '2. Permanently Remove Vault Protection',
            'fr' => '2. Supprimer définitivement la protection du coffre',
            'es' => '2. Eliminar permanentemente la protección de bóveda',
            'nl' => '2. Kluisbeveiliging permanent opheffen',
        ],
        'Entschlüsselt die Datei dauerhaft auf dem Server, sodass sie wieder als normale Datei vorliegt.' => [
            'en' => 'Permanently decrypts the file on the server so it is stored as a regular file again.',
            'fr' => 'Déchiffre définitivement le fichier sur le serveur pour qu’il redevienne un fichier normal.',
            'es' => 'Descifra permanentemente el archivo en el servidor para que vuelva a ser un archivo normal.',
            'nl' => 'Ontsleutelt het bestand permanent op de server zodat het weer als normaal bestand beschikbaar is.',
        ],
        '🔓 Tresor-Schutz dauerhaft entfernen' => [
            'en' => '🔓 Remove Vault protection permanently',
            'fr' => '🔓 Supprimer définitivement la protection',
            'es' => '🔓 Eliminar protección permanentemente',
            'nl' => '🔓 Kluisbeveiliging permanent verwijderen',
        ],
        'Das eingegebene Tresor-Passwort ist falsch.' => [
            'en' => 'The entered Vault password is incorrect.',
            'fr' => 'Le mot de passe du coffre saisi est incorrect.',
            'es' => 'La contraseña de bóveda introducida es incorrecta.',
            'nl' => 'Het ingevoerde kluiswachtwoord is onjuist.',
        ],

        // --- Verschieben / Kopieren Modal & 2FA-Selbstverwaltung ---
        'Verschieben oder Kopieren' => [
            'en' => 'Move or Copy',
            'fr' => 'Déplacer ou copier',
            'es' => 'Mover o copiar',
            'nl' => 'Verplaatsen of kopiëren',
        ],
        'Ordner verschieben oder kopieren' => [
            'en' => 'Move or copy folder',
            'fr' => 'Déplacer ou copier le dossier',
            'es' => 'Mover o copiar carpeta',
            'nl' => 'Map verplaatsen of kopiëren',
        ],
        'Datei verschieben oder kopieren' => [
            'en' => 'Move or copy file',
            'fr' => 'Déplacer ou copier le fichier',
            'es' => 'Mover o copiar archivo',
            'nl' => 'Bestand verplaatsen of kopiëren',
        ],
        'Aktion & Zielordner auswählen' => [
            'en' => 'Select action & target folder',
            'fr' => 'Sélectionner l’action et le dossier cible',
            'es' => 'Seleccionar acción y carpeta de destino',
            'nl' => 'Actie & doelmap selecteren',
        ],
        'Ausführen' => [
            'en' => 'Execute',
            'fr' => 'Exécuter',
            'es' => 'Ejecutar',
            'nl' => 'Uitvoeren',
        ],
        'Zwei-Faktor-Authentifizierung (2FA)' => [
            'en' => 'Two-Factor Authentication (2FA)',
            'fr' => 'Authentification à deux facteurs (2FA)',
            'es' => 'Autenticación de dos factores (2FA)',
            'nl' => 'Tweefactorauthenticatie (2FA)',
        ],
        'Entscheiden Sie selbst, ob beim Anmelden zusätzlich ein 6-stelliger Sicherheitscode per E-Mail abgefragt werden soll.' => [
            'en' => 'Decide for yourself whether an additional 6-digit security code should be requested via email when signing in.',
            'fr' => 'Choisissez librement si un code de sécurité à 6 chiffres doit être demandé par e-mail lors de la connexion.',
            'es' => 'Decida usted mismo si desea solicitar un código de seguridad adicional de 6 dígitos por correo al iniciar sesión.',
            'nl' => 'Bepaal zelf of er bij het inloggen een extra 6-cijferige beveiligingscode per e-mail moet worden gevraagd.',
        ],
        '✓ 2FA Aktiv' => [
            'en' => '✓ 2FA Active',
            'fr' => '✓ 2FA Active',
            'es' => '✓ 2FA Activo',
            'nl' => '✓ 2FA Actief',
        ],
        'Deaktiviert' => [
            'en' => 'Disabled',
            'fr' => 'Désactivé',
            'es' => 'Desactivado',
            'nl' => 'Uitgeschakeld',
        ],
        'Ihre E-Mail-Adresse für Sicherheitscodes' => [
            'en' => 'Your email address for security codes',
            'fr' => 'Votre adresse e-mail pour les codes de sécurité',
            'es' => 'Su dirección de correo para códigos de seguridad',
            'nl' => 'Uw e-mailadres voor beveiligingscodes',
        ],
        '2FA per E-Mail beim Login aktivieren' => [
            'en' => 'Enable email 2FA on login',
            'fr' => 'Activer la 2FA par e-mail à la connexion',
            'es' => 'Activar 2FA por correo al iniciar sesión',
            'nl' => '2FA per e-mail bij inloggen inschakelen',
        ],
        '2FA-Einstellung speichern' => [
            'en' => 'Save 2FA setting',
            'fr' => 'Enregistrer le paramètre 2FA',
            'es' => 'Guardar ajuste 2FA',
            'nl' => '2FA-instelling opslaan',
        ],
        'Bitte geben Sie eine gültige E-Mail-Adresse ein.' => [
            'en' => 'Please enter a valid email address.',
            'fr' => 'Veuillez saisir une adresse e-mail valide.',
            'es' => 'Por favor, introduzca una dirección de correo válida.',
            'nl' => 'Voer een geldig e-mailadres in.',
        ],
        'Für die Aktivierung der Zwei-Faktor-Authentifizierung (2FA) ist eine E-Mail-Adresse erforderlich.' => [
            'en' => 'An email address is required to enable Two-Factor Authentication (2FA).',
            'fr' => 'Une adresse e-mail est requise pour activer l’authentification à deux facteurs (2FA).',
            'es' => 'Se requiere una dirección de correo electrónico para activar la autenticación de dos factores (2FA).',
            'nl' => 'Voor het inschakelen van tweefactorauthenticatie (2FA) is een e-mailadres vereist.',
        ],
        'Zwei-Faktor-Authentifizierung (2FA per E-Mail) wurde aktiviert.' => [
            'en' => 'Two-Factor Authentication (Email 2FA) has been enabled.',
            'fr' => 'L’authentification à deux facteurs (2FA par e-mail) a été activée.',
            'es' => 'La autenticación de dos factores (2FA por correo) ha sido activada.',
            'nl' => 'Tweefactorauthenticatie (2FA per e-mail) is ingeschakeld.',
        ],
        'Zwei-Faktor-Authentifizierung (2FA) wurde deaktiviert.' => [
            'en' => 'Two-Factor Authentication (2FA) has been disabled.',
            'fr' => 'L’authentification à deux facteurs (2FA) a été désactivée.',
            'es' => 'La autenticación de dos factores (2FA) ha sido desactivada.',
            'nl' => 'Tweefactorauthenticatie (2FA) is uitgeschakeld.',
        ],
    ];

    return $dict;
}

/**
 * Übersetzt einen einzelnen Textknoten oder Attributwert in die Zielsprache.
 */
function translateTextSegment(string $text, string $lang): string
{
    if ($lang === 'de' || trim($text) === '') {
        return $text;
    }

    // Führende und nachfolgende Leerzeichen bewahren
    preg_match('/^(\s*)(.*?)(\s*)$/su', $text, $m);
    $lead  = $m[1] ?? '';
    $core  = $m[2] ?? '';
    $trail = $m[3] ?? '';

    if ($core === '') {
        return $text;
    }

    // HTML-Entities wie &amp; oder &larr; für den Wörterbuch-Abgleich normalisieren
    // Mehrfache Leerzeichen/Zeilenumbrüche innerhalb eines Buttons/Titels auf ein Leerzeichen reduzieren
    $decodedCore = preg_replace('/\s+/u', ' ', html_entity_decode($core, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '';
    $dict = getTranslationDictionary();

    if (isset($dict[$decodedCore][$lang])) {
        return $lead . htmlspecialchars($dict[$decodedCore][$lang], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . $trail;
    }

    // Dynamische Muster (Zahlen, Dateigrößen, Benutzernamen, Daten)
    $patterns = [
        // "Anmelden bei MiniCloud"
        '/^Anmelden bei (.+)$/u' => [
            'en' => 'Sign in to $1',
            'fr' => 'Connexion à $1',
            'es' => 'Iniciar sesión en $1',
            'nl' => 'Inloggen bij $1',
        ],
        // "Willkommen zurück, admin!"
        '/^Willkommen zurück, (.+)!$/u' => [
            'en' => 'Welcome back, $1!',
            'fr' => 'Bon retour, $1 !',
            'es' => '¡Bienvenido de nuevo, $1!',
            'nl' => 'Welkom terug, $1!',
        ],
        // "MiniCloud als App installieren"
        '/^(.+) als App installieren$/u' => [
            'en' => 'Install $1 as an App',
            'fr' => 'Installer $1 comme application',
            'es' => 'Instalar $1 como aplicación',
            'nl' => '$1 als app installeren',
        ],
        // "Ordner (3):"
        '/^Ordner \((\d+)\):$/u' => [
            'en' => 'Folders ($1):',
            'fr' => 'Dossiers ($1) :',
            'es' => 'Carpetas ($1):',
            'nl' => 'Mappen ($1):',
        ],
        // "🏠 Hauptverzeichnis (0 • 0 B)"
        '/^🏠 (.+) – Hauptverzeichnis \((.+)\)$/u' => [
            'en' => '🏠 $1 – Home Directory ($2)',
            'fr' => '🏠 $1 – Répertoire principal ($2)',
            'es' => '🏠 $1 – Directorio principal ($2)',
            'nl' => '🏠 $1 – Hoofdmap ($2)',
        ],
        '/^🏠 Hauptverzeichnis \((.+)\)$/u' => [
            'en' => '🏠 Home Directory ($1)',
            'fr' => '🏠 Répertoire principal ($1)',
            'es' => '🏠 Directorio principal ($1)',
            'nl' => '🏠 Hoofdmap ($1)',
        ],
        // "Neuer Unterordner in X..."
        '/^Neuer Unterordner in (.+)\.\.\.$/u' => [
            'en' => 'New subfolder in $1...',
            'fr' => 'Nouveau sous-dossier dans $1...',
            'es' => 'Nueva subcarpeta en $1...',
            'nl' => 'Nieuwe submap in $1...',
        ],
        // "Frei: 500 MB • Max. pro Upload: 512 MB"
        '/^Frei: (.+) • Max\. pro Upload: (.+)$/u' => [
            'en' => 'Free: $1 • Max per upload: $2',
            'fr' => 'Libre : $1 • Max. par envoi : $2',
            'es' => 'Libre: $1 • Máx. por subida: $2',
            'nl' => 'Vrij: $1 • Max. per upload: $2',
        ],
        // "2 Ordner • 5 Dateien" oder "0 Dateien" oder "1 Datei"
        '/^(\d+) Ordner • (\d+) Dateien?$/u' => [
            'en' => '$1 folders • $2 files',
            'fr' => '$1 dossiers • $2 fichiers',
            'es' => '$1 carpetas • $2 archivos',
            'nl' => '$1 mappen • $2 bestanden',
        ],
        '/^(\d+) Dateien?$/u' => [
            'en' => '$1 files',
            'fr' => '$1 fichiers',
            'es' => '$1 archivos',
            'nl' => '$1 bestanden',
        ],
        // "Noch 30 Tage" / "Noch 1 Tag"
        '/^Noch (\d+) Tage?$/u' => [
            'en' => '$1 days left',
            'fr' => '$1 jours restants',
            'es' => 'Quedan $1 días',
            'nl' => 'Nog $1 dagen',
        ],
        // "50 MB • Noch 30 Tage"
        '/^(.+) • Noch (\d+) Tage?$/u' => [
            'en' => '$1 • $2 days left',
            'fr' => '$1 • $2 jours restants',
            'es' => '$1 • Quedan $2 días',
            'nl' => '$1 • Nog $2 dagen',
        ],
        // "Gelöscht am 24.10.2026 11:00 Uhr"
        '/^Gelöscht am (.+) Uhr$/u' => [
            'en' => 'Deleted on $1',
            'fr' => 'Supprimé le $1',
            'es' => 'Eliminado el $1',
            'nl' => 'Verwijderd op $1',
        ],
        // "24.10.2026 11:00 Uhr"
        '/^(\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}) Uhr$/u' => [
            'en' => '$1',
            'fr' => '$1',
            'es' => '$1',
            'nl' => '$1',
        ],
        // "3 Dateien • 1,2 MB"
        '/^(\d+) Dateien? • (.+)$/u' => [
            'en' => '$1 files • $2',
            'fr' => '$1 fichiers • $2',
            'es' => '$1 archivos • $2',
            'nl' => '$1 bestanden • $2',
        ],
        // "0 B von 500 MB belegt (0%)"
        '/^(.+) von (.+) belegt \((.+)\)$/u' => [
            'en' => '$1 of $2 used ($3)',
            'fr' => '$1 sur $2 utilisés ($3)',
            'es' => '$1 de $2 usados ($3)',
            'nl' => '$1 van $2 gebruikt ($3)',
        ],
        // "Belegt: 0 B von 500 MB (500 MB frei)"
        '/^Belegt: (.+) von (.+) \((.+) frei\)$/u' => [
            'en' => 'Used: $1 of $2 ($3 free)',
            'fr' => 'Utilisé : $1 sur $2 ($3 libres)',
            'es' => 'Usado: $1 de $2 ($3 libres)',
            'nl' => 'Gebruikt: $1 van $2 ($3 vrij)',
        ],
        // "Registrierte Cloud-Benutzer (2)"
        '/^Registrierte Cloud-Benutzer \((\d+)\)$/u' => [
            'en' => 'Registered Cloud Users ($1)',
            'fr' => 'Utilisateurs enregistrés ($1)',
            'es' => 'Usuarios registrados ($1)',
            'nl' => 'Geregistreerde cloudgebruikers ($1)',
        ],
        // "Vorhandene Benutzergruppen (1)"
        '/^Vorhandene Benutzergruppen \((\d+)\)$/u' => [
            'en' => 'Existing User Groups ($1)',
            'fr' => 'Groupes d’utilisateurs existants ($1)',
            'es' => 'Grupos de usuarios existentes ($1)',
            'nl' => 'Bestaande gebruikersgroepen ($1)',
        ],
        // "Gruppenordner & Zugriffsrechte (1)"
        '/^Gruppenordner & Zugriffsrechte \((\d+)\)$/u' => [
            'en' => 'Group Folders & Access Permissions ($1)',
            'fr' => 'Dossiers de groupe & Droits d’accès ($1)',
            'es' => 'Carpetas de grupo y Permisos de acceso ($1)',
            'nl' => 'Groepsmappen & Toegangsrechten ($1)',
        ],
        // "⚡ CPU-Auslastung (4 Kerne)"
        '/^⚡ CPU-Auslastung \((\d+) Kerne?\)$/u' => [
            'en' => '⚡ CPU Load ($1 cores)',
            'fr' => '⚡ Charge CPU ($1 cœurs)',
            'es' => '⚡ Carga de CPU ($1 núcleos)',
            'nl' => '⚡ CPU-belasting ($1 kernen)',
        ],
        // "🌐 Netzwerk (eth0)"
        '/^🌐 Netzwerk \((.+)\)$/u' => [
            'en' => '🌐 Network ($1)',
            'fr' => '🌐 Réseau ($1)',
            'es' => '🌐 Red ($1)',
            'nl' => '🌐 Netwerk ($1)',
        ],
        // "© 2026 MiniCloud – Leichtgewichtiger, sicherer Cloud-Speicher"
        '/^© (\d{4}) (.+) – Leichtgewichtiger, sicherer Cloud-Speicher$/u' => [
            'en' => '© $1 $2 – Lightweight, secure cloud storage',
            'fr' => '© $1 $2 – Stockage cloud léger et sécurisé',
            'es' => '© $1 $2 – Almacenamiento en la nube ligero y seguro',
            'nl' => '© $1 $2 – Lichtgewicht, veilige cloudopslag',
        ],
        // "Rechtliche Hinweise gemäß § 5 DDG & DSGVO für MiniCloud"
        '/^Rechtliche Hinweise gemäß § 5 DDG & DSGVO für (.+)$/u' => [
            'en' => 'Legal Notice & GDPR Information for $1',
            'fr' => 'Mentions légales & Informations RGPD pour $1',
            'es' => 'Aviso legal e información RGPD para $1',
            'nl' => 'Juridische kennisgeving & AVG-informatie voor $1',
        ],
    ];

    foreach ($patterns as $regex => $langs) {
        if (isset($langs[$lang]) && preg_match($regex, $decodedCore)) {
            $replaced = preg_replace($regex, $langs[$lang], $decodedCore);
            if (is_string($replaced)) {
                return $lead . htmlspecialchars($replaced, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . $trail;
            }
        }
    }

    // Mehrzeiliger Standard-Rechtshinweis im Impressum übersetzen, falls unverändert
    if (str_contains($decodedCore, 'Hinweis gemäß § 5 DDG (Digitale-Dienste-Gesetz)')) {
        $imprintTranslations = [
            'en' => "Legal Notice pursuant to Section 5 DDG & Art. 2(2)(c) GDPR\n\nThis web application is a purely private, non-commercial system operated exclusively for personal and family purposes within a closed user group.\n\n1. No Imprint Obligation (Section 5(1) DDG / Section 18(1) MStV)\nThis is not a commercial, paid, or journalistic-editorial service. Public self-registration is disabled. Therefore, there is no statutory obligation to publish a full provider identification.\n\n2. Data Protection & Household Exemption (Art. 2(2)(c) GDPR)\nUse takes place exclusively in a private and family context. No analytics, advertising, or tracking services are used, and no data is transmitted to third parties or external servers (CDNs). Only technically necessary session data is processed for signed-in users.\n\n3. Technical Contact\nFor technical inquiries, please contact the administrator via email.",
            'fr' => "Mentions légales conformément au § 5 DDG & à l’art. 2, par. 2, point c) du RGPD\n\nCette application web est un système strictement privé et non commercial, exploité exclusivement à des fins personnelles et familiales au sein d’un cercle fermé d’utilisateurs.\n\n1. Absence d’obligation de mentions légales complètes (§ 5 al. 1 DDG)\nIl ne s’agit pas d’un service commercial, payant ou journalistique. L’auto-inscription publique est désactivée.\n\n2. Protection des données & Exception domestique (Art. 2, par. 2, point c) du RGPD)\nL’utilisation s’effectue exclusivement dans un cadre privé et familial. Aucun service d’analyse, de publicité ou de suivi n’est utilisé et aucune donnée n’est transmise à des serveurs tiers ou CDN externes.\n\n3. Contact technique\nPour toute question technique, veuillez contacter l’administrateur par e-mail.",
            'es' => "Aviso legal conforme al § 5 DDG y al art. 2, apdo. 2, letra c) del RGPD\n\nEsta aplicación web es un sistema estrictamente privado y no comercial, operado exclusivamente con fines personales y familiares dentro de un grupo cerrado de usuarios.\n\n1. Sin obligación de aviso legal comercial (§ 5 apdo. 1 DDG)\nNo se trata de un servicio comercial, de pago ni periodístico. El autorregistro público está desactivado.\n\n2. Protección de datos y Excepción doméstica (Art. 2, apdo. 2, letra c) del RGPD)\nEl uso se realiza exclusivamente en el ámbito privado y familiar. No se utilizan servicios de análisis, publicidad ni seguimiento, ni se transfieren datos a terceros o servidores externos (CDN).\n\n3. Contacto técnico\nPara consultas técnicas, puede contactar al administrador por correo electrónico.",
            'nl' => "Juridische kennisgeving overeenkomstig § 5 DDG & art. 2, lid 2, onder c) AVG\n\nDeze webapplicatie is een zuiver particulier, niet-commercieel systeem dat uitsluitend voor persoonlijke en familiale doeleinden binnen een besloten gebruikersgroep wordt beheerd.\n\n1. Geen colofonplicht (§ 5 lid 1 DDG)\nEr is geen sprake van een commerciële, betaalde of journalistieke dienst. Openbare zelfregistratie is uitgeschakeld.\n\n2. Gegevensbescherming & Huishoudelijke uitzondering (Art. 2, lid 2, onder c) AVG)\nHet gebruik vindt uitsluitend plaats in de privésfeer en familiekring. Er worden geen analyse-, reclame- of trackingdiensten gebruikt en er worden geen gegevens doorgegeven aan derden of externe servers (CDN’s).\n\n3. Technisch contact\nVoor technische vragen kunt u per e-mail contact opnemen met de beheerder.",
        ];
        if (isset($imprintTranslations[$lang])) {
            return $lead . htmlspecialchars($imprintTranslations[$lang], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . $trail;
        }
    }

    return $text;
}

/**
 * Output-Buffer-Callback: Übersetzt das gerenderte HTML vor dem Senden an den Browser,
 * sofern eine andere Sprache als Deutsch ('de') gewählt ist.
 */
function translateHtmlBuffer(string $html): string
{
    $lang = getCurrentLang();
    if ($lang === 'de' || $html === '') {
        return $html;
    }

    // 1. <html lang="de" ...> auf die gewählte Sprache setzen
    $html = preg_replace('/(<html\b[^>]*\blang=")de(")/i', '$1' . $lang . '$2', $html, 1) ?? $html;

    // 2. <script> und <style> Blöcke temporär schützen, damit kein JS/CSS verändert wird
    $protectedBlocks = [];
    $html = preg_replace_callback(
        '#<(script|style)\b[^>]*>.*?</\1>#is',
        static function (array $m) use (&$protectedBlocks): string {
            $key = '___MC_PROTECTED_BLOCK_' . count($protectedBlocks) . '___';
            $protectedBlocks[$key] = $m[0];
            return $key;
        },
        $html
    ) ?? $html;

    // 3. Attribute placeholder="...", title="...", aria-label="..." und optgroup label="..." übersetzen
    $html = preg_replace_callback(
        '/\b(placeholder|title|aria-label|label)="([^"]+)"/u',
        static function (array $m) use ($lang): string {
            return $m[1] . '="' . translateTextSegment($m[2], $lang) . '"';
        },
        $html
    ) ?? $html;

    // 4. Sichtbare Textknoten zwischen HTML-Tags übersetzen
    $html = preg_replace_callback(
        '/>([^<>]+)</u',
        static function (array $m) use ($lang): string {
            return '>' . translateTextSegment($m[1], $lang) . '<';
        },
        $html
    ) ?? $html;

    // 5. Geschützte <script>/<style> Blöcke wiederherstellen
    if (!empty($protectedBlocks)) {
        $html = strtr($html, $protectedBlocks);
    }

    return $html;
}
