# ManaLog

石渡塾向け学習記録アプリの開発用プロジェクトです。PHP 8.2+ / MySQL 8.0互換を想定しています。

## 開発環境

1. `.env.example` を `.env` にコピーし、DB設定を入力します。
2. MySQLに `database/migrations/001_initial_schema.sql`、続けて `database/migrations/002_chat_session_subjects.sql` を適用します。既存DBは後者だけを追加します。
3. Webルートを `public/` に設定します（XAMPPでは `/manalog/public`）。
4. 管理者ユーザーは初期セットアップ画面から作成します。

PHP標準機能とPDO以外の依存パッケージはありません。アップロード領域は公開ディレクトリ外の `storage/uploads/` を使います。

## 現在の実装

- PHPアプリの入口、環境設定読み込み、PDO接続
- 初回管理者作成、ログイン・ログアウト
- 管理者による生徒登録、一覧、無効化、パスワード再発行
- 生徒プロフィールの表示・編集
- 月〜土・8限までの時間割手入力と今日の時間割表示
- 時間割画像の検証・一時保存・AI解析・確認後保存
- 通知希望時刻の保存（通知送信はなし）
- 会話セッション・履歴の保存と表示、今日の教科を1つずつ聞く短い学習振り返り
- ブラウザ音声認識での文字起こしと自動送信、非対応時の手入力
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
