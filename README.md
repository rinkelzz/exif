# EXIF Upload Tool

Ein kleines PHP-Skript, mit dem sich EXIF-Metadaten aus hochgeladenen Bildern im Browser anzeigen und optional per E-Mail versenden lassen.

## Voraussetzungen

- PHP 7.2 oder neuer
- Aktiviertes Session-Handling
- Für das Auslesen von Metadaten: die PHP-Extension `exif`
- Für die optionale Thumbnail-Erzeugung (falls das Bild kein eingebettetes EXIF-Thumbnail enthält): die PHP-Extension `gd` inklusive JPEG-Unterstützung

Wenn `gd` fehlt oder ohne die benötigten Funktionen kompiliert wurde, zeigt die Anwendung weiterhin die Metadaten an, verzichtet aber auf die Thumbnail-Vorschau.

## Installation

1. Projektdateien auf den Webserver kopieren.
2. Die Datei `config.php` anpassen und mindestens die Absenderadresse (`sender_email`) hinterlegen.
3. Dem Webserver Schreibrechte für den Session-Speicher geben (normalerweise bereits konfiguriert).

## Konfiguration

```php
<?php
return [
    'sender_email' => 'no-reply@example.com',
    'sender_name'  => 'Exif Tool',
    'mail_subject' => 'Metadaten des hochgeladenen Bildes',
];
```

- **sender_email**: Absenderadresse für E-Mails (Pflichtfeld).
- **sender_name**: Optionaler Name, der neben der Absenderadresse angezeigt wird.
- **mail_subject**: Betreffzeile der automatisch versendeten E-Mail.

## Verwendung

1. Seite im Browser aufrufen.
2. Ein Bild auswählen und das einfache Captcha lösen.
3. Optional eine Empfängeradresse angeben und die Checkbox zum E-Mail-Versand aktivieren.
4. Formular abschicken und die ermittelten EXIF-Daten direkt unter dem Formular einsehen.

Die Anwendung prüft jede Eingabe auf Fehler und zeigt verständliche Meldungen an. Wird auf den E-Mail-Versand verzichtet, erscheinen die Metadaten und – sofern möglich – ein Thumbnail direkt auf der Seite.

## Fehlerbehebung

- Eine weiße Seite nach dem Upload deutet häufig auf fehlende PHP-Extensions oder deaktivierte GD-Funktionen hin. Die Anwendung prüft diese Voraussetzungen und zeigt entsprechende Fehlermeldungen an; aktivieren Sie in der `php.ini` die Extensions `exif` und `gd`.
- Aktivieren Sie bei Bedarf das PHP-Error-Logging, um serverseitige Fehlermeldungen zu sehen (`display_errors=On` nur zu Testzwecken setzen).

## Sicherheitshinweise

- Das Skript akzeptiert ausschließlich Bilddateien, deren MIME-Type geprüft wird.
- Ein einfaches Captcha schützt das Formular vor Bots.
- Ergänzen Sie bei Bedarf weitere Sicherheitsmechanismen (z. B. Upload-Verzeichnisse außerhalb des Document-Roots oder Antivirus-Scans) gemäß Ihren Anforderungen.
