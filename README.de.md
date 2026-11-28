# Paymos für Drupal Commerce

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | [Español](README.es.md) | **Deutsch** | [Türkçe](README.tr.md)

Akzeptieren Sie Zahlungen über Paymos in Drupal Commerce. Version `1.0.0` ist das erste stabile Release und über [Drupal.org](https://www.drupal.org/project/commerce_paymos) sowie das [offizielle GitHub-Repository](https://github.com/Paymos-labs/drupal-commerce) verfügbar.

[![Video ansehen: 12 offizielle CMS-Krypto-Plugins — 1-Klick-Verbindung (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)
> **Video-Rundgang (1:12, Untertitel in 6 Sprachen):** [Auf YouTube ansehen](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Voraussetzungen

Verwenden Sie Drupal `^10.3 || ^11` mit Drupal Commerce `^3`. Das Modul deklariert PHP `^8.1`; Ihre Drupal-Version verlangt möglicherweise eine neuere PHP-Version — richten Sie sich nach der strengeren Vorgabe. Ihr Shop benötigt eine öffentlich erreichbare HTTPS-Adresse für die Verbindung und Zahlungsbenachrichtigungen.

Das Modul steht unter der Lizenz GPL-2.0-or-later. Seine PHP-SDK-Abhängigkeit (`paymos/php-sdk`) steht unter der MIT-Lizenz und erfordert Version `^1.12.0` (`1.12.0` oder ein neueres kompatibles `1.x`-Release) für die Normalisierung von `products[]` im Checkout über `Paymos\Plugin\InvoicePresentation::normalizeProducts`.

## Modul installieren

Installieren Sie das stabile Release über Composer, um `paymos/php-sdk:^1.12.0` aufzulösen:

```sh
composer require 'drupal/commerce_paymos:^1.0'
```

Alternativ können Sie das separate manuelle ZIP-Paket installieren. Entpacken Sie dessen Verzeichnis `commerce_paymos` in das Verzeichnis für Contributed-Module Ihrer Website. Das manuelle Paket enthält `paymos/php-sdk` `1.11.0` im Ordner `vendor/` (`SdkBootstrap` lädt es, sofern `Paymos\Client` noch nicht per Autoloading geladen wurde) — belassen Sie die mitgelieferten Dateien daher zusammen. Aktivieren Sie das Paymos-Modul nach der Installation in Drupal.

Wenn eine Website das manuelle ZIP zusammen mit einer älteren Version von `paymos/php-sdk` einsetzt, die bereits global über ein anderes Composer-Paket geladen wurde, prüft `CheckoutService` mittels `method_exists(InvoicePresentation::class, 'normalizeProducts')` und fällt sicher auf einen reinen Betrags-Payload (`amount-only`) zurück, anstatt den Checkout abzubrechen.

Leeren Sie nach einem Update des Moduls den Drupal-Cache: `drush --uri=https://your-store.example cache:rebuild`.

## Shop verbinden

Öffnen Sie `/admin/commerce/config/payment/paymos` in Drupal. Wählen Sie im Paymos-Dashboard das Projekt aus, das Sie für diesen Shop nutzen möchten. Klicken Sie in Drupal auf **Connect Paymos**, bestätigen Sie die Verbindung und kehren Sie nach Drupal zurück, um **Check approval** auszuwählen.

Öffnen Sie anschließend `/admin/commerce/config/payment-gateways/add`. Erstellen Sie ein Zahlungsgateway, wählen Sie Paymos und legen Sie Sandbox oder Live für die gewünschte Umgebung fest. Die Verbindung und das Commerce-Zahlungsgateway sind zwei getrennte Schritte; schließen Sie beide ab, bevor Sie das Gateway im Checkout einsetzen.

Kopieren Sie die im Drupal-Admin angezeigten Rückkehr-URLs in Ihr bestehendes Paymos-Projekt: `/paymos/return` als Success URL und `/paymos/return?cancel=1` als Fail URL. Dies ist nur einmal erforderlich. Der gehostete Checkout nutzt dafür seinen Link **Back to store**. Drupal speichert die nativen Rückkehrziele des letzten Checkouts für eine Stunde in der Browsersitzung des Käufers. Ein anderer Browser, eine abgelaufene Sitzung oder eine geänderte Bestell-/Gateway-Zuordnung leitet den Käufer auf die Startseite des Shops. Eine Browser-Rückkehr bestätigt niemals eine Zahlung; der Zahlungsstatus wird ausschließlich durch verifizierte Webhook-/API-Prüfungen und die native Commerce-Zahlung bestimmt. Rechnungsanfragen übertragen nur die Bestellreferenz, `project_id`, `amount`, `currency` sowie optional das normalisierte Array `products[]` — ohne Angaben zur Identität des Käufers.

Die Zugangsdaten werden verschlüsselt im Drupal-State gespeichert. Die Verschlüsselung verwendet den privaten Drupal-Schlüssel sowie `hash_salt`, sodass Secrets nicht in Konfigurationsexporten erscheinen. Ändert sich die kanonische URL Ihres Shops, verbinden Sie den Shop erneut mit Paymos.

## Bestellpositionen und Kataloggrenzen

Wenn `Paymos\Plugin\InvoicePresentation::normalizeProducts` verfügbar ist, filtert `CheckoutService` Versand-, Rabatt-, Steuer-, Gebühren- und Notizzeilen heraus und normalisiert `1..50` Bestellpositionen (`title`, positive Ganzzahl `quantity`, Dezimalstring `unit_amount` sowie optionales reines Textfeld `description`) nach dem Alles-oder-nichts-Prinzip in `products[]`: Ist eine Produktzeile ungültig oder enthält der Warenkorb mehr als `50` Positionen, sendet Drupal statt eines abgeschnittenen Warenkorbs eine `amount-only`-Rechnung mit dem nativen Gesamtbetrag (`amount`). Leere Beschreibungen werden pro Position weggelassen, ohne die Position zu verwerfen. Der normalisierte `products[]`-Snapshot wird dauerhaft im `InvoiceStore`-Intent gespeichert, sodass Wiederholungen nach einer unterbrochenen Erstellung die eingefrorenen Werte wiederverwenden statt eine geänderte Bestellung zu lesen. Bei älteren Snapshots entfernen Wiederholungen lediglich den ausgemusterten Schlüssel `image_url` aus der ausgehenden Kopie, behalten alle übrigen Werte bei und lassen den gespeicherten Snapshot unverändert.

Im gesamten 12-Plugin-CMS-Katalog von Paymos (`plugins/cms-store-catalog.json`):

- Alle **11** Warenkorb-CMS-Plugins übermitteln `products[]`: die **10** PHP-Plugins auf Basis von `Paymos\Plugin\InvoicePresentation::normalizeProducts` (`WooCommerce`, `Easy Digital Downloads`, `Drupal Commerce` mit `paymos/php-sdk:^1.12.0`, `OpenCart`, `PrestaShop`, `WHMCS`, `CS-Cart`, `Magento 2`, `Shopware 6` sowie `HikaShop` `1.1.0` mit `^1.11.0`) sowie **Odoo** (`20.0.1.1.0`, das `products` über das mitgelieferte Paymos Python SDK `1.x` (`>= 1.6.0`) per `client.request("POST", "/v1/invoices", payload)` sendet).
- Lediglich das Spenden-Gateway **`givewp-paymos`** bleibt bei `amount-only`, da Spenden keinen Produktwarenkorb haben.

## Zahlungsprüfungen aktiv halten

Planen Sie Drupal-Cron mit der kanonischen HTTPS-URL Ihres Shops ein. Zum Beispiel:

```sh
drush --uri=https://your-store.example cron
```

Cron prüft noch ungeklärte Zahlungen in begrenzten Stapeln. Lassen Sie Cron auch dann weiterlaufen, wenn Zahlungsbenachrichtigungen regulär eintreffen, damit offene Zahlungen zuverlässig nachgeprüft werden.

## Wie Bestellungen Zahlungen erhalten

Zahlungsbenachrichtigungen enthalten eine Signatur und lösen eine API-Prüfung aus. Bevor eine Zahlung verbucht wird, muss die Rechnung mit Bestellung, Paymos-Projekt, Umgebung, Betrag und Währung übereinstimmen. Eine native SQL-Transaktion verbucht genau eine abgeschlossene Commerce-Zahlung; doppelte Benachrichtigungen führen zu keiner Mehrfachbuchung.

Teilzahlungen oder verspätete Zahlungen geben Waren nicht automatisch frei. Eine Zahlung mit abweichenden Details muss manuell geprüft werden. Drupal Commerce steuert die Auftragsabwicklung über den eigenen Bestell-Workflow Ihres Shops; prüfen Sie diesen Workflow beim Einrichten des Gateways.

## Rückerstattungen

Das Modul unterstützt native Rückerstattungen über die Paymos Merchant API (`/v1/refunds`) in zwei serverseitig berechneten Modi: `full` (vollständige Rechnungsrückerstattung) und `overpayment` (Rückerstattung des überzahlten Betrags einer `paid_over`-Rechnung). Paymos sendet die Mittel als neue ausgehende Blockchain-Transaktion ausschließlich an die verifizierte `first_sender`-Adresse der bezahlten Rechnung — eine Empfänger-Wallet-Adresse wird nicht manuell eingegeben, und beliebige Teilbeträge werden nicht unterstützt.

Berechtigte Administratoren können Rückerstattungen an zwei geschützten Stellen in Drupal auslösen und überwachen:

- das Commerce-Zahlungsformular **Refund** (`RefundForm`) auf dem Reiter **Payments** der Bestellung, über das Sie **Full refund** (`full`) oder **Refund overpayment** (`overpayment`) wählen können;
- das Panel **Paymos refunds** unter `/admin/commerce/config/payment/paymos` (`ConnectForm`), mit dem `full`- und `overpayment`-Rückerstattungen für geeignete Paymos-Zahlungen gestartet sowie Operationsstatus und externe Rückerstattungsbeobachtungen eingesehen werden können.

Signierte Webhook-Benachrichtigungen an `/paymos/webhook` und der Drupal-Cron-Abgleich aktualisieren ausstehende Rückerstattungen und setzen den nativen Commerce-Rückerstattungsstatus, sobald Paymos den Abschluss (`completed`) bestätigt. Im Sandbox-Modus lassen sich Endzustände (`completed`, `failed`, `cancelled`) zu Testzwecken simulieren.

## Modul entfernen

Das Trennen von Paymos unter `/admin/commerce/config/payment/paymos` ist blockiert, solange Rückerstattungshistorien für den Shop vorhanden sind. Die Deinstallation des Moduls ist blockiert, solange Rückerstattungsdatensätze oder offene, ausstehende Paymos-Zahlungen in der Datenbank existieren (abgeschlossene Rechnungen und Webhook-Ereigniszeilen blockieren weder Trennung noch Deinstallation). Sobald keine offenen Zahlungen oder Rückerstattungsdatensätze die Entfernung blockieren, löscht die Deinstallation die verschlüsselten Zugangsdaten des Moduls und belässt die nativen Zahlungsdatensätze von Drupal Commerce unberührt.

## Weitere Informationen

Besuchen Sie [Paymos](https://paymos.io/de) für Informationen zum Zahlungsdienst. Weitere Details finden Sie in der [Paymos-Dokumentation](https://paymos.io/de/docs).
