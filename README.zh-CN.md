# Paymos Drupal Commerce 稳定币支付模块

[English](README.md) | **简体中文** | [Русский](README.ru.md) | [Español](README.es.md) | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

在 Drupal Commerce 中通过 Paymos 接收稳定币支付与 USDT/USDC 收款。`1.0.0` 为首个稳定版本，可通过 [Drupal.org](https://www.drupal.org/project/commerce_paymos) 与[官方 GitHub 仓库](https://github.com/Paymos-labs/drupal-commerce)获取。

[![观看视频：12 款官方 CMS 稳定币支付插件 — 一键连接（Paymos）](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)
> **视频演示（1:12，提供 6 种语言字幕）：** [在 YouTube 观看](https://www.youtube.com/watch?v=VMLUGVumYkg)

## 环境要求

使用 Drupal `^10.3 || ^11` 与 Drupal Commerce `^3`。本模块声明要求 PHP `^8.1`；您所使用的 Drupal 版本可能需要更高的 PHP 版本，请以更严格的要求为准。您的店铺需要一个可公网访问的 HTTPS 地址，以完成一键连接并接收支付通知。

本模块采用 GPL-2.0-or-later 开源协议。其 PHP SDK 依赖（`paymos/php-sdk`）采用 MIT 协议，并要求 `^1.11.0` 版本（`1.11.0` 或更新的兼容 `1.x` 版本），以支持通过 `Paymos\Plugin\InvoicePresentation::normalizeProducts` 规范化结账时的 `products[]` 商品明细。

## 安装模块

使用 Composer 安装稳定版并自动解析 `paymos/php-sdk:^1.11.0`：

```sh
composer require 'drupal/commerce_paymos:^1.0'
```

您也可以使用单独发布的手动 ZIP 安装包。将压缩包内的 `commerce_paymos` 目录解压到站点的 contributed modules 目录中。手动安装包在 `vendor/` 内已打包 `paymos/php-sdk` `1.11.0`（当 `Paymos\Client` 尚未通过全局自动加载时，由 `SdkBootstrap` 负责加载），因此请完整保留内置文件。安装完成后，在 Drupal 后台启用 Paymos 模块。

如果站点同时使用手动 ZIP 包与其他 Composer 包全局加载的旧版 `paymos/php-sdk`，`CheckoutService` 会先检查 `method_exists(InvoicePresentation::class, 'normalizeProducts')`，并在不存在时安全回退为仅含金额（`amount-only`）的账单载荷，不会导致结账中断。

更新模块后，请重建 Drupal 缓存：`drush --uri=https://your-store.example cache:rebuild`。

## 一键连接店铺

在 Drupal 后台打开 `/admin/commerce/config/payment/paymos`。在 Paymos 控制台中选择该店铺要使用的项目。随后在 Drupal 中点击 **Connect Paymos**，确认授权连接，再返回 Drupal 点击 **Check approval**。

接下来打开 `/admin/commerce/config/payment-gateways/add`。新建一个支付网关，选择 Paymos，并按需选择 Sandbox 或 Live 环境。账号连接与 Commerce 支付网关配置为两个独立步骤，请全部完成后再于前台结账启用该网关。

将 Drupal 后台显示的返回地址复制到您已有的 Paymos 项目中：Success URL 填写 `/paymos/return`，Fail URL 填写 `/paymos/return?cancel=1`。只需配置一次即可。托管收银台会通过其 **Back to store** 链接跳回店铺。Drupal 会在买家的浏览器会话中保留最近一次结账的原生返回地址 1 小时；如果切换浏览器、会话过期或订单/网关绑定发生变化，买家会被导向店铺首页。浏览器跳回绝不代表付款确认；最终支付状态由经校验的 Webhook/API 查询与原生 Commerce 支付记录共同决定。创建账单的请求仅发送订单参考号、`project_id`、`amount`、`currency` 以及可选的规范化 `products[]`，不包含任何买家身份字段。

凭据保存在加密的 Drupal state 中，加密基于 Drupal 的私钥与 `hash_salt`，且密钥绝不会出现在配置导出（configuration exports）里。如果店铺的规范访问 URL（canonical URL）发生变化，请重新将店铺连接到 Paymos。

## 订单商品行与目录边界

当 `Paymos\Plugin\InvoicePresentation::normalizeProducts` 可用时，`CheckoutService` 会过滤掉运费、折扣、税费、附加费和备注行，并按全有或全无（all-or-nothing）规则将 `1..50` 条订单商品行（`title`、正整数 `quantity`、十进制字符串 `unit_amount` 以及可选纯文本 `description`）规范化为 `products[]`：如果任一商品行无效或购物车商品超过 `50` 项，Drupal 会回退为携带原生订单总额（`amount`）的 `amount-only` 账单，而不会发送被截断的购物车。单条商品中空白的描述字段会被省略，不会导致整条商品被丢弃。规范化后的 `products[]` 快照会持久化存储在 `InvoiceStore` 的支付意图中，以便创建中断重试时复用已冻结的原始值，而不读取后续发生变动的订单。对于历史快照，重试时仅会从外发副本中移除已废弃的 `image_url` 键，其余字段原样保留，且不改写已存储快照。

在 Paymos 官方 12 款 CMS 插件目录（`plugins/cms-store-catalog.json`）中：

- 全部 **11** 款商品交易类 CMS 插件均传输 `products[]`：包括基于 `Paymos\Plugin\InvoicePresentation::normalizeProducts` 构建的 **10** 款 PHP 插件（`WooCommerce`、`Easy Digital Downloads`、基于 `paymos/php-sdk:^1.11.0` 的 `Drupal Commerce`、`OpenCart`、`PrestaShop`、`WHMCS`、`CS-Cart`、`Magento 2`、`Shopware 6` 以及基于 `^1.11.0` 的 `HikaShop` `1.1.0`），外加 **Odoo**（`20.0.1.1.0`，通过内置 Paymos Python SDK `1.x`（`>= 1.6.0`）的 `client.request("POST", "/v1/invoices", payload)` 发送 `products`）。
- 仅捐赠网关 **`givewp-paymos`** 保持 `amount-only`，因为捐赠场景不存在商品购物车。

## 保持支付定时检查运行

请使用店铺的规范 HTTPS URL 配置 Drupal 定时任务（cron），例如：

```sh
drush --uri=https://your-store.example cron
```

Cron 会分批对尚未完结的支付执行有界对账检查。即使支付 Webhook 通知送达正常，也请保持 cron 持续运行，以确保未决支付始终能得到跟进校验。

## 订单如何接收付款

支付通知带有签名，并会触发一次 API 反向校验。在写入支付记录之前，账单必须与订单、Paymos 项目、运行环境、金额和币种完全一致。模块通过原生 SQL 事务仅写入一条已完成的 Commerce 支付记录；重复通知不会重复入账。

部分付款或延迟付款不会自动放行商品。存在信息不匹配的付款需要人工复核。Drupal Commerce 通过您店铺自身的订单工作流控制履约发货，请在配置该网关时一并检查相关工作流。

## 退款

本模块通过 Paymos Merchant API（`/v1/refunds`）支持两种由服务端计算金额的原生退款模式：`full`（全额退款）与 `overpayment`（退还 `paid_over` 账单中多付的差额）。Paymos 会发起一笔新的链上出站转账，将资金严格退回至已支付账单记录的经验证 `first_sender` 地址——无需手动填写收款钱包地址，且不支持任意自定义部分退款金额。

具备权限的管理员可通过 Drupal 中的两个受保护入口发起并追踪退款：

- 订单 **Payments** 标签页中的 Commerce 支付 **Refund** 操作表单（`RefundForm`），可选择 **Full refund**（`full`）或 **Refund overpayment**（`overpayment`）；
- `/admin/commerce/config/payment/paymos`（`ConnectForm`）上的 **Paymos refunds** 面板，支持针对符合条件的 Paymos 支付发起 `full` 与 `overpayment` 退款，并展示操作状态与外部退款观测记录。

`/paymos/webhook` 的签名 Webhook 通知和 Drupal cron 对账会持续更新待处理退款操作，并在 Paymos 确认完成（`completed`）后应用原生 Commerce 支付退款状态。在 Sandbox 模式下，可模拟终态退款结果（`completed`、`failed`、`cancelled`）以供测试。

## 卸载模块

只要店铺仍存在退款历史记录，在 `/admin/commerce/config/payment/paymos` 上断开 Paymos 连接的操作就会被拦截。只要数据库中仍存在退款记录或未完结的待处理 Paymos 支付，模块卸载就会被阻止（已完成的账单与 Webhook 事件记录不会阻止断开连接或卸载）。当不再有待处理支付或退款记录阻塞移除时，卸载模块会清除模块自有的加密凭据，同时完整保留原生 Drupal Commerce 支付记录。

## 更多信息

访问 [Paymos](https://paymos.io/zh-hans) 了解支付服务详情，或查阅 [Paymos 文档](https://paymos.io/zh-hans/docs) 获取更多集成说明。
