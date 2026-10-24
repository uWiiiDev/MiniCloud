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
        'z.B. Rechnungen-2026' => [
            'en' => 'e.g. Invoices-2026',
            'fr' => 'ex. Factures-2026',
            'es' => 'p. ej. Facturas-2026',
            'nl' => 'bijv. Facturen-2026',
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
        'Vom Administrator für Sie oder Ihre Benutzergruppen freigegebene gemeinsame Ordner.' => [
            'en' => 'Shared folders granted to you or your user groups by the administrator.',
            'fr' => 'Dossiers partagés accordés à vous ou à vos groupes par l’administrateur.',
            'es' => 'Carpetas compartidas habilitadas para usted o sus grupos por el administrador.',
            'nl' => 'Gedeelde mappen die door de beheerder voor u of uw groepen zijn vrijgegeven.',
        ],
        'Lesen & Schreiben' => [
            'en' => 'Read & Write',
            'fr' => 'Lecture & Écriture',
            'es' => 'Lectura y Escritura',
            'nl' => 'Lezen & Schrijven',
        ],
        'Nur Lesen' => [
            'en' => 'Read Only',
            'fr' => 'Lecture seule',
            'es' => 'Solo lectura',
            'nl' => 'Alleen lezen',
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
        'Dateien und Ordner, die andere Benutzer dieser Cloud direkt mit Ihnen geteilt haben.' => [
            'en' => 'Files and folders that other users of this cloud have shared directly with you.',
            'fr' => 'Fichiers et dossiers que d’autres utilisateurs ont partagés directement avec vous.',
            'es' => 'Archivos y carpetas que otros usuarios han compartido directamente con usted.',
            'nl' => 'Bestanden en mappen die andere gebruikers rechtstreeks met u hebben gedeeld.',
        ],
        'Dateien oder Ordner hochladen' => [
            'en' => 'Upload files or folders',
            'fr' => 'Téléverser des fichiers ou des dossiers',
            'es' => 'Subir archivos o carpetas',
            'nl' => 'Bestanden of mappen uploaden',
        ],
        'Ziehen Sie Dateien per Drag & Drop hierher' => [
            'en' => 'Drag & drop files here',
            'fr' => 'Glissez-déposez vos fichiers ici',
            'es' => 'Arrastre y suelte archivos aquí',
            'nl' => 'Sleep bestanden hierheen',
        ],
        'oder wählen Sie einzelne Dateien, mehrere Dateien oder einen ganzen Ordner von Ihrem Gerät' => [
            'en' => 'or select single files, multiple files, or an entire folder from your device',
            'fr' => 'ou sélectionnez des fichiers ou un dossier complet depuis votre appareil',
            'es' => 'o seleccione archivos individuales, varios archivos o una carpeta completa de su dispositivo',
            'nl' => 'of kies losse bestanden, meerdere bestanden of een hele map vanaf uw apparaat',
        ],
        '📄 Dateien auswählen' => [
            'en' => '📄 Select Files',
            'fr' => '📄 Choisir des fichiers',
            'es' => '📄 Seleccionar archivos',
            'nl' => '📄 Bestanden kiezen',
        ],
        '📁 Ordner auswählen' => [
            'en' => '📁 Select Folder',
            'fr' => '📁 Choisir un dossier',
            'es' => '📁 Seleccionar carpeta',
            'nl' => '📁 Map kiezen',
        ],
        'Optionaler Ziel-Unterordner (falls neu):' => [
            'en' => 'Optional target subfolder (if new):',
            'fr' => 'Sous-dossier cible optionnel (si nouveau) :',
            'es' => 'Subcarpeta de destino opcional (si es nueva):',
            'nl' => 'Optionele doelsubmap (indien nieuw):',
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
        'Verschieben nach...' => [
            'en' => 'Move to...',
            'fr' => 'Déplacer vers...',
            'es' => 'Mover a...',
            'nl' => 'Verplaatsen naar...',
        ],
        'Kopieren nach...' => [
            'en' => 'Copy to...',
            'fr' => 'Copier vers...',
            'es' => 'Copiar a...',
            'nl' => 'Kopiëren naar...',
        ],
        'Ordner verschieben nach...' => [
            'en' => 'Move folder to...',
            'fr' => 'Déplacer le dossier vers...',
            'es' => 'Mover carpeta a...',
            'nl' => 'Map verplaatsen naar...',
        ],
        'Ordner kopieren nach...' => [
            'en' => 'Copy folder to...',
            'fr' => 'Copier le dossier vers...',
            'es' => 'Copiar carpeta a...',
            'nl' => 'Map kopiëren naar...',
        ],
        'Datei verschieben nach...' => [
            'en' => 'Move file to...',
            'fr' => 'Déplacer le fichier vers...',
            'es' => 'Mover archivo a...',
            'nl' => 'Bestand verplaatsen naar...',
        ],
        'Datei kopieren nach...' => [
            'en' => 'Copy file to...',
            'fr' => 'Copier le fichier vers...',
            'es' => 'Copiar archivo a...',
            'nl' => 'Bestand kopiëren naar...',
        ],
        'Vorschau' => [
            'en' => 'Preview',
            'fr' => 'Aperçu',
            'es' => 'Vista previa',
            'nl' => 'Voorbeeld',
        ],
        'Datei direkt im Browser ansehen' => [
            'en' => 'Preview file directly in browser',
            'fr' => 'Prévisualiser le fichier dans le navigateur',
            'es' => 'Ver archivo directamente en el navegador',
            'nl' => 'Bestand direct in de browser bekijken',
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
        'Teilen' => [
            'en' => 'Share',
            'fr' => 'Partager',
            'es' => 'Compartir',
            'nl' => 'Delen',
        ],
        'Öffentlichen Link erstellen oder für Benutzer freigeben' => [
            'en' => 'Create public link or share with users',
            'fr' => 'Créer un lien public ou partager avec des utilisateurs',
            'es' => 'Crear enlace público o compartir con usuarios',
            'nl' => 'Openbare link maken of delen met gebruikers',
        ],
        'Löschen' => [
            'en' => 'Delete',
            'fr' => 'Supprimer',
            'es' => 'Eliminar',
            'nl' => 'Verwijderen',
        ],
        'In den Papierkorb verschieben' => [
            'en' => 'Move to trash',
            'fr' => 'Mettre à la corbeille',
            'es' => 'Mover a la papelera',
            'nl' => 'Naar prullenbak verplaatsen',
        ],
        'Dieser Ordner ist noch leer' => [
            'en' => 'This folder is still empty',
            'fr' => 'Ce dossier est encore vide',
            'es' => 'Esta carpeta aún está vacía',
            'nl' => 'Deze map is nog leeg',
        ],
        'Laden Sie oben Ihre erste Datei oder einen Ordner hoch.' => [
            'en' => 'Upload your first file or folder above.',
            'fr' => 'Téléversez votre premier fichier ou dossier ci-dessus.',
            'es' => 'Suba su primer archivo o carpeta arriba.',
            'nl' => 'Upload hierboven uw eerste bestand of map.',
        ],

        // --- Papierkorb (trash.php) ---
        'Gelöschte Dateien werden hier für 30 Tage aufbewahrt und danach automatisch endgültig entfernt.' => [
            'en' => 'Deleted files are kept here for 30 days and then permanently removed automatically.',
            'fr' => 'Les fichiers supprimés sont conservés ici pendant 30 jours avant d’être définitivement effacés.',
            'es' => 'Los archivos eliminados se conservan aquí durante 30 días y luego se eliminan definitivamente.',
            'nl' => 'Verwijderde bestanden worden hier 30 dagen bewaard en daarna automatisch definitief verwijderd.',
        ],
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
        'Gruppe anlegen' => [
            'en' => 'Create group',
            'fr' => 'Créer le groupe',
            'es' => 'Crear grupo',
            'nl' => 'Groep aanmaken',
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
            'en' => 'Create group folder',
            'fr' => 'Créer le dossier de groupe',
            'es' => 'Crear carpeta de grupo',
            'nl' => 'Groepsmap aanmaken',
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
        'Server-Auslastung & System-Ressourcen' => [
            'en' => 'Server Load & System Resources',
            'fr' => 'Charge du serveur & Ressources système',
            'es' => 'Carga del servidor y Recursos del sistema',
            'nl' => 'Serverbelasting & Systeemstatus',
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
        'Datei / Ordner teilen' => [
            'en' => 'Share File / Folder',
            'fr' => 'Partager un fichier / dossier',
            'es' => 'Compartir archivo / carpeta',
            'nl' => 'Bestand / map delen',
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
        'Ablaufdatum (optional)' => [
            'en' => 'Expiration date (optional)',
            'fr' => 'Date d’expiration (optionnel)',
            'es' => 'Fecha de caducidad (opcional)',
            'nl' => 'Vervaldatum (optioneel)',
        ],
        'Link erzeugen' => [
            'en' => 'Generate link',
            'fr' => 'Générer le lien',
            'es' => 'Generar enlace',
            'nl' => 'Link genereren',
        ],
        'Direkt mit Cloud-Benutzer teilen' => [
            'en' => 'Share directly with cloud user',
            'fr' => 'Partager directement avec un utilisateur du cloud',
            'es' => 'Compartir directamente con un usuario de la nube',
            'nl' => 'Direct delen met cloudgebruiker',
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
        'Ordner als ZIP herunterladen' => [
            'en' => 'Download folder as ZIP',
            'fr' => 'Télécharger le dossier en ZIP',
            'es' => 'Descargar carpeta como ZIP',
            'nl' => 'Map downloaden als ZIP',
        ],
        'Freigabe entsperren' => [
            'en' => 'Unlock share',
            'fr' => 'Déverrouiller le partage',
            'es' => 'Desbloquear recurso compartido',
            'nl' => 'Gedeeld item ontgrendelen',
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
    $decodedCore = html_entity_decode($core, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
        // "Max. 512 MB pro Upload • 500 MB freier Speicherplatz"
        '/^Max\. (.+) pro Upload • (.+) freier Speicherplatz$/u' => [
            'en' => 'Max. $1 per upload • $2 free storage',
            'fr' => 'Max. $1 par envoi • $2 d’espace libre',
            'es' => 'Máx. $1 por subida • $2 de espacio libre',
            'nl' => 'Max. $1 per upload • $2 vrije opslagruimte',
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

    // Meharzeiliger Standard-Rechtshinweis im Impressum übersetzen, falls unverändert
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
