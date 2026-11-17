# Php Diy Libs

一個基於 PHP 的輕量級自定義函式庫集合，無需依賴大型框架，即可快速整合圖片處理、郵件發送、金流支付與安全性防護等功能。

## 📂 專案結構與功能介紹

本專案依照功能模組化，目錄結構如下：

### 🖼️ `image` (圖片處理)
*   **NotoSansCJKbold.otf**: 中文字體檔，供圖片文字渲染使用。
*   **PhotoGenerator.php**: 圖片生成工具（例如：自動生成帶有文字的圖片、浮水印等）。

### ✉️ `mailer` (郵件發送)
*   **DSNConfigurator.php**: 郵件伺服器 DSN (Data Source Name) 設定工具。
*   **EmailSender.php**: 郵件發送主邏輯封裝。
*   **Exception.php**: 郵件模組專用的例外處理類別。
*   **OAuth.php & OAuthTokenProvider.php**: 支援 OAuth 2.0 認證（例如 Gmail API 發信）。
*   **PHPMailer.php**: PHPMailer 核心類別的封裝或擴充。
*   **POP3.php & SMTP.php**: 支援 POP3 收信與 SMTP 發信協定。

### 💳 `payment` (金流支付)
*   **PaypalRestAPI.php**: PayPal REST API 串接工具。
*   **StripeAPI.php**: Stripe 信用卡支付 API 串接工具。

### 🛡️ `security` (安全性與防護)
*   **HtmlSanitizer.php**: HTML 淨化工具，防止 XSS (跨站腳本攻擊)。
*   **SecureFileCrypto.php**: 檔案加密與解密工具，保護敏感資料。
