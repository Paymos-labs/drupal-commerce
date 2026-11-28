# Paymos para Drupal Commerce

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | **Español** | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

Acepte pagos a través de Paymos en Drupal Commerce. La versión `1.0.0` es el primer lanzamiento estable, disponible en [Drupal.org](https://www.drupal.org/project/commerce_paymos) y en el [repositorio oficial de GitHub](https://github.com/Paymos-labs/drupal-commerce).

[![Ver vídeo: 12 plugins oficiales de pagos cripto para CMS — Conexión en 1 clic (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)
> **Recorrido en vídeo (1:12, subtítulos en 6 idiomas):** [Ver en YouTube](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Requisitos

Utilice Drupal `^10.3 || ^11` con Drupal Commerce `^3`. El módulo declara PHP `^8.1`; su versión de Drupal puede requerir una versión de PHP más reciente, así que aplique el requisito más estricto. Su tienda necesita una dirección HTTPS pública para la conexión y las notificaciones de pago.

El módulo utiliza la licencia GPL-2.0-or-later. Su dependencia del SDK de PHP (`paymos/php-sdk`) utiliza la licencia MIT y requiere la versión `^1.12.0` (`1.12.0` o una versión compatible más reciente de `1.x`) para normalizar `products[]` en el checkout mediante `Paymos\Plugin\InvoicePresentation::normalizeProducts`.

## Instalar el módulo

Utilice Composer para instalar la versión estable y resolver `paymos/php-sdk:^1.12.0`:

```sh
composer require 'drupal/commerce_paymos:^1.0'
```

También puede instalar el paquete ZIP manual independiente. Extraiga su directorio `commerce_paymos` dentro del directorio de módulos contribuidos de su sitio. El paquete manual incluye `paymos/php-sdk` `1.11.0` dentro de `vendor/` (`SdkBootstrap` lo carga cuando `Paymos\Client` aún no está en el autoloader), por lo que debe mantener juntos los archivos incluidos. Habilite el módulo Paymos en Drupal después de instalarlo.

Si un sitio utiliza el ZIP manual junto con una versión anterior de `paymos/php-sdk` ya cargada globalmente por otro paquete de Composer, `CheckoutService` comprueba `method_exists(InvoicePresentation::class, 'normalizeProducts')` y recurre de forma segura a un payload de factura `amount-only` en lugar de hacer fallar el checkout.

Después de actualizar el módulo, reconstruya la caché de Drupal: `drush --uri=https://your-store.example cache:rebuild`.

## Conectar su tienda

Abra `/admin/commerce/config/payment/paymos` en Drupal. En el panel de Paymos, seleccione el proyecto que desea utilizar para esta tienda. Elija **Connect Paymos** en Drupal, apruebe la conexión y vuelva a Drupal para pulsar **Check approval**.

Después, abra `/admin/commerce/config/payment-gateways/add`. Cree una pasarela de pago, seleccione Paymos y elija Sandbox o Live según el entorno que vaya a usar. La conexión y la pasarela de pago de Commerce son pasos independientes; complete ambos antes de utilizar la pasarela en el checkout.

Copie las direcciones de retorno mostradas en la administración de Drupal en su proyecto de Paymos existente: `/paymos/return` como Success URL y `/paymos/return?cancel=1` como Fail URL. Solo es necesario hacerlo una vez. El checkout alojado utiliza su enlace **Back to store**. Drupal conserva los destinos de retorno nativos del último checkout en la sesión del navegador del comprador durante una hora. Otro navegador, una sesión caducada o un cambio en la vinculación entre pedido y pasarela redirigen al comprador a la página principal de la tienda. El retorno del navegador nunca confirma un pago; el estado del pago se determina mediante las comprobaciones verificadas de webhook/API y el pago nativo de Commerce. Las solicitudes de factura solo envían la referencia del pedido, `project_id`, `amount`, `currency` y el array opcional normalizado `products[]`, sin campos de identidad del comprador.

Las credenciales permanecen en el estado cifrado de Drupal (`Drupal state`). El cifrado utiliza la clave privada de Drupal y `hash_salt`, y los secretos no aparecen en las exportaciones de configuración. Si cambia la URL canónica de su tienda, vuelva a conectar la tienda con Paymos.

## Líneas de pedido y alcance del catálogo

Cuando `Paymos\Plugin\InvoicePresentation::normalizeProducts` está disponible, `CheckoutService` filtra las líneas de envío, descuento, impuestos, comisiones y notas, y normaliza de `1..50` líneas de producto (`title`, entero positivo `quantity`, cadena decimal `unit_amount` y campo opcional de texto plano `description`) en `products[]` bajo una regla de todo o nada: si alguna línea de producto no es válida o el carrito supera `50` artículos, Drupal envía una factura `amount-only` con el total nativo del pedido (`amount`) en lugar de un carrito truncado. Las descripciones en blanco se omiten por artículo sin descartar el producto. La instantánea normalizada `products[]` se guarda de forma persistente en el intento de `InvoiceStore`, de modo que los reintentos tras una creación interrumpida reutilizan los valores congelados en lugar de leer un pedido modificado. En instantáneas históricas, los reintentos eliminan únicamente la clave retirada `image_url` de la copia saliente, preservando el resto de valores y sin alterar la instantánea almacenada.

En todo el catálogo de 12 plugins CMS de Paymos (`plugins/cms-store-catalog.json`):

- Los **11** plugins CMS de comercio transmiten `products[]`: los **10** plugins PHP basados en `Paymos\Plugin\InvoicePresentation::normalizeProducts` (`WooCommerce`, `Easy Digital Downloads`, `Drupal Commerce` con `paymos/php-sdk:^1.12.0`, `OpenCart`, `PrestaShop`, `WHMCS`, `CS-Cart`, `Magento 2`, `Shopware 6` y `HikaShop` `1.1.0` con `^1.11.0`) además de **Odoo** (`20.0.1.1.0`, que envía `products` mediante el SDK de Python de Paymos incluido `1.x` (`>= 1.6.0`) a través de `client.request("POST", "/v1/invoices", payload)`).
- Únicamente la pasarela de donaciones **`givewp-paymos`** permanece como `amount-only`, porque las donaciones no tienen carrito de productos.

## Mantener activas las comprobaciones de pago

Programe el cron de Drupal con la URL HTTPS canónica de su tienda. Por ejemplo:

```sh
drush --uri=https://your-store.example cron
```

Cron revisa los pagos pendientes de resolución en lotes acotados. Manténgalo en ejecución incluso cuando las notificaciones de pago lleguen con normalidad, para que los pagos sin resolver sigan recibiendo comprobaciones.

## Cómo reciben pago los pedidos

Las notificaciones de pago llevan firma y activan una comprobación contra la API. Antes de registrar un pago, la factura debe coincidir con el pedido, el proyecto de Paymos, el entorno, el importe y la divisa. Una transacción SQL nativa registra un único pago completado de Commerce; las notificaciones duplicadas no lo registran de nuevo.

Los pagos parciales o tardíos no liberan productos automáticamente. Un pago con datos discordantes requiere revisión manual. Drupal Commerce controla la entrega mediante el propio flujo de pedidos de su tienda, así que revise dicho flujo al configurar la pasarela.

## Reembolsos

El módulo admite reembolsos nativos a través de la Paymos Merchant API (`/v1/refunds`) en dos modos calculados por el servidor: `full` (reembolso completo de la factura) y `overpayment` (reembolso del excedente en una factura `paid_over`). Paymos devuelve los fondos mediante una nueva transferencia saliente en blockchain exclusivamente a la dirección verificada `first_sender` registrada en la factura pagada: no se introduce ninguna cartera de destino manualmente y no se admiten importes parciales arbitrarios.

Los administradores autorizados pueden iniciar y supervisar reembolsos desde dos puntos protegidos en Drupal:

- el formulario de operación **Refund** de pago de Commerce (`RefundForm`) en la pestaña **Payments** del pedido, que permite elegir **Full refund** (`full`) o **Refund overpayment** (`overpayment`);
- el panel **Paymos refunds** en `/admin/commerce/config/payment/paymos` (`ConnectForm`), que permite iniciar reembolsos `full` y `overpayment` para pagos elegibles de Paymos y muestra los estados de operación y observaciones de reembolsos externos.

Las notificaciones webhook firmadas en `/paymos/webhook` y la conciliación por cron de Drupal actualizan las operaciones de reembolso pendientes y aplican el estado nativo de reembolso de pago en Commerce una vez que Paymos confirma la finalización (`completed`). En modo Sandbox se pueden simular resultados terminales de reembolso (`completed`, `failed`, `cancelled`) para pruebas.

## Desinstalar el módulo

La desconexión de Paymos en `/admin/commerce/config/payment/paymos` queda bloqueada mientras existan registros de historial de reembolsos en la tienda. La desinstalación del módulo queda bloqueada mientras existan registros de reembolso o pagos Paymos pendientes sin resolver en la base de datos (las facturas completadas y las filas de eventos webhook no bloquean la desconexión ni la desinstalación). Cuando no haya pagos pendientes ni registros de reembolso que impidan retirarlo, la desinstalación elimina las credenciales cifradas del módulo y conserva los registros nativos de pago de Drupal Commerce.

## Más información

Visite [Paymos](https://paymos.io/es) para conocer el servicio de pagos. Consulte la [documentación de Paymos](https://paymos.io/es/docs) para obtener más información sobre el servicio.
