=== WooCommerce MokaPOS Integration ===
Contributors: yourname
Tags: woocommerce, mokapos, pos, sync, orders, inventory
Requires at least: 5.8
Tested up to: 6.4
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Синхронизация товаров, цен, остатков и заказов между WooCommerce и Moka POS CRM.

== Description ==

Плагин обеспечивает двустороннюю интеграцию вашего магазина на WooCommerce с системой учёта Moka POS.

= Возможности =
* Автоматическая синхронизация цен товаров (WooCommerce → Moka)
* Синхронизация остатков (двусторонняя)
* Отправка новых заказов в Moka POS при смене статуса
* Получение статусов заказов из Moka через webhook
* Привязка товаров по SKU
* Логирование всех операций
* Настройки в админ-панели WordPress

= Требования =
* WooCommerce 5.0+
* PHP 7.4+
* Доступ к API Moka POS (приложение в Moka Connect)
* SSL-сертификат на сайте (обязательно для OAuth2)

== Installation ==

1. Загрузите папку `woocommerce-mokapos` в `/wp-content/plugins/`
2. Активируйте плагин в разделе "Плагины"
3. Перейдите в "WooCommerce → MokaPOS" и настройте подключение
4. Создайте приложение в [Moka Connect](https://connect.mokapos.com) и введите полученные ключи
5. Привяжите товары по SKU или вручную

== Frequently Asked Questions ==

= Как привязать товары? =
Плагин автоматически ищет товары в Moka по полю SKU. Если совпадение найдено — привязка происходит автоматически. Также можно указать Moka Item ID вручную в карточке товара.

= Что делать, если синхронизация не работает? =
Проверьте: 1) валидность токена доступа, 2) наличие прав у приложения в Moka Connect, 3) логи в wp-content/uploads/mokapos-logs/

== Changelog ==

= 1.0.0 =
* Первоначальный релиз
* Базовая синхронизация товаров и заказов
* Поддержка OAuth2 авторизации
* Webhook handler для статусов заказов