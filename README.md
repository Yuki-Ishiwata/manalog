# ManaLog

石渡塾向け学習記録アプリの開発用プロジェクトです。PHP 8.2+ / MySQL 8.0互換を想定しています。

## 開発環境

1. `.env.example` を `.env` にコピーし、DB設定を入力します。
2. MySQLに `database/migrations/001_initial_schema.sql` から `006_web_push.sql` まで番号順に適用します。既存DBは未適用のマイグレーションだけを追加します。
3. Webルートを `public/` に設定します（XAMPPでは `/manalog/public`）。
4. `composer install --no-dev` でWeb Pushライブラリを入れます。
5. 管理者ユーザーは初期セットアップ画面から作成します。

アップロード領域は公開ディレクトリ外の `storage/uploads/` を使います。Composerの `vendor/` も公開ディレクトリ外に置きます。

## 現在の実装

- PHPアプリの入口、環境設定読み込み、PDO接続
- 初回管理者作成、ログイン・ログアウト
- 管理者による生徒登録、一覧、無効化、パスワード再発行
- 生徒プロフィールの表示・編集
- 月〜土・8限までの時間割手入力・一括削除と今日の時間割表示
- 時間割画像の検証・一時保存・AI解析・確認後保存
- 月〜日の曜日別・帰宅後／夜の通知時刻とON/OFF、一時停止
- 夜の時刻は24:00〜29:59にも対応し、選んだ曜日の翌朝として保存
- PWAのインストール、Web Push購読・解除、cron送信、送信履歴
- 帰宅後と家庭学習の夜の振り返りを別々に保存・再開
- 従来の自由会話も引き続き利用可能
- 会話セッション・履歴の保存と表示、今日の教科を1つずつ各2問で聞く学習振り返り
- 教科名ごとの「質問しない教科」設定
- ブラウザ音声認識での文字起こし・自動送信・連続した会話モード、非対応時の手入力
- 生徒プロフィール、時間割、チャット、通知設定を含む初期DBスキーマ
- モバイル向けのログイン画面

チャットと画像解析を使うには `.env` に `AI_API_KEY` と利用する `AI_MODEL` を設定してください。設定がない場合は案内を表示します。画像は5MB以下のJPEG・PNG・WebPを受け付け、解析後に一時ファイルを削除します。APIキーはサーバー側だけで使います。

## エックスサーバーへの配置

`deploy.sh` は `../i-yoyaku/deploy_ishiwata.sh` のSSH・rsync方式を参考にしています。初回の接続先と配置先は推測せず、`deploy.config.local` に設定します。

1. `deploy.config.example` を `deploy.config.local` にコピーし、SSH接続先、公開領域外の `DEPLOY_ROOT`、サブドメインのドキュメントルート `DEPLOY_PUBLIC_ROOT`、サーバー側の `DEPLOY_ENV_FILE` を入力します。
2. エックスサーバーのサブドメイン設定で `DEPLOY_PUBLIC_ROOT` を公開先として選びます。たとえば `.../public_html/manalog/` です。
3. サーバーに `DEPLOY_ENV_FILE` で指定した `.env` を作成します。別の場所に置いた場合、デプロイ時に `DEPLOY_ROOT/.env` へシンボリックリンクを作成します。このファイルと画像・ログはデプロイで転送しません。
4. `./deploy.sh --dry-run` で転送予定を確認し、`./deploy.sh --apply` で転送します。初回で `DEPLOY_ROOT` がまだない場合も、dry-run は作成予定と転送ファイルを表示します。
5. SQLマイグレーションは内容を確認し、専用DBに別途適用します。スクリプトはDBを変更しません。

`public/` の中身は `DEPLOY_PUBLIC_ROOT` に、`src/` と `database/migrations/` は `DEPLOY_ROOT` に転送します。`app_root.local.php` はサーバー上に生成し、公開側からアプリ本体を参照します。既存サイトや別DBには触れません。古いリモートファイルを自動削除しないため、不要ファイルが出た場合は個別に確認して削除してください。

## Phase 1.5 Web Push 導入

Web PushにはHTTPS、Service Worker、端末ごとの購読、VAPID鍵が必要です。iPhoneはホーム画面に追加したWebアプリから通知許可を操作します。AndroidはChromeなどの対応ブラウザからホーム画面に追加して使います。許可ダイアログは生徒が「この端末で通知を受け取る」を押したときだけ表示します。

1. DBをバックアップし、`database/migrations/006_web_push.sql` を **Manalog専用DB** に適用します。既存の曜日別時刻は残り、ONが初期値です。購読するまでは送信されません。
2. `composer install --no-dev --prefer-dist --optimize-autoloader` を実行します。`deploy.sh` は `vendor/`、`composer.json`、`composer.lock`、`bin/` を公開ディレクトリ外の `DEPLOY_ROOT` に転送します。
3. `./deploy.sh --dry-run` を確認してから `./deploy.sh --apply` で配置します。`public/` 以外に置く `.env`、`vendor/`、`bin/` はWebから直接参照できません。
4. サーバーの公開ディレクトリ外のアプリルートで `/usr/bin/php8.3 bin/generate_vapid.php https://manalog.ishiwata-juku.com/` を1回実行します。公開鍵・秘密鍵・subjectを **`DEPLOY_ENV_FILE` の `.env` だけ** に書き、秘密鍵は画面に表示しません。鍵を変えると既存の購読が無効になるため保管してください。`APP_BASE_URL=https://manalog.ishiwata-juku.com/` も `.env` に設定します。
5. エックスサーバーのcronに5分間隔で次を登録します。例: `*/5 * * * *`、実行コマンド `/usr/bin/php8.3 /home/xs934178/ishiwata-juku.com/manalog/bin/send_notifications.php >> /home/xs934178/ishiwata-juku.com/manalog/storage/logs/notification-cron.log 2>&1`。CLIのPHPに `curl`、`mbstring`、`openssl` が必要です。cronは `Asia/Tokyo` を明示して実行します。
6. iPhoneはSafariの共有メニューからホーム画面に追加し、そのアイコンからログインして通知を許可します。Androidはホーム画面に追加して通知を許可します。生徒画面で曜日別時刻・ON/OFF・一時停止を保存してください。

夜の `25:00` は選択した曜日の翌日 `01:00` として計算します。cronは予定時刻を過ぎてから12時間以内の未処理通知を拾うため、5分間隔から多少遅れても送信できます。同じ生徒・学習日・通知種別はDBで一意にし、端末ごとの送信結果を別に保存します。送信が受理された端末には再送しません。結果が不明な通信エラーも二重送信を避けるため自動再送しません。通知タップは対象の会話を開き、未回答の質問はそのまま残ります。管理者画面の「通知送信履歴」と `notification_deliveries` / `notification_delivery_devices` で結果とエラーを確認できます。

ローカルの自動確認は `php tests/phase15.php` です。Manalog専用のローカルDBへ `006` を適用した後に実行してください。一時的なテスト生徒を作成し、終了時に削除します。実機ではiPhoneとAndroidそれぞれで購読、通知受信、タップで会話を開く動作、解除と一時停止を確認してください。
