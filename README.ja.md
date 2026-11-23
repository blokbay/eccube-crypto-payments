# Blokbay 暗号資産決済 for EC-CUBE 4.2 / 4.3

EC-CUBE のショップで Bitcoin（BTC）、USDC、USDT、ETH による支払いを受け付けます。注文手続きでお客様は安全な Blokbay の
支払いページに移動し、お好きなウォレットから支払います。代金はショップ様ご自身のウォレットに直接届き、Blokbay が資金を預かることはありません。

料金：お支払いごとに 1%（自動で差し引き）。月額費用・初期費用はありません。

## 動作条件

- EC-CUBE 4.2 または 4.3（プラグインコード `BlokbayPayment`）。
- ショップの通貨（`.env` の `ECCUBE_CURRENCY`）：JPY、USD、EUR、GBP、CHF、CAD、AUD、SGD、HKD、AED。注文金額はショップの
  通貨で送信され（JPY は小数なし）、お客様は通貨を選んだ時点で固定される現在の為替レートで暗号資産で支払います。
  ほかの通貨の場合、この支払方法は注文手続き画面に表示されず、設定画面に警告が表示されます。
- Webhook 用に、インターネットから接続できる https のアドレス。

## インストール

1. 管理画面：オーナーズストア > プラグイン > プラグイン一覧 から `blokbay-eccube.zip` をアップロードします
   （コマンドの場合：`bin/console eccube:plugin:install --path=blokbay-eccube.zip`）。
2. 「Blokbay crypto payments」を有効にします（`bin/console eccube:plugin:enable --code=BlokbayPayment`）。
3. プラグインの設定画面を開き、Blokbay のダッシュボード（サイトからの支払い）で作成したシークレットキー、公開キー、
   Webhook 署名シークレットを貼り付けて登録します。API ベースURL は空欄のままで構いません。
4. Blokbay のダッシュボードに Webhook URL `https://your-shop/blokbay_payment/webhook`（設定画面に表示）を設定します。
5. 支払方法「暗号資産決済 (Blokbay)」はすべての配送方法に追加されます。設定 > 店舗設定 > 配送方法設定 で変更できます。

## 仕組み

- 受注は「決済処理中」になり、お客様は Blokbay の支払いページへ移動します。
- 署名付きの `payment.paid`（ヘッダー `Blokbay-Signature: t=<unix>,v1=<HMAC-SHA256>`）で受注が「入金済み」になります。
  金額と通貨が受注と一致しない場合は入金済みにせず、ショップ用メモに記録します。
- お客様が戻ってきたときに API で状態を確認するため、Webhook が遅れても正しく処理されます。
  確認待ちの場合は「新規受付」として受注し、あとで Webhook により入金済みになります。
- `payment.expired` は、その支払いが現在の支払いである場合にのみ受注を取り消します。古い支払いが新しい受注を取り消すことはありません。
- 期限切れのあとに支払いが届いた場合、取り消した受注を再開して入金済みにします（代金はすでにウォレットにあります）。
  受注のショップ用メモのとおり、在庫をご確認ください。
- 支払いページでのキャンセルや、支払い作成時のエラーでは、在庫を戻し、カートの内容はそのまま残ります。
- 同じイベントが2回届いても変化はありません。支払いは受注・金額・試行ごとの Idempotency-Key で作成します。

## サポート

mail@blokbay.com 、ドキュメント https://blokbay.com/docs/api

ライセンス：MIT。作者：Blokbay。
