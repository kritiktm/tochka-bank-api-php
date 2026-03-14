# 🏦 Tochka Bank uAPI Module for PHP

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D7.4-blue.svg)](https://www.php.net/)

Простой и мощный модуль на PHP для интеграции с **Tochka Bank uAPI**. Позволяет работать с выписками, балансом, вебхуками и создавать ссылки на оплату (Эквайринг).

## ✨ Возможности

- 📄 **Выписки**: Получение банковских выписок в синхронном режиме.
- 💰 **Баланс**: Мгновенная проверка доступного остатка на счете.
- 🔗 **Эквайринг**: Генерация платежных ссылок (СБП, карты, QR).
- ⚓ **Вебхуки**: Готовый обработчик входящих платежей с уведомлениями в Telegram.
- 📥 **Импорт**: Скрипт для удобного импорта транзакций в вашу БД.

## 🚀 Быстрый старт

### 1. Требования
- PHP 7.4 или выше
- Расширение `curl` и `pdo_mysql`
- Аккаунт в Банке Точка с доступом к uAPI

### 2. Установка
Просто скопируйте папку `bank_module` в ваш проект.

### 3. Настройка БД
Выполните SQL-запрос для создания необходимых таблиц:

```sql
CREATE TABLE `incomes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `amount` decimal(15,2) NOT NULL,
  `date` date NOT NULL,
  `description` text,
  `external_id` varchar(255) DEFAULT NULL,
  `is_verified` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `external_id` (`external_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `settings` (
  `setting_key` varchar(255) NOT NULL PRIMARY KEY,
  `setting_value` text
);
```

### 4. Конфигурация
Переименуйте `db.php.example` в `db.php` и введите данные вашей базы данных. Затем добавьте ключи API Точки в таблицу `settings`.

## 🛠 Примеры использования

### Получение баланса
```php
require_once 'api/tochka_service.php';
$tochka = new TochkaService($jwt, $client_id, $account, $bik);
echo "Баланс: " . $tochka->getBalance() . " руб.";
```

### Создание ссылки на оплату
```php
$link = $tochka->createPaymentLink(1000.50, "Оплата заказа #123", "https://your-site.com/success");
echo "Оплатите здесь: " . $link['data']['Data']['paymentLink'];
```


## 📄 Лицензия
Данный проект распространяется под лицензией MIT. Подробнее см. в файле [LICENSE](LICENSE).

---
*Сделано для упрощения работы с API Банка Точка.*
