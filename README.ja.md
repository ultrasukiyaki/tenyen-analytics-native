[English](README.md)

# Tenyen Analytics

Tenyen Analyticsは、ブラウザ上のインストーラー、ページビュー・エンゲージメント計測、GeoLite2位置情報、ASN分析、Bot判定、非同期管理コンソールを備えたPHPサイト向けのセルフホスト型アクセス解析プラットフォームです。

## 概要

管理者自身の環境に解析データを保存します。CDN、外部解析サービス、本番用の必須ビルド工程はありません。

## 機能

- ページビュー、エンゲージメント、リンク、ダウンロード、404、制限付きカスタムイベントの計測
- 流入チャネル、参照元ドメイン、初回接触UTMキャンペーン帰属
- 生IPの暗号化保存とHMACによる完全一致検索
- 内蔵MMDB ReaderによるGeoLite2 City・ASN情報の付加
- Bot判定、組織分類、10種類の非同期管理画面
- 英語・標準日本語のインターフェース

## 動作要件

PHP 8.1以降、PDO MySQL、MySQLまたはMariaDBが必要です。公開本番環境ではHTTPSが必須です。HTTPはローカル・テスト環境で利用できます。

## SSH・Composerなしの簡単インストール

安定版ZIPをアップロードし、`data/`と`storage/`をPHPから書き込み可能にし、DocumentRootを`public/`に設定して`/install/`を開きます。Composerは任意です。

## 共有サーバーへのインストール

DocumentRootを変更できない場合は`analytics/`以下へ配置し、`/analytics/public/install/`を開きます。`public/`以外は外部公開しないでください。

## 推奨DocumentRoot構成

Webから公開するディレクトリは`public/`だけです。可能な場合、`app/`、`config.php`、`data/`、`storage/`、CLIツールはDocumentRootの外へ置いてください。

## GUIインストーラー

7段階のウィザードで環境確認、サイト・DB設定、管理者作成、任意のGeoLite2設定を行います。秘密情報は`config.php`へ保存され、完了時に`storage/installed.lock`を作成します。

## GeoLite2の設定

GeoLite2 MMDBは同梱していません。MaxMindの利用条件に従って取得してください。GUIは512 KB単位で分割送信し、DB種類を検証します。公式`maxmind-db/reader`がない場合は内蔵Readerを使用します。ASN組織名はMaxMind提供値を変更せず表示します。

## 管理コンソール

インストーラーで作成したアカウントでログインします。イベント、キャンペーン、流入元、セッション、既存分析画面を認証・CSRF保護された非同期通信で表示します。

![Tenyen Analytics管理ダッシュボード](screenshot_dashboard.png)

## 管理者ナレッジ機能

バージョン0.6.2では、サイト単位の管理者用別名（120文字）、プレーンテキストのメモ（4,000文字）、大文字・小文字を区別せず再利用できるタグ（50文字）、組織の注目状態、非公開の保存ビューを追加しました。注釈の識別子には、数値ASN、既存の匿名訪問者ID、保存済みの正規コンテンツパス、正規化済み参照元ドメイン、5種類のUTM値からなる決定的なJSON、外部リンク先ドメインを使用します。別名は収集値と併記し、解析上の事実を変更しません。

ASNを注目しても通知や見込み度の算定は行いません。ASN情報はIPアドレスの登録先組織を示すもので、閲覧者の勤務先や個人識別を証明するものではありません。コンテンツ注釈は保存済みパスをキーとするため、URL変更後は孤立する場合があります。孤立した注釈もナレッジ画面で確認できます。

保存ビューは設定済み管理者だけが利用でき、将来の複数管理者対応を考慮した所有者キーで分離します。画面別の許可済みフィルター、相対・絶対日付、Human/Bot、並び順、表示件数、表示列、タグ・注目条件、ピン、画面ごとに1件の既定値だけを保存します。ページ番号、認証状態、CSRF、トークン、秘密鍵、復号IP、SQL、API応答は保存しません。相対日付は読込時に再計算し、カスタム日付は固定します。

エンティティキー、スキーマ、索引、孤立データ、更新の詳細は[管理者メタデータと保存ビュー](docs/ADMIN_METADATA.ja.md)を参照してください。

## イベント・キャンペーン連携

外部リンクとダウンロードは自動計測します。内部リンクは`track_internal_links`、ボタンは`track_buttons`と`data-tenyen-event="name"`、フォームは`track_forms`と同属性を明示した場合だけ計測します。フォーム値、DOM、パスワード、決済情報は収集しません。

`TYAnalytics.trackEvent('radio_play', {station: 'example-station', server: 'primary'})`または`TYAnalytics.trackEvent('stream_server_change', {server: 'backup'})`を利用できます。名前とスカラー型メタデータには厳格な上限があります。ラジオを自動連携する機能ではありません。

Webサーバーに依存しない404テンプレートでは`TYAnalytics.trackNotFound(location.href)`を呼び出します。通常ページビューとの重複を避ける場合、そのテンプレートでは通常埋め込みを外してください。

チャネルはDirect、Organic Search、Social、Referral、Internal、Campaign、Unknownです。認識する5種類のUTMは参照元分類より優先され、キャンペーン画面は入口ページを初回接触として扱います。

## CLIツール

診断は`php bin/doctor.php`、保持期間の整理は`php bin/cleanup.php`、認証情報生成は`php bin/generate-secrets.php`、CLI案内は`php bin/install.php`を実行します。

## 以前のバージョンからの更新

バックアップ後、`config.php`、`data/`、`storage/`を保持してアプリケーションファイルを上書きします。v0.6.1からv0.6.2への更新では、解析データを書き換えず、注釈、タグ、割り当て、保存ビューのテーブルを冪等に作成します。既存設定、導入ロック、管理者・DB認証情報、サイトトークン、暗号化・HMAC鍵、言語設定、MMDB、イベント、セッションを保持します。移行を再実行しても既存メタデータは保持されます。

直帰率は1ページだけ閲覧した入口セッション数÷入口セッション数です。クリック率は対象ページから一致するクリックがあったセッション数÷そのページの対象ページビューを含むセッション数で、分母0は0%です。通知、保持管理、エクスポート、日次集計、複数サイト、権限、完全な除外管理は今後の課題です。

## プライバシーとセキュリティ

IP、アクセス履歴、参照元、User-Agent・端末情報、地域・ASN情報を処理する場合があります。運営者は保持期間とセルフホスト処理をプライバシーポリシーに記載し、設定、ログ、バックアップ、解析データへのアクセスを制限してください。

## トラブルシューティング

PHP 8.1以降、PDO MySQL、ディレクトリ権限、DB認証情報、公開URL、HTTPSを確認してください。GeoLite2がなくても収集は動作します。

## 開発

`php tests/run.php`、PHP lint、JavaScript構文確認、`tools/build-release.sh`を実行してください。本番動作にComposerやNodeを必須としないでください。

## ライセンス

Copyright © 2026 10yendama.com. GPL-2.0-or-laterです。[LICENSE](LICENSE)と[THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md)を参照してください。
