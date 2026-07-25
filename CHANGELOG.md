# Changelog

## 0.6.0 - 2026-07-25

### Added

- Added an asynchronous session list, ordered session journeys, and anonymous browser visitor history.
- Added entries, exits, bounces, estimated bounce rate, exit rate, sessions, and pageviews-per-session to Content.
- Added navigation from access-history session and visitor identifiers.

### Changed

- Extended English and standard-Japanese interface resources and corrected residual dialect error text.

### Security

- Protected the new session API with existing administrator authentication and CSRF verification.

### Compatibility

- No database schema or index changes are required; the existing `session_time` and `visitor_time` indexes are reused.
- Existing v0.5.7 configuration and data remain compatible. Upgrade packages preserve `config.php`, `data/`, and `storage/`.

## 0.5.7 - 2026-07-23

### Fixed

- Prevented long values such as User-Agent strings from overlapping adjacent columns in expanded access-history details.
- Excluded expanded detail rows from the no-wrap table preference and added safe long-text wrapping.
- Updated the history stylesheet cache key so existing browsers load the corrected layout.
- No database schema changes are required; existing v0.5.6 configuration and data remain compatible.

### 修正

- アクセス履歴の展開詳細で、User-Agentなどの長い文字列が隣の列へ重なる問題を修正しました。
- 展開詳細行を折り返し禁止設定の対象外とし、長い文字列を安全に折り返すよう改善しました。
- 修正済みCSSが既存ブラウザにも読み込まれるよう、キャッシュキーを更新しました。
- DBスキーマ変更はなく、既存のv0.5.6の設定とデータを引き続き利用できます。

## 0.5.6 - 2026-07-23

### Fixed

- Replaced remaining Kansai-dialect text in the Japanese interface with standard Japanese.
- Audited PHP and JavaScript interface strings for translation consistency.
- Preserved MaxMind ASN organization names exactly as provided.
- No database schema changes are required; existing v0.5.5 configuration and data remain compatible.

### 修正

- 日本語インターフェースに残っていた関西弁の文言を標準語へ修正しました。
- PHPおよびJavaScriptの表示文言を再点検し、翻訳の一貫性を改善しました。
- MaxMindが提供するASN組織名は変更せず、そのまま表示します。
- DBスキーマ変更はなく、既存のv0.5.5の設定とデータを引き続き利用できます。

## 0.5.4

- Native管理画面で `hidden` 属性がCSSに上書きされ、読み込み表示が消えない問題を修正。
- 「設定」画面にもGeoLite2 City／ASNの分割アップロードUIを追加。
- 「システム」画面のGeoLite2アップロードも維持。
- HTTPのApacheテスト環境でもGeoLite2アップロードを利用できる旨を明記。
- DBスキーマ変更なし。

## 0.5.3

- GeoLite2 City／ASNを512KBずつ送信する分割アップローダーを追加。
- 大容量MMDB送信時に`post_max_size`超過が「セッション切れ」に見える問題を修正。
- アップロード進捗、種類検査、再試行可能なエラー表示を追加。
- 管理画面認証をApache／FastCGIのAuthorizationヘッダーに依存しないセッションログイン方式へ変更。
- HTTPのApache 2＋PHP 8.3テスト環境に対応し、HTTPS時だけCookieへ`Secure`属性を付与。
- Apache 2向け`.htaccess`のファイル保護ディレクティブ構造を修正。
- HTTP利用時に本番ではHTTPSが必要であることを画面上へ明示。
- Basic認証は既存クライアント向け互換フォールバックとして維持。
- DBスキーマ変更なし。

## 0.5.2

- SSHやComposerを使わずブラウザだけで導入できるGUIセットアップウィザードを追加。
- PHP環境、書込み権限、DB接続、管理アカウント、GeoLite2アップロード、設定確認、埋め込みコード表示を一連の画面に統合。
- `config.php`、秘密鍵、サイトトークン、DBテーブル、`storage/installed.lock`を自動生成。
- GeoLite2 City／ASNの種類をアップロード時に検査。
- 内蔵MMDB Readerを追加し、Composerと公式MaxMind Readerを任意依存へ変更。
- 不完全または古い`vendor/autoload.php`があっても、本体クラスの読込みを継続できるよう改善。
- 機能別の非同期管理コンソールを追加。
- ダッシュボード、リアルタイム、アクセス履歴、コンテンツ、流入元、ASN・組織、ユーザー環境、エンゲージメント、システム、設定へ画面を分割。
- `?view=`、History API、戻る・進む、再読込、通信中断、再試行、レスポンシブメニューに対応。
- `bin/doctor.php`を追加し、環境、DB、テーブル、MMDB、公開URL、埋め込みコードを確認可能にした。
- `site_url`と解析対象ページのオリジンを照合するよう収集元判定を修正。
- 共有サーバー向けに非公開ディレクトリを保護する`.htaccess`を追加。
- DBスキーマ変更なし。

## 0.5.0

- アクセス詳細履歴を認証付き非同期APIへ変更。
- 履歴セクションの折り畳み、コンパクト表示、25件初期表示を追加。
- 検索・日付・イベント・訪問者・国・ブラウザ・OS・端末・並び順の非同期フィルタを追加。
- 表示列、密度、折り返し、固定ヘッダー、自動更新をlocalStorageへ保存する設定ペインを追加。
- DBスキーマ変更なし。

## 0.4.1

- WordPress版の期間推移修正に合わせ、時間・日・月バケット生成SQLを同一方式へ統一。
- `DATE_FORMAT()`依存を外し、MariaDB／MySQL環境差に対する集計の堅牢性を向上。
- DBスキーマ変更なし。

## 0.4.0

- 記事・固定ページ・参照元・対象URLを安全な別タブリンクに変更。
- 指定期間のPV、推定UU、セッション、平均滞在、平均スクロール、Botイベントを追加。
- 時間別・日別・月別のPV／UU／セッション推移グラフを追加。
- ブラウザ、OS、端末、国、ASN／組織の構成グラフを追加。
- 人間のみ、Botのみ、すべての分析対象切替を追加。
- `app.site_url`を追加。未設定時は管理画面と同一オリジンへフォールバック。
- グラフはローカルCanvas実装で外部通信なし。
- DBスキーマ変更なし。

## 0.3.0

- ASN組織カテゴリーの自動判定とバッジ表示を追加。
- 注目組織アクセスを追加。
- pageviewとengagementを統合した最近の閲覧を追加。
- 人気記事・注目組織の直近7日ランキングを追加。
- 生ログ行へ展開詳細を追加。
- `config.php`からASN分類を上書き可能にした。
- DBスキーマ変更なしでv0.2.0から更新可能。

## 0.2.0

- 検索、日付・イベント・人間／Bot絞り込み、25／50／100件ページングを追加。

## 0.1.1

- 管理パスワード生成表示とFastCGI Basic認証対応を修正。

## 0.1.0

- Native PHP向け初回リリース。
## 0.5.5

- Added English and standard-Japanese interface support.
- Added a lightweight translation layer, installer language selector, and protected admin language preference.
- Localized browser-script messages and added common product branding/footer.
- Added public repository metadata, GPL licensing, bilingual documentation, community files, CI, smoke tests, and release tooling.
- Preserved raw ASN organization names exactly as supplied by MaxMind.
- No database schema changes are required.
